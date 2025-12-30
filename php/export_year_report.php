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
            $calResult = calculateCalendarDetails($hireDate, $targetYear);
        } else {
            $note = "未滿6個月";
        }

    } elseif ($hireDate > $yearEndDate) {
        $tenureStr = "尚未到職";
    } else {
        // --- C. 舊員工 (2024以前) ---
        $tenure = $hireDate->diff($calcDate);
        $tenureStr = "{$tenure->y}年{$tenure->m}個月";

        // 1. 週年制
        $annivDateCurrentYear = new DateTime("$targetYear-" . $hireDate->format('m-d'));
        $annivEntitledDays = calculateAnnualLeaveDays($user['start_date'], $annivDateCurrentYear);
        
        $nextAnniv = clone $annivDateCurrentYear;
        $nextAnniv->modify('+1 year')->modify('-1 day');
        $annivPeriod = $annivDateCurrentYear->format('Y/m/d') . '~' . $nextAnniv->format('Y/m/d');

        // 2. 曆年制
        $calResult = calculateCalendarDetails($hireDate, $targetYear);
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

function calculateCalendarDetails(DateTime $hireDate, int $year) {
    $hireDate->setTime(0, 0, 0);
    $annivDate = new DateTime("$year-" . $hireDate->format('m-d'));
    $annivDate->setTime(0, 0, 0);
    
    $p1Start = "$year-01-01";
    $p1EndObj = clone $annivDate;
    $p1EndObj->modify('-1 day');
    $p1End = $p1EndObj->format('Y-m-d');
    
    $p2Start = $annivDate->format('Y-m-d');
    $p2End   = "$year-12-31";

    $month = (int)$annivDate->format('n');
    $day   = (int)$annivDate->format('j');
    $daysInMonth = (int)$annivDate->format('t');
    
    $monthsBeforeAnniv = ($month - 1) + (($day - 1) / $daysInMonth);
    
    // 🔥【強制修正】不使用 diff，直接用年份相減，確保 2024->2025 算成 1 年
    $hireYear = (int)$hireDate->format('Y');
    $yearsOfService = $year - $hireYear;

    // --- 計算 Days 1 (舊年資) ---
    $days1 = 0;
    if ($yearsOfService == 0) {
        $days1 = 0; 
    } elseif ($yearsOfService == 1) {
        // 滿1年前是滿6個月的權益 (3天)
        // 邏輯：滿6個月的3天權益，從去年9/1用到今年3/1
        // 今年佔用的比例 = 1月~3月(週年日) / 6個月
        $days1 = customCeil(3 * ($monthsBeforeAnniv / 6));
        if ($days1 > 3) $days1 = 3;
    } else {
        $prevTotal = getLawEntitlement($yearsOfService - 1);
        $days1 = customCeil($prevTotal * ($monthsBeforeAnniv / 12));
    }

    // --- 計算 Days 2 (新年資) ---
    $days2 = 0;
    if ($yearsOfService == 0) {
        // 新人...
        $endOfYear = new DateTime("$year-12-31");
        $endOfYear->setTime(0,0,0);
        $diff = $hireDate->diff($endOfYear);
        if (($diff->y * 12 + $diff->m) >= 6) {
            $deduction = customCeil(3 * ($monthsBeforeAnniv / 6));
            $days2 = 3 - $deduction;
            if ($days2 < 0) $days2 = 0;
        }
    } else {
        // 舊人
        $currTotal = getLawEntitlement($yearsOfService);
        $deduction = customCeil($currTotal * ($monthsBeforeAnniv / 12));
        $days2 = $currTotal - $deduction;
    }

    return [
        'total'   => $days1 + $days2,
        'period1' => ($yearsOfService==0) ? '-' : str_replace('-', '/', "$p1Start~$p1End"),
        'days1'   => $days1,
        'period2' => str_replace('-', '/', "$p2Start~$p2End"),
        'days2'   => $days2,
        'debug'   => " [Debug: YOS=$yearsOfService, Hire=$hireYear]" // 除錯資訊
    ];
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