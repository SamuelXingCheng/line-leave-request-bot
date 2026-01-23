<?php
// LeaveQueryHandler.php
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

class LeaveQueryHandler {
    private $lineId;
    private $db;
    private $session;
    private $userText;
    private $replyToken;

    public function __construct($lineId, $userText, $replyToken) {
        $this->lineId     = $lineId;
        $this->db         = Database::getConnection();
        $this->session    = new UserSession($lineId);
        $this->userText   = trim($userText);
        $this->replyToken = $replyToken;
    }

    public function handle() {
        if (isCommand($this->userText) && $this->userText !== "/查詢請假") {
            $this->session->clearStep();
            return false; 
        }
        
        // 🔥 合併邏輯：輸入指令直接顯示統計 + 明細 (預設顯示)
        if ($this->userText === "/查詢請假" || $this->userText === "查剩餘休假") {
            $this->session->reset();
            $this->session->setStep("query_range"); // 設為查詢狀態，以便接收後續按鈕指令

            $stats = getLeaveSummary($this->lineId);

            // --- 組裝 Flex Message (儀表板) ---
            $contents = [
                "type" => "bubble",
                "body" => [
                    "type" => "box",
                    "layout" => "vertical",
                    "contents" => [
                        // 1. 特休區塊
                        [
                            "type" => "text",
                            "text" => "📊 年度休假統計",
                            "weight" => "bold",
                            "size" => "xl",
                            "color" => "#1DB446",
                            "margin" => "md"
                        ],
                        ["type" => "separator", "margin" => "md"],
                        [
                            "type" => "text", 
                            "text" => "🌴 特休假", 
                            "weight" => "bold", 
                            "size" => "md", 
                            "margin" => "md"
                        ],
                        ["type" => "text", "text" => "應得：" . formatHoursAndDays($stats['entitledAnnual']), "size" => "sm", "margin" => "sm"],
                        ["type" => "text", "text" => "已用：" . formatHoursAndDays($stats['usedAnnual']), "size" => "sm", "color" => "#CC0000"],
                        ["type" => "text", "text" => "剩餘：" . formatHoursAndDays($stats['remainingAnnual']), "size" => "sm", "color" => "#228B22", "weight" => "bold"],
                        
                        // 2. 補休區塊
                        ["type" => "separator", "margin" => "lg"],
                        [
                            "type" => "text", 
                            "text" => "💤 加班補休", 
                            "weight" => "bold", 
                            "size" => "md", 
                            "margin" => "md"
                        ],
                        ["type" => "text", "text" => "累積：" . formatHoursAndDays($stats['earnedComp']), "size" => "sm", "margin" => "sm"],
                        ["type" => "text", "text" => "已休：" . formatHoursAndDays($stats['usedComp']), "size" => "sm", "color" => "#CC0000"],
                        ["type" => "text", "text" => "可休：" . formatHoursAndDays($stats['remainingComp']), "size" => "sm", "color" => "#228B22", "weight" => "bold"],
                    ]
                ]
            ];

            // 3. 其他假別區塊 (有資料才顯示)
            $hasOther = false;
            foreach ($stats['summary'] as $type => $hours) {
                if ($type !== '特休' && $type !== '補休' && $hours > 0) {
                    if (!$hasOther) {
                        $contents["body"]["contents"][] = ["type" => "separator", "margin" => "lg"];
                        $contents["body"]["contents"][] = ["type" => "text", "text" => "📌 其他已用假別", "weight" => "bold", "size" => "md", "margin" => "md"];
                        $hasOther = true;
                    }
                    $contents["body"]["contents"][] = ["type" => "text", "text" => "• {$type}：" . formatHoursAndDays($hours), "size" => "sm", "color" => "#555555", "margin" => "sm"];
                }
            }

            // 4. 明細列表 (🔥 限制顯示最近 10 筆)
            $contents["body"]["contents"][] = ["type" => "separator", "margin" => "lg"];
            $contents["body"]["contents"][] = ["type" => "text", "text" => "📋 近期請假明細", "weight" => "bold", "size" => "md", "margin" => "md"];

            if ($stats['allDetails']) {
                $count = 0;
                foreach ($stats['allDetails'] as $row) {
                    if ($count >= 10) break; // ✅ 這裡控制了長度，避免年底太長
                    
                    // 🔥 呼叫 utils.php 的函式，取得展開後的日期列表 (會自動扣除 holiday)
                    $actualDays = getActualLeaveDays($row['start_at'], $row['end_at']);
                    
                    // 把陣列變成字串 (用換行符號連接)
                    if (empty($actualDays)) {
                        $dateText = "⚠️ 無需請假 (全為假日)";
                    } else {
                        $dateText = implode("\n", $actualDays);
                    }
                    
                    $contents["body"]["contents"][] = [
                        "type" => "box",
                        "layout" => "vertical",
                        "margin" => "sm",
                        "contents" => [
                            [
                                "type" => "text", 
                                "text" => "【{$row['leave_type']}】", // 標題只放假別
                                "weight" => "bold",
                                "size" => "sm", 
                                "color" => "#333333"
                            ],
                            [
                                "type" => "text", 
                                "text" => $dateText, // 🔥 這裡放入展開後的日期
                                "size" => "xs",      // 字體稍微縮小一點以免太佔版面
                                "color" => "#666666",
                                "wrap" => true       // ⚠️ 重要：要開啟換行功能
                            ]
                        ]
                    ];
                    $count++;
                }
                // 如果超過 10 筆，顯示提示
                if (count($stats['allDetails']) > 10) {
                    $contents["body"]["contents"][] = [
                        "type" => "text", 
                        "text" => "... (僅顯示最近 10 筆，完整紀錄請點選下方按鈕)", 
                        "size" => "xs", 
                        "color" => "#999999", 
                        "align" => "center",
                        "margin" => "md"
                    ];
                }
            } else {
                $contents["body"]["contents"][] = ["type" => "text", "text" => "（今年尚無請假紀錄）", "size" => "sm", "color" => "#999999", "margin" => "md"];
            }

            // 🔥 準備 Quick Reply (保留挑選時段功能)
            $quickReplyItems = [
                ["type" => "action", "action" => ["type" => "message", "label" => "📆 查上個月", "text" => "查上個月"]],
                ["type" => "action", "action" => ["type" => "message", "label" => "📊 查本月", "text" => "查本月"]],
                ["type" => "action", "action" => ["type" => "message", "label" => "📋 查今年", "text" => "查今年"]], // ✅ 加回這顆按鈕
                ["type" => "action", "action" => ["type" => "message", "label" => "✏️ 自訂區間", "text" => "自訂查詢"]],
                ["type" => "action", "action" => ["type" => "message", "label" => "❌ 關閉", "text" => "/取消查詢"]],
            ];

            // 發送 Flex Message 帶 Quick Reply
            $url = "https://api.line.me/v2/bot/message/reply";
            $headers = ["Content-Type: application/json", "Authorization: Bearer " . getenv("LINE_CHANNEL_ACCESS_TOKEN")];
            $postData = [
                "replyToken" => $this->replyToken,
                "messages" => [[
                    "type" => "flex",
                    "altText" => "📊 年度休假統計",
                    "contents" => $contents,
                    "quickReply" => ["items" => $quickReplyItems]
                ]]
            ];
            callLineAPI($url, $headers, $postData);
            
            return true;
        }

        // Step 2: 處理查詢範圍 (這些邏輯保留，供按鈕點擊後使用)
        if ($this->session->getStep() === "query_range") {
            $today = new DateTimeImmutable("today");

            if ($this->userText === "查今天") {
                $startDate = $endDate = $today;
            } elseif ($this->userText === "查上個月") {
                $startDate = $today->modify('first day of last month');
                $endDate   = $today->modify('last day of last month');
            } elseif ($this->userText === "查本月") {
                $startDate = $today->modify('first day of this month');
                $endDate   = $today->modify('last day of this month');
            } elseif ($this->userText === "查今年") {
                $startDate = $today->modify('first day of january');
                $endDate   = $today->modify('last day of december');
            } elseif ($this->userText === "自訂查詢") {
                $this->session->setStep("custom_query");
                replyTextMessage($this->replyToken, "請輸入起訖日期（格式：2025/07/01-2025/07/15）");
                return true;
            } elseif ($this->userText === "/取消查詢") {
                $this->session->clear();
                replyTextMessage($this->replyToken, "已取消查詢");
                return true;
            } else {
                replyTextMessage($this->replyToken, "❌ 無效選項，請點選下方按鈕或輸入 /取消查詢");
                return true;
            }

            $this->session->set("last_query_start", $startDate->format("Y-m-d"));
            $this->session->set("last_query_end", $endDate->format("Y-m-d"));

            $this->queryAndReply($startDate, $endDate);
            $this->session->clearStep();
            return true;
        }

        // Step 3: 自訂區間
        if ($this->session->getStep() === "custom_query") {
            try {
                [$startStr, $endStr] = explode("-", $this->userText);
                $startDate = new DateTimeImmutable(trim($startStr));
                $endDate   = new DateTimeImmutable(trim($endStr));
            } catch (Exception $e) {
                replyTextMessage($this->replyToken, "❌ 日期格式錯誤，請用 2025/07/01-2025/07/15");
                return true;
            }

            $this->queryAndReply($startDate, $endDate);
            $this->session->clearStep();
            return true;
        }

        return false;
    }

    public function handleWithDateRange(DateTimeImmutable $startDate, DateTimeImmutable $endDate, $prefixMessage = null) {
        $stmt = $this->db->prepare("SELECT request_group_id, start_at, end_at, leave_type, status FROM leave_requests WHERE user_id = ? AND start_at >= ? AND end_at <= ? ORDER BY start_at DESC");
        $stmt->execute([$this->lineId, $startDate->format("Y-m-d 00:00:00"), $endDate->format("Y-m-d 23:59:59")]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!$rows) { replyTextMessage($this->replyToken, "📭 此區間內沒有請假紀錄"); return; }
        
        $flexMessage = $this->buildFlexMessage($rows);
        
        if ($prefixMessage) { 
            replyMessage($this->replyToken, [["type" => "text", "text" => $prefixMessage], $flexMessage]); 
        } else { 
            replyMessage($this->replyToken, $flexMessage); 
        }
    }
    
    private function queryAndReply($startDate, $endDate) {
        $stmt = $this->db->prepare("SELECT request_group_id, start_at, end_at, leave_type, status FROM leave_requests WHERE user_id = ? AND start_at >= ? AND end_at <= ? ORDER BY start_at DESC");
        $stmt->execute([$this->lineId, $startDate->format("Y-m-d 00:00:00"), $endDate->format("Y-m-d 23:59:59")]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!$rows) { replyTextMessage($this->replyToken, "❌ 此區間內沒有請假紀錄"); return; }
        
        $flexMessage = $this->buildFlexMessage($rows);
        replyMessage($this->replyToken, $flexMessage);
    }

    // ✅ 抽出共用的訊息組裝
    private function buildFlexMessage($rows) {
        $contents = ["type" => "bubble", "body" => ["type" => "box", "layout" => "vertical", "contents" => [["type" => "text", "text" => "📋 請假紀錄", "weight" => "bold", "size" => "lg", "margin" => "md"], ["type" => "separator", "margin" => "md"]]]];
        foreach ($rows as $row) {
            $statusMap = ["pending" => ["尚未核准", "#999999"], "approved" => ["已通過", "#228B22"], "rejected" => ["已駁回", "#CC0000"]];
            [$statusText, $statusColor] = $statusMap[$row['status']] ?? [$row['status'], "#555555"];
            
            $actualDays = getActualLeaveDays($row['start_at'], $row['end_at']);
            $dateText = empty($actualDays) ? "⚠️ 全為假日" : implode("\n", $actualDays);

            $item = [
                "type" => "box", "layout" => "vertical", "margin" => "md", "spacing" => "sm",
                "contents" => [
                    // 🔥 這裡改成顯示展開後的日期
                    [
                        "type" => "text", 
                        "text" => $dateText, 
                        "wrap" => true, // ⚠️ 重要：必須開啟換行
                        "size" => "sm", 
                        "color" => "#555555"
                    ],
                    ["type" => "box", "layout" => "baseline", "spacing" => "sm", "contents" => [["type" => "text", "text" => $row['leave_type'], "size" => "sm", "color" => "#111111", "flex" => 2], ["type" => "text", "text" => "狀態: " . $statusText, "size" => "sm", "color" => $statusColor, "flex" => 3]]]
                ]
            ];
            
            if ($row['status'] === "pending") {
                $item["contents"][] = ["type" => "button", "style" => "secondary", "height" => "sm", "action" => ["type" => "message", "label" => "刪除", "text" => "/刪除請假 " . $row['request_group_id']], "margin" => "md"];
            }
            $item["contents"][] = ["type" => "separator", "margin" => "md"];
            $contents["body"]["contents"][] = $item;
        }
        return ["type" => "flex", "altText" => "📋 請假紀錄", "contents" => $contents];
    }
}