<?php
// AttendanceQueryHandler.php
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

class AttendanceQueryHandler {
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
        // 如果輸入了全域指令 → 清掉 step 並交給其他 Handler
        if (in_array($this->userText, getGlobalCommands()) && $this->userText !== "/查詢打卡") {
            $this->session->clearStep();
            return false; 
        }

        // Step 1: 啟動查詢流程
        if ($this->userText === "/查詢打卡") {
            $this->session->reset();
            $this->session->setStep("attendance_query_range");

            $message = [
                "type" => "text",
                "text" => "請選擇要查詢的打卡紀錄範圍：",
                "quickReply" => [
                    "items" => [
                        ["type" => "action", "action" => ["type" => "message", "label" => "📅 今天", "text" => "打卡今天"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "📆 上個月", "text" => "打卡上個月"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "📊 本月", "text" => "打卡本月"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "📋 今年", "text" => "打卡今年"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "✏️ 自訂區間", "text" => "打卡自訂"]],
                        ["type" => "action", "action" => ["type" => "message", "label" => "❌ 取消", "text" => "/取消查詢打卡"]],
                    ]
                ]
            ];

            replyMessage($this->replyToken, $message);
            return true;
        }

        // Step 2: 處理查詢範圍
        if ($this->session->getStep() === "attendance_query_range") {
            $today = new DateTimeImmutable("today");

            if ($this->userText === "打卡今天") {
                $startDate = $endDate = $today;
            } elseif ($this->userText === "打卡上個月") {
                $startDate = $today->modify('first day of last month');
                $endDate   = $today->modify('last day of last month');
            } elseif ($this->userText === "打卡本月") {
                $startDate = $today->modify('first day of this month');
                $endDate   = $today->modify('last day of this month');
            } elseif ($this->userText === "打卡今年") {
                $startDate = $today->modify('first day of january');
                $endDate   = $today->modify('last day of december');
            } elseif ($this->userText === "打卡自訂") {
                $this->session->setStep("attendance_custom_query");
                replyTextMessage($this->replyToken, "請輸入起訖日期（格式：2025/07/01-2025/07/15）");
                return true;
            } elseif ($this->userText === "/取消查詢打卡") {
                $this->session->clear();
                replyTextMessage($this->replyToken, "已取消查詢打卡紀錄");
                return true;
            } else {
                replyTextMessage($this->replyToken, "❌ 指令無效，請重新選擇。");
                return true;
            }

            $this->queryAndReply($startDate, $endDate);
            $this->session->clearStep();
            return true;
        }

        // Step 3: 自訂區間
        if ($this->session->getStep() === "attendance_custom_query") {
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

    private function queryAndReply($startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT attendance_uuid, mode, status, reason, approval_status, created_at
            FROM attendance_logs
            WHERE user_id = ?
              AND created_at BETWEEN ? AND ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([
            $this->lineId,
            $startDate->format("Y-m-d 00:00:00"),
            $endDate->format("Y-m-d 23:59:59")
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
        if (!$rows) {
            replyTextMessage($this->replyToken, "📭 此區間內沒有打卡紀錄");
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
                    ["type" => "text", "text" => "📋 打卡紀錄", "weight" => "bold", "size" => "lg", "margin" => "md"],
                    ["type" => "separator", "margin" => "md"]
                ]
            ]
        ];
    
        $weekdayMap = ["日","一","二","三","四","五","六"];
    
        foreach ($rows as $row) {
            // 狀態翻譯
            $statusMap = [
                "success" => ["公司打卡", "#555555"],
                "fail"    => ["外地打卡", "#555555"],
                "pending" => ["待確認", "#555555"],
            ];
            [$statusText, $statusColor] = $statusMap[$row['status']] ?? [$row['status'], "#555555"];
    
            // 審核翻譯
            $approvalMap = [
                "pending"  => ["待審核", "#CC0000"], // 紅字
                "approved" => ["已核准", "#228B22"], // 綠字
                "rejected" => ["已駁回", "#CC0000"], // 紅字
                "normal"   => ["正常", "#555555"],   // 公司打卡
            ];
            [$approvalText, $approvalColor] = $approvalMap[$row['approval_status']] ?? [$row['approval_status'], "#555555"];
    
            // 背景顏色邏輯 → 只要未審核就黃卡
            $bgColor = (empty($row['approval_status']) || $row['approval_status'] === "pending") 
                ? "#FFFACD"   // 黃色
                : "#FFFFFF";  // 白色
    
            // 週幾顯示
            $dt = new DateTimeImmutable($row['created_at']);
            $weekday = "週" . $weekdayMap[(int)$dt->format("w")];
    
            // 組裝卡片
            $contents["body"]["contents"][] = [
                "type" => "box",
                "layout" => "vertical",
                "margin" => "md",
                "spacing" => "sm",
                "backgroundColor" => $bgColor,
                "cornerRadius" => "md",
                "paddingAll" => "10px",
                "contents" => [
                    ["type" => "text", "text" => $dt->format("m/d H:i") . " " . $weekday . " " . $row['mode'], "size" => "sm", "color" => "#111111", "weight" => "bold"],
                    ["type" => "text", "text" => "來源: " . $statusText, "size" => "sm", "color" => $statusColor],
                    ["type" => "text", "text" => "原因: " . ($row['reason'] ?: "無"), "size" => "sm", "color" => "#555555"],
                    ["type" => "text", "text" => "審核: " . $approvalText, "size" => "sm", "color" => $approvalColor],
                ]
            ];
        }
    
        return ["type" => "flex", "altText" => "📋 打卡紀錄", "contents" => $contents];
    }
    
}
