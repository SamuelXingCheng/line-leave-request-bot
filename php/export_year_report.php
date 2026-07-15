<?php
// export_leave_comparison_2025.php
// 用途：匯出「2025年 (114年)」特休比對報表 (V5: 強制年份相減法 + 勞基法標準)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/utils.php';

$secretKey = getenv('EXPORT_SECRET') ?: '123456';
if (($_GET['key'] ?? '') !== $secretKey) {
    http_response_code(403);
    die("⛔ 存取被拒");
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="leave_detail_2025_FINAL_' . date('Ymd') . '.csv"');

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");

// 設定目標年份
$targetYear = 2025;
$yearEndStr = "$targetYear-12-31";
$yearEndDate = new DateTime($yearEndStr);
$yearEndDate->setTime(0, 0, 0);
$calcDate = new DateTime("$targetYear-01-01");
$calcDate->setTime(0, 0, 0);

fputcsv($output, [
    "報表年度: $targetYear (民國114年) - 強制年資修正版", '', '', '', '', '', '', '', '', '', '', '', ''
]);
fputcsv($output, [
    '姓名', '到職日', '年資狀態',
    '【週年制】權益', '【週年制】期間', '【週年制】剩餘',
    ' | ',
    '【曆年制】總天數',
    '【曆年制】區段1', '天數1',
    '【曆年制】區段2', '天數2',
    '【曆年制】剩餘',
    '說明'
]);

$db = Database::getConnection();
$users = $db->query("SELECT user_id, name, start_date FROM users ORDER BY user_id ASC")->fetchAll();

foreach ($users as $user) {
    if (empty($user['start_date'])) continue;

    $hireDate = new DateTime($user['start_date']);
    $hireDate->setTime(0, 0, 0);

    // 初始化
    $tenureStr = "";
    $annivEntitledDays = 0;
    $annivPeriod = "-";
    $calResult = ['total'=>0, 'period1'=>'-', 'days1'=>0, 'period2'=>'-', 'days2'=>0, 'debug'=>''];
    $note = "";

    // 判斷員工類型
    if ($hireDate->format('Y') == $targetYear) {
        // --- A. 今年 (2025) 新進員工 ---
        $tenureStr = "今年到職";
        $sixMonthDate = clone $hireDate;
        $sixMonthDate->modify('+6 months');

        if ($sixMonthDate <= $yearEndDate) {
            $note = "年度中滿6個月";
            $annivEntitledDays = 3;
            $oneYearDate = clone $hireDate;
            $oneYearDate->modify('+1 year')->modify('-1 day');
            $annivPeriod = $sixMonthDate->format('Y/m/d') . '~' . $oneYearDate->format('Y/m/d');
            // 2. 曆年制 (統一使用嚴謹版公式，確保邏輯單一)
            $calendarDays = calculateAnnualLeaveDaysStrict($user['start_date'], $targetYear);
            $calResult = [
                'total'   => $calendarDays,
                'period1' => '-', // 簡化報表，不再錯誤計算工作日
                'days1'   => '-',
                'period2' => '-',
                'days2'   => '-',
                'debug'   => ''
            ];
        } else {
            $note = "未滿6個月";
        }

    } elseif ($hireDate > $yearEndDate) {
        $tenureStr = "尚未到職";
    } else {
        // --- C. 舊員工 (2024以前) ---
        $tenure = $hireDate->diff($calcDate);
        $tenureStr = "{$tenure->y}年{$tenure->m}個月";

        // 1. 週年制 (🔥 修正：直接呼叫 getLawDays，並移除錯誤的 DateTime 傳參)
        $yearsOfService = $targetYear - (int)$hireDate->format('Y');
        $annivEntitledDays = getLawDays($yearsOfService);
        
        $annivDateCurrentYear = new DateTime("$targetYear-" . $hireDate->format('m-d'));
        
        $nextAnniv = clone $annivDateCurrentYear;
        $nextAnniv->modify('+1 year')->modify('-1 day');
        $annivPeriod = $annivDateCurrentYear->format('Y/m/d') . '~' . $nextAnniv->format('Y/m/d');

        // 2. 曆年制 (統一使用嚴謹版公式，確保邏輯單一)
        $calendarDays = calculateAnnualLeaveDaysStrict($user['start_date'], $targetYear);
        $calResult = [
            'total'   => $calendarDays,
            'period1' => '-', // 簡化報表，不再錯誤計算工作日
            'days1'   => '-',
            'period2' => '-',
            'days2'   => '-',
            'debug'   => ''
        ];
    }

    // 取得已用時數
    $stats = getLeaveSummary($user['user_id']);
    $usedHours = $stats['usedAnnual'];
    $usedDays = round($usedHours / 8, 2);

    $annivRemaining = $annivEntitledDays - $usedDays;
    $calRemaining   = $calResult['total'] - $usedDays;

    fputcsv($output, [
        $user['name'],
        $user['start_date'],
        $tenureStr,
        $annivEntitledDays,
        $annivPeriod,
        $annivRemaining,
        '|',
        $calResult['total'],
        $calResult['period1'],
        $calResult['days1'],
        $calResult['period2'],
        $calResult['days2'],
        $calRemaining,
        $note . $calResult['debug'] . (($calRemaining < 0) ? ' ⚠️透支' : '')
    ]);
}

fclose($output);
exit;

// ----------------------------------------------------
// 函式區
// ----------------------------------------------------

function getHolidays(int $year): array {
    global $db;
    $stmt = $db->prepare("SELECT date FROM holiday WHERE YEAR(date) = :year");
    $stmt->execute(['year' => $year]);
    $holidays = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $holidays;
}

// 勞基法標準版
function getLawEntitlement($years) {
    if ($years < 1) return 0;
    if ($years < 2) return 7;
    if ($years < 3) return 10;
    if ($years < 5) return 14;
    if ($years < 10) return 15;
    return min(16 + ($years - 10), 30);
}

function customCeil($val) {
    return ceil($val * 10) / 10;
}
?>