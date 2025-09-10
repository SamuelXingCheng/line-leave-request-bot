<?php
// LeaveQueryHandler.php
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/Db.php';

class LeaveQueryHandler {
    private $lineId;
    private $db;
    private $session;
    private $userText;

    public function __construct($lineId, $userText) {
        $this->lineId = $lineId;
        $this->db = Database::getConnection();
        $this->session = new UserSession($lineId);
        $this->userText = trim($userText);
    }

    public function handle($replyToken) {
        // Step 1: 啟動查詢流程
        if ($this->userText === "/查詢請假") {
            $this->session->reset();
            $this->session->setStep("query_range");

            $message = [
                "type" => "text",
                "text" => "請選擇要查詢的範圍：",
                "quickReply" => [
                    "items" => [
                        ["type" => "action", "action" => ["type" => "message", "label" => "📅 今天", "text" => "查今天"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "📆 上個月", "text" => "查上個月"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "📊 本月", "text" => "查本月"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "📋 今年", "text" => "查今年"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "✏️ 自訂區間", "text" => "自訂查詢"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "❌ 取消", "text" => "/取消查詢"]],
                    ]
                ]
            ];

            replyMessage($replyToken, $message);
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
                replyTextMessage($replyToken, "請輸入起訖日期（格式：2025/07/01-2025/07/15）");
                return true;
            } elseif ($this->userText === "/取消查詢") {
                $this->session->clear();
                replyTextMessage($replyToken, "已取消查詢");
                return true;
            } else {
                replyTextMessage($replyToken, "❌ 指令無效，請重新選擇。");
                return true;
            }

            $this->queryAndReply($replyToken, $startDate, $endDate);
            $this->session->clear();
            return true;
        }

        // Step 3: 自訂區間
        if ($this->session->getStep() === "custom_query") {
            try {
                [$startStr, $endStr] = explode("-", $this->userText);
                $startDate = new DateTimeImmutable(trim($startStr));
                $endDate   = new DateTimeImmutable(trim($endStr));
            } catch (Exception $e) {
                replyTextMessage($replyToken, "❌ 日期格式錯誤，請用 2025/07/01-2025/07/15");
                return true;
            }

            $this->queryAndReply($replyToken, $startDate, $endDate);
            $this->session->clear();
            return true;
        }

        return false;
    }

    private function queryAndReply($replyToken, $startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT id, start_at, end_at, leave_type, status
            FROM leave_requests
            WHERE user_id = ? AND start_at >= ? AND end_at <= ?
            ORDER BY start_at DESC
        ");
        $stmt->execute([$this->lineId, $startDate->format("Y-m-d 00:00:00"), $endDate->format("Y-m-d 23:59:59")]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            replyTextMessage($replyToken, "❌ 此區間內沒有請假紀錄");
            return;
        }

        // 建立 Flex Message
        $contents = [
            "type" => "bubble",
            "body" => [
                "type" => "box",
                "layout" => "vertical",
                "contents" => [
                    [
                        "type" => "text",
                        "text" => "📋 請假紀錄",
                        "weight" => "bold",
                        "size" => "lg",
                        "margin" => "md"
                    ],
                    ["type" => "separator", "margin" => "md"]
                ]
            ]
        ];

        foreach ($rows as $row) {
            // 狀態文字 & 顏色對應
            $statusMap = [
                "pending"  => ["尚未核准", "#999999"], // 灰色
                "approved" => ["已通過", "#228B22"],   // 綠色
                "rejected" => ["已駁回", "#CC0000"]    // 紅色
            ];
        
            if (isset($statusMap[$row['status']])) {
                [$statusText, $statusColor] = $statusMap[$row['status']];
            } else {
                $statusText  = $row['status'];
                $statusColor = "#555555"; // 預設灰
            }
        
            $item = [
                "type" => "box",
                "layout" => "vertical",
                "margin" => "md",
                "spacing" => "sm",
                "contents" => [
                    [
                        "type" => "text",
                        "text" => sprintf("%s ~ %s",
                            substr($row['start_at'], 0, 16),
                            substr($row['end_at'], 0, 16)
                        ),
                        "wrap" => true,
                        "size" => "sm",
                        "color" => "#555555"
                    ],
                    [
                        "type" => "box",
                        "layout" => "baseline",
                        "spacing" => "sm",
                        "contents" => [
                            [
                                "type" => "text",
                                "text" => $row['leave_type'],
                                "size" => "sm",
                                "color" => "#111111",
                                "flex" => 2
                            ],
                            [
                                "type" => "text",
                                "text" => "狀態: " . $statusText,
                                "size" => "sm",
                                "color" => $statusColor, // ✅ 顏色依狀態變化
                                "flex" => 3
                            ]
                        ]
                    ],
                    ["type" => "separator", "margin" => "md"]
                ]
            ];
            $contents["body"]["contents"][] = $item;
        }          

        $flexMessage = [
            "type" => "flex",
            "altText" => "📋 請假紀錄",
            "contents" => $contents
        ];

        replyMessage($replyToken, $flexMessage);
    }
}
