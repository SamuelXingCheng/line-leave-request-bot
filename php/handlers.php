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
    $leaveQueryHandler = new LeaveQueryHandler($userId, $text, $replyToken);
    if ($leaveQueryHandler->handle()) {
        return;
    }

    // ---------- 請假同意指令 ----------
    $approvalHandler = new ApprovalHandler($userId, $text, $replyToken);
    if ($approvalHandler->handle()) {
        return;
    }

    // ---------- 刪除請假 ----------
    $deleteHandler = new DeleteHandler($userId, $text, $replyToken);
    if ($deleteHandler->handle()) {
        return;
    }

    // ---------- 取消流程 ----------
    $cancelHandler = new CancelHandler($userId, $text, $replyToken);
    if ($cancelHandler->handle()) {
        return;
    }

    // ---------- 請假流程 ----------
    $flow = new LeaveFlow($userId, $event, $db);
    if ($flow->handle()) {
        return;
    }

    // ---------- 預設回覆 ----------
    replyTextMessage($replyToken, "❓ 未知指令，請輸入 /請假、/打卡 或 /補打卡");
}
