<?php
// utils.php
require_once __DIR__ . '/Db.php';  // ✅ 注意大小寫，與你的 Database class 一致

// ---------- 已有的 ----------
function replyTextMessage($replyToken, $text) {
    $url = "https://api.line.me/v2/bot/message/reply";
    $headers = [
        "Content-Type: application/json",
        "Authorization: " . "Bearer " . getenv("LINE_CHANNEL_ACCESS_TOKEN")
    ];
    $postData = [
        "replyToken" => $replyToken,
        "messages" => [[ "type" => "text", "text" => $text ]]
    ];
    return callLineAPI($url, $headers, $postData);
}

function replyQuickReply($replyToken, $text, $items) {
    $actions = [];
    foreach ($items as $item) {
        $actions[] = [
            "type" => "action",
            "action" => [
                "type" => "message",
                "label" => $item[0],
                "text" => $item[1]
            ]
        ];
    }
    $url = "https://api.line.me/v2/bot/message/reply";
    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . getenv("LINE_CHANNEL_ACCESS_TOKEN")
    ];
    $postData = [
        "replyToken" => $replyToken,
        "messages" => [[
            "type" => "text",
            "text" => $text,
            "quickReply" => ["items" => $actions]
        ]]
    ];
    return callLineAPI($url, $headers, $postData);
}

// ---------- 新增的 ----------

/**
 * 呼叫 LINE API
 */
function callLineAPI($url, $headers, $postData) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData, JSON_UNESCAPED_UNICODE));
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("📡 LINE API response ($httpCode): " . $result);
    return $result;
}

/**
 * 查主管姓名
 */
function getSupervisorNames(array $ids): array {
    if (empty($ids)) return [];

    $pdo = Database::getConnection();  // ✅ 改用 Database class
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT name FROM users WHERE user_id IN ($placeholders)");
    $stmt->execute($ids);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * 建立請假轉傳訊息
 */
function buildForwardMessage(array $requests, string $requestGroupId): array {
    if (empty($requests)) {
        return ["⚠️ 請假資料為空，無法建立轉發訊息。", null];
    }

    $firstReq = $requests[0];
    $userName = $firstReq["name"];
    $reason = $firstReq["reason"] ?? "未填寫";
    $leaveType = $firstReq["leave_type"] ?? "假別未填";
    $supervisorIds = $firstReq["supervisor_ids"] ?? [];

    $botId = getenv("LINE_BOT_ID"); // 例如 @123xyz
    // ✅ 改成只帶 group id
    $approvalCommand = "/同意請假 {$requestGroupId}";
    $encodedQuery = rawurlencode($approvalCommand);
    $approvalLink = "line://oaMessage/@" . $botId . "/?" . $encodedQuery;

    // 整理多日清單
    $dateLines = [];
    foreach ($requests as $req) {
        $line = "📅 {$req['start_date']} {$req['start_time']} ~ {$req['end_date']} {$req['end_time']}";
        $dateLines[] = $line;
    }
    $dateLinesStr = implode("\n", $dateLines);

    $forwardMsg =
        "👤 員工：{$userName}\n" .
        "📋 假別：{$leaveType}\n" .
        "📝 原因：{$reason}\n\n" .
        "以下時間需要請假：\n" .
        $dateLinesStr .
        "\n\n👉 點擊以下連結，系統將自動填入「/同意請假」指令，請直接送出即可完成簽核：\n" .
        $approvalLink .
        "\n\n若不同意，請口頭告知請假者即可，無需操作此連結。";

    $userHintMsg = null;
    if (!empty($supervisorIds)) {
        $supervisorNames = getSupervisorNames($supervisorIds);
        $lines = [];
        foreach ($supervisorNames as $name) {
            $lines[] = "- " . $name;
        }
        $userHintMsg = "📌 請記得轉傳上方訊息給以下主管簽核：\n" . implode("\n", $lines);
    }

    return [$forwardMsg, $userHintMsg];
}

