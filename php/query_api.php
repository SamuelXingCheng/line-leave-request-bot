<?php
// query_api.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';

// 開啟錯誤顯示 (除錯用，上線可關閉)
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// ==========================================
// 🔥 內建最新的勞動部曆年制公式
// ==========================================
if (!function_exists('getLawDays')) {
    function getLawDays($years) {
        if ($years >= 10) return min(15 + floor($years - 9), 30); 
        if ($years >= 5)  return 15;
        if ($years >= 3)  return 14;
        if ($years >= 2)  return 10;
        if ($years >= 1)  return 7;
        if ($years >= 0.5) return 3; 
        return 0;
    }
}

if (!function_exists('calculateAnnualLeaveDaysStrict')) {
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
}
// ==========================================

try {
    $db = Database::getConnection();
    $action = $_GET['action'] ?? '';

    if ($action === 'user_history') {
        $userId = $_GET['userId'] ?? '';
        if (empty($userId)) throw new Exception("缺少 userId");

        // Move here: Ensure permissions are checked after $userId is retrieved
        if (!function_exists('hasPermission') || !hasPermission($userId)) {
            throw new Exception("使用者沒有權限");
        }

        // 1. 查詢使用者資料
        $stmtUser = $db->prepare("SELECT start_date, comp_leave_hours, last_year_annual_hours FROM users WHERE user_id = ?");
        $stmtUser->execute([$userId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        $hireDate = $user['start_date'] ?? null;
        $remainingComp = floatval($user['comp_leave_hours'] ?? 0); 
        $lastYearAnnual = floatval($user['last_year_annual_hours'] ?? 0); 

        // 🔥 2. 精準計算今年已用時數 (使用 deduct_annual 解決混合假漏洞，並加入 pending 解決覆蓋漏洞)
        $currentYear = (int)date('Y');
        $sqlExact = "
            SELECT 
                SUM(deduct_annual) as used_annual, 
                SUM(deduct_comp) as used_comp,
                SUM(CASE WHEN leave_type LIKE '%事假%' THEN leave_hours ELSE 0 END) as used_personal,
                SUM(CASE WHEN leave_type LIKE '%病假%' THEN leave_hours ELSE 0 END) as used_sick
            FROM leave_requests 
            WHERE user_id = ? AND status IN ('approved', 'pending') AND start_at LIKE ?
        ";
        $stmtExact = $db->prepare($sqlExact);
        $stmtExact->execute([$userId, "$currentYear%"]);
        $exactStats = $stmtExact->fetch(PDO::FETCH_ASSOC);

        $usedAnnual   = floatval($exactStats['used_annual'] ?? 0);
        $usedComp     = floatval($exactStats['used_comp'] ?? 0);
        $usedPersonal = floatval($exactStats['used_personal'] ?? 0);
        $usedSick     = floatval($exactStats['used_sick'] ?? 0);

        // (供前端圓餅圖分類顯示用的查詢，維持原樣但加入 pending)
        $sqlStat = "SELECT leave_type, SUM(leave_hours) as total_used FROM leave_requests WHERE user_id = ? AND status IN ('approved', 'pending') AND start_at LIKE ? GROUP BY leave_type";
        $stmtStat = $db->prepare($sqlStat);
        $stmtStat->execute([$userId, "$currentYear%"]);
        $usedStats = $stmtStat->fetchAll(PDO::FETCH_KEY_PAIR);

        // 🔥 3. 結算最新「剩餘特休」(完美支援每年1月1日重置與日常即時扣款)
        $entitledAnnual = 0;
        if ($hireDate) {
            $entitledDays = calculateAnnualLeaveDaysStrict($hireDate, $currentYear);
            $entitledAnnual = $entitledDays * 8; 
        }

        $totalEntitled = $lastYearAnnual + $entitledAnnual;
        $remainingAnnual = max(0, $totalEntitled - $usedAnnual);

        // 4. 同步更新回資料庫
        $updateStmt = $db->prepare("
            UPDATE users 
            SET entitled_annual_hours = ?, annual_leave_hours = ?, used_annual_hours = ?, used_personal_hours = ?, used_sick_hours = ? WHERE user_id = ?
        ");
        $updateStmt->execute([$entitledAnnual, $remainingAnnual, $usedAnnual, $usedPersonal, $usedSick, $userId]);

        $earnedComp = $remainingComp + $usedComp;

        // 7. 查詢列表紀錄 
        $sqlList = "SELECT * FROM leave_requests WHERE user_id = ? ORDER BY start_at DESC LIMIT 100";
        $stmtList = $db->prepare($sqlList);
        $stmtList->execute([$userId]);
        $rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);

        // 8. 回傳完整資料包給前端
        echo json_encode([
            'status' => 'success',
            'data' => $rows,
            'stats' => [
                'annual' => [
                    'entitled' => $totalEntitled,   
                    'used' => $usedAnnual,          
                    'remaining' => $currentAnnualBal // 🔥 修正：改用我們剛撈出來的真實存摺餘額
                ],
                'comp' => [
                    'earned' => $earnedComp,       
                    'used' => $usedComp,           
                    'remaining' => $remainingComp   
                ],
                'others' => $usedStats
            ]
        ]);
        exit;
    }

    // ==========================================
    // 功能 B: 查詢同事
    // ==========================================
    if ($action === 'colleague_status') {
        $now = date('Y-m-d H:i:s');
        $sql = "
            SELECT 
                u.user_id, 
                u.name,
                lr.leave_type,
                lr.end_at as return_time
            FROM users u
            LEFT JOIN leave_requests lr ON u.user_id = lr.user_id 
                AND lr.status = 'approved'
                AND ? BETWEEN lr.start_at AND lr.end_at
            ORDER BY lr.end_at DESC, u.name ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$now]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $row) {
            $isOnLeave = !empty($row['leave_type']);
            $results[] = [
                'name' => $row['name'],
                'status' => $isOnLeave ? 'leave' : 'work',
                'status_text' => $isOnLeave ? '休假中' : '在勤',
                'return_time' => $isOnLeave ? substr($row['return_time'], 0, 16) : null
            ];
        }

        echo json_encode([
            'status' => 'success',
            'data' => $results
        ]);
        exit;
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>