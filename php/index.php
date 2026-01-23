<?php
// php/index.php
// 回歸最穩定、最簡單的同步模式

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/handlers.php';

// 測試 utils.php 是否載入
if (!function_exists('replyTextMessage')) {
    error_log("❌ replyTextMessage not loaded from utils.php");
    exit;
}

// 只允許 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(200);
    echo "Webhook running (Sync Mode)";
    exit;
}

// 1. 讀取內容
$input = file_get_contents('php://input');
$events = json_decode($input, true);

if (!isset($events['events'])) {
    http_response_code(400);
    exit;
}

// 埋點：紀錄開始時間
$startTime = microtime(true);
// error_log("📩 [Sync] 收到請求，開始處理...");

// 2. 連線 DB
$db = Database::getConnection();

// 3. 處理事件
foreach ($events['events'] as $event) {
    if ($event['type'] === 'message' && $event['message']['type'] === 'text') {
        handleMessage($event, $db);
    } elseif ($event['type'] === 'postback') {
        handlePostback($event, $db);
    }
}

// 4. 結束
$duration = round(microtime(true) - $startTime, 3);
error_log("✅ [Sync] 處理完畢，總耗時: {$duration}s"); // 這裡應該要顯示 0.3~0.5s 左右

http_response_code(200);