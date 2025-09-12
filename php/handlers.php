<?php
// handlers.php
require_once __DIR__ . '/CancelHandler.php';
require_once __DIR__ . '/LeaveFlow.php';
require_once __DIR__ . '/LeaveQueryHandler.php';
require_once __DIR__ . '/ApprovalHandler.php';
require_once __DIR__ . '/DeleteHandler.php';
require_once __DIR__ . '/RegisterHandler.php';
require_once __DIR__ . '/AttendanceApprovalHandler.php';
require_once __DIR__ . '/CorrectionHandler.php';

function handleMessage($event, $db) {
    $replyToken = $event['replyToken'];
    $userId     = $event['source']['userId'];
    $text       = $event['message']['text'];

    // ---------- 出勤打卡 ----------
    if ($text === "/打卡") {
        $liffId  = getenv("LIFF_ID"); // 你的 LIFF ID
        $liffUrl = "line://app/" . $liffId;
    
        $templateMessage = [
            "type" => "template",
            "altText" => "出勤打卡",
            "template" => [
                "type" => "buttons",
                "title" => "台中市召會行政人員出勤系統",
                "text"  => "點下方按鈕進入打卡頁面",
                "actions" => [
                    [
                        "type" => "uri",
                        "label" => "📍上下班打卡",
                        "uri"  => $liffUrl
                    ]
                ]
            ]
        ];
    
        replyMessage($replyToken, $templateMessage);
        error_log("✅ Sent 打卡 LIFF button to userId=" . $userId);
        return;
    }    

    // ---------- 註冊 ----------
    $registerHandler = new RegisterHandler($userId, $text, $replyToken);
    if ($registerHandler->handle()) {
        return;
    }

    // ---------- 打卡審核 ----------
    $attendanceApprovalHandler = new AttendanceApprovalHandler($userId, $text);
    if ($attendanceApprovalHandler->handle($replyToken)) {
        return;
    }

    // ---------- 補打卡申請 ----------
    $correctionHandler = new CorrectionHandler($userId, $text);
    if ($correctionHandler->handle($replyToken)) {
        return;
    }

    // ---------- 查詢請假 ----------
    if (strpos($text, "/查詢請假") === 0 || $text === "查今天" || 
        $text === "查上個月" || $text === "查本月" || $text === "查今年" || 
        $text === "自訂查詢" || $text === "/取消查詢" || 
        preg_match('/\d{4}\/\d{2}\/\d{2}-\d{4}\/\d{2}\/\d{2}/', $text)) {

        $handler = new LeaveQueryHandler($userId, $text);
        $handler->handle($replyToken);
        return;
    }

    // ---------- 請假同意指令 ----------
    if (strpos($text, "/同意請假") === 0 ) {
        $handler = new ApprovalHandler($userId, $text);
        $handler->handle($replyToken);
        return;
    }

    // ---------- 刪除請假 ----------
    if (strpos($text, "/刪除請假") === 0) {
        $handler = new DeleteHandler($userId, $event);
        if ($handler->handle()) {
            return;
        }
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
    replyTextMessage($replyToken, "❓ 未知指令，請輸入 /請假、/打卡 或 /補打卡");
}
