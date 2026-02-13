<?php
/**
 * 餘額同步校正腳本 (Web 版)
 * 邏輯：重新統計 Overtime 與 Leave 紀錄，更新 Users 表的存摺數字
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

// --- 安全設定 ---
$secretKey = "church2026"; // 🔥 請務必與 update_annual_leave_web.php 相同

echo "<html><head><meta charset='utf-8'><title>餘額校正系統</title></head><body style='font-family:sans-serif; background:#f0f2f5; padding:20px;'>";
echo "<div style='max-width:800px; margin:auto; background:#fff; padding:30px; border-radius:12px; box-shadow:0 4px 15px rgba(0,0,0,0.1);'>";

// 1. 驗證金鑰
if (($_GET['key'] ?? '') !== $secretKey) {
    http_response_code(403);
    die("<h2 style='color:#e74c3c;'>❌ 存取被拒絕</h2><p>金鑰錯誤，無法執行校正。</p></div></body></html>");
}

try {
    $db = Database::getConnection();
    
    // 2. 撈取所有員工
    $stmt = $db->query("SELECT user_id, name, entitled_annual_hours FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h2 style='color:#2c3e50; border-bottom:2px solid #3498db; padding-bottom:10px;'>🔄 員工休假餘額同步中...</h2>";
    echo "<table style='width:100%; border-collapse:collapse; margin-top:20px;'>";
    echo "<tr style='background:#f8f9fa; text-align:left;'><th style='padding:12px; border-bottom:2px solid #dee2e6;'>姓名</th><th style='padding:12px; border-bottom:2px solid #dee2e6;'>特休餘額</th><th style='padding:12px; border-bottom:2px solid #dee2e6;'>補休餘額</th></tr>";

    $db->beginTransaction();

    foreach ($users as $user) {
        $userId = $user['user_id'];
        $entitledAnnual = floatval($user['entitled_annual_hours'] ?? 0);

        // A. 計算總補休來源 (加班核准總時數)
        $stmtOT = $db->prepare("SELECT SUM(hours) FROM overtime_requests WHERE user_id = ? AND status = 'approved'");
        $stmtOT->execute([$userId]);
        $totalEarnedComp = floatval($stmtOT->fetchColumn() ?: 0);

        // B. 計算已使用的時數 (請假核准總扣抵數)
        // 抓取 leave_requests 中專門紀錄扣抵的欄位
        $stmtUsed = $db->prepare("
            SELECT SUM(deduct_annual) as used_annual, SUM(deduct_comp) as used_comp 
            FROM leave_requests 
            WHERE user_id = ? AND status = 'approved'
        ");
        $stmtUsed->execute([$userId]);
        $used = $stmtUsed->fetch(PDO::FETCH_ASSOC);

        $usedAnnual = floatval($used['used_annual'] ?? 0);
        $usedComp   = floatval($used['used_comp'] ?? 0);

        // C. 計算最終餘額
        $finalAnnualBalance = max(0, $entitledAnnual - $usedAnnual);
        $finalCompBalance   = max(0, $totalEarnedComp - $usedComp);

        // D. 更新 Users 表
        $upd = $db->prepare("
            UPDATE users 
            SET annual_leave_hours = ?, 
                comp_leave_hours = ? 
            WHERE user_id = ?
        ");
        $upd->execute([$finalAnnualBalance, $finalCompBalance, $userId]);

        echo "<tr>";
        echo "<td style='padding:12px; border-bottom:1px solid #eee;'><strong>{$user['name']}</strong></td>";
        echo "<td style='padding:12px; border-bottom:1px solid #eee; color:#2980b9;'>{$finalAnnualBalance} 小時 <small>(總額 {$entitledAnnual} - 已請 {$usedAnnual})</small></td>";
        echo "<td style='padding:12px; border-bottom:1px solid #eee; color:#27ae60;'>{$finalCompBalance} 小時 <small>(獲得 {$totalEarnedComp} - 已抵 {$usedComp})</small></td>";
        echo "</tr>";
    }

    $db->commit();
    echo "</table>";
    echo "<h3 style='color:#27ae60; margin-top:30px;'>✅ 同步完成！所有員工存摺已依據歷史紀錄校正完畢。</h3>";

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo "<h2 style='color:#e74c3c;'>❌ 執行失敗</h2><p>" . $e->getMessage() . "</p>";
}

echo "</div><p style='text-align:center; color:#95a5a6; font-size:12px; margin-top:20px;'>© 2026 Church Administration System</p></body></html>";