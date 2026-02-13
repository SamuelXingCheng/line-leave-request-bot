<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';

echo "<h2>資料庫欄位修正工具</h2>";

try {
    $db = Database::getConnection();

    // 執行修改指令：將 target_date 改為 VARCHAR(100)
    // 並允許為 NULL (為了相容 full_revoke)
    $sql = "ALTER TABLE leave_modifications MODIFY COLUMN target_date VARCHAR(100) NULL";
    
    $db->exec($sql);
    
    echo "<h3 style='color:green'>✅ 修正成功！</h3>";
    echo "<p>已將 leave_modifications 表格的 target_date 欄位長度擴充為 100。</p>";
    echo "<p>現在您可以重新送出修改申請了。</p>";

} catch (PDOException $e) {
    echo "<h3 style='color:red'>❌ 修正失敗</h3>";
    echo "錯誤訊息：" . $e->getMessage();
}
?>