<?php
/**
 * 全體特休與請假數據校正腳本 (全體批次更新版)
 * 用途：一鍵結算全公司員工的特休、事假、病假，並寫入 users 表格的新欄位
 */

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
    if ($yearsOfService < 1) return 0; 

    $prevDays = getLawDays($yearsOfService - 1); 
    $currentDays = getLawDays($yearsOfService);  

    $monthsBefore = $hireMonth - 1;
    $daysBefore = $hireDay - 1;
    $daysInAnniversaryMonth = (int)date('t', strtotime("$targetYear-$hireMonth-01"));
    
    $ratioBefore = ($monthsBefore + ($daysBefore / $daysInAnniversaryMonth)) / 12;

    $part1 = $ratioBefore * $prevDays;
    $part2 = $currentDays - ($ratioBefore * $currentDays);
    $totalDays = $part1 + $part2;

    return ceil(round($totalDays, 2) * 10) / 10;
}

try {
    $db = Database::getConnection();
    $currentYear = (int)date('Y');
    
    // 撈取全體員工
    $stmt = $db->query("SELECT user_id, name, start_date, last_year_annual_hours FROM users WHERE start_date IS NOT NULL");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h2>--- 開始執行全體資料校正 (自動填寫新欄位) ---</h2>";
    echo "<ul style='line-height:1.8;'>";

    $db->beginTransaction();
    $successCount = 0;

    foreach ($users as $user) {
        $userId = $user['user_id'];
        $hireDate = $user['start_date'];
        $lastYearAnnual = floatval($user['last_year_annual_hours'] ?? 0);
        
        // 1. 撈取該員工今年各假別已請時數
        $stmtStat = $db->prepare("
            SELECT leave_type, SUM(leave_hours) as total_used 
            FROM leave_requests 
            WHERE user_id = ? AND status = 'approved' AND start_at LIKE ?
            GROUP BY leave_type
        ");
        $stmtStat->execute([$userId, "$currentYear%"]);
        $usedStats = $stmtStat->fetchAll(PDO::FETCH_KEY_PAIR);

        $usedAnnual   = floatval($usedStats['特休假'] ?? $usedStats['特休'] ?? 0);
        $usedPersonal = floatval($usedStats['事假'] ?? 0);
        $usedSick     = floatval($usedStats['病假'] ?? 0);

        // 2. 計算今年法定特休
        $entitledDays = calculateAnnualLeaveDaysStrict($hireDate, $currentYear);
        $entitledAnnual = $entitledDays * 8; 

        // 3. 結算特休剩餘 (歷年保留 + 今年法定 - 今年已請)
        $totalEntitled = $lastYearAnnual + $entitledAnnual;
        $remainingAnnual = max(0, $totalEntitled - $usedAnnual);

        // 4. 寫入資料庫的新欄位
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