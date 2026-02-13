<?php
/**
 * 假單資料修復腳本 (Web 版)
 * 目的：將舊資料的 leave_hours 依據假別填入 deduct_annual 或 deduct_comp
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

// --- 安全設定 ---
$secretKey = "church2026"; 

echo "<html><head><meta charset='utf-8'><title>資料表修復工具</title></head><body style='font-family:monospace; background:#f4f4f4; padding:20px;'>";
echo "<div style='background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1);'>";

// 1. 驗證金鑰
if (($_GET['key'] ?? '') !== $secretKey) {
    die("<h2 style='color:red;'>❌ 密鑰錯誤</h2></div></body></html>");
}

try {
    $db = Database::getConnection();

    echo "<h2>--- 開始修復 leave_requests 資料表 ---</h2>";

    $db->beginTransaction();

    // 2. 修復特休假資料
    // 邏輯：如果假別包含「特休」，且扣抵欄位還是 0，就從 leave_hours 補齊
    $stmtAnnual = $db->prepare("
        UPDATE leave_requests 
        SET deduct_annual = leave_hours 
        WHERE (leave_type LIKE '%特休%') 
          AND (deduct_annual IS NULL OR deduct_annual = 0)
          AND status = 'approved'
    ");
    $stmtAnnual->execute();
    $annualCount = $stmtAnnual->rowCount();

    // 3. 修復補休假資料
    // 邏輯：如果假別包含「補休」，且扣抵欄位還是 0，就從 leave_hours 補齊
    $stmtComp = $db->prepare("
        UPDATE leave_requests 
        SET deduct_comp = leave_hours 
        WHERE (leave_type LIKE '%補休%') 
          AND (deduct_comp IS NULL OR deduct_comp = 0)
          AND status = 'approved'
    ");
    $stmtComp->execute();
    $compCount = $stmtComp->rowCount();

    $db->commit();

    echo "<ul style='font-size:1.1rem;'>";
    echo "<li>✅ 已更新 <strong style='color:blue;'>{$annualCount}</strong> 筆特休扣抵紀錄。</li>";
    echo "<li>✅ 已更新 <strong style='color:green;'>{$compCount}</strong> 筆補休扣抵紀錄。</li>";
    echo "</ul>";
    
    echo "<div style='margin-top:20px; padding:15px; background:#e8f5e9; border-left:5px solid #4caf50;'>";
    echo "<strong>下一步建議：</strong><br>";
    echo "現在 leave_requests 的明細已經正確，您可以執行 <strong>sync_leave_balances.php</strong> 來更新 users 表的存摺總額了。";
    echo "</div>";

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo "<h2 style='color:red;'>❌ 執行失敗</h2><p>" . $e->getMessage() . "</p>";
}

echo "</div></body></html>";