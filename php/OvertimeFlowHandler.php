<?php
// OvertimeFlowHandler.php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/Session.php';

class OvertimeFlowHandler {
    private $lineId;
    private $event;
    private $session;
    private $db;

    public function __construct($lineId, $event, $db) {
        $this->lineId = $lineId;
        $this->event = $event;
        $this->session = new UserSession($lineId, $db);
        $this->db = $db;
    }

    public function handle() {

        $userText = $this->event['message']['text'] ?? '';
        
        // 🔥【修改這段】通用判斷：如果是指令，且不是 "/加班" 本身，就中斷
        if (isCommand($userText) && $userText !== "/加班") {
            $this->session->clearStep();
            return false;
        }

        // 1. 處理 Postback (日期/時間選擇器)
        if ($this->event['type'] === 'postback') {
            $data = $this->event['postback']['data'];
            parse_str($data, $params);

            if (isset($params['action'])) {
                if ($params['action'] === 'select_ot_date') return $this->handleDate($this->event['postback']['params']['date']);
                if ($params['action'] === 'select_ot_start') return $this->handleStartTime($this->event['postback']['params']['time']);
                if ($params['action'] === 'select_ot_end') return $this->handleEndTime($this->event['postback']['params']['time']);
            }
        }

        $userText = $this->event['message']['text'] ?? '';
        

        $step = $this->session->getStep();

        if ($userText === "/加班") {
            return $this->startFlow();
        } elseif ($step === "ot_date") {
            return $this->handleDate($userText);
        } elseif ($step === "ot_start") {
            return $this->handleStartTime($userText);
        } elseif ($step === "ot_end") {
            return $this->handleEndTime($userText);
        } elseif ($step === "ot_reason") {
            return $this->handleReason($userText);
        }

        return false;
    }

    private function startFlow() {
        $this->session->reset();
        $this->session->setStep("ot_date");
        $today = date("Y-m-d");

        replyQuickReply($this->event['replyToken'], "💪 加班辛苦了！請選擇加班日期：", [
            ["📆 今天", $today],
            [
                "type" => "action",
                "action" => [
                    "type" => "datetimepicker",
                    "label" => "✏️ 選擇日期",
                    "data" => "action=select_ot_date",
                    "mode" => "date"
                ]
            ],
            ["❌ 取消", "/取消"]
        ]);
        return true;
    }

    private function handleDate($text) {
        $this->session->set("ot_date", $text);
        $this->session->setStep("ot_start");
        
        replyQuickReply($this->event['replyToken'], "🕒 請選擇「開始」加班時間：", [[
            "type" => "action",
            "action" => ["type" => "datetimepicker", "label" => "選擇開始時間", "data" => "action=select_ot_start", "mode" => "time"]
        ]]);
        return true;
    }

    private function handleStartTime($text) {
        $this->session->set("ot_start", $text);
        $this->session->setStep("ot_end");
        
        replyQuickReply($this->event['replyToken'], "🕒 請選擇「結束」加班時間：", [[
            "type" => "action",
            "action" => ["type" => "datetimepicker", "label" => "選擇結束時間", "data" => "action=select_ot_end", "mode" => "time"]
        ]]);
        return true;
    }

    private function handleEndTime($text) {
        $this->session->set("ot_end", $text);
        $this->session->setStep("ot_reason");
        replyTextMessage($this->event['replyToken'], "📝 請輸入加班原因/內容：");
        return true;
    }

    private function handleReason($text) {
        $date = $this->session->get("ot_date");
        $start = $this->session->get("ot_start");
        $end = $this->session->get("ot_end");
        $reason = $text;

        // 存入 DB
        $uuid = uniqid("OT-");
        $stmt = $this->db->prepare("SELECT name FROM users WHERE user_id = ?");
        $stmt->execute([$this->lineId]);
        $name = $stmt->fetchColumn() ?: "未知";

        $stmt = $this->db->prepare("
            INSERT INTO overtime_requests (overtime_uuid, user_id, user_name, start_at, end_at, reason, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$uuid, $this->lineId, $name, "$date $start", "$date $end", $reason]);

        // 產生審核連結
        $botId = getenv("LINE_BOT_ID"); 
        $approvalCommand = "/同意加班 {$uuid}";
        $encoded = rawurlencode($approvalCommand);
        $approvalLink = "https://line.me/R/oaMessage/@{$botId}/?{$encoded}";

        // 1️⃣ 第一則訊息：申請詳情 + 連結
        $mainMsgText = "✅ 加班申請已送出！\n" .
                       "👤 員工：{$name}\n" .
                       "📅 日期：{$date}\n" .
                       "🕒 時間：{$start} ~ {$end}\n" .
                       "📝 原因：{$reason}\n\n" .
                       "👉 點擊以下連結，系統將自動填入指令，請直接送出即可完成簽核：\n" .
                       $approvalLink . "\n\n" .
                       "若不同意，請口頭告知申請者即可，無需操作此連結。";

        // 2️⃣ 第二則訊息：主管名單 (提醒轉傳)
        $supStmt = $this->db->prepare("SELECT supervisor_id FROM user_supervisors WHERE user_id = ?");
        $supStmt->execute([$this->lineId]);
        $supervisorIds = $supStmt->fetchAll(PDO::FETCH_COLUMN);

        $supervisorMsgText = "";
        if (!empty($supervisorIds)) {
            $names = getSupervisorNames($supervisorIds);
            $supervisorMsgText = "📌 請記得轉傳上方訊息給以下主管簽核：\n- " . implode("\n- ", $names);
        } else {
            $supervisorMsgText = "⚠️ 系統未設定您的主管，請通知管理員。";
        }

        // 組合成陣列，一次發送兩則
        $messages = [
            ['type' => 'text', 'text' => $mainMsgText],
            ['type' => 'text', 'text' => $supervisorMsgText]
        ];

        // 使用 replyMessage 發送多則訊息
        replyMessage($this->event['replyToken'], $messages);
        
        $this->session->clear();
        return true;
    }
}