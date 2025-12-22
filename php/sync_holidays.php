<?php
// 1. 務必先載入設定，這會讀取 .env
require_once __DIR__ . '/config.php'; 

// 2. 再載入資料庫連線與工具
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';
$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['holiday_csv'])) {
    try {
        $file = $_FILES['holiday_csv']['tmp_name'];
        if (!is_uploaded_file($file)) throw new Exception("檔案上傳失敗。");

        $handle = fopen($file, "r");
        if ($handle === false) throw new Exception("無法開啟檔案。");

        $db = Database::getConnection();
        
        // 準備 SQL
        $stmt = $db->prepare("
            INSERT INTO holidays (date, name, type) 
            VALUES (:date, :name, :type)
            ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type)
        ");

        $rowCount = 0;
        $importCount = 0;

        while (($data = fgetcsv($handle, 1000, ",")) !== false) {
            $rowCount++;
            if ($rowCount === 1) continue; // 跳過標題列

            // 根據截圖索引：0:日期(20260101), 1:星期, 2:是否放假(2或0), 3:備註
            $rawDate = trim($data[0] ?? '');
            $isHolidayNum = trim($data[2] ?? '0');
            $description = trim($data[3] ?? '');

            if (empty($rawDate) || strlen($rawDate) !== 8) continue;

            // 轉換日期格式：20260101 -> 2026-01-01
            $formattedDate = substr($rawDate, 0, 4) . '-' . substr($rawDate, 4, 2) . '-' . substr($rawDate, 6, 2);
            $dayOfWeek = (int)date('N', strtotime($formattedDate)); // 1(一) ~ 7(日)

            $type = null;

            if ($isHolidayNum === '2') {
                // 數字為 2 代表放假
                $type = 'holiday';
            } elseif ($isHolidayNum === '0' && $dayOfWeek >= 6) {
                // 數字為 0 (不放假) 但當天是週末 -> 補班日
                $type = 'workday';
                if (empty($description)) $description = '補行上班日';
            }

            // 只有放假或補班才寫入
            if ($type !== null) {
                $stmt->execute([
                    ':date' => $formattedDate,
                    ':name' => $description,
                    ':type' => $type
                ]);
                $importCount++;
            }
        }
        fclose($handle);
        $message = "✅ 成功！共處理 $rowCount 行，匯入/更新 $importCount 筆特殊日期。";

    } catch (Exception $e) {
        $message = "❌ 錯誤：" . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>上傳 115 年國定假日</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 40px; background: #f4f7f6; }
        .card { max-width: 500px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .msg { padding: 15px; margin-bottom: 20px; border-radius: 6px; font-size: 14px; }
        .msg-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        h2 { color: #333; margin-top: 0; }
        input[type="file"] { display: block; margin: 20px 0; }
        button { background: #06C755; color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; width: 100%; font-weight: bold; }
        button:hover { background: #05b34c; }
        .hint { font-size: 12px; color: #666; margin-top: 15px; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <h2>📅 匯入 115 年辦公日曆</h2>
        
        <?php if ($message): ?>
            <div class="msg <?php echo strpos($message, '✅') !== false ? 'msg-success' : ''; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <form action="" method="post" enctype="multipart/form-data">
            <p>請上傳政府提供的 CSV 檔案：</p>
            <input type="file" name="holiday_csv" accept=".csv" required>
            <button type="submit">開始更新資料庫</button>
        </form>

        <div class="hint">
            <strong>格式自動識別：</strong><br>
            • 日期格式：YYYYMMDD (例如 20260101)<br>
            • 放假標記：數字 2 為放假，數字 0 為上班。<br>
            • 系統會自動將「週末且標記為 0」的日期存為補班日。
        </div>
    </div>
</body>
</html>