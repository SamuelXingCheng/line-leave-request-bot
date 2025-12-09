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
        
        // 如果是文字訊息，取 text；如果是 Postback，text 為空字串
        $this->userText = $event['message']['text'] ?? '';
        $this->userText = trim($this->userText);
        
        $this->db       = Database::getConnection();
        $this->session  = new UserSession($lineId);
    }

    public function handle() {

        if (isCommand($this->userText) && $this->userText !== "/補打卡") {
            $this->session->clearStep(); 
            return false; 
        }
        
        // 1. 優先處理 Postback (日期/時間選擇器)
        if ($this->event['type'] === 'postback') {
            $data = $this->event['postback']['data'];
            parse_str($data, $params);

            // 📅 補打卡-選擇日期
            if (isset($params['action']) && $params['action'] === 'select_correction_date') {
                $selectedDate = $this->event['postback']['params']['date'];
                $this->userText = $selectedDate; 
                if ($this->session->getStep() === 'correction_date') {
                     return $this->processDateStep();
                }
            }

            // 🕒 補打卡-選擇時間
            if (isset($params['action']) && $params['action'] === 'select_correction_time') {
                $selectedTime = $this->event['postback']['params']['time'];
                $this->userText = $selectedTime;
                if ($this->session->getStep() === 'correction_time') {
                     return $this->processTimeStep();
                }
            }
        }

        $step = $this->session->getStep();

        // 檢查全域指令 (排除 "/補打卡")
        if (in_array($this->userText, getGlobalCommands()) && $this->userText !== "/補打卡") {
            $this->session->clearStep(); 
            return false; 
        }
        
        // 全域取消
        if ($this->userText === "/取消補打卡") {
            $this->session->clear();
            replyTextMessage($this->replyToken, "❌ 已取消補打卡流程");
            return true;
        }
        
        // Step 1: 啟動補打卡流程
        if (strpos($this->userText, "/補打卡") === 0) {
            $this->session->reset();
            $this->session->setStep("correction_date");

            $today = date("Y/m/d");
            $yesterday = date("Y/m/d", strtotime("-1 day"));

            replyQuickReply($this->replyToken, "📅 請選擇補打卡的日期：", [
                ["📆 今天 ($today)", $today],
                ["📆 昨天 ($yesterday)", $yesterday],
                [
                    "type" => "action",
                    "action" => [
                        "type" => "datetimepicker",
                        "label" => "✏️ 選擇日期",
                        "data" => "action=select_correction_date",
                        "mode" => "date"
                    ]
                ],
                ["❌ 取消補打卡", "/取消補打卡"]
            ]);
            return true;
        }

        // Step 2: 選擇日期
        if ($step === "correction_date") {
            return $this->processDateStep();
        }

        // Step 3: 選擇類型
        if ($step === "correction_type") {
            if (in_array($this->userText, ["上班", "下班"])) {
                $this->session->setStep("correction_time");
                $this->session->set("correction_type", $this->userText);

                replyQuickReply($this->replyToken, "請選擇補打卡時間或自訂輸入：", [
                    ["08:30", "08:30"],
                    ["12:00", "12:00"],
                    ["13:00", "13:00"],
                    ["17:30", "17:30"],
                    [
                        "type" => "action",
                        "action" => [
                            "type" => "datetimepicker",
                            "label" => "✏️ 選擇時間",
                            "data" => "action=select_correction_time",
                            "mode" => "time"
                        ]
                    ],
                    ["❌ 取消補打卡", "/取消補打卡"]
                ]);
            } else {
                replyTextMessage($this->replyToken, "❌ 請選擇有效的補打卡類型（上班或下班）。");
            }
            return true;
        }

        // Step 4: 輸入時間
        if ($step === "correction_time") {
            return $this->processTimeStep();
        }

        // Step 5: 選擇原因
        if ($step === "correction_reason") {
            // 🔥 修改：區分「忘記打卡」和「在外服事」
            if ($this->userText === "忘記打卡") {
                $this->session->setStep("correction_complete");
                $this->session->set("correction_reason", $this->userText);
            } elseif ($this->userText === "在外服事") {
                // 🔥 如果是在外服事，跳到新步驟詢問說明
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

        // 🔥 新增 Step 5.5: 處理服事說明
        if ($step === "correction_description") {
            // 將原因組合成：在外服事：XXX
            $reason = "在外服事：" . $this->userText;
            
            $this->session->setStep("correction_complete");
            $this->session->set("correction_reason", $reason);
        }

        // Step 6: 自訂原因 (純文字)
        if ($step === "custom_reason") {
            $this->session->setStep("correction_complete");
            $this->session->set("correction_reason", $this->userText);
        }

        // Step 7: 完成，存 DB
        if ($this->session->getStep() === "correction_complete") {
            $this->finishCorrection();
            return true;
        }

        return false;
    }

    // --- 輔助函式 ---

    private function processDateStep() {
        if ($this->userText === "自訂日期") {
            replyTextMessage($this->replyToken, "請點選按鈕選擇日期，或輸入：2025/07/16");
            return true;
        }

        $dateText = str_replace('-', '/', $this->userText); 
        $date = DateTime::createFromFormat("Y/m/d", $dateText);
        
        if (!$date) {
            replyTextMessage($this->replyToken, "❌ 日期格式錯誤，請重新輸入。");
            return true;
        }

        $this->session->setStep("correction_type");
        $this->session->set("correction_date", $date->format("Y-m-d"));

        replyQuickReply($this->replyToken, "🕒 請選擇補打卡的類型：", [
            ["上班", "上班"],
            ["下班", "下班"],
            ["❌ 取消補打卡", "/取消補打卡"]
        ]);
        return true;
    }

    private function processTimeStep() {
        if ($this->userText === "自訂時間") {
             replyTextMessage($this->replyToken, "請點選按鈕選擇時間，或輸入：08:30");
             return true;
        }

        $time = DateTime::createFromFormat("H:i", $this->userText);
        if (!$time) {
            replyTextMessage($this->replyToken, "❌ 時間格式錯誤，請重新輸入（例如：08:30）");
            return true;
        }

        $this->session->setStep("correction_reason");
        $this->session->set("correction_time", $this->userText);

        replyQuickReply($this->replyToken, "📋 請選擇補打卡原因：", [
            ["忘記打卡", "忘記打卡"],
            ["在外服事", "在外服事"],
            ["✏️ 自訂", "自訂原因"],
            ["❌ 取消補打卡", "/取消補打卡"]
        ]);
        return true;
    }

    private function finishCorrection() {
        $date   = $this->session->get("correction_date");
        $time   = $this->session->get("correction_time");
        $reason = $this->session->get("correction_reason");
        $type   = $this->session->get("correction_type");

        $uuid = $this->saveCorrection($date, $time, $type, $reason);

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
        $this->session->clear();
    }

    private function saveCorrection($date, $time, $type, $reason) {
        $uuid = $this->db->query("SELECT UUID()")->fetchColumn();

        $stmt = $this->db->prepare("
            INSERT INTO attendance_logs 
            (attendance_uuid, user_id, mode, latitude, longitude, distance, status, reason, approval_status, created_at)
            VALUES (?, ?, ?, NULL, NULL, NULL, 'pending', ?, 'pending', ?)
        ");

        $datetime = $date . ' ' . $time . ':00';
        $stmt->execute([$uuid, $this->lineId, $type, $reason, $datetime]);

        return $uuid;
    }
}