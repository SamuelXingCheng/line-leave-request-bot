<?php
// php/cron_remind_supervisors.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

// 設定一個簡單的密碼保護，避免被外人惡意亂點觸發
$secretKey = "remind2026";
if (($_GET['key'] ?? '') !== $secretKey && php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden");
}

try {
    $db = Database::getConnection();
    $liffId = getenv("MENU_LIFF_ID");
    $approvalLink = "https://liff.line.me/{$liffId}/?page=supervisor";

    // 1. 取得所有目前有被設為主管理員的 LINE ID
    $stmt = $db->query("SELECT DISTINCT supervisor_id FROM user_supervisors");
    $supervisors = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $count = 0;

    foreach ($supervisors as $supId) {
        // A. 統計該主管待審核的請假單
        $leaveStmt = $db->prepare("SELECT COUNT(*) FROM leave_approvals WHERE supervisor_id = ? AND status = 'pending'");
        $leaveStmt->execute([$supId]);
        $pendingLeave = (int)$leaveStmt->fetchColumn();

        // B. 統計該主管待審核的加班單 (透過下屬關聯反查)
        $otStmt = $db->prepare("
            SELECT COUNT(*) FROM overtime_requests o
            JOIN user_supervisors us ON o.user_id = us.user_id
            WHERE us.supervisor_id = ? AND o.status = 'pending'
        ");
        $otStmt->execute([$supId]);
        $pendingOt = (int)$otStmt->fetchColumn();

        // C. 統計該主管待審核的補打卡 (透過下屬關聯反查)
        $ckStmt = $db->prepare("
            SELECT COUNT(*) FROM attendance_logs a
            JOIN user_supervisors us ON a.user_id = us.user_id
            WHERE us.supervisor_id = ? AND a.approval_status = 'pending'
        ");
        $ckStmt->execute([$supId]);
        $pendingCk = (int)$ckStmt->fetchColumn();

        $totalPending = $pendingLeave + $pendingOt + $pendingCk;

        // 如果有任何待辦事項，就發送通知給該主管
        if ($totalPending > 0) {
            $flexCard = createBusinessFlex(
                "REMINDER", 
                "待簽核單據提醒", 
                [
                    "請假單" => "{$pendingLeave} 筆待審核",
                    "加班單" => "{$pendingOt} 筆待審核",
                    "補打卡" => "{$pendingCk} 筆待審核",
                    "總計"   => "共 {$totalPending} 筆未處理"
                ], 
                "#FF8C00" // 橘色提醒
            );

            $messages = [
                $flexCard,
                ["type" => "text", "text" => "點擊下方連結前往審核中心：\n" . $approvalLink]
            ];

            if (function_exists('pushMessage')) {
                pushMessage($supId, $messages);
            }
            $count++;
        }
    }

    echo "成功發送提醒給 {$count} 位主管。";

} catch (Exception $e) {
    error_log("自動提醒排程錯誤: " . $e->getMessage());
    echo "發生錯誤：" . $e->getMessage();
}