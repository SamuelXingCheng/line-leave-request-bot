<?php
// attendance_api.php

// 允許跨域請求
header("Access-Control-Allow-Origin: https://www.citc.org.tw");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// 如果是預檢請求 (OPTIONS)，直接回應 200
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';

header('Content-Type: application/json; charset=utf-8');

// 公司座標 & 半徑
const COMPANY_LAT = 24.13384;
const COMPANY_LNG = 120.68162;
const ALLOWED_RADIUS = 200; // 公尺

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

if (!$userId || !$mode || !$lat || !$lng) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "❌ 缺少必要參數"]);
    exit;
}

// 計算距離
$distance = calculateDistance($lat, $lng);
$now = date("Y-m-d H:i:s");

if ($distance <= ALLOWED_RADIUS) {
    $message = "✅ {$mode}打卡成功！時間：{$now}";
    $status = "success";
} else {
    $message = "⚠️ 不在公司範圍內（距離 " . intval($distance) . " 公尺），請確認位置或事後補充說明。";
    $status = "fail";
}

// 存進資料庫
try {
    $db = Database::getConnection();
    $stmt = $db->prepare("
        INSERT INTO attendance_logs (user_id, mode, latitude, longitude, distance, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$userId, $mode, $lat, $lng, intval($distance), $status]);
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "❌ 存取打卡紀錄失敗: " . $e->getMessage()
    ]);
    exit; // ⚠️ 一定要 exit，避免還繼續 echo success
}

echo json_encode([
    "status" => $status,
    "message" => $message,
    "distance" => intval($distance)
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
