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

    // 2. 請假紀錄 (Leave) - 修改版：展開連續日期
    $stmt = $db->prepare("
        SELECT start_at, end_at, leave_type, status
        FROM leave_requests
        WHERE user_id = ? AND status IN ('approved', 'pending')
            AND (start_at <= ? AND end_at >= ?) 
    ");
    // 注意：SQL 條件改為「只要請假區間跟當月有重疊」就撈出來
    $stmt->execute([$userId, "$endDate 23:59:59", "$startDate 00:00:00"]);
    $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($leaves as $row) {
        // 設定迴圈起訖時間
        $begin = new DateTime($row['start_at']);
        $end   = new DateTime($row['end_at']);
        
        // 為了確保包含最後一天，我們比較日期字串
        $current = clone $begin;
        
        while ($current->format('Y-m-d') <= $end->format('Y-m-d')) {
            $dateStr = $current->format('Y-m-d');
            
            // 如果這一天不在查詢的月份範圍內，就跳過 (優化效能)
            if ($dateStr < $startDate || $dateStr > $endDate) {
                $current->modify('+1 day');
                continue;
            }

            // 計算當天的時間顯示 (頭尾兩天顯示具體時間，中間全天)
            $isFirstDay = ($dateStr === $begin->format('Y-m-d'));
            $isLastDay  = ($dateStr === $end->format('Y-m-d'));

            $sTime = $isFirstDay ? $begin->format('H:i') : "08:30";
            $eTime = $isLastDay  ? $end->format('H:i')   : "17:30";

            // 判斷上午/下午/全天 (影響藍點是畫半圓還是全圓)
            $period = 'all';
            if ($sTime < '12:00' && $eTime <= '13:00') $period = 'am';
            elseif ($sTime >= '12:00') $period = 'pm';

            // 🔥 動態判斷狀態給予後綴與顏色
            $isPending = ($row['status'] === 'pending');
            $desc = $row['leave_type'] . ($isPending ? ' (待審核)' : '');
            $color = $isPending ? 'gray' : 'blue';

            $events[$dateStr][] = [
                'type' => 'leave', 
                'color' => $color, 
                'desc' => $desc,
                'period' => $period,
                'time_info' => "$sTime ~ $eTime" 
            ];

            // 往後推一天
            $current->modify('+1 day');
        }
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