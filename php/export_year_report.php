<?php
// export_leave_comparison_2025.php
// 用途：匯出「2025年 (114年)」特休比對報表 (V3: 包含年度中滿6個月的新進員工)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/utils.php';

$secretKey = getenv('EXPORT_SECRET') ?: '123456';
if (($_GET['key'] ?? '') !== $secretKey) {
    http_response_code(403);
    die("⛔ 存取被拒");
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="leave_detail_2025_v3_' . date('Ymd') . '.csv"');

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");

// 設定目標年份
$targetYear = 2025; 
$yearEndStr = "$targetYear-12-31";
$yearEndDate = new DateTime($yearEndStr);
$calcDate = new DateTime("$targetYear-01-01"); // 用於計算舊員工年資

fputcsv($output, [
    "報表年度: $targetYear (民國114年)", '', '', '', '', '', '', '', '', '', '', '', ''
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
    if (empty($user['start_date'])) {
        continue;
    }

    $hireDate = new DateTime($user['start_date']);
    
    // 初始化變數
    $tenureStr = "";
    $annivEntitledDays = 0;
    $annivPeriod = "-";
    $calResult = ['total'=>0, 'period1'=>'-', 'days1'=>0, 'period2'=>'-', 'days2'=>0];
    $note = "";

    // 判斷員工類型
    if ($hireDate->format('Y') == $targetYear) {
        // --- A. 今年 (2025) 新進員工 ---
        $tenureStr = "今年到職";
        
        // 計算滿6個月的日期
        $sixMonthDate = clone $hireDate;
        $sixMonthDate->modify('+6 months');
        
        // 檢查是否在年底前滿6個月
        if ($sixMonthDate <= $yearEndDate) {
            $note = "年度中滿6個月";
            
            // 1. 週年制 (滿6個月給3天)
            $annivEntitledDays = 3;
            $oneYearDate = clone $hireDate;
            $oneYearDate->modify('+1 year')->modify('-1 day');
            $annivPeriod = $sixMonthDate->format('Y/m/d') . '~' . $oneYearDate->format('Y/m/d');

            // 2. 曆年制 (計算比例)
            $calResult = calculateCalendarDetails($hireDate, $targetYear);
        } else {
            $note = "未滿6個月";
            // 天數皆為 0
        }

    } elseif ($hireDate > $yearEndDate) {
        // --- B. 未來員工 (明年才到職) ---
        $tenureStr = "尚未到職";
        // 全為 0
    } else {
        // --- C. 舊員工 (2024以前到職) ---
        $tenure = $hireDate->diff($calcDate);
        $tenureStr = "{$tenure->y}年{$tenure->m}個月";

        // 1. 週年制
        // 找出今年的週年日
        $annivDateCurrentYear = new DateTime("$targetYear-" . $hireDate->format('m-d'));
        
        // 計算天數 (依 utils.php 邏輯或勞基法)
        $annivEntitledDays = calculateAnnualLeaveDays($user['start_date'], $annivDateCurrentYear);
        
        // 期間：週年日 ~ 下次週年日前一天
        $nextAnniv = clone $annivDateCurrentYear;
        $nextAnniv->modify('+1 year')->modify('-1 day');
        $annivPeriod = $annivDateCurrentYear->format('Y/m/d') . '~' . $nextAnniv->format('Y/m/d');

        // 2. 曆年制
        $calResult = calculateCalendarDetails($hireDate, $targetYear);
    }
    
    // 取得已用時數 (資料庫紀錄)
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

        $note . (($calRemaining < 0) ? ' ⚠️透支' : '')
    ]);
}

fclose($output);
exit;

// ----------------------------------------------------
// 函式區
// ----------------------------------------------------

function calculateCalendarDetails(DateTime $hireDate, int $year) {
    $annivDate = new DateTime("$year-" . $hireDate->format('m-d'));
    
    // 區段 1: 1/1 ~ 週年日前一天
    $p1Start = "$year-01-01";
    $p1EndObj = clone $annivDate;
    $p1EndObj->modify('-1 day');
    $p1End = $p1EndObj->format('Y-m-d');
    
    // 區段 2: 週年日 ~ 12/31
    $p2Start = $annivDate->format('Y-m-d');
    $p2End   = "$year-12-31";

    // 比例參數
    $month = (int)$annivDate->format('n');
    $day   = (int)$annivDate->format('j');
    $daysInMonth = (int)$annivDate->format('t');
    
    // 1/1 ~ 週年日前的月數
    $monthsBeforeAnniv = ($month - 1) + (($day - 1) / $daysInMonth);
    
    // 年資 (以今年的週年日為準)
    // 若是今年新進員工，$annivDate == $hireDate，Diff為0
    $yearsOfService = $hireDate->diff($annivDate)->y;

    // --- 計算 Days 1 (舊年資/上半段) ---
    $days1 = 0;
    if ($yearsOfService == 0) {
        $days1 = 0; // 新人上半年無假
    } elseif ($yearsOfService == 1) {
        // 滿1年前是滿6個月的權益 (3天)
        // 3天是給「6個月~1年」這6個月用的
        // 我們要算出這段期間有多少比例落在今年 1/1 ~ 週年日
        $days1 = customCeil(3 * ($monthsBeforeAnniv / 6));
        if ($days1 > 3) $days1 = 3;
    } else {
        // 滿N年(>1)，舊年資是 N-1 年
        $prevTotal = getLawEntitlement($yearsOfService - 1);
        $days1 = customCeil($prevTotal * ($monthsBeforeAnniv / 12));
    }

    // --- 計算 Days 2 (新年資/下半段) ---
    $days2 = 0;
    if ($yearsOfService == 0) {
        // 新人：週年日(到職日) ~ 年底
        // 檢查年底前是否滿6個月
        $endOfYear = new DateTime("$year-12-31");
        $diff = $hireDate->diff($endOfYear);
        // 判斷月數差是否 >= 6
        if (($diff->y * 12 + $diff->m) >= 6) {
            // 滿6個月給3天
            // 但這3天是給「6個月~1年」用的
            // 我們要看今年年底前佔用了多少比例
            // "到職日~年底" 的長度 = 12 - monthsBeforeAnniv
            // 但特休是從 "滿6個月" 才開始算
            // 簡單算法： 3天 - (留給明年用的)
            // 明年用的 = 1月~到職日這段長度 = monthsBeforeAnniv
            $deduction = customCeil(3 * ($monthsBeforeAnniv / 6));
            $days2 = 3 - $deduction;
            if ($days2 < 0) $days2 = 0;
        }
    } else {
        // 舊人：週年日 ~ 年底 (滿N年權益)
        $currTotal = getLawEntitlement($yearsOfService);
        // 總天數 - (1月~週年日已被算在去年的扣打)
        $deduction = customCeil($currTotal * ($monthsBeforeAnniv / 12));
        $days2 = $currTotal - $deduction;
    }

    return [
        'total'   => $days1 + $days2,
        'period1' => ($yearsOfService==0) ? '-' : str_replace('-', '/', "$p1Start~$p1End"),
        'days1'   => $days1,
        'period2' => str_replace('-', '/', "$p2Start~$p2End"),
        'days2'   => $days2
    ];
}

function getLawEntitlement($years) {
    if ($years < 1) return 0; // 未滿1年但在函式外處理了0.5年邏輯
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