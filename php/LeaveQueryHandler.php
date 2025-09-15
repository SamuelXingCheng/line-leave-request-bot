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
        // Step 1: 啟動查詢流程
        if ($this->userText === "/查詢請假") {
            $this->session->reset();
            $this->session->setStep("query_range");

            $message = [
                "type" => "text",
                "text" => "請選擇要查詢的範圍：",
                "quickReply" => [
                    "items" => [
                        ["type" => "action", "action" => ["type" => "message", "label" => "📅 剩餘休假時數", "text" => "查剩餘休假"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "📅 今天", "text" => "查今天"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "📆 上個月", "text" => "查上個月"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "📊 本月", "text" => "查本月"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "📋 今年", "text" => "查今年"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "✏️ 自訂區間", "text" => "自訂查詢"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "❌ 取消", "text" => "/取消查詢"]],
                    ]
                ]
            ];

            replyMessage($this->replyToken, $message);
            return true;
        }

        // Step X: 查詢剩餘休假
        if ($this->userText === "查剩餘休假") {
            $stats = getLeaveSummary($this->lineId);

            $contents = [
                "type" => "bubble",
                "body" => [
                    "type" => "box",
                    "layout" => "vertical",
                    "contents" => [
                        [
                            "type" => "text",
                            "text" => "📊 特休統計（今年）",
                            "weight" => "bold",
                            "size" => "lg",
                            "margin" => "md"
                        ],
                        ["type" => "separator", "margin" => "md"],
                        ["type" => "text", "text" => "應得：" . formatHoursAndDays($stats['entitledAnnual']), "size" => "sm"],
                        ["type" => "text", "text" => "已用：" . formatHoursAndDays($stats['usedAnnual']), "size" => "sm", "color" => "#CC0000"],
                        ["type" => "text", "text" => "剩餘：" . formatHoursAndDays($stats['remainingAnnual']), "size" => "sm", "color" => "#228B22"],
                        ["type" => "separator", "margin" => "md"],
                        ["type" => "text", "text" => "📅 特休紀錄", "weight" => "bold", "size" => "md", "margin" => "md"]
                    ]
                ]
            ];

            if ($stats['annualDetails']) {
                foreach ($stats['annualDetails'] as $row) {
                    $contents["body"]["contents"][] = [
                        "type" => "box",
                        "layout" => "vertical",
                        "margin" => "sm",
                        "contents" => [
                            ["type" => "text", "text" => substr($row['start_at'],0,16) . " ~ " . substr($row['end_at'],0,16), "size" => "sm", "color" => "#555555"],
                            ["type" => "text", "text" => "原因: " . ($row['reason'] ?: "未填寫"), "size" => "xs", "color" => "#111111"]
                        ]
                    ];
                }
            } else {
                $contents["body"]["contents"][] = [
                    "type" => "text",
                    "text" => "（今年尚未使用特休）",
                    "size" => "sm",
                    "color" => "#999999",
                    "margin" => "md"
                ];
            }

            replyMessage($this->replyToken, [
                "type" => "flex",
                "altText" => "📊 特休統計（今年）",
                "contents" => $contents
            ]);
            return true;
        }

        // Step 2: 處理查詢範圍
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
                replyTextMessage($this->replyToken, "❌ 指令無效，請重新選擇。");
                return true;
            }

            $this->session->set("last_query_start", $startDate->format("Y-m-d"));
            $this->session->set("last_query_end", $endDate->format("Y-m-d"));

            $this->queryAndReply($startDate, $endDate);
            $this->session->setStep(null);
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
            $this->session->clear();
            return true;
        }

        return false;
    }

    // ✅ 新增給 DeleteHandler 用的方法
    public function handleWithDateRange(DateTimeImmutable $startDate, DateTimeImmutable $endDate, $prefixMessage = null) {
        error_log("handleWithDateRange start=" . $startDate->format("Y-m-d") . " end=" . $endDate->format("Y-m-d"));
    
        $stmt = $this->db->prepare("
            SELECT request_group_id, start_at, end_at, leave_type, status
            FROM leave_requests
            WHERE user_id = ? AND start_at >= ? AND end_at <= ?
            ORDER BY start_at DESC
        ");
        $stmt->execute([
            $this->lineId,
            $startDate->format("Y-m-d 00:00:00"),
            $endDate->format("Y-m-d 23:59:59")
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
        error_log("handleWithDateRange rows=" . json_encode($rows));
    
        if (!$rows) {
            replyTextMessage($this->replyToken, "📭 此區間內沒有請假紀錄");
            return;
        }
    
        $flexMessage = $this->buildFlexMessage($rows);
    
        if ($prefixMessage) {
            replyMessage($this->replyToken, [
                ["type" => "text", "text" => $prefixMessage],
                $flexMessage
            ]);
        } else {
            replyMessage($this->replyToken, $flexMessage);
        }
    }
    

    private function queryAndReply($startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT request_group_id, start_at, end_at, leave_type, status
            FROM leave_requests
            WHERE user_id = ? AND start_at >= ? AND end_at <= ?
            ORDER BY start_at DESC
        ");
        $stmt->execute([$this->lineId, $startDate->format("Y-m-d 00:00:00"), $endDate->format("Y-m-d 23:59:59")]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            replyTextMessage($this->replyToken, "❌ 此區間內沒有請假紀錄");
            return;
        }

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
                    ["type" => "text", "text" => "📋 請假紀錄", "weight" => "bold", "size" => "lg", "margin" => "md"],
                    ["type" => "separator", "margin" => "md"]
                ]
            ]
        ];

        foreach ($rows as $row) {
            $statusMap = [
                "pending"  => ["尚未核准", "#999999"],
                "approved" => ["已通過", "#228B22"],
                "rejected" => ["已駁回", "#CC0000"]
            ];
            [$statusText, $statusColor] = $statusMap[$row['status']] ?? [$row['status'], "#555555"];

            $item = [
                "type" => "box",
                "layout" => "vertical",
                "margin" => "md",
                "spacing" => "sm",
                "contents" => [
                    ["type" => "text", "text" => sprintf("%s ~ %s", substr($row['start_at'], 0, 16), substr($row['end_at'], 0, 16)), "wrap" => true, "size" => "sm", "color" => "#555555"],
                    [
                        "type" => "box",
                        "layout" => "baseline",
                        "spacing" => "sm",
                        "contents" => [
                            ["type" => "text", "text" => $row['leave_type'], "size" => "sm", "color" => "#111111", "flex" => 2],
                            ["type" => "text", "text" => "狀態: " . $statusText, "size" => "sm", "color" => $statusColor, "flex" => 3]
                        ]
                    ]
                ]
            ];

            if ($row['status'] === "pending") {
                $item["contents"][] = [
                    "type" => "button",
                    "style" => "secondary",
                    "height" => "sm",
                    "action" => ["type" => "message", "label" => "刪除", "text" => "/刪除請假 " . $row['request_group_id']],
                    "margin" => "md"
                ];
            }

            $item["contents"][] = ["type" => "separator", "margin" => "md"];
            $contents["body"]["contents"][] = $item;
        }

        return ["type" => "flex", "altText" => "📋 請假紀錄", "contents" => $contents];
    }
}
