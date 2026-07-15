<?php
/**
 * 全體特休與請假數據校正腳本 (全體批次更新版)
 * 用途：一鍵結算全公司員工的特休、事假、病假，並寫入 users 表格的新欄位
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';

// --- 安全設定 ---
$secretKey = "church2026"; 

echo "<html><head><meta charset='utf-8'><title>全體休假數據校正系統</title></head><body style='font-family:monospace; background:#f4f4f4; padding:20px;'>"; 
echo "<div style='background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1);'>";



if (($_GET['key'] ?? '') !== $secretKey) {
    http_response_code(403);
    die("<h2 style='color:red;'>❌ 存取被拒絕</h2><p>請輸入正確的安全性金鑰。</p></div></body></html>");
}

try {
    $db = Database::getConnection();
    
    // 測試用戶數據
    $user = [
        'id' => 1,
        'start_date' => '2023-01-01',
        'last_year_annual_hours' => 10.5,
        // 其他必要的用戶字段...
    ];

    // 模擬請假數據
    $leaveRequests = [
        [
            'user_id' => 1,
            'status' => 'approved',
            'start_at' => '2023-04-01 09:00:00',
            'end_at' => '2023-04-05 18:00:00',
            'leave_hours' => 20.0,
            'leave_type' => '特休假' // <--- 補上這行
        ],
        // 其他請假數據...
    ];

    // 計算年資與剩餘假數
    calculateAnnualLeave($user, $leaveRequests);

    echo "計算完成";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
echo "</div></body></html>";

function calculateAnnualLeave($user, $leaveRequests) {
    // 計算年資與剩餘假數
    $hireDate = $user['start_date'];
    $currentYear = (int)date('Y');

    // 計算應得特休天數
    $entitledDays = calculateAnnualLeaveDaysStrict($hireDate, $currentYear);
    $entitledAnnual = $entitledDays * 8; // 將天數轉換為小時

    // 撈取該員工今年已請假時數
    $usedStats = getUsedLeaveHours($leaveRequests);

    $usedAnnual   = floatval($usedStats['特休假'] ?? $usedStats['特休'] ?? 0);
    $usedPersonal = floatval($usedStats['事假'] ?? 0);
    $usedSick     = floatval($usedStats['病假'] ?? 0);

    // 結算特休剩餘 (歷年保留 + 今年法定 - 今年已請)
    $lastYearAnnual = floatval($user['last_year_annual_hours'] ?? 0);
    $totalEntitled = $lastYearAnnual + $entitledAnnual;
    $remainingAnnual = max(0, $totalEntitled - $usedAnnual);

    // 創建結果陣列
    $result = [
        'entitled_annual_hours' => $entitledAnnual,
        'annual_leave_hours' => $remainingAnnual,
        'used_annual_hours' => $usedAnnual,
        'used_personal_hours' => $usedPersonal,
        'used_sick_hours' => $usedSick
    ];

    return $result;
}

function getUsedLeaveHours($leaveRequests) {
    $usedStats = [
        '特休假' => 0,
        '事假' => 0,
        '病假' => 0
    ];

    foreach ($leaveRequests as $request) {
        $type = $request['leave_type'] ?? '';
        $hours = floatval($request['leave_hours'] ?? 0);

        if (isset($usedStats[$type])) {
            $usedStats[$type] += $hours;
        }
    }

    return $usedStats;
}

// --- 曆年制公式 ---
function getLawDays($years) {
    if ($years >= 10) return min(15 + floor($years - 9), 30); 
    if ($years >= 5)  return 15;
    if ($years >= 3)  return 14;
    if ($years >= 2)  return 10;
    if ($years >= 1)  return 7;
    if ($years >= 0.5) return 3; 
    return 0;
}

function calculateAnnualLeaveDaysStrict($hireDate, $targetYear) {
    if (empty($hireDate)) return 0;
    $hire = new DateTime($hireDate);
    $hireYear = (int)$hire->format('Y');
    $hireMonth = (int)$hire->format('n');
    $hireDay = (int)$hire->format('j');

    $yearsOfService = $targetYear - $hireYear;

    // 處理未滿一年的情況 (檢查今年是否會滿半年)
    if ($yearsOfService == 0) {
        $halfYearDate = clone $hire;
        $halfYearDate->modify('+6 months');
        if ((int)$halfYearDate->format('Y') == $targetYear) {
            // 滿半年給3天，依曆年制只給下半年的比例
            $monthsActive = 12 - (int)$halfYearDate->format('n') + 1;
            return ceil(round(3 * ($monthsActive / 12), 2) * 10) / 10;
        }
        return 0;
    }

    // 滿一年以上的曆年制切割
    $prevYears = $yearsOfService - 1;
    if ($prevYears == 0) $prevYears = 0.5; // 🔥 關鍵修復：滿一年的前一個級距是「半年」

    $prevDays = getLawDays($prevYears);
    $currentDays = getLawDays($yearsOfService);  

    $monthsBefore = $hireMonth - 1;
    $daysBefore = $hireDay - 1;
    $daysInAnniversaryMonth = (int)date('t', strtotime("$hireYear-$hireMonth-01"));

    $ratioBefore = ($monthsBefore + ($daysBefore / $daysInAnniversaryMonth)) / 12;

    $part1 = $ratioBefore * $prevDays;
    $part2 = $currentDays - ($ratioBefore * $currentDays);
    $totalDays = $part1 + $part2;

    return ceil(round($totalDays, 2) * 10) / 10;
}
// 測試部分
if ($_GET['test'] ?? false) {
    echo "<h2>--- 測試代碼開始 ---</h2>";

    try {
        // 測試計算年資與剩餘假數
        $user = [
            'id' => 1,
            'start_date' => '2023-01-01',
            'last_year_annual_hours' => 10.5,
        ];

        $leaveRequests = [
            ['user_id' => 1, 'status' => 'approved', 'start_at' => '2023-04-01 09:00:00', 'end_at' => '2023-04-05 18:00:00', 'leave_hours' => 20.0, 'leave_type' => '特休假'],
            // 其他請假數據...
        ];

        $result = calculateAnnualLeave($user, $leaveRequests);
        echo "<p>計算結果：</p>";
        print_r($result);

    } catch (Exception $e) {
        echo "<p>Error: " . $e->getMessage() . "</p>";
    }

    echo "<h2>Test Result:</h2>";
    $result = calculateAnnualLeave($user, $leaveRequests);
    echo "<pre>" . print_r($result, true) . "</pre>";
    exit;
}

try {
    $db = Database::getConnection();
    $currentYear = (int)date('Y');
    
    // 撈取全體員工
    $stmt = $db->query("SELECT user_id, name, start_date, last_year_annual_hours, entitled_annual_hours, annual_leave_hours FROM users WHERE start_date IS NOT NULL");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h2>--- 開始執行全體資料校正 (自動填寫新欄位) ---</h2>";
    echo "<ul style='line-height:1.8;'>";

    $db->beginTransaction();
    $successCount = 0;

    // 【請將 foreach 迴圈內部替換為以下代碼】
    foreach ($users as $user) {
        $userId = $user['user_id'];
        $hireDate = $user['start_date'];
        $lastYearAnnual = floatval($user['last_year_annual_hours'] ?? 0);
        
        // 🔥 1. 精準撈取扣抵時數 (加入 pending 與 deduct_annual)
        $stmtStat = $db->prepare(" 
            SELECT 
                SUM(deduct_annual) as used_annual,
                SUM(CASE WHEN leave_type LIKE '%事假%' THEN leave_hours ELSE 0 END) as used_personal,
                SUM(CASE WHEN leave_type LIKE '%病假%' THEN leave_hours ELSE 0 END) as used_sick
            FROM leave_requests 
            WHERE user_id = ? AND status IN ('approved', 'pending') AND start_at LIKE ? 
        ");
        $stmtStat->execute([$userId, "$currentYear%"]);
        $usedStats = $stmtStat->fetch(PDO::FETCH_ASSOC);

        $usedAnnual   = floatval($usedStats['used_annual'] ?? 0);
        $usedPersonal = floatval($usedStats['used_personal'] ?? 0);
        $usedSick     = floatval($usedStats['used_sick'] ?? 0);

        // 2. 計算今年法定特休
        $entitledDays = calculateAnnualLeaveDaysStrict($hireDate, $currentYear);
        $entitledAnnual = $entitledDays * 8; 

        // 🔥 3. 結算特休剩餘 (歷年保留 + 今年法定 - 已請含待審核)
        $totalEntitled = $lastYearAnnual + $entitledAnnual;
        $remainingAnnual = max(0, $totalEntitled - $usedAnnual);

        // 4. 寫入資料庫
        $updateStmt = $db->prepare(" 
            UPDATE users 
            SET entitled_annual_hours = ?, 
                annual_leave_hours = ?,
                used_annual_hours = ?,
                used_personal_hours = ?,
                used_sick_hours = ? 
            WHERE user_id = ?
        ");
        $updateStmt->execute([
            $entitledAnnual, $remainingAnnual, 
            $usedAnnual, $usedPersonal, $usedSick, 
            $userId
        ]);

        echo "<li>✅ 員工: <strong>{$user['name']}</strong> | 應得: {$entitledAnnual}h | 已休: {$usedAnnual}h | 剩餘特休: <strong>{$remainingAnnual}</strong>h | 事假: {$usedPersonal}h | 病假: {$usedSick}h</li>";
        $successCount++;
    }

    $db->commit();
    echo "</ul><h2 style='color:green;'>✅ 全體校正完成，共更新 {$successCount} 位員工的資料！</h2>";

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "<h2 style='color:red;'>❌ 執行失敗</h2><p>" . $e->getMessage() . "</p>";
}
echo "</div></body></html>";
?>