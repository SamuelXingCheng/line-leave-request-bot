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
require_once __DIR__ . '/AttendanceQueryHandler.php';
require_once __DIR__ . '/OvertimeFlowHandler.php';
require_once __DIR__ . '/OvertimeApprovalHandler.php';
require_once __DIR__ . '/OvertimeQueryHandler.php';

function handleMessage($event, $db) {
    $replyToken = $event['replyToken'];
    $userId     = $event['source']['userId'];
    $text       = $event['message']['text'] ?? ''; // 防止非文字訊息報錯

    // 1️⃣ 【最高優先】取消與刪除指令
    $cancelHandler = new CancelHandler($userId, $text, $replyToken);
    if ($cancelHandler->handle()) return;

    $deleteHandler = new DeleteHandler($userId, $text, $replyToken);
    if ($deleteHandler->handle()) return;

    // 2️⃣ 【次高優先】簽核指令 (避免主管被員工的查詢狀態卡住)
    $approvalHandler = new ApprovalHandler($userId, $text, $replyToken);
    if ($approvalHandler->handle()) return;

    $attendanceApprovalHandler = new AttendanceApprovalHandler($userId, $text);
    if ($attendanceApprovalHandler->handle($replyToken)) return;

    $overtimeApprovalHandler = new OvertimeApprovalHandler($userId, $text);
    if ($overtimeApprovalHandler->handle($replyToken)) return;

    // 🔥【新增】加班查詢指令
    $overtimeQueryHandler = new OvertimeQueryHandler($userId, $text, $replyToken);
    if ($overtimeQueryHandler->handle()) return;
    
    // 3️⃣ 【功能指令】無狀態的查詢
    $employeeHandler = new EmployeeQueryHandler($userId, $text, $replyToken);
    if ($employeeHandler->handle()) return;

    $attendanceHandler = new AttendanceHandler($userId, $text, $replyToken);
    if ($attendanceHandler->handle()) return;
    
    $moreHandler = new MoreFeaturesHandler($userId, $text, $replyToken);
    if ($moreHandler->handle()) return;

    // 4️⃣ 【有狀態的流程】(補打卡、請假、查詢)
    // 這些 Handler 內部必須實作「全域指令檢查」，否則會吃掉上面的指令
    
    $registerHandler = new RegisterHandler($userId, $text, $replyToken);
    if ($registerHandler->handle()) return;

    // 🔥 修改：傳入 $event 並移除 handle() 的參數
    $correctionHandler = new CorrectionHandler($userId, $event);
    if ($correctionHandler->handle()) return;

    $leaveFlowHandler = new LeaveFlowHandler($userId, $event, $db);
    if ($leaveFlowHandler->handle()) return;
    
    $leaveQueryHandler = new LeaveQueryHandler($userId, $text, $replyToken);
    if ($leaveQueryHandler->handle()) return;

    $attendanceQueryHandler = new AttendanceQueryHandler($userId, $text, $replyToken);
    if ($attendanceQueryHandler->handle()) return;

    $overtimeFlowHandler = new OvertimeFlowHandler($userId, $event, $db);
    if ($overtimeFlowHandler->handle()) return;

    

    // 5️⃣ 預設回覆
    replyTextMessage($replyToken, "未知指令，請點選選單或輸入 /更多功能");
}

/**
 * 處理 Postback 事件 (例如日期選擇器)
 */
function handlePostback($event, $db) {
    $userId = $event['source']['userId'];
    $data   = $event['postback']['data'];

    // 解析 action (例如 action=select_leave_date)
    parse_str($data, $params);
    $action = $params['action'] ?? '';

    // 1. 請假流程相關 Action
    $leaveActions = ['select_leave_date', 'select_start_time', 'select_end_time'];
    if (in_array($action, $leaveActions)) {
        $handler = new LeaveFlowHandler($userId, $event, $db);
        $handler->handle(); // 讓 LeaveFlowHandler 自己去解析 postback 參數
        return;
    }

    // 2. 🔥 補打卡流程相關 Action (新增)
    $correctionActions = ['select_correction_date', 'select_correction_time'];
    if (in_array($action, $correctionActions)) {
        // 因為 CorrectionHandler 現在建構子支援 ($userId, $event)，可以直接傳入
        $handler = new CorrectionHandler($userId, $event);
        $handler->handle();
        return;
    }

    // 加班流程 Postback
    $otActions = ['select_ot_date', 'select_ot_start', 'select_ot_end'];
    if (in_array($action, $otActions)) {
        $handler = new OvertimeFlowHandler($userId, $event, $db);
        $handler->handle();
        return;
    }

}