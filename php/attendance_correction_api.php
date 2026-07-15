<?php
// php/attendance_correction_api.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Method Not Allowed");
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) throw new Exception("Invalid Input");

    $db = Database::getConnection();
    
    // 1. 取得員工姓名與角色 (加入老闆特權判斷)
    $stmt = $db->prepare("SELECT name, role FROM users WHERE user_id = ?");
    $stmt->execute([$input['userId']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $userName = $user ? $user['name'] : "員工";
    $isBoss = ($user && $user['role'] === 'boss');

    // 2. 準備資料與動態狀態
    $uuid = uniqid("FIX-"); 
    $typeStr = ($input['type'] === 'in') ? "上班" : "下班"; 
    $datetime = $input['targetDate'] . ' ' . $input['correctTime'] . ':00';

    $approvalStatus = $isBoss ? 'approved' : 'pending';
    $recordStatus   = $isBoss ? 'success' : 'pending';

    // 🔥 3. 寫入資料庫 (代入動態狀態)
    $stmt = $db->prepare("
        INSERT INTO attendance_logs 
        (attendance_uuid, user_id, mode, created_at, reason, approval_status, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $uuid, $input['userId'], $typeStr, $datetime, $input['reason'], $approvalStatus, $recordStatus
    ]);

    // 🔥 4. 如果是老闆，直接回傳成功訊息，提早結束！
    if ($isBoss) {
        $bossMsg = [
            "type" => "text",
            "text" => "補打卡已自動核准歸檔。\n日期：{$input['targetDate']}\n類型：{$typeStr}\n時間：{$input['correctTime']}\n原因：{$input['reason']}"
        ];
        echo json_encode(["status" => "success", "messages" => [$bossMsg]]);
        exit;
    }

    // 4. 取得主管名單
    $stmtSup = $db->prepare("SELECT supervisor_id FROM user_supervisors WHERE user_id = ?");
    $stmtSup->execute([$input['userId']]);
    $supervisorIds = $stmtSup->fetchAll(PDO::FETCH_COLUMN);
    
    $supervisorHint = "尚未設定直屬主管，請聯繫管理員。";
    if (!empty($supervisorIds)) {
        $names = getSupervisorNames($supervisorIds);
        $supervisorHint = "請將上方訊息轉傳給：\n─ " . implode("\n─ ", $names);
    }

    // 5. 產生商務版訊息
    $botId = getenv("LINE_BOT_ID");
    $approvalCommand = "/同意補卡 {$uuid}"; 
    $approvalLink = "line://oaMessage/@{$botId}/?" . rawurlencode($approvalCommand);

    $mainMsg = [
        "type" => "text",
        "text" => 
            "【補打卡簽核通知】\n" .
            "────────────────\n" .
            "申請人員｜{$userName}\n" .
            "補卡類別｜{$typeStr}\n" .
            "補卡時間｜{$datetime}\n" .
            "補卡原因｜{$input['reason']}\n" .
            "────────────────\n" .
            "若同意申請，請點擊下方連結簽核：\n" .
            $approvalLink
    ];

    $hintMsg = [
        "type" => "text",
        "text" => "【系統提示】\n────────────────\n" . $supervisorHint
    ];

    echo json_encode([
        "status" => "success",
        "messages" => [$mainMsg, $hintMsg]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}