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
        if ($yearsOfService < 1) return 0;

        // Restore the correct logic for the current year's segmentation, don't use a loop to accumulate
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
        
        // 1. 查詢使用者的「到職日」、「補休餘額」與「歷年剩餘特休」
        $stmtUser = $db->prepare("SELECT start_date, comp_leave_hours, last_year_annual_hours FROM users WHERE user_id = ?");
        $stmtUser->execute([$userId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        $hireDate = $user['start_date'] ?? null;
        $remainingComp = floatval($user['comp_leave_hours'] ?? 0); 
        // 抓取歷年保留下來的特休 (預設0)
        $lastYearAnnual = floatval($user['last_year_annual_hours'] ?? 0); 

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
        $entitledAnnual = 0;
        if ($hireDate) {
            $entitledDays = calculateAnnualLeaveDaysStrict($hireDate, $currentYear);
            $entitledAnnual = $entitledDays * 8; // 換算成小時
        }

        // 5. 結算最新「剩餘特休」
        // 公式：(歷年保留 + 今年應得) - 今年已請 = 最終剩餘
        $totalEntitled = $lastYearAnnual + $entitledAnnual;
        $remainingAnnual = max(0, $totalEntitled - $usedAnnual);

        // 🔥 6. 同步更新回資料庫 (把所有狀態寫入 users 表格)
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
            $entitledAnnual,   // 今年法定應得
            $remainingAnnual,  // 最終剩餘特休
            $usedAnnual,       // 今年已請特休
            $usedPersonal,     // 今年已請事假
            $usedSick,         // 今年已請病假
            $userId
        ]);

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
                    'entitled' => $totalEntitled,   // 顯示在前端的「應得」包含去年保留
                    'used' => $usedAnnual,          
                    'remaining' => $remainingAnnual 
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