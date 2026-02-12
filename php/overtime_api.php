<?php
// overtime_api.php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['status' => 'error', 'message' => '無效的輸入資料']);
    exit;
}

$userId = $input['userId'];
$date = $input['date'];
$startTime = $input['start'];
$endTime = $input['end'];
$reason = $input['reason'];

if (!$userId || !$date || !$startTime || !$endTime || !$reason) {
    echo json_encode(['status' => 'error', 'message' => '所有欄位皆為必填']);
    exit;
}

try {
    $db = Database::getConnection();

    // 1. 取得員工姓名
    $stmt = $db->prepare("SELECT name FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userName = $stmt->fetchColumn() ?: "員工";

    // 2. 計算時數
    $startAt = "$date $startTime:00";
    $endAt   = "$date $endTime:00";
    $t1 = strtotime($startAt);
    $t2 = strtotime($endAt);
    if ($t2 <= $t1) throw new Exception("結束時間必須晚於開始時間");
    $hours = round(($t2 - $t1) / 3600, 1);

    // 3. 寫入資料庫
    $uuid = uniqid("OT-");
    $stmt = $db->prepare("
        INSERT INTO overtime_requests 
        (overtime_uuid, user_id, user_name, start_at, end_at, hours, reason, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$uuid, $userId, $userName, $startAt, $endAt, $hours, $reason]);

    // 4. 準備訊息 (準備透過 LIFF 發送)
    $messages = [];

    // --- 訊息 1：加班申請單 (主要轉傳內容) ---
    $botId = getenv("LINE_BOT_ID");
    $approvalCommand = "/同意加班 {$uuid}";
    $approvalLink = "line://oaMessage/@{$botId}/?" . rawurlencode($approvalCommand);

    $mainText = "【加班申請】\n" .
                "────────────────\n" .
                "申請人員｜{$userName}\n" .
                "加班日期｜{$date}\n" .
                "加班時段｜{$startTime} ~ {$endTime}\n" .
                "加班時數｜{$hours} 小時\n" .
                "加班內容｜{$reason}\n" .
                "────────────────\n" .
                "👉 主管簽核連結：\n" .
                $approvalLink;

    $messages[] = [
        "type" => "text",
        "text" => $mainText
    ];

    // --- 訊息 2：系統提示 (告訴員工轉給誰) ---
    // 查詢主管姓名
    $supStmt = $db->prepare("
        SELECT u.name 
        FROM user_supervisors us
        JOIN users u ON us.supervisor_id = u.user_id
        WHERE us.user_id = ?
    ");
    $supStmt->execute([$userId]);
    $supervisors = $supStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($supervisors)) {
        $hintText = "【系統提示】\n請將上方訊息轉傳給：\n─ " . implode("\n─ ", $supervisors);
        $messages[] = [
            "type" => "text",
            "text" => $hintText
        ];
    } else {
        // 如果沒設定主管，還是給個提示
        $messages[] = [
            "type" => "text",
            "text" => "【系統提示】\n尚未設定您的直屬主管，請自行確認轉傳對象。"
        ];
    }

    // 5. 系統推播備份 (只推給員工自己留底，不推給主管)
    $employeeFlex = createBusinessFlex(
        "SUBMITTED",
        "加班申請已建立",
        [
            "申請日期" => $date,
            "加班時段" => "$startTime ~ $endTime",
            "加班時數" => "$hours 小時",
            "說明"     => "請將聊天室中的申請訊息轉傳給主管。"
        ],
        "#06C755"
    );
    if (function_exists('pushFlexMessage')) {
        pushFlexMessage($userId, $employeeFlex);
    }

    // 6. 回傳給前端 (注意這裡是回傳 messages 陣列)
    echo json_encode([
        'status' => 'success', 
        'message' => '申請成功',
        'messages' => $messages // 🔥 包含兩則訊息
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}