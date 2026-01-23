<?php
// php/handlers.php

// 1. 引入所有檔案 (這裡最容易掛點，我們加上 try-catch 雖然 PHP Fatal Error 抓不到，但盡量保護)
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/Session.php';

// 依序引入，確認檔案存在
$files = [
    'CancelHandler.php', 'LeaveFlowHandler.php', 'LeaveQueryHandler.php',
    'ApprovalHandler.php', 'DeleteHandler.php', 'RegisterHandler.php',
    'AttendanceApprovalHandler.php', 'CorrectionHandler.php', 'AttendanceHandler.php',
    'EmployeeQueryHandler.php', 'MoreFeaturesHandler.php', 'AttendanceQueryHandler.php',
    'OvertimeFlowHandler.php', 'OvertimeApprovalHandler.php', 'OvertimeQueryHandler.php'
];

foreach ($files as $file) {
    if (file_exists(__DIR__ . '/' . $file)) {
        require_once __DIR__ . '/' . $file;
    } else {
        error_log("❌ [Fatal] 找不到檔案: " . $file);
    }
}

function handleMessage($event, $db) {
    $replyToken = $event['replyToken'];
    $userId     = $event['source']['userId'];
    $text       = $event['message']['text'] ?? ''; 

    error_log("🔍 [Trace] 1. 進入 handleMessage, 指令: " . $text);

    // 統一清除 Session
    if (isCommand($text)) {
        $session = new UserSession($userId);
        $session->clearStep();
        error_log("🔍 [Trace] 2. Session 已清除");
    }

    // --- 開始逐一執行 Handler ---

    error_log("🔍 [Trace] 3. 檢查 Cancel/Delete");
    $cancelHandler = new CancelHandler($userId, $text, $replyToken);
    if ($cancelHandler->handle()) return;

    $deleteHandler = new DeleteHandler($userId, $text, $replyToken);
    if ($deleteHandler->handle()) return;

    error_log("🔍 [Trace] 4. 檢查 Approval (請假/打卡/加班)");
    $approvalHandler = new ApprovalHandler($userId, $text, $replyToken);
    if ($approvalHandler->handle()) return;

    $attendanceApprovalHandler = new AttendanceApprovalHandler($userId, $text);
    if ($attendanceApprovalHandler->handle($replyToken)) return;

    // 🔥 懷疑點：OvertimeApprovalHandler
    $overtimeApprovalHandler = new OvertimeApprovalHandler($userId, $text);
    if ($overtimeApprovalHandler->handle($replyToken)) return;

    error_log("🔍 [Trace] 5. 檢查 OvertimeQuery");
    // 🔥 懷疑點：OvertimeQueryHandler
    $overtimeQueryHandler = new OvertimeQueryHandler($userId, $text, $replyToken);
    if ($overtimeQueryHandler->handle()) return;
    
    error_log("🔍 [Trace] 6. 檢查 Employee/Attendance/More");
    $employeeHandler = new EmployeeQueryHandler($userId, $text, $replyToken);
    if ($employeeHandler->handle()) return;

    $attendanceHandler = new AttendanceHandler($userId, $text, $replyToken);
    if ($attendanceHandler->handle()) return;
    
    $moreHandler = new MoreFeaturesHandler($userId, $text, $replyToken);
    if ($moreHandler->handle()) return;

    error_log("🔍 [Trace] 7. 檢查 Register");
    $registerHandler = new RegisterHandler($userId, $text, $replyToken);
    if ($registerHandler->handle()) return;

    error_log("🔍 [Trace] 8. 檢查 Correction (補打卡)");
    // 🔥 補打卡是這裡執行的
    $correctionHandler = new CorrectionHandler($userId, $event);
    if ($correctionHandler->handle()) {
        error_log("✅ [Trace] CorrectionHandler 處理完畢 (return true)");
        return;
    }

    error_log("🔍 [Trace] 9. 檢查 LeaveFlow");
    $leaveFlowHandler = new LeaveFlowHandler($userId, $event, $db);
    if ($leaveFlowHandler->handle()) return;
    
    error_log("🔍 [Trace] 10. 檢查 LeaveQuery");
    $leaveQueryHandler = new LeaveQueryHandler($userId, $text, $replyToken);
    if ($leaveQueryHandler->handle()) return;

    error_log("🔍 [Trace] 11. 檢查 AttendanceQuery");
    $attendanceQueryHandler = new AttendanceQueryHandler($userId, $text, $replyToken);
    if ($attendanceQueryHandler->handle()) return;

    error_log("🔍 [Trace] 12. 檢查 OvertimeFlow (加班)");
    // 🔥 加班是這裡執行的
    $overtimeFlowHandler = new OvertimeFlowHandler($userId, $event, $db);
    if ($overtimeFlowHandler->handle()) {
        error_log("✅ [Trace] OvertimeFlowHandler 處理完畢");
        return;
    }

    error_log("🔍 [Trace] 13. 全部落空，回覆預設訊息");
    replyTextMessage($replyToken, "未知指令，請點選選單或輸入 /更多功能");
}

function handlePostback($event, $db) {
    error_log("🔍 [Trace] 進入 handlePostback");
    $userId = $event['source']['userId'];
    $data   = $event['postback']['data'];
    parse_str($data, $params);
    $action = $params['action'] ?? '';

    if (strpos($action, 'select_leave') === 0 || strpos($action, 'select_start') === 0 || strpos($action, 'select_end') === 0) {
        $handler = new LeaveFlowHandler($userId, $event, $db);
        $handler->handle();
        return;
    }

    if (strpos($action, 'select_correction') === 0) {
        $handler = new CorrectionHandler($userId, $event);
        $handler->handle();
        return;
    }

    if (strpos($action, 'select_ot') === 0) {
        $handler = new OvertimeFlowHandler($userId, $event, $db);
        $handler->handle();
        return;
    }
}