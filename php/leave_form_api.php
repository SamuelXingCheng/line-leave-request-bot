<?php
// php/leave_form_api.php
header('Content-Type: application/json; charset=utf-8');

// 載入設定與資料庫工具
require_once __DIR__ . '/config.php'; 
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("請求方法錯誤");
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!$input || empty($input['userId'])) {
        throw new Exception("無效的輸入資料");
    }

    $db = Database::getConnection();
    $userId = $input['userId'];

    // 1. 取得員工姓名
    $stmt = $db->prepare("SELECT name FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userName = $stmt->fetchColumn() ?: "員工";

    // 2. 查詢直屬主管名單 (核心修正)
    $stmtSup = $db->prepare("SELECT supervisor_id FROM user_supervisors WHERE user_id = ?");
    $stmtSup->execute([$userId]);
    $supervisorIds = $stmtSup->fetchAll(PDO::FETCH_COLUMN);

    $supervisorNames = [];
    if (!empty($supervisorIds)) {
        // 調用 utils.php 函式取得姓名
        $supervisorNames = getSupervisorNames($supervisorIds);
    }

    // 3. 處理請假事由 (選填)
    $reason = trim($input['reason'] ?? '');
    if ($reason === '') {
        $reason = "（未填寫）";
    }

    // 4. 產生 UUID 與存入假單
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $startAt = $input['startDate'] . ' ' . $input['startTime'];
    $endAt   = $input['endDate'] . ' ' . $input['endTime'];

    $leaveHours = calculateHours($startAt, $endAt);

    $stmt = $db->prepare("
        INSERT INTO leave_requests (
            request_group_id, user_id, user_name, leave_type, reason, start_at, end_at, leave_hours, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
        $uuid, $userId, $userName, $input['leaveType'], $reason, $startAt, $endAt, $leaveHours
    ]);
    $leaveId = $db->lastInsertId();

    // 5. 建立簽核關聯 (確保主管有權限簽核)
    if (!empty($supervisorIds)) {
        $stmtApp = $db->prepare("INSERT INTO leave_approvals (request_id, supervisor_id, status) VALUES (?, ?, 'pending')");
        foreach ($supervisorIds as $sid) {
            $stmtApp->execute([$leaveId, $sid]);
        }
    }

    // ----------------------------------------------------
    // 6. 製作商務版訊息 (拆分為兩則獨立文字串)
    // ----------------------------------------------------

    $botId = getenv("LINE_BOT_ID"); 
    $approvalCommand = "/同意請假 {$uuid}";
    $approvalLink = "line://oaMessage/@{$botId}/?" . rawurlencode($approvalCommand);

    // 第一則：正式簽核通知
    $mainMessage = [
        "type" => "text",
        "text" => 
            "【請假簽核通知】\n" .
            "────────────────\n" .
            "申請人員｜{$userName}\n" .
            "假別類別｜{$input['leaveType']}\n" .
            "請假事由｜{$reason}\n" .
            "────────────────\n" .
            "申請時段｜\n" .
            "{$input['startDate']} {$input['startTime']} ~ {$input['endDate']} {$input['endTime']}\n\n" .
            "若同意申請，請點擊下方連結簽核：\n" .
            $approvalLink
    ];

    // 第二則：主管提示訊息
    $hintText = "【系統提示】\n────────────────\n請將上方訊息轉傳給：";
    if (!empty($supervisorNames)) {
        $hintText .= "\n─ " . implode("\n─ ", $supervisorNames);
    } else {
        $hintText .= "\n尚未設定您的直屬主管，請聯繫管理員。";
    }

    $hintMessage = [
        "type" => "text",
        "text" => $hintText
    ];

    // 回傳兩則訊息給前端
    echo json_encode([
        "status" => "success",
        "forward_message" => [$mainMessage, $hintMessage]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}