<?php
// handlers.php
require_once __DIR__ . '/CancelHandler.php';
require_once __DIR__ . '/LeaveFlowHandler.php';
require_once __DIR__ . '/LeaveQueryHandler.php';
require_once __DIR__ . '/ApprovalHandler.php';
require_once __DIR__ . '/DeleteHandler.php';
require_once __DIR__ . '/RegisterHandler.php';
require_once __DIR__ . '/AttendanceApprovalHandler.php';
require_once __DIR__ . '/CorrectionHandler.php';
require_once __DIR__ . '/AttendanceHandler.php';
require_once __DIR__ . '/EmployeeQueryHandler.php';
require_once __DIR__ . '/MoreFeaturesHandler.php';

function handleMessage($event, $db) {
    $replyToken = $event['replyToken'];
    $userId     = $event['source']['userId'];
    $text       = $event['message']['text'];

    // ---------- 出勤打卡 ----------
    $attendanceHandler = new AttendanceHandler($userId, $text, $replyToken);
    if ($attendanceHandler->handle()) {
        return;
    }

    // ---------- 註冊 ----------
    $registerHandler = new RegisterHandler($userId, $text, $replyToken);
    if ($registerHandler->handle()) {
        return;
    }

    // ---------- 查詢同事 ----------
    $employeeHandler = new EmployeeQueryHandler($userId, $text, $replyToken);
    if ($employeeHandler->handle()) {
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
    $leaveFlowHandler = new LeaveFlowHandler($userId, $event, $db);
    if ($leaveFlowHandler->handle()) {
        return;
    }

    // ---------- 更多功能 ----------
    $moreHandler = new MoreFeaturesHandler($userId, $text, $replyToken);
    if ($moreHandler->handle()) {
        return;
    }

    // ---------- 預設回覆 ----------
    replyTextMessage($replyToken, "❓ 未知指令，請輸入 /請假、/打卡 或 /補打卡");
}
