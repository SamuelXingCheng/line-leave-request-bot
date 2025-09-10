<?php
// handlers.php
require_once __DIR__ . '/CancelHandler.php';
require_once __DIR__ . '/LeaveFlow.php';
require_once __DIR__ . '/LeaveQueryHandler.php';

function handleMessage($event, $db) {
    $replyToken = $event['replyToken'];
    $userId     = $event['source']['userId'];
    $text       = $event['message']['text'];

    // ---------- 查詢請假 ----------
    if (strpos($text, "/查詢請假") === 0 || $text === "查今天" || 
        $text === "查上個月" || $text === "查本月" || $text === "查今年" || 
        $text === "自訂查詢" || $text === "/取消查詢" || 
        preg_match('/\d{4}\/\d{2}\/\d{2}-\d{4}\/\d{2}\/\d{2}/', $text)) {

        $handler = new LeaveQueryHandler($userId, $text);
        $handler->handle($replyToken);
        return;
    }

    // ---------- 取消流程 ----------
    if (in_array($text, ["/取消請假", "/取消查詢", "/取消補打卡"])) {
        $handler  = new CancelHandler($userId, $text);
        $messages = $handler->handle();
        if ($messages) {
            foreach ($messages as $msg) {
                replyTextMessage($replyToken, $msg['text']);
            }
            return;
        }
    }

    // ---------- 請假流程 ----------
    $flow = new LeaveFlow($userId, $event, $db);
    if ($flow->handle()) {
        return; // 已處理
    }

    // ---------- 預設回覆 ----------
    replyTextMessage($replyToken, "❓ 未知指令，請輸入 /請假 或 /取消請假");
}
