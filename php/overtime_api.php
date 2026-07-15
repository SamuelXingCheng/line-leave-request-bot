<?php
// overtime_api.php
header("Content-Type: application/json; charset=utf-8");
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0750, true);
}
ini_set('error_log', $logDir . '/overtime_error.log');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['status' => 'error', 'message' => '無效的輸入資料']);
    exit;
}

if (
    !isset($input['accessToken'], $input['date'], $input['start'], $input['end'], $input['reason']) ||
    $input['accessToken'] === '' || $input['date'] === '' ||
    $input['start'] === '' || $input['end'] === '' || $input['reason'] === ''
) {
    echo json_encode(['status' => 'error', 'message' => '所有欄位皆為必填']);
    exit;
}

$accessToken = $input['accessToken'];
try {
    $verifiedUserId = verifyLineAccessToken($accessToken);
} catch (Exception $e) {
    error_log('[overtime_api] Token verification failed: ' . $e->getMessage());
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => '身份驗證失敗，請重新登入。']);
    exit;
}

$userId    = $verifiedUserId;
$date      = $input['date'];
$startTime = $input['start'];
$endTime   = $input['end'];
$reason    = $input['reason'];

try {
    $db = Database::getConnection();

    // 1. 取得員工姓名
    $stmt = $db->prepare("SELECT name FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userName = $stmt->fetchColumn() ?: "員工";

    // 2. 驗證格式並計算時數
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ||
        !preg_match('/^\d{2}:\d{2}$/', $startTime) ||
        !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
        throw new Exception("日期或時間格式無效");
    }

    $startAt = "$date $startTime:00";
    $endAt   = "$date $endTime:00";

    $hours = calculateOvertimeHours($startAt, $endAt);
    if ($hours <= 0) {
        throw new Exception("加班時數計算結果為零或負數，請確認時段是否正確。");
    }

    // 🔥 新增：加班時段重疊防呆檢查
    $overlapStmt = $db->prepare("
        SELECT COUNT(*) FROM overtime_requests 
        WHERE user_id = ? 
          AND status IN ('pending', 'approved')
          AND start_at < ? AND end_at > ?
    ");
    $overlapStmt->execute([$userId, $endAt, $startAt]);
    if ($overlapStmt->fetchColumn() > 0) {
        throw new Exception("您申請的時段與現有（或審核中）的加班單重疊，請確認後再送出。");
    }

    // 3. 寫入資料庫
    $uuid = generateOvertimeUuid();
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
        $messages[] = [
            "type" => "text",
            "text" => "【系統提示】\n尚未設定您的直屬主管，請自行確認轉傳對象。"
        ];
    }

    // 5. 回傳給前端
    echo json_encode([
        'status'   => 'success',
        'message'  => '申請成功',
        'messages' => $messages
    ]);

} catch (Exception $e) {
    error_log('[overtime_api] Exception: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
