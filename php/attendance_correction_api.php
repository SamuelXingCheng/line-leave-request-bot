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

    if (!$user) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "系統找不到您的員工資料，請先聯繫管理員完成註冊。"]);
        exit;
    }
    $userName = $user['name'];
    $isBoss = ($user['role'] === 'boss');

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
    
    $names = !empty($supervisorIds) ? getSupervisorNames($supervisorIds) : [];
    $supStr = !empty($names) ? implode("、", $names) : "系統管理員 (未設定主管)";
    $hintMsg = createBusinessFlex(
        "SYSTEM", "系統提示", 
        ["簽核主管" => $supStr, "後續動作" => "請將上方申請單「轉傳」給簽核主管"], 
        "#17A2B8"
    );

    // 5. 產生商務版訊息
    $liffId = getenv("MENU_LIFF_ID");
    $approvalLink = "https://liff.line.me/{$liffId}/?page=supervisor&tab=clockin&highlight={$uuid}";

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
            "請點選下方連結進入審核中心簽核：\n" .
            $approvalLink
    ];

    echo json_encode([
        "status" => "success",
        "messages" => [$mainMsg, $hintMsg]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}