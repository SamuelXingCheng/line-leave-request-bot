<?php
// attendance_api.php

// 允許跨域請求
header("Access-Control-Allow-Origin: https://www.citc.org.tw");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';

header('Content-Type: application/json; charset=utf-8');

// 公司座標 & 半徑
define("COMPANY_LAT", (float) getenv("COMPANY_LAT"));
define("COMPANY_LNG", (float) getenv("COMPANY_LNG"));
define("ALLOWED_RADIUS", (float) getenv("ALLOWED_RADIUS"));

// 讀取 LIFF 傳來的 JSON
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "❌ 無效的輸入"]);
    exit;
}

$userId = $data['userId'] ?? null;
$mode   = $data['mode'] ?? null;   // 上班 or 下班
$lat    = isset($data['latitude']) ? floatval($data['latitude']) : null;
$lng    = isset($data['longitude']) ? floatval($data['longitude']) : null;
$reason = $data['reason'] ?? null;

if (!$userId || !$mode || !$lat || !$lng) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "❌ 缺少必要參數"]);
    exit;
}

// DB 連線
$db = Database::getConnection();

// 產生 UUID（要先用）
$uuid = $db->query("SELECT UUID()")->fetchColumn();

// 撈出員工姓名
$stmt = $db->prepare("SELECT name FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$row = $stmt->fetch();
$userName = $row ? $row['name'] : "未知使用者";

// 計算距離
$distance = calculateDistance($lat, $lng);
$now = date("Y-m-d H:i:s");

$supervisors = [];
$approvalUrl = null;

if ($distance <= ALLOWED_RADIUS) {
    $status = "success";
    $approval = "normal";
    $reason = null; 
    $message = "✅ {$mode}打卡成功！時間：{$now}";
} else {
    $status = "fail";
    $approval = "pending";

    // 查詢該員工的主管名單
    $stmt = $db->prepare("
        SELECT u.user_id, u.name 
        FROM user_supervisors us
        JOIN users u ON us.supervisor_id = u.user_id
        WHERE us.user_id = ?
    ");
    $stmt->execute([$userId]);
    $supervisors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 主管審核連結（改成官方帳號 URL scheme）
    $BOT_BASIC_ID = getenv("LINE_BOT_ID");
    $approvalCommand = "/審核打卡 {$uuid}";
    $approvalUrl = "https://line.me/R/oaMessage/@{$BOT_BASIC_ID}/?" . rawurlencode($approvalCommand);

    // Google Maps 連結
    $mapUrl = "https://www.google.com/maps?q={$lat},{$lng}";

    // 提示訊息（只給框1用）
    $message = "⚠️ 不在公司範圍內（距離 " . intval($distance) . " 公尺），打卡狀態為【待審核】。";
}

// 存進資料庫
try {
    $stmt = $db->prepare("
        INSERT INTO attendance_logs 
        (attendance_uuid, user_id, mode, latitude, longitude, distance, status, reason, approval_status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $uuid,
        $userId,
        $mode,
        $lat,
        $lng,
        intval($distance),
        $status,
        $reason,
        $approval
    ]);
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "❌ 存取打卡紀錄失敗: " . $e->getMessage()
    ]);
    exit;
}

// 回傳給前端（乾淨結構）
echo json_encode([
    "status" => $status,
    "message" => $message,             // 框1用
    "employee_name" => $userName,      // 框3用
    "attendance_time" => $now,         // 框3用
    "reason" => $reason,               // 框3用
    "distance" => intval($distance),
    "approval_status" => $approval,
    "attendance_uuid" => $uuid,
    "approval_url" => $approvalUrl,    // 框3用（跳官方帳號 + 帶指令）
    "map_url" => $mapUrl ?? null,      // 地圖連結
    "supervisors" => $supervisors      // 框2用
]);

// --- 工具函式 ---
function calculateDistance($lat, $lng) {
    $R = 6371000; // 地球半徑（公尺）
    $phi1 = deg2rad(COMPANY_LAT);
    $phi2 = deg2rad($lat);
    $dPhi = deg2rad($lat - COMPANY_LAT);
    $dLambda = deg2rad($lng - COMPANY_LNG);

    $a = sin($dPhi/2)**2 + cos($phi1) * cos($phi2) * sin($dLambda/2)**2;
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}
