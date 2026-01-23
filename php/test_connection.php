<?php
// test_connection.php
// 用途：診斷主機是否能連線到 LINE API

header('Content-Type: text/plain; charset=utf-8');

echo "🚀 開始 LINE API 連線診斷...\n";
echo "------------------------------------------------\n";

$url = "https://api.line.me/v2/bot/message/reply";

// 初始化 cURL
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true); // 顯示 Header 以便除錯
curl_setopt($ch, CURLOPT_VERBOSE, true); // 開啟詳細模式

// 🔥 測試設定 1: 強制 IPv4 (我們剛剛加的)
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

// 🔥 測試設定 2: 設定短逾時，避免卡死
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

// 隨便塞一個錯誤的 Token 沒關係，我們只是要測「連不連得上」
$headers = [
    "Content-Type: application/json",
    "Authorization: Bearer TEST_TOKEN"
];
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["test" => "data"]));

// 執行並捕捉詳細資訊
$streamVerboseHandle = fopen('php://temp', 'w+');
curl_setopt($ch, CURLOPT_STDERR, $streamVerboseHandle);

$start = microtime(true);
$result = curl_exec($ch);
$end = microtime(true);
$duration = round($end - $start, 3);

echo "⏱️ 耗時: {$duration} 秒\n";

if ($result === false) {
    echo "❌ cURL 錯誤: " . curl_error($ch) . "\n";
    echo "❌ 錯誤代碼: " . curl_errno($ch) . "\n";
} else {
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "✅ 連線成功！HTTP 狀態碼: {$httpCode}\n";
    echo "(註：如果是 401 Unauthorized 代表連線通暢，只是 Token 錯誤，這是好事！)\n";
}

// 顯示詳細通訊過程 (握手、DNS解析等)
rewind($streamVerboseHandle);
$verboseLog = stream_get_contents($streamVerboseHandle);
echo "\n🔍 詳細通訊紀錄 (Verbose Log):\n";
echo "------------------------------------------------\n";
echo $verboseLog;

curl_close($ch);
?>