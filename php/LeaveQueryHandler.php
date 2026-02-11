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
                        // 1. 標題區塊
                        [
                            "type" => "text",
                            "text" => "【年度休假概況】", // 去除 📊，改用【】
                            "weight" => "bold",
                            "size" => "xl",
                            "color" => "#333333", // 改深灰色
                            "margin" => "md"
                        ],
                        ["type" => "separator", "margin" => "md"],

                        // 2. 特休區塊
                        [
                            "type" => "text", 
                            "text" => "特休假｜Annual Leave", // 去除 🌴，加上英文或直線裝飾
                            "weight" => "bold", 
                            "size" => "sm", 
                            "color" => "#999999",
                            "margin" => "lg"
                        ],
                        // 數據欄位微調：左右對齊的排版會更有質感，這裡維持直列但調整字體
                        ["type" => "text", "text" => "• 應得天數：" . formatHoursAndDays($stats['entitledAnnual']), "size" => "sm", "margin" => "sm", "color" => "#555555"],
                        ["type" => "text", "text" => "• 已用天數：" . formatHoursAndDays($stats['usedAnnual']), "size" => "sm", "color" => "#555555"],
                        ["type" => "text", "text" => "• 剩餘天數：" . formatHoursAndDays($stats['remainingAnnual']), "size" => "sm", "color" => "#1DB446", "weight" => "bold"], // 綠色強調
                        
                        // 3. 補休區塊
                        ["type" => "separator", "margin" => "lg"],
                        [
                            "type" => "text", 
                            "text" => "加班補休｜Compensatory Leave", // 去除 💤
                            "weight" => "bold", 
                            "size" => "sm", 
                            "color" => "#999999",
                            "margin" => "lg"
                        ],
                        ["type" => "text", "text" => "• 累積時數：" . formatHoursAndDays($stats['earnedComp']), "size" => "sm", "margin" => "sm", "color" => "#555555"],
                        ["type" => "text", "text" => "• 已用時數：" . formatHoursAndDays($stats['usedComp']), "size" => "sm", "color" => "#555555"],
                        ["type" => "text", "text" => "• 可用時數：" . formatHoursAndDays($stats['remainingComp']), "size" => "sm", "color" => "#1DB446", "weight" => "bold"],
                    ]
                ]
            ];

            // 3. 其他假別區塊 (有資料才顯示)
            $hasOther = false;
            foreach ($stats['summary'] as $type => $hours) {
                if ($type !== '特休' && $type !== '補休' && $hours > 0) {
                    if (!$hasOther) {
                        $contents["body"]["contents"][] = ["type" => "separator", "margin" => "lg"];
                        // 去除 📌
                        $contents["body"]["contents"][] = ["type" => "text", "text" => "其他已用假別｜Others", "weight" => "bold", "size" => "sm", "color" => "#999999", "margin" => "lg"];
                        $hasOther = true;
                    }
                    $contents["body"]["contents"][] = ["type" => "text", "text" => "• {$type}：" . formatHoursAndDays($hours), "size" => "sm", "color" => "#555555", "margin" => "sm"];
                }
            }

            // 4. 明細列表 (🔥 限制顯示最近 10 筆)
            $contents["body"]["contents"][] = ["type" => "separator", "margin" => "lg"];
            // 去除 📋
            $contents["body"]["contents"][] = ["type" => "text", "text" => "近期請假明細", "weight" => "bold", "size" => "md", "margin" => "md", "color" => "#333333"];

            if ($stats['allDetails']) {
                $count = 0;
                foreach ($stats['allDetails'] as $row) {
                    if ($count >= 10) break;
                    
                    $actualDays = getActualLeaveDays($row['start_at'], $row['end_at']);
                    
                    if (empty($actualDays)) {
                        $dateText = "（無需請假，全為假日）"; // 去除 ⚠️
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
                                "text" => "【{$row['leave_type']}】", 
                                "weight" => "bold",
                                "size" => "sm", 
                                "color" => "#333333"
                            ],
                            [
                                "type" => "text", 
                                "text" => $dateText, 
                                "size" => "xs",
                                "color" => "#666666",
                                "wrap" => true
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
                ["type" => "action", "action" => ["type" => "message", "label" => "查詢上月", "text" => "查上個月"]],
                ["type" => "action", "action" => ["type" => "message", "label" => "查詢本月", "text" => "查本月"]],
                ["type" => "action", "action" => ["type" => "message", "label" => "查詢今年", "text" => "查今年"]],
                ["type" => "action", "action" => ["type" => "message", "label" => "自訂區間", "text" => "自訂查詢"]],
                ["type" => "action", "action" => ["type" => "message", "label" => "關閉選單", "text" => "/取消查詢"]],
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
                replyTextMessage($this->replyToken, "已關閉查詢功能。"); // 去除 "已取消查詢"
                return true;
            } else {
                // 去除 ❌
                replyTextMessage($this->replyToken, "【系統提示】選項無效，請點選下方按鈕或輸入 /取消查詢");
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
                replyTextMessage($this->replyToken, "【格式錯誤】請使用格式：2025/07/01-2025/07/15");
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
        $contents = [
            "type" => "bubble",
            "body" => [
                "type" => "box",
                "layout" => "vertical",
                "contents" => [
                    [
                        "type" => "text",
                        "text" => "請假紀錄一覽", // 簡潔標題
                        "weight" => "bold",
                        "size" => "xl",
                        "color" => "#333333",
                        "margin" => "md"
                    ],
                    ["type" => "separator", "margin" => "md"]
                ]
            ]
        ];
        foreach ($rows as $row) {
            // 狀態顯示：不依賴 Emoji，改用文字顏色
            $statusMap = [
                "pending" => ["審核中", "#FF9800"], // 橘色
                "approved" => ["已核准", "#4CAF50"], // 綠色
                "rejected" => ["已駁回", "#F44336"]  // 紅色
            ];
            [$statusText, $statusColor] = $statusMap[$row['status']] ?? [$row['status'], "#999999"];

            $actualDays = getActualLeaveDays($row['start_at'], $row['end_at']);
            $dateText = empty($actualDays) ? "無工作日 (全為假日)" : implode("\n", $actualDays);

            $item = [
                "type" => "box",
                "layout" => "vertical",
                "margin" => "lg",
                "spacing" => "sm",
                "contents" => [
                    // 第一行：假別 + 狀態 (左右對齊)
                    [
                        "type" => "box",
                        "layout" => "baseline",
                        "contents" => [
                            [
                                "type" => "text",
                                "text" => $row['leave_type'],
                                "weight" => "bold",
                                "size" => "md",
                                "color" => "#333333",
                                "flex" => 1
                            ],
                            [
                                "type" => "text",
                                "text" => $statusText,
                                "size" => "sm",
                                "color" => $statusColor,
                                "align" => "end",
                                "flex" => 0
                            ]
                        ]
                    ],
                    // 第二行：日期細項 (灰色細字)
                    [
                        "type" => "text",
                        "text" => $dateText,
                        "wrap" => true,
                        "size" => "sm",
                        "color" => "#666666",
                        "lineSpacing" => "2px" // 增加行距提升閱讀感
                    ]
                ]
            ];

            // 刪除按鈕：改為簡潔文字
            if ($row['status'] === "pending") {
                $item["contents"][] = [
                    "type" => "box",
                    "layout" => "horizontal",
                    "margin" => "md",
                    "contents" => [
                        [
                            "type" => "text",
                            "text" => "❌ 撤回申請", // 這裡保留一個常用的撤回符號，或是改成純文字 "撤回"
                            "size" => "xs",
                            "color" => "#999999",
                            "action" => [
                                "type" => "message",
                                "label" => "撤回",
                                "text" => "/刪除請假 " . $row['request_group_id']
                            ],
                            "align" => "end"
                        ]
                    ]
                ];
            }
            
            $item["contents"][] = ["type" => "separator", "margin" => "md"];
            $contents["body"]["contents"][] = $item;
        }
        
        return ["type" => "flex", "altText" => "請假紀錄", "contents" => $contents];
    }
}