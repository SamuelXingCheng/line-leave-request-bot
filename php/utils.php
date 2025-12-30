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
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData, JSON_UNESCAPED_UNICODE));
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("📡 LINE API response ($httpCode): " . $result);
    return $result;
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
        $line = "📅 {$req['start_date']} {$req['start_time']} ~ {$req['end_date']} {$req['end_time']}";
        $dateLines[] = $line;
    }
    $dateLinesStr = implode("\n", $dateLines);

    // 主訊息
    $forwardMsg = [
        "type" => "text",
        "text" =>
            "員工：{$userName}\n" .
            "假別：{$leaveType}\n" .
            "原因：{$reason}\n\n" .
            "以下時間需要請假：\n" .
            $dateLinesStr .
            "\n\n👉 點擊以下連結，系統將自動填入「/同意請假」指令，請直接送出即可完成簽核：\n" .
            $approvalLink .
            "\n\n若不同意，請口頭告知請假者即可，無需操作此連結。"
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
            "text" => "📌 請記得轉傳上方訊息給以下主管簽核：\n" . implode("\n", $lines)
        ];

        $messages[] = $hintMsg;
    }

    return $messages;
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
 * 取得請假統計 (含特休與補休，以及所有明細)
 */
function getLeaveSummary($userId) {
    $pdo = Database::getConnection();

    // 1. 特休計算
    $stmt = $pdo->prepare("SELECT start_date FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $hireDate = $stmt->fetchColumn();

    $entitledHours = 0;
    if ($hireDate) {
        $entitledDays = calculateAnnualLeaveDays($hireDate);
        $entitledHours = $entitledDays * 8;
    }

    // 2. 統計各假別已用時數
    $stmt = $pdo->prepare("
        SELECT leave_type,
               COALESCE(SUM(
                   TIMESTAMPDIFF(HOUR, start_at, end_at)
                   - CASE
                       WHEN TIME(start_at) < '12:00:00' AND TIME(end_at) > '13:00:00'
                       THEN 1 ELSE 0
                     END
               ), 0) as hours
        FROM leave_requests
        WHERE user_id = ? AND status='approved'
          AND YEAR(start_at) = YEAR(CURDATE())
        GROUP BY leave_type
    ");
    $stmt->execute([$userId]);
    $leaveUsage = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // ['特休'=>16, '事假'=>8, ...]

    // 特休
    $usedAnnual = $leaveUsage['特休'] ?? 0;
    $remainingAnnual = max(0, $entitledHours - $usedAnnual);

    // 3. 補休計算
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
                   TIMESTAMPDIFF(HOUR, start_at, end_at)
                   - CASE
                       WHEN TIME(start_at) < '12:00:00' AND TIME(end_at) > '13:00:00'
                       THEN 1 ELSE 0
                     END
               ), 0)
        FROM overtime_requests
        WHERE user_id = ? AND status = 'approved'
          AND YEAR(start_at) = YEAR(CURDATE())
    ");
    $stmt->execute([$userId]);
    $earnedComp = $stmt->fetchColumn();

    $usedComp = $leaveUsage['補休'] ?? 0;
    $remainingComp = max(0, $earnedComp - $usedComp);

    // 4. 🔥【修改】取得「所有假別」的請假明細 (不只特休)
    $stmt = $pdo->prepare("
        SELECT start_at, end_at, leave_type, reason, status
        FROM leave_requests
        WHERE user_id = ? AND status = 'approved'
          AND YEAR(start_at) = YEAR(CURDATE())
        ORDER BY start_at DESC 
    ");
    $stmt->execute([$userId]);
    $allDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        "entitledAnnual"  => $entitledHours,
        "usedAnnual"      => $usedAnnual,
        "remainingAnnual" => $remainingAnnual,
        
        "earnedComp"      => $earnedComp,
        "usedComp"        => $usedComp,
        "remainingComp"   => $remainingComp,
        
        "summary"         => $leaveUsage, // 各假別統計
        "allDetails"      => $allDetails  // 🔥 所有明細
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