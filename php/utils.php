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
            // 如果已經是完整的 action 結構，驗證 label 長度
            if (isset($item['action']['label'])) {
                $label = $item['action']['label'];
                if (mb_strlen($label, 'UTF-8') > 20) {
                    error_log("⚠️ Quick Reply label 超過 20 字元限制，已自動截斷: " . $label);
                    $item['action']['label'] = mb_substr($label, 0, 20, 'UTF-8');
                }
            }
            $actions[] = $item;
        } else {
            // 否則維持原本的簡易模式：[標籤, 回傳文字]
            $label = $item[0];
            if (mb_strlen($label, 'UTF-8') > 20) {
                error_log("⚠️ Quick Reply label 超過 20 字元限制，已自動截斷: " . $label);
                $label = mb_substr($label, 0, 20, 'UTF-8');
            }
            $actions[] = [
                "type" => "action",
                "action" => [
                    "type" => "message",
                    "label" => $label,
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
                
                // 🔥 精準計算午休交集 (Overlap)
                $lunchStartSec = strtotime("$dateString $lunchStart");
                $lunchEndSec   = strtotime("$dateString $lunchEnd");
                $reqStartSec   = strtotime("$dateString $s");
                $reqEndSec     = strtotime("$dateString $e");

                $overlapStart = max($reqStartSec, $lunchStartSec);
                $overlapEnd   = min($reqEndSec, $lunchEndSec);

                if ($overlapStart < $overlapEnd) {
                    $dayHours -= (($overlapEnd - $overlapStart) / 3600);
                }
                $totalHours += $dayHours;
            }
        }
        $currDate->modify('+1 day');
    }
    return max(0, $totalHours);
}

/**
         * 加班時數計算：不自動扣除午休或假日，依據申請區間直接計算（由主管最終核准）
         */
        function calculateOvertimeHours($startStr, $endStr) {
            $start = strtotime($startStr);
            $end   = strtotime($endStr);
            if ($end <= $start) return 0;

            $totalSeconds = $end - $start;
            $totalHours = $totalSeconds / 3600;

            // 格式化：保留一位小數
            return max(0, round($totalHours, 1));
        }

// 定義勞基法「滿N年」的法定特休天數
function getLawDays($years) {
    if ($years >= 10) return min(15 + floor($years - 9), 30); // 滿10年加1天，上限30天
    if ($years >= 5)  return 15;
    if ($years >= 3)  return 14;
    if ($years >= 2)  return 10;
    if ($years >= 1)  return 7;
    if ($years >= 0.5) return 3; // 滿半年
    return 0;
}

// 特休天數計算：精確曆年制 (包含非1號到職的天數比例)
function calculateAnnualLeaveDays($hireDate, $targetYear = null) {
    if (!$targetYear) $targetYear = (int)date('Y');
    
    $hire = new DateTime($hireDate);
    $hireYear = (int)$hire->format('Y');
    $hireMonth = (int)$hire->format('n');
    $hireDay = (int)$hire->format('j');

    // 計算在目標年度時，會達成的年資
    $yearsOfService = $targetYear - $hireYear;

    // 若目標年度根本還沒到職，或第一年還未滿半年 (此處主要處理滿1年以上，滿半年曆年制較為特殊，若需精算可再擴充)
    if ($yearsOfService < 1) {
        return 0; 
    }

    // 取得法定天數
    $prevDays = getLawDays($yearsOfService - 1); // 前一次滿週年的假
    $currentDays = getLawDays($yearsOfService);  // 這次滿週年的假

    // === 計算「週年紀念日前」的時間比例 ===
    
    // 1. 完整月數 (例如 3/15 到職，前面有 1、2 月共 2 個完整月)
    $monthsBefore = $hireMonth - 1;

    // 2. 零星天數 (例如 3/15 到職，當月在週年前有 14 天)
    $daysBefore = $hireDay - 1;

    // 3. 取得該「到職月份」的總天數 (用來算天數比例，例如3月有31天)
    $daysInAnniversaryMonth = (int)date('t', strtotime("$targetYear-$hireMonth-01"));

    // 4. 計算佔全年的比例：(月數 + 天數/當月天數) / 12
    $ratioBefore = ($monthsBefore + ($daysBefore / $daysInAnniversaryMonth)) / 12;

    // === 套用勞動部曆年制公式 ===
    
    // 前段天數 ＝ 比例 * 去年年資假
    $part1 = $ratioBefore * $prevDays;
    
    // 後段天數 ＝ 今年年資假 - (比例 * 今年年資假)
    $part2 = $currentDays - ($ratioBefore * $currentDays);

    // 加總特休天數
    $totalDays = $part1 + $part2;

    // === 處理小數點進位規則 ===
    // 依截圖：「先計算至小數第2位，再判斷小數第2位若大於1則進位」
    // 這裡我們直接用 ceil 將數字乘以 10 後進位，再除以 10 (例如 9.51 -> 96 / 10 -> 9.6)
    $roundedDays = ceil(round($totalDays, 2) * 10) / 10;

    return $roundedDays;
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
    $pdo = Database::getConnection(); //
    $currentYear = date('Y'); //

    // 1. 從 users 表抓取：姓名、法定總額、特休餘額、補休餘額
    $stmt = $pdo->prepare("SELECT name, entitled_annual_hours, annual_leave_hours, comp_leave_hours FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 取得數據庫中的真實數值
    $entitledAnnual = floatval($user['entitled_annual_hours'] ?? 0); // 年度總額
    $remainingAnnual = floatval($user['annual_leave_hours'] ?? 0);   // 特休餘額
    $remainingComp   = floatval($user['comp_leave_hours'] ?? 0);     // 補休餘額

    // 2. 統計「已用」時數 (僅供 Flex Message 顯示參考)
    // 雖然我們可以透過 (總額 - 剩餘) 算出，但撈取假單紀錄能確保假別明細正確
    $stmt = $pdo->prepare("
        SELECT leave_type, COALESCE(SUM(leave_hours), 0) as hours
        FROM leave_requests
        WHERE user_id = ? AND status = 'approved'
          AND YEAR(start_at) = ?
        GROUP BY leave_type
    ");
    $stmt->execute([$userId, $currentYear]); //
    $leaveUsage = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); //

    // 已經請掉的特休 (顯示用)
    $usedAnnual = $entitledAnnual - $remainingAnnual;

    // 3. 補休總額計算 (今年累計獲得的加班時數)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(hours), 0)
        FROM overtime_requests
        WHERE user_id = ? AND status = 'approved'
          AND YEAR(start_at) = ?
    ");
    $stmt->execute([$userId, $currentYear]); //
    $earnedComp = floatval($stmt->fetchColumn());

    // 已請掉的補休
    $usedComp = $earnedComp - $remainingComp;

    // 4. 取得明細 (維持不變)
    $stmt = $pdo->prepare("
        SELECT start_at, end_at, leave_type, reason, status, leave_hours
        FROM leave_requests
        WHERE user_id = ? AND status = 'approved'
          AND YEAR(start_at) = ?
        ORDER BY start_at DESC 
    ");
    $stmt->execute([$userId, $currentYear]); //
    $allDetails = $stmt->fetchAll(PDO::FETCH_ASSOC); //

    return [
        "entitledAnnual"  => $entitledAnnual,  // 法定總額 (由資料庫欄位提供)
        "usedAnnual"      => $usedAnnual,      // 已請 (計算所得)
        "remainingAnnual" => $remainingAnnual, // 剩餘 (由資料庫欄位提供)
        
        "earnedComp"      => $earnedComp,      // 累計加班
        "usedComp"        => $usedComp,        // 已用補休
        "remainingComp"   => $remainingComp,   // 補休餘額
        
        "summary"         => $leaveUsage,
        "allDetails"      => $allDetails
    ];
}

/**
 * 產生商務風格的 Flex Message 結構
 * * @param string $topStatus  頂部小標 (如: APPROVED, SUCCESS)
 * @param string $mainTitle  主標題 (如: 補打卡核准通知)
 * @param array  $dataPairs  資料陣列 ['員工' => '王小明', '時間' => '09:00']
 * @param string $color      主色調 (預設 LINE Green: #06C755)
 * @return array Flex Message 內容物件
 */
function createBusinessFlex($topStatus, $mainTitle, $dataPairs, $color = '#06C755') {
    $rows = [];
    foreach ($dataPairs as $label => $value) {
        $rows[] = [
            "type" => "box",
            "layout" => "baseline",
            "spacing" => "sm",
            "contents" => [
                [
                    "type" => "text",
                    "text" => $label,
                    "color" => "#aaaaaa",
                    "size" => "sm",
                    "flex" => 2
                ],
                [
                    "type" => "text",
                    "text" => $value,
                    "wrap" => true,
                    "color" => "#666666",
                    "size" => "sm",
                    "flex" => 5
                ]
            ]
        ];
    }

    return [
        "type" => "flex",
        "altText" => $mainTitle, // 在聊天列表顯示的預覽文字
        "contents" => [
            "type" => "bubble",
            "size" => "giga", // 卡片寬度
            "body" => [
                "type" => "box",
                "layout" => "vertical",
                "contents" => [
                    [
                        "type" => "text",
                        "text" => strtoupper($topStatus),
                        "weight" => "bold",
                        "color" => $color,
                        "size" => "xs"
                    ],
                    [
                        "type" => "text",
                        "text" => $mainTitle,
                        "weight" => "bold",
                        "size" => "xl",
                        "margin" => "md"
                    ],
                    [
                        "type" => "separator",
                        "margin" => "xxl"
                    ],
                    [
                        "type" => "box",
                        "layout" => "vertical",
                        "margin" => "xxl",
                        "spacing" => "sm",
                        "contents" => $rows
                    ],
                    [
                        "type" => "separator",
                        "margin" => "xxl"
                    ],
                    [
                        "type" => "box",
                        "layout" => "vertical",
                        "margin" => "md",
                        "contents" => [
                            [
                                "type" => "text",
                                "text" => "系統自動發送・請勿直接回覆",
                                "size" => "xs",
                                "color" => "#bbbbbb",
                                "align" => "center"
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];
}

/**
 * 發送 Flex Message 的輔助函式 (改用 cURL，無需 Guzzle)
 */
function replyFlexMessage($replyToken, $flexContent) {
    $accessToken = getenv('LINE_CHANNEL_ACCESS_TOKEN');
    $url = 'https://api.line.me/v2/bot/message/reply';

    $postData = [
        'replyToken' => $replyToken,
        'messages'   => [$flexContent]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ]);

    $result = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("Reply Flex Error: " . $error);
    }
}

/**
 * 推播 Flex Message 的輔助函式 (改用 cURL，無需 Guzzle)
 */
function pushFlexMessage($userId, $flexContent) {
    $accessToken = getenv('LINE_CHANNEL_ACCESS_TOKEN');
    $url = 'https://api.line.me/v2/bot/message/push';

    $postData = [
        'to' => $userId,
        'messages' => [$flexContent]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ]);

    $result = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("Push Flex Error: " . $error);
    }
}

/**
 * 取得全域指令清單
 */
function generateOvertimeUuid(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

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