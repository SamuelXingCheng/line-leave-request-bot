<?php
// CorrectionHandler.php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/utils.php';

class CorrectionHandler {
    private $lineId;
    private $event;
    private $userText;
    private $db;
    private $session;
    private $replyToken;

    public function __construct($lineId, $event) {
        $this->lineId     = $lineId;
        $this->event      = $event;
        $this->replyToken = $event['replyToken'];
        
        $this->userText = $event['message']['text'] ?? '';
        $this->userText = trim($this->userText);
        
        $this->db       = Database::getConnection();
        $this->session  = new UserSession($lineId);
    }

    public function handle() {
        // 通用指令判斷
        if (isCommand($this->userText) && $this->userText !== "/補打卡") {
            $this->session->clearStep(); 
            return false; 
        }
        
        // Postback 處理
        if ($this->event['type'] === 'postback') {
            $data = $this->event['postback']['data'];
            parse_str($data, $params);

            if (isset($params['action'])) {
                if ($params['action'] === 'select_correction_date') {
                    $this->userText = $this->event['postback']['params']['date']; 
                    if ($this->session->getStep() === 'correction_date') return $this->processDateStep();
                }
                if ($params['action'] === 'select_correction_time') {
                    $this->userText = $this->event['postback']['params']['time'];
                    if ($this->session->getStep() === 'correction_time') return $this->processTimeStep();
                }
            }
        }

        $step = $this->session->getStep();

        if ($this->userText === "/取消補打卡") {
            $this->session->clear();
            replyTextMessage($this->replyToken, "❌ 已取消補打卡流程");
            return true;
        }
        
        if (strpos($this->userText, "/補打卡") === 0) {
            $this->session->reset();
            $this->session->setStep("correction_date");
            $today = date("Y/m/d");
            $yesterday = date("Y/m/d", strtotime("-1 day"));

            replyQuickReply($this->replyToken, "📅 請選擇補打卡的日期：", [
                ["📆 今天 ($today)", $today],
                ["📆 昨天 ($yesterday)", $yesterday],
                ["type" => "action", "action" => ["type" => "datetimepicker", "label" => "✏️ 選擇日期", "data" => "action=select_correction_date", "mode" => "date"]],
                ["❌ 取消補打卡", "/取消補打卡"]
            ]);
            return true;
        }

        if ($step === "correction_date") return $this->processDateStep();
        
        if ($step === "correction_type") {
            if (in_array($this->userText, ["上班", "下班"])) {
                $this->session->setStep("correction_time");
                $this->session->set("correction_type", $this->userText);
                replyQuickReply($this->replyToken, "請選擇補打卡時間或自訂輸入：", [
                    ["08:30", "08:30"], ["12:00", "12:00"], ["13:00", "13:00"], ["17:30", "17:30"],
                    ["type" => "action", "action" => ["type" => "datetimepicker", "label" => "✏️ 選擇時間", "data" => "action=select_correction_time", "mode" => "time"]],
                    ["❌ 取消補打卡", "/取消補打卡"]
                ]);
            } else {
                replyTextMessage($this->replyToken, "❌ 請選擇有效的補打卡類型（上班或下班）。");
            }
            return true;
        }

        if ($step === "correction_time") return $this->processTimeStep();

        if ($step === "correction_reason") {
            if ($this->userText === "忘記打卡") {
                $this->session->setStep("correction_complete");
                $this->session->set("correction_reason", $this->userText);
            } elseif ($this->userText === "在外服事") {
                $this->session->setStep("correction_description");
                replyTextMessage($this->replyToken, "請輸入服事內容說明：\n(例如：探訪聖徒、參加聚會)");
                return true;
            } elseif ($this->userText === "自訂原因") {
                $this->session->setStep("custom_reason");
                replyTextMessage($this->replyToken, "請輸入補打卡原因：");
                return true;
            } else {
                replyTextMessage($this->replyToken, "❌ 請選擇有效的補打卡原因。");
                return true;
            }
        }

        if ($step === "correction_description") {
            $reason = "在外服事：" . $this->userText;
            $this->session->setStep("correction_complete");
            $this->session->set("correction_reason", $reason);
        }

        if ($step === "custom_reason") {
            $this->session->setStep("correction_complete");
            $this->session->set("correction_reason", $this->userText);
        }

        if ($this->session->getStep() === "correction_complete") {
            $this->finishCorrection();
            return true;
        }

        return false;
    }

    // --- 輔助函式 ---

    private function processDateStep() {
        if ($this->userText === "自訂日期") {
            replyTextMessage($this->replyToken, "請點選按鈕選擇日期");
            return true;
        }
        $dateText = str_replace('-', '/', $this->userText); 
        $date = DateTime::createFromFormat("Y/m/d", $dateText);
        if (!$date) {
            replyTextMessage($this->replyToken, "❌ 日期格式錯誤。");
            return true;
        }
        $this->session->setStep("correction_type");
        $this->session->set("correction_date", $date->format("Y-m-d"));
        replyQuickReply($this->replyToken, "🕒 請選擇補打卡的類型：", [
            ["上班", "上班"], ["下班", "下班"], ["❌ 取消補打卡", "/取消補打卡"]
        ]);
        return true;
    }

    private function processTimeStep() {
        if ($this->userText === "自訂時間") {
             replyTextMessage($this->replyToken, "請點選按鈕選擇時間");
             return true;
        }
        $time = DateTime::createFromFormat("H:i", $this->userText);
        if (!$time) {
            replyTextMessage($this->replyToken, "❌ 時間格式錯誤。");
            return true;
        }
        $this->session->setStep("correction_reason");
        $this->session->set("correction_time", $this->userText);
        replyQuickReply($this->replyToken, "📋 請選擇補打卡原因：", [
            ["忘記打卡", "忘記打卡"], ["在外服事", "在外服事"], ["✏️ 自訂", "自訂原因"], ["❌ 取消補打卡", "/取消補打卡"]
        ]);
        return true;
    }

    private function finishCorrection() {
        $date   = $this->session->get("correction_date");
        $time   = $this->session->get("correction_time");
        $reason = $this->session->get("correction_reason");
        $type   = $this->session->get("correction_type");

        // 🔥 1. 檢查是否為 Boss
        $stmt = $this->db->prepare("SELECT role FROM users WHERE user_id = ?");
        $stmt->execute([$this->lineId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $isBoss = ($row && $row['role'] === 'boss');

        // 🔥 2. 決定狀態
        $approvalStatus = $isBoss ? 'approved' : 'pending';

        // 3. 存檔 (傳入狀態)
        $uuid = $this->saveCorrection($date, $time, $type, $reason, $approvalStatus);

        // 4. 回覆訊息
        if ($isBoss) {
            // Boss 直接核准
            $msg = "✅ 補打卡已自動核准歸檔。\n"
                 . "日期：{$date}\n"
                 . "類型：{$type}\n"
                 . "時間：{$time}\n"
                 . "原因：{$reason}";
            replyTextMessage($this->replyToken, $msg);
        } else {
            // 一般員工：產生審核連結
            $approvalCommand = "/審核打卡 {$uuid}";
            $botId = getenv("LINE_BOT_ID"); 
            $encoded = rawurlencode($approvalCommand);
            $approvalLink = "https://line.me/R/oaMessage/@{$botId}/?{$encoded}";

            $msg = "📌 已提交補打卡申請\n\n"
                 . "日期：{$date}\n"
                 . "類型：{$type}\n"
                 . "時間：{$time}\n"
                 . "原因：{$reason}\n\n"
                 . "👉 主管審核連結：\n{$approvalLink}";

            replyTextMessage($this->replyToken, $msg);
        }

        $this->session->clear();
    }

    private function saveCorrection($date, $time, $type, $reason, $approvalStatus) {
        $uuid = $this->db->query("SELECT UUID()")->fetchColumn();

        $stmt = $this->db->prepare("
            INSERT INTO attendance_logs 
            (attendance_uuid, user_id, mode, latitude, longitude, distance, status, reason, approval_status, created_at)
            VALUES (?, ?, ?, NULL, NULL, NULL, 'pending', ?, ?, ?)
        ");

        $datetime = $date . ' ' . $time . ':00';
        // 🔥 注意：這裡多了一個參數 $approvalStatus
        $stmt->execute([$uuid, $this->lineId, $type, $reason, $approvalStatus, $datetime]);

        return $uuid;
    }
}