<?php
// query_api.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';

// 開啟錯誤顯示 (除錯用，上線可關閉)
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    $db = Database::getConnection();
    $action = $_GET['action'] ?? '';

    // ==========================================
    // 功能 A: 查詢個人請假紀錄
    // ==========================================
    if ($action === 'user_history') {
        $userId = $_GET['userId'] ?? '';
        if (empty($userId)) throw new Exception("缺少 userId");

        // 1. 查詢使用者目前的「剩餘餘額」
        $stmtUser = $db->prepare("SELECT annual_leave_hours, comp_leave_hours FROM users WHERE user_id = ?");
        $stmtUser->execute([$userId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        $remainingAnnual = floatval($user['annual_leave_hours'] ?? 0);
        $remainingComp   = floatval($user['comp_leave_hours'] ?? 0);

        // 2. 查詢「今年已核准」的總時數 (用來回推 應得/累積)
        // 注意：這裡只統計 'approved'，因為 'pending' 還沒扣款
        $currentYear = date('Y');
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
        $usedStats = $stmtStat->fetchAll(PDO::FETCH_KEY_PAIR); // [ '特休' => 10, '病假' => 4 ]

        // 3. 計算各項數值
        $usedAnnual = floatval($usedStats['特休假'] ?? $usedStats['特休'] ?? 0);
        $usedComp   = floatval($usedStats['補休假'] ?? $usedStats['補休'] ?? 0);

        // 推算「年度總額度」 = 剩餘 + 已用
        $entitledAnnual = $remainingAnnual + $usedAnnual;
        $earnedComp     = $remainingComp + $usedComp;

        // 4. 查詢列表紀錄 (維持原本邏輯)
        $sqlList = "SELECT * FROM leave_requests WHERE user_id = ? ORDER BY start_at DESC LIMIT 100";
        $stmtList = $db->prepare($sqlList);
        $stmtList->execute([$userId]);
        $rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);

        // 5. 回傳完整資料包
        echo json_encode([
            'status' => 'success',
            'data' => $rows,
            'stats' => [
                'annual' => [
                    'entitled' => $entitledAnnual, // 應得
                    'used' => $usedAnnual,         // 已用
                    'remaining' => $remainingAnnual // 剩餘
                ],
                'comp' => [
                    'earned' => $earnedComp,       // 累積
                    'used' => $usedComp,           // 已用
                    'remaining' => $remainingComp   // 剩餘
                ],
                // 其他假別只回傳已用
                'others' => $usedStats
            ]
        ]);
        exit;
    }

    // ==========================================
    // 功能 B: 查詢同事 (預留給下一步)
    // ==========================================
    if ($action === 'colleague_status') {
        // 取得目前時間 (YYYY-MM-DD HH:mm:ss)
        $now = date('Y-m-d H:i:s');

        // 查詢所有使用者，並關聯查詢「當下是否正在休假」
        // 如果 lr.id 有值，代表該員工目前正在請假中
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

        // 整理資料：將狀態轉為易讀格式
        $results = [];
        foreach ($rows as $row) {
            $isOnLeave = !empty($row['leave_type']);
            $results[] = [
                'name' => $row['name'],
                'status' => $isOnLeave ? 'leave' : 'work',
                'status_text' => $isOnLeave ? '休假中' : '在勤',
                // 如果在休假，顯示預計回來時間 (只取到分)
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