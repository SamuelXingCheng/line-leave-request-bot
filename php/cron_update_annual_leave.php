<?php
/**
 * 年度特休發放腳本 - 瀏覽器執行版 (累積模式)
 * 安全機制：需在網址加上 ?key=您的金鑰
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

// --- 安全設定 ---
$secretKey = "church2026"; // 🔥 請自行修改這組密鑰

echo "<html><head><meta charset='utf-8'><title>特休更新系統</title></head><body style='font-family:monospace; background:#f4f4f4; padding:20px;'>";
echo "<div style='background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1);'>";

// 1. 驗證金鑰
$userKey = $_GET['key'] ?? '';
if ($userKey !== $secretKey) {
    http_response_code(403);
    die("<h2 style='color:red;'>❌ 存取被拒絕</h2><p>請輸入正確的安全性金鑰以執行此腳本。</p></div></body></html>");
}

try {
    $db = Database::getConnection();
    $currentYear = date('Y');
    
    // 2. 撈取所有員工目前的餘額
    $stmt = $db->query("SELECT user_id, name, start_date, annual_leave_hours FROM users WHERE start_date IS NOT NULL");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h2>--- 開始執行 {$currentYear} 年度特休更新 (累計模式) ---</h2>";
    echo "<ul style='line-height:1.8;'>";

    $db->beginTransaction();

    foreach ($users as $user) {
        $userId = $user['user_id'];
        $hireDate = $user['start_date'];
        
        // 3. 計算新年度法定應得天數 (依據到職日)
        $newDays = calculateAnnualLeaveDays($hireDate);
        $newHours = $newDays * 8;

        // 4. 更新資料庫：年度總額覆蓋，存摺餘額累加
        $updateStmt = $db->prepare("
            UPDATE users 
            SET entitled_annual_hours = ?, 
                annual_leave_hours = annual_leave_hours + ? 
            WHERE user_id = ?
        ");
        $updateStmt->execute([$newHours, $newHours, $userId]);

        $oldBalance = floatval($user['annual_leave_hours']);
        $finalBalance = $oldBalance + $newHours;

        echo "<li>員工: <strong>{$user['name']}</strong> | 入職日: {$hireDate} | <span style='color:blue;'>新增: {$newHours}</span> | 總計存摺: <strong>{$finalBalance}</strong> 小時</li>";
    }

    $db->commit();
    echo "</ul><h2 style='color:green;'>✅ 更新完成，共處理 " . count($users) . " 筆資料</h2>";

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "<h2 style='color:red;'>❌ 執行失敗</h2><p>" . $e->getMessage() . "</p>";
}

echo "</div></body></html>";