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
        $daysInAnniversaryMonth = (int)date('t', strtotime("$targetYear-$hireMonth-01"));
        
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

        // 1. 查詢使用者資料 (🔥 新增抓取 entitled_annual_hours 與 annual_leave_hours)
        $stmtUser = $db->prepare("SELECT start_date, comp_leave_hours, last_year_annual_hours, entitled_annual_hours, annual_leave_hours FROM users WHERE user_id = ?");
        $stmtUser->execute([$userId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        $hireDate = $user['start_date'] ?? null;
        $remainingComp = floatval($user['comp_leave_hours'] ?? 0); 
        $lastYearAnnual = floatval($user['last_year_annual_hours'] ?? 0); 
        
        $currentEntitled = floatval($user['entitled_annual_hours'] ?? 0);
        $currentAnnualBal = floatval($user['annual_leave_hours'] ?? 0);

        // 2. 查詢「今年已核准」的請假總時數
        $currentYear = (int)date('Y');
        $sqlStat = "
            SELECT leave_type, SUM(leave_hours) as total_used 
            FROM leave_requests 
            WHERE user_id = ? 
            AND status = 'approved' 
            AND start_at LIKE ?
            GROUP BY leave_type
        ";
        $stmtStat = $db->prepare($sqlStat);
        $stmtStat->execute([$userId, "$currentYear%"]);
        $usedStats = $stmtStat->fetchAll(PDO::FETCH_KEY_PAIR);

        // 3. 取得各假別的「已用時數」
        $usedAnnual   = floatval($usedStats['特休假'] ?? $usedStats['特休'] ?? 0);
        $usedComp     = floatval($usedStats['補休假'] ?? $usedStats['補休'] ?? 0);
        $usedPersonal = floatval($usedStats['事假'] ?? 0);
        $usedSick     = floatval($usedStats['病假'] ?? 0);

        // 4. 動態計算當下最新的「法定應得總時數」(只有今年的份)
        $newEntitledAnnual = 0;
        if ($hireDate) {
            $entitledDays = calculateAnnualLeaveDaysStrict($hireDate, $currentYear);
            $newEntitledAnnual = $entitledDays * 8; 
        }

        // 🔥 5. 存摺補發邏輯：只在「應得額度增加時」(例如跨年資) 才把差額補進存摺
        if ($newEntitledAnnual > $currentEntitled) {
            $diff = $newEntitledAnnual - $currentEntitled;
            $currentAnnualBal += $diff; // 將差額補進現有真實餘額
            
            // 補發特休並更新已發放額度
            $db->prepare("UPDATE users SET entitled_annual_hours = ?, annual_leave_hours = ? WHERE user_id = ?")
               ->execute([$newEntitledAnnual, $currentAnnualBal, $userId]);
            
            $currentEntitled = $newEntitledAnnual;
        }

        $totalEntitled = $lastYearAnnual + $currentEntitled;

        // 🔥 6. 更新其他顯示用數據 (絕對不可再用 total - used 覆蓋 annual_leave_hours！)
        $updateStmt = $db->prepare("
            UPDATE users 
            SET used_annual_hours = ?, used_personal_hours = ?, used_sick_hours = ?
            WHERE user_id = ?
        ");
        $updateStmt->execute([$usedAnnual, $usedPersonal, $usedSick, $userId]);

        // 推算補休累積
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