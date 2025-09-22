<?php
// CorrectionHandler.php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/utils.php';

class CorrectionHandler {
    private $lineId;
    private $userText;
    private $db;
    private $session;

    public function __construct($lineId, $userText) {
        $this->lineId   = $lineId;
        $this->userText = trim($userText);
        $this->db       = Database::getConnection();
        $this->session  = new UserSession($lineId);
    }

    public function handle($replyToken) {
        $step = $this->session->getStep();

        // 全域取消
        if ($this->userText === "/取消補打卡") {
            $this->session->clear();
            replyTextMessage($replyToken, "❌ 已取消補打卡流程");
            return true;
        }
        
        // Step 1: 啟動補打卡流程
        if (strpos($this->userText, "/補打卡") === 0) {
            $this->session->reset();
            $this->session->setStep("correction_date");

            $today = date("Y/m/d");
            $yesterday = date("Y/m/d", strtotime("-1 day"));

            replyQuickReply($replyToken, "📅 請選擇補打卡的日期：", [
                ["📆 今天 ($today)", $today],
                ["📆 昨天 ($yesterday)", $yesterday],
                ["✏️ 自訂日期", "自訂日期"],
                ["❌ 取消補打卡", "/取消補打卡"]
            ]);
            return true;
        }

        // Step 2: 選擇日期
        if ($step === "correction_date") {
            if ($this->userText === "自訂日期") {
                replyTextMessage($replyToken, "請輸入補打卡日期（例如：2025/07/16）：");
                return true;
            }

            $date = DateTime::createFromFormat("Y/m/d", $this->userText);
            if (!$date) {
                replyTextMessage($replyToken, "❌ 日期格式錯誤，請重新輸入。");
                return true;
            }

            $this->session->setStep("correction_type");
            $this->session->set("correction_date", $date->format("Y-m-d"));

            replyQuickReply($replyToken, "🕒 請選擇補打卡的類型：", [
                ["上班", "上班"],
                ["下班", "下班"],
                ["❌ 取消補打卡", "/取消補打卡"]
            ]);
            return true;
        }

        // Step 3: 選擇類型
        if ($step === "correction_type") {
            if (in_array($this->userText, ["上班", "下班"])) {
                $this->session->setStep("correction_time");
                $this->session->set("correction_type", $this->userText);

                replyQuickReply($replyToken, "請選擇補打卡時間或自訂輸入：", [
                    ["08:30", "08:30"],
                    ["12:00", "12:00"],
                    ["13:00", "13:00"],
                    ["17:30", "17:30"],
                    ["✏️ 自訂時間", "自訂時間"],
                    ["❌ 取消補打卡", "/取消補打卡"]
                ]);
            } else {
                replyTextMessage($replyToken, "❌ 請選擇有效的補打卡類型（上班或下班）。");
            }
            return true;
        }

        // Step 4: 輸入時間
        if ($step === "correction_time") {
            if ($this->userText === "自訂時間") {
                replyTextMessage($replyToken, "請輸入補打卡時間（例如：08:30）：");
                return true;
            }

            $time = DateTime::createFromFormat("H:i", $this->userText);
            if (!$time) {
                replyTextMessage($replyToken, "❌ 時間格式錯誤，請重新輸入（例如：08:30）");
                return true;
            }

            $this->session->setStep("correction_reason");
            $this->session->set("correction_time", $this->userText);

            replyQuickReply($replyToken, "📋 請選擇補打卡原因：", [
                ["忘記打卡", "忘記打卡"],
                ["在外服事", "在外服事"],
                ["✏️ 自訂", "自訂原因"],
                ["❌ 取消補打卡", "/取消補打卡"]
            ]);
            return true;
        }

        // Step 5: 選擇原因
        if ($step === "correction_reason") {
            if (in_array($this->userText, ["忘記打卡", "在外服事"])) {
                $this->session->setStep("correction_complete");
                $this->session->set("correction_reason", $this->userText);
            } elseif ($this->userText === "自訂原因") {
                $this->session->setStep("custom_reason");
                replyTextMessage($replyToken, "請輸入補打卡原因：");
                return true;
            } else {
                replyTextMessage($replyToken, "❌ 請選擇有效的補打卡原因。");
                return true;
            }
        }

        // Step 6: 自訂原因
        if ($step === "custom_reason") {
            $this->session->setStep("correction_complete");
            $this->session->set("correction_reason", $this->userText);
        }

        // Step 7: 完成，存 DB
        if ($this->session->getStep() === "correction_complete") {
            $date   = $this->session->get("correction_date");
            $time   = $this->session->get("correction_time");
            $reason = $this->session->get("correction_reason");
            $type   = $this->session->get("correction_type");

            // 儲存到 attendance_logs
            $uuid = $this->saveCorrection($date, $time, $type, $reason);

            // 統一使用 /審核打卡
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

            replyTextMessage($replyToken, $msg);
            $this->session->clear();
            return true;
        }

        return false;
    }

    private function saveCorrection($date, $time, $type, $reason) {
        $uuid = $this->db->query("SELECT UUID()")->fetchColumn();

        $stmt = $this->db->prepare("
            INSERT INTO attendance_logs 
            (attendance_uuid, user_id, mode, latitude, longitude, distance, status, reason, approval_status, created_at)
            VALUES (?, ?, ?, NULL, NULL, NULL, 'pending', ?, 'pending', ?)
        ");

        // 把補打卡的日期 + 時間，組合成 datetime
        $datetime = $date . ' ' . $time . ':00';

        $stmt->execute([$uuid, $this->lineId, $type, $reason, $datetime]);

        return $uuid;
    }
}
