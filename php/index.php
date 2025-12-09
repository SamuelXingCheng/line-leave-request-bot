<?php
// index.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/handlers.php';  // ✅ 引入集中處理器

// 測試 utils.php 是否載入
if (!function_exists('replyTextMessage')) {
    error_log("❌ replyTextMessage not loaded from utils.php");
    echo "utils.php not loaded";
    exit;
} else {
    error_log("✅ utils.php loaded successfully");
}

// 只允許 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(200);
    echo "Webhook running (waiting for POST from LINE)";
    exit;
}

// 讀取請求
$input = file_get_contents('php://input');
error_log("📩 Received input: " . $input);

$events = json_decode($input, true);
if (!isset($events['events'])) {
    error_log("❌ No events field");
    http_response_code(400);
    exit;
}

$db = Database::getConnection();

// 處理事件交給 handlers.php
foreach ($events['events'] as $event) {
    if ($event['type'] === 'message' && $event['message']['type'] === 'text') {
        handleMessage($event, $db); // 集中處理
    }elseif ($event['type'] === 'postback') {
        handlePostback($event, $db);
    }
}

http_response_code(200);
