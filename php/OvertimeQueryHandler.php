<?php
// OvertimeQueryHandler.php
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

class OvertimeQueryHandler {
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
        // 通用判斷：如果是指令，且不是 "/查詢加班" 本身，就中斷
        if (isCommand($this->userText) && $this->userText !== "/查詢加班") {
            $this->session->clearStep();
            return false; 
        }

        // Step 1: 啟動查詢流程
        if ($this->userText === "/查詢加班") {
            $this->session->reset();
            $this->session->setStep("ot_query_range");

            $message = [
                "type" => "text",
                "text" => "請選擇要查詢的加班紀錄範圍：",
                "quickReply" => [
                    "items" => [
                        ["type" => "action", "action" => ["type" => "message", "label" => "📅 本月", "text" => "加班本月"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "⏳ 待審核", "text" => "加班待審核"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "📋 今年", "text" => "加班今年"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "❌ 取消", "text" => "/取消查詢加班"]],
                    ]
                ]
            ];

            replyMessage($this->replyToken, $message);
            return true;
        }

        // Step 2: 處理查詢範圍
        if ($this->session->getStep() === "ot_query_range") {
            $today = new DateTimeImmutable("today");
            $startDate = null;
            $endDate = null;
            $statusFilter = null; // 用來過濾 pending

            if ($this->userText === "加班本月") {
                $startDate = $today->modify('first day of this month');
                $endDate   = $today->modify('last day of this month');
            } elseif ($this->userText === "加班今年") {
                $startDate = $today->modify('first day of january');
                $endDate   = $today->modify('last day of december');
            } elseif ($this->userText === "加班待審核") {
                $statusFilter = 'pending';
                // 待審核通常查最近一年即可
                $startDate = $today->modify('-1 year');
                $endDate   = $today->modify('+1 year');
            } elseif ($this->userText === "/取消查詢加班") {
                $this->session->clear();
                replyTextMessage($this->replyToken, "已取消查詢加班紀錄");
                return true;
            } else {
                replyTextMessage($this->replyToken, "❌ 指令無效，請重新選擇。");
                return true;
            }

            $this->queryAndReply($startDate, $endDate, $statusFilter);
            $this->session->clearStep();
            return true;
        }

        return false;
    }

    private function queryAndReply($startDate, $endDate, $statusFilter = null) {
        $sql = "
            SELECT overtime_uuid, start_at, end_at, reason, status, created_at
            FROM overtime_requests
            WHERE user_id = ?
              AND start_at >= ? AND end_at <= ?
        ";
        $params = [$this->lineId, $startDate->format("Y-m-d 00:00:00"), $endDate->format("Y-m-d 23:59:59")];

        if ($statusFilter) {
            $sql .= " AND status = ?";
            $params[] = $statusFilter;
        }

        $sql .= " ORDER BY start_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
        if (!$rows) {
            replyTextMessage($this->replyToken, "📭 查無加班紀錄");
            return;
        }
    
        $flexMessage = $this->buildFlexMessage($rows);
        replyMessage($this->replyToken, $flexMessage);
    }
    
    private function buildFlexMessage($rows) {
        $contents = [
            "type" => "bubble",
            "body" => [
                "type" => "box",
                "layout" => "vertical",
                "contents" => [
                    ["type" => "text", "text" => "💪 加班紀錄", "weight" => "bold", "size" => "lg", "margin" => "md"],
                    ["type" => "separator", "margin" => "md"]
                ]
            ]
        ];
    
        foreach ($rows as $row) {
            // 狀態顏色
            $statusMap = [
                "pending"  => ["待審核", "#CC0000", "#FFFACD"], // 文字紅，底色黃
                "approved" => ["已核准", "#228B22", "#FFFFFF"], // 文字綠，底色白
                "rejected" => ["已駁回", "#999999", "#F0F0F0"], // 文字灰，底色灰
            ];
            [$statusText, $statusColor, $bgColor] = $statusMap[$row['status']] ?? [$row['status'], "#555555", "#FFFFFF"];
    
            // 計算時數
            $start = new DateTime($row['start_at']);
            $end   = new DateTime($row['end_at']);
            $diff  = $start->diff($end);
            $hours = $diff->h + ($diff->i / 60);

            // 組裝卡片 Item
            $item = [
                "type" => "box",
                "layout" => "vertical",
                "margin" => "md",
                "spacing" => "sm",
                "backgroundColor" => $bgColor,
                "cornerRadius" => "md",
                "paddingAll" => "10px",
                "contents" => [
                    [
                        "type" => "text", 
                        "text" => $start->format("m/d H:i") . " ~ " . $end->format("H:i") . " (" . number_format($hours, 1) . "h)", 
                        "size" => "sm", 
                        "color" => "#111111", 
                        "weight" => "bold"
                    ],
                    ["type" => "text", "text" => "內容: " . ($row['reason'] ?: "無"), "size" => "sm", "color" => "#555555"],
                    ["type" => "text", "text" => "狀態: " . $statusText, "size" => "sm", "color" => $statusColor],
                ]
            ];

            // 如果是待審核 (pending)，加上刪除按鈕
            if ($row['status'] === "pending") {
                $item["contents"][] = [
                    "type" => "separator", 
                    "margin" => "sm"
                ];
                $item["contents"][] = [
                    "type" => "button",
                    "style" => "secondary",
                    "height" => "sm",
                    "action" => [
                        "type" => "message", 
                        "label" => "🗑️ 刪除", 
                        "text" => "/刪除加班 " . $row['overtime_uuid']
                    ],
                    "margin" => "sm"
                ];
            }

            $contents["body"]["contents"][] = $item;
        }
    
        return ["type" => "flex", "altText" => "💪 加班紀錄", "contents" => $contents];
    }
}