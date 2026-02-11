<?php
// utils.php
require_once __DIR__ . '/Db.php';  // ✅ 注意大小寫，與你的 Database class 一致

function pushMessage($to, $messageObjects) {
    $url = 'https://api.line.me/v2/bot/message/push';
    $headers = [
        'Content-Type: application/json',
        "Authorization: Bearer " . getenv("LINE_CHANNEL_ACCESS_TOKEN")
    ];

    // 確保是陣列
    if (isset($messageObjects['type'])) {
        $messageObjects = [$messageObjects];
    }

    $data = [
        'to' => $to,
        'messages' => $messageObjects
    ];

    return callLineAPI($url, $headers, $data);
}

function replyMessage($replyToken, $messageObjects) {
    $url = 'https://api.line.me/v2/bot/message/reply';
    $headers = [
        'Content-Type: application/json',
        "Authorization: " . "Bearer " . getenv("LINE_CHANNEL_ACCESS_TOKEN")
    ];

    // 確保是陣列
    if (isset($messageObjects['type'])) {
        $messageObjects = [$messageObjects];
    }

    $data = [
        'replyToken' => $replyToken,
        'messages'   => $messageObjects
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
    $result = curl_exec($ch);
    curl_close($ch);

    error_log("📤 ReplyMessage result: " . $result);
}

/**
 * 檢查某一天是否為國定假日或補班日
 * 回傳: 'holiday' (放假), 'workday' (補班), 或 null (無特殊設定)
 */
function getHolidayType($dateStr) {
    $pdo = Database::getConnection(); //
    $stmt = $pdo->prepare("SELECT type FROM holidays WHERE date = ?");
    $stmt->execute([$dateStr]);
    return $stmt->fetchColumn(); 
}

// ---------- 已有的 ----------
function replyTextMessage($replyToken, $text) {
    $url = "https://api.line.me/v2/bot/message/reply";
    $headers = [
        "Content-Type: application/json",
        "Authorization: " . "Bearer " . getenv("LINE_CHANNEL_ACCESS_TOKEN")
    ];
    $postData = [
        "replyToken" => $replyToken,
        "messages" => [[ "type" => "text", "text" => $text ]]
    ];
    return callLineAPI($url, $headers, $postData);
}

function replyQuickReply($replyToken, $text, $items) {
    $actions = [];
    foreach ($items as $item) {
        // 🔥【修改這段】判斷是否為進階 Action 物件 (例如 datetimepicker)
        if (isset($item['type']) && $item['type'] === 'action') {
            // 如果已經是完整的 action 結構，直接使用
            $actions[] = $item;
        } else {
            // 否則維持原本的簡易模式：[標籤, 回傳文字]
            $actions[] = [
                "type" => "action",
                "action" => [
                    "type" => "message",
                    "label" => $item[0],
                    "text" => $item[1]
                ]
            ];
        }
    }

    $url = "https://api.line.me/v2/bot/message/reply";
    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . getenv("LINE_CHANNEL_ACCESS_TOKEN")
    ];
    $postData = [
        "replyToken" => $replyToken,
        "messages" => [[
            "type" => "text",
            "text" => $text,
            "quickReply" => ["items" => $actions]
        ]]
    ];
    return callLineAPI($url, $headers, $postData);
}

// ---------- 新增的 ----------

/**
 * 呼叫 LINE API
 */
function callLineAPI($url, $headers, $postData) {
    // 🔥 設定重試次數 (抵抗網路瞬斷)
    $maxRetries = 3;
    $retryDelay = 1; // 失敗後休息 1 秒

    for ($i = 0; $i < $maxRetries; $i++) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData, JSON_UNESCAPED_UNICODE));
        
        // 🔥 強制 IPv4 (必備)
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

        // 🔥 建議設定：連線 10秒 / 總執行 30秒
        // 這樣可以避免 PHP 被系統強制殺掉，導致資料庫卡在 processing
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);        
        
        $start = microtime(true);
        $result = curl_exec($ch);
        $duration = round(microtime(true) - $start, 3);
        
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorMsg = curl_error($ch);
        
        curl_close($ch);

        // 判斷成功 (HTTP 200 且無錯誤碼)
        if ($errno === 0 && $httpCode === 200) {
            error_log("📡 LINE API 成功 ({$duration}秒): " . substr($result, 0, 50) . "...");
            return $result;
        }

        // 失敗記錄
        error_log("⚠️ [第 " . ($i + 1) . " 次失敗] cURL Error ($errno): $errorMsg | HTTP: $httpCode | 耗時: {$duration}秒");
        
        // 如果還沒達到最大重試次數，就休息一下再試
        if ($i < $maxRetries - 1) {
            sleep($retryDelay);
        }
    }

    error_log("❌ LINE API 徹底失敗 (已重試 {$maxRetries} 次)");
    return false;
}

/**
 * 查主管姓名
 */
function getSupervisorNames(array $ids): array {
    if (empty($ids)) return [];

    $pdo = Database::getConnection();  // ✅ 改用 Database class
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT name FROM users WHERE user_id IN ($placeholders)");
    $stmt->execute($ids);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * 建立請假轉傳訊息
 */
function buildForwardMessage(array $requests, string $requestGroupId): array {
    if (empty($requests)) {
        return [[
            "type" => "text",
            "text" => "⚠️ 請假資料為空，無法建立轉發訊息。"
        ]];
    }

    $firstReq      = $requests[0];
    $userName      = $firstReq["name"];
    $reason        = $firstReq["reason"] ?? "未填寫";
    $leaveType     = $firstReq["leave_type"] ?? "假別未填";
    $supervisorIds = $firstReq["supervisor_ids"] ?? [];

    $botId = getenv("LINE_BOT_ID"); // 例如 @123xyz
    $approvalCommand = "/同意請假 {$requestGroupId}";
    $encodedQuery    = rawurlencode($approvalCommand);
    $approvalLink    = "line://oaMessage/@" . $botId . "/?" . $encodedQuery;

    // 整理多日清單
    $dateLines = [];
    foreach ($requests as $req) {
        $line = "{$req['start_date']} {$req['start_time']} ~ {$req['end_date']} {$req['end_time']}";
        $dateLines[] = $line;
    }
    $dateLinesStr = implode("\n", $dateLines);

    // 主訊息
    $forwardMsg = [
        "type" => "text",
        "text" =>
            "【請假簽核通知】\n" .
            "────────────────\n" .
            "申請人員｜{$userName}\n" .
            "假別類別｜{$leaveType}\n" .
            "請假事由｜{$reason}\n" .
            "────────────────\n" .
            "申請時段：\n" .
            $dateLinesStr . "\n\n" .
            "若同意申請，請點擊下方連結簽核：" .
            "\n" . $approvalLink
    ];

    $messages = [$forwardMsg];

    // 提示訊息（主管清單）
    if (!empty($supervisorIds)) {
        $supervisorNames = getSupervisorNames($supervisorIds); // ← 需要你實作
        $lines = [];
        foreach ($supervisorNames as $name) {
            $lines[] = "- " . $name;
        }

        $hintMsg = [
            "type" => "text",
            "text" => "系統提示｜請將上方訊息轉傳給：\n" . implode("、", $lines) // 改用頓號分隔更像文章
        ];

        $messages[] = $hintMsg;
    }

    return $messages;
}

/**
 * 核心時數計算：支援跨日、自動排除假日與週末、扣除午休
 */
function calculateHours($startStr, $endStr) {
    $start = strtotime($startStr);
    $end   = strtotime($endStr);
    if ($end <= $start) return 0;

    $totalHours = 0;
    $workStartHour = "08:30";
    $workEndHour   = "17:30";
    $lunchStart    = "12:00";
    $lunchEnd      = "13:00";

    $currDate = new DateTime(date('Y-m-d', $start));
    $endDate  = new DateTime(date('Y-m-d', $end));
    
    while ($currDate <= $endDate) {
        $dateString = $currDate->format('Y-m-d');
        $specialType = getHolidayType($dateString); // 呼叫已有的假日判斷

        $isWorkDay = true;
        if ($specialType === 'holiday') { $isWorkDay = false; }
        elseif ($specialType === 'workday') { $isWorkDay = true; }
        else {
            $dayOfWeek = (int)$currDate->format('N');
            if ($dayOfWeek >= 6) $isWorkDay = false;
        }

        if ($isWorkDay) {
            $s = ($dateString === date('Y-m-d', $start)) ? date('H:i', $start) : $workStartHour;
            $e = ($dateString === date('Y-m-d', $end)) ? date('H:i', $end) : $workEndHour;

            if ($s < $workStartHour) $s = $workStartHour;
            if ($e > $workEndHour)   $e = $workEndHour;

            if ($e > $s) {
                $daySeconds = strtotime("$dateString $e") - strtotime("$dateString $s");
                $dayHours = $daySeconds / 3600;
                // 扣除午休
                if ($s < $lunchStart && $e > $lunchEnd) { $dayHours -= 1; }
                $totalHours += $dayHours;
            }
        }
        $currDate->modify('+1 day');
    }
    return max(0, $totalHours);
}

/**
 * 加班時數計算：不排除假日，但仍建議扣除午休 (若加班跨越 12:00-13:00)
 */
function calculateOvertimeHours($startStr, $endStr) {
    $start = strtotime($startStr);
    $end   = strtotime($endStr);
    if ($end <= $start) return 0;

    $totalSeconds = $end - $start;
    $totalHours = $totalSeconds / 3600;

    // 選項：是否要扣除午休？ 
    // 很多公司規定加班滿 4 小時要休息 0.5 或 1 小時。
    // 這裡提供一個簡單判斷：如果加班時間跨越了 12:00-13:00，扣除 1 小時。
    $sTime = date('H:i:s', $start);
    $eTime = date('H:i:s', $end);
    
    // 如果加班起始在 12:00 前，且結束在 13:00 後，扣除一小時午休
    if ($sTime < '12:00:00' && $eTime > '13:00:00') {
        $totalHours -= 1;
    }

    // 格式化：保留一位小數
    return max(0, round($totalHours, 1));
}

// 特休天數計算：根據年資計算特休天數
function calculateAnnualLeaveDays($hireDate, $today = null) {
    if (!$today) $today = new DateTime();
    $hire = new DateTime($hireDate);

    // 年資（到今年 1/1 為基準）
    $years = $hire->diff(new DateTime($today->format('Y-01-01')))->y;

    // 未滿一年 → 用比例表
    if ($years < 1) {
        $month = (int)$hire->format('n'); // 到職月份
        $map = [1=>7, 2=>6, 3=>5.5, 4=>5, 5=>4.5, 6=>4,
                7=>3.5, 8=>3, 9=>2.5, 10=>2, 11=>1.5, 12=>1];
        return $map[$month] ?? 0;
    }

    // 滿一年以上 → 勞基法表
    if ($years < 2) return 7;
    if ($years < 3) return 10;
    if ($years < 5) return 14;
    if ($years < 10) return 15;
    return min(16 + ($years - 10), 30); // 最多30天
}

function formatHoursAndDays($hours) {
    $days = $hours / 8; // 1天=8小時
    // 四捨五入到小數1位，例如 1.5天
    return $hours . " 小時（約 " . round($days, 1) . " 天）";
}

/**
 * 取得請假統計 (商務優化版：直接讀取預算時數欄位)
 */
function getLeaveSummary($userId) {
    $pdo = Database::getConnection();
    $currentYear = date('Y');

    // 1. 取得員工入職日期並計算年假總額度
    $stmt = $pdo->prepare("SELECT start_date FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $hireDate = $stmt->fetchColumn();

    $entitledHours = 0;
    if ($hireDate) {
        $entitledDays = calculateAnnualLeaveDays($hireDate);
        $entitledHours = $entitledDays * 8; // 換算成總小時
    }

    // 2. 統計各假別已用時數 (🔥 改為直接加總 leave_hours)
    // 這樣做最精準，且自動包含「銷假」修改後的結果
    $stmt = $pdo->prepare("
        SELECT leave_type, COALESCE(SUM(leave_hours), 0) as hours
        FROM leave_requests
        WHERE user_id = ? AND status = 'approved'
          AND YEAR(start_at) = ?
        GROUP BY leave_type
    ");
    $stmt->execute([$userId, $currentYear]);
    $leaveUsage = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // 特休數據
    $usedAnnual = $leaveUsage['特休假'] ?? $leaveUsage['特休'] ?? 0;
    $remainingAnnual = max(0, $entitledHours - $usedAnnual);

    // 3. 補休計算 (同樣建議 overtime_requests 也應具備 hours 欄位)
    // 如果您的加班單還沒有 hours 欄位，請同步增加
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(hours), 0)
        FROM overtime_requests
        WHERE user_id = ? AND status = 'approved'
          AND YEAR(start_at) = ?
    ");
    $stmt->execute([$userId, $currentYear]);
    $earnedComp = $stmt->fetchColumn();

    $usedComp = $leaveUsage['補休假'] ?? $leaveUsage['補休'] ?? 0;
    $remainingComp = max(0, $earnedComp - $usedComp);

    // 4. 取得所有請假明細 (用於顯示最近的紀錄)
    $stmt = $pdo->prepare("
        SELECT start_at, end_at, leave_type, reason, status, leave_hours
        FROM leave_requests
        WHERE user_id = ? AND status = 'approved'
          AND YEAR(start_at) = ?
        ORDER BY start_at DESC 
    ");
    $stmt->execute([$userId, $currentYear]);
    $allDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        "entitledAnnual"  => $entitledHours,
        "usedAnnual"      => $usedAnnual,
        "remainingAnnual" => $remainingAnnual,
        
        "earnedComp"      => $earnedComp,
        "usedComp"        => $usedComp,
        "remainingComp"   => $remainingComp,
        
        "summary"         => $leaveUsage, // 各假別小時統計
        "allDetails"      => $allDetails  // 包含 leave_hours 的明細
    ];
}

/**
 * 取得全域指令清單
 */
function getGlobalCommands() {
    // 📝 這裡列出所有「開頭指令」與「動作指令」
    return [
        "/更多功能", 
        "/補打卡", "/取消補打卡",
        "/註冊", 
        "/請假", "/取消請假",
        "/查詢請假", "/取消查詢",
        "/查詢打卡", "/取消查詢打卡",
        "/查詢同事",
        "/同意請假", // 簽核指令 (重要)
        "/審核打卡", // 簽核指令 (重要)
        "/刪除請假",
        "/加班", 
        "/同意加班"
    ];
}

/**
 * 判斷字串是否為系統指令 (以 / 開頭)
 */
function isCommand($text) {
    // 如果是空字串或不是字串，直接回傳 false
    if (!is_string($text) || $text === '') {
        return false;
    }
    // 檢查第一個字元是否為斜線
    return strpos(trim($text), '/') === 0;
}

/**
 * 核心邏輯：根據使用者輸入的起訖日期，列出「真正需要扣假」的日子
 * 邏輯：只要資料庫 holidays 表格裡有的日期，一律跳過
 */
function getActualLeaveDays($startStr, $endStr) {
    $start = new DateTime($startStr);
    $end   = new DateTime($endStr);
    
    // 防呆：結束時間不能早於開始時間
    if ($start > $end) return [];

    $result = [];
    $current = clone $start;

    // 迴圈跑每一天
    while ($current->format('Y-m-d') <= $end->format('Y-m-d')) {
        $dateStr = $current->format('Y-m-d');
        
        // 1. 查詢 DB: 今天是不是假日？
        $holidayType = getHolidayType($dateStr); 

        // 2. 判斷邏輯
        $isDayOff = false;

        // 如果資料庫有資料，且 type 是 holiday，那就是放假
        if ($holidayType === 'holiday') {
            $isDayOff = true;
        } 
        // 💡 安全網：如果您的資料庫「漏填」了某個週六日，這裡補救一下
        // 如果 DB 沒資料 (null)，但它是週六(6) 或 週日(7)，也當作放假
        elseif ($holidayType === null) {
            $weekDay = (int)$current->format('N');
            if ($weekDay >= 6) {
                $isDayOff = true;
            }
        }

        // 3. 只有「不是放假日」才加入清單
        if (!$isDayOff) {
            // 計算當天的時間顯示 (頭尾兩天要顯示具體時間，中間的天數顯示 09:00-18:00)
            $dayStart = ($dateStr === $start->format('Y-m-d')) ? $start->format('H:i') : "09:00";
            $dayEnd   = ($dateStr === $end->format('Y-m-d'))   ? $end->format('H:i') : "18:00";

            $result[] = "📅 $dateStr ($dayStart ~ $dayEnd)";
        }

        // 往後推一天
        $current->modify('+1 day');
    }

    return $result;
}