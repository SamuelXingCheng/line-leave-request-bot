<?php
// attendance_api.php

// 允許跨域請求
header("Access-Control-Allow-Origin: https://churchintaichung.org");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

header('Content-Type: application/json; charset=utf-8');

// --- 讀取環境變數 ---
// 打卡點 1 (原有)
$locations = [
    [
        'lat' => (float) getenv("COMPANY_LAT"),
        'lng' => (float) getenv("COMPANY_LNG"),
        'name' => '辦公室 A'
    ],
    // 打卡點 2 (新增，請在 .env 中設定 COMPANY_LAT_2 與 COMPANY_LNG_2)
    [
        'lat' => (float) getenv("COMPANY_LAT_2"),
        'lng' => (float) getenv("COMPANY_LNG_2"),
        'name' => '辦公室 B'
    ]
];

define("ALLOWED_RADIUS", (float) getenv("ALLOWED_RADIUS"));
define("OFFICE_QR_TOKEN", getenv("OFFICE_QR_TOKEN")); // 在 .env 設定一個秘密字串

// 讀取 LIFF 傳來的 JSON
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "❌ 無效的輸入"]);
    exit;
}

$userId  = $data['userId'] ?? null;
$mode    = $data['mode'] ?? null;
$lat     = isset($data['latitude']) ? floatval($data['latitude']) : null;
$lng     = isset($data['longitude']) ? floatval($data['longitude']) : null;
$reason  = $data['reason'] ?? null;
$qrToken = $data['qr_token'] ?? null; // 接收來自前端掃描到的 Token

if (!$userId || !$mode || !$lat || !$lng) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "❌ 缺少必要參數"]);
    exit;
}

// DB 連線
$db = Database::getConnection();
$uuid = $db->query("SELECT UUID()")->fetchColumn();

// 撈出員工姓名
$stmt = $db->prepare("SELECT name FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$row = $stmt->fetch();
$userName = $row ? $row['name'] : "未知使用者";

$now = date("Y-m-d H:i:s");

// --- 判定打卡是否合規 ---
$is_in_range = false;
$min_distance = 999999;

// 1. 檢查 GPS 是否在任一辦公據點範圍內
foreach ($locations as $loc) {
    $d = calculateDistance($lat, $lng, $loc['lat'], $loc['lng']);
    if ($d < $min_distance) $min_distance = $d;
    if ($d <= ALLOWED_RADIUS) {
        $is_in_range = true;
        break; 
    }
}

// 2. 檢查 QR Code Token 是否符合
$is_qr_valid = (!empty(OFFICE_QR_TOKEN) && $qrToken === OFFICE_QR_TOKEN);

$supervisors = [];
$approvalUrl = null;

if ($is_in_range || $is_qr_valid) {
    // 成功條件：在範圍內 OR 掃描了正確的 QR Code
    $status = "success";
    $approval = "normal";
    
    if ($is_qr_valid) {
        $reason = "位置補償 (QR Code)";
        $message = "【打卡成功】{$mode}\n方式｜QR Code 驗證";
    } else {
        // 🔥 修改：標準風格
        $message = "【打卡成功】{$mode}\n時間｜" . date('H:i', strtotime($now));
    }
} else {
    // 失敗條件：不在範圍內且 QR Token 錯誤
    $status = "fail";
    $approval = "pending";

    // 查詢主管名單
    $stmt = $db->prepare("
        SELECT u.user_id, u.name 
        FROM user_supervisors us
        JOIN users u ON us.supervisor_id = u.user_id
        WHERE us.user_id = ?
    ");
    $stmt->execute([$userId]);
    $supervisors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $BOT_BASIC_ID = getenv("LINE_BOT_ID");
    $approvalCommand = "/審核打卡 {$uuid}";
    $approvalUrl = "https://line.me/R/oaMessage/@{$BOT_BASIC_ID}/?" . rawurlencode($approvalCommand);

    $mapUrl = "https://www.google.com/maps?q={$lat},{$lng}";
    $message = "【打卡異常】不在允許範圍內\n狀態｜已送出申請，待主管審核\n距離｜" . intval($min_distance) . " 公尺";
}

// 存進資料庫
try {
    $stmt = $db->prepare("
        INSERT INTO attendance_logs 
        (attendance_uuid, user_id, mode, latitude, longitude, distance, status, reason, approval_status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $uuid, $userId, $mode, $lat, $lng, intval($min_distance), $status, $reason, $approval
    ]);

    // 🔥【新增 2】資料庫存檔成功後，直接推播 LINE 訊息給使用者
    if ($userId && $message) {
        $pushContent = [
            "type" => "text",
            "text" => $message 
        ];
        
        // 如果是待審核 (距離太遠)，也可以多推播一個地圖連結給使用者確認 (選擇性功能)
        if ($status === 'fail' && isset($mapUrl)) {
         $pushText .= "\n定位｜$mapUrl";
        }

        // 呼叫 utils.php 裡的函式
        pushMessage($userId, $pushContent);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "❌ 存取打卡紀錄失敗: " . $e->getMessage()]);
    exit;
}

// 回傳結果
echo json_encode([
    "status" => $status,
    "message" => $message,
    "employee_name" => $userName,
    "attendance_time" => $now,
    "reason" => $reason,
    "distance" => intval($min_distance),
    "approval_status" => $approval,
    "attendance_uuid" => $uuid,
    "approval_url" => $approvalUrl,
    "map_url" => $mapUrl ?? null,
    "supervisors" => $supervisors
]);

/**
 * 計算兩點座標距離 (公尺)
 */
function calculateDistance($lat1, $lng1, $lat2, $lng2) {
    $R = 6371000; 
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dPhi = deg2rad($lat2 - $lat1);
    $dLambda = deg2rad($lng2 - $lng1);

    $a = sin($dPhi/2)**2 + cos($phi1) * cos($phi2) * sin($dLambda/2)**2;
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}