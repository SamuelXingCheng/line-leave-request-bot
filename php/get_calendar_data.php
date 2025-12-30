<?php
// php/get_calendar_data.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    } else {
        throw new Exception("找不到 config.php");
    }
    require_once __DIR__ . '/Db.php';

    $userId = $_GET['userId'] ?? null;
    $year   = $_GET['year'] ?? date('Y');
    $month  = $_GET['month'] ?? date('m');

    if (!$userId) throw new Exception("未提供 UserID");

    $db = Database::getConnection();
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate   = date("Y-m-t", strtotime($startDate)); 

    $events = [];

    // 1. 打卡紀錄 (Attendance)
    $stmt = $db->prepare("
        SELECT DATE(created_at) as date, created_at, status, approval_status, latitude, mode
        FROM attendance_logs
        WHERE user_id = ? AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$userId, "$startDate 00:00:00", "$endDate 23:59:59"]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($logs as $row) {
        $date = $row['date'];
        $isCorrection = is_null($row['latitude']);
        $mode = $row['mode']; 
        $timeStr = date('H:i', strtotime($row['created_at']));

        if ($row['status'] === 'fail' || $row['approval_status'] === 'pending') {
            $color = 'red';
        } elseif ($isCorrection) {
            $color = 'orange';
        } else {
            $color = 'green';
        }

        $events[$date][] = [
            'type' => 'attendance', 
            'color' => $color, 
            'desc' => $mode,
            'time_info' => $timeStr 
        ];
    }

    // 2. 請假紀錄 (Leave)
    $stmt = $db->prepare("
        SELECT start_at, end_at, leave_type
        FROM leave_requests
        WHERE user_id = ? AND status = 'approved'
          AND (start_at BETWEEN ? AND ? OR end_at BETWEEN ? AND ?)
    ");
    $stmt->execute([$userId, "$startDate 00:00:00", "$endDate 23:59:59", "$startDate 00:00:00", "$endDate 23:59:59"]);
    $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($leaves as $row) {
        $date = date('Y-m-d', strtotime($row['start_at']));
        $sTime = date('H:i', strtotime($row['start_at']));
        $eTime = date('H:i', strtotime($row['end_at']));
        
        $period = 'all';
        if ($sTime < '12:00' && $eTime <= '13:00') $period = 'am';
        elseif ($sTime >= '12:00') $period = 'pm';

        $events[$date][] = [
            'type' => 'leave', 
            'color' => 'blue', 
            'desc' => $row['leave_type'],
            'period' => $period,
            'time_info' => "$sTime ~ $eTime" 
        ];
    }

    // 3. 加班紀錄 (Overtime)
    $stmt = $db->prepare("
        SELECT start_at, end_at FROM overtime_requests
        WHERE user_id = ? AND status = 'approved' AND start_at BETWEEN ? AND ?
    ");
    $stmt->execute([$userId, "$startDate 00:00:00", "$endDate 23:59:59"]);
    $ots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($ots as $row) {
        $date = date('Y-m-d', strtotime($row['start_at']));
        $sTime = date('H:i', strtotime($row['start_at']));
        $eTime = date('H:i', strtotime($row['end_at']));

        $events[$date][] = [
            'type' => 'overtime', 
            'color' => 'purple', 
            'desc' => '加班',
            'time_info' => "$sTime ~ $eTime"
        ];
    }

    // 🔥 4. 新增：國定假日與補班日 (Holidays)
    $stmt = $db->prepare("
        SELECT date, name, type FROM holidays 
        WHERE date BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate, $endDate]);
    $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($holidays as $row) {
        $date = $row['date'];
        $events[$date][] = [
            'type' => 'holiday_info', 
            'color' => ($row['type'] === 'holiday' ? 'pink' : 'gray'), 
            'desc' => $row['name'],
            'holiday_type' => $row['type']
        ];
    }

    echo json_encode($events);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>