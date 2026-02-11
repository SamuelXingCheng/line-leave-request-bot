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
        
        if (isCommand($userText) && $userText !== "/加班") {
            $this->session->clearStep();
            return false;
        }

        if ($this->event['type'] === 'postback') {
            $data = $this->event['postback']['data'];
            parse_str($data, $params);

            if (isset($params['action'])) {
                if ($params['action'] === 'select_ot_date') return $this->handleDate($this->event['postback']['params']['date']);
                if ($params['action'] === 'select_ot_start') return $this->handleStartTime($this->event['postback']['params']['time']);
                if ($params['action'] === 'select_ot_end') return $this->handleEndTime($this->event['postback']['params']['time']);
            }
        }

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

        replyQuickReply($this->event['replyToken'], "請選擇或輸入加班日期：", [
            ["今日", $today],
            [
                "type" => "action",
                "action" => [
                    "type" => "datetimepicker",
                    "label" => "選擇日期",
                    "data" => "action=select_ot_date",
                    "mode" => "date"
                ]
            ],
            ["取消", "/取消"]
        ]);
        return true;
    }

    private function handleDate($text) {
        $this->session->set("ot_date", $text);
        $this->session->setStep("ot_start");
        
        replyQuickReply($this->event['replyToken'], "請選擇「開始」加班時間：", [[
            "type" => "action",
            "action" => ["type" => "datetimepicker", "label" => "選擇開始時間", "data" => "action=select_ot_start", "mode" => "time"]
        ]]);
        return true;
    }

    private function handleStartTime($text) {
        $this->session->set("ot_start", $text);
        $this->session->setStep("ot_end");
        
        replyQuickReply($this->event['replyToken'], "請選擇「結束」加班時間：", [[
            "type" => "action",
            "action" => ["type" => "datetimepicker", "label" => "選擇結束時間", "data" => "action=select_ot_end", "mode" => "time"]
        ]]);
        return true;
    }

    private function handleEndTime($text) {
        $this->session->set("ot_end", $text);
        $this->session->setStep("ot_reason");
        replyTextMessage($this->event['replyToken'], "請簡述加班原因或內容：");
        return true;
    }

    private function handleReason($text) {
        $date = $this->session->get("ot_date");
        $start = $this->session->get("ot_start");
        $end = $this->session->get("ot_end");
        $reason = $text;

        // 🔥 1. 計算加班時數 (含假日邏輯)
        $otHours = calculateOvertimeHours("$date $start", "$date $end");

        $uuid = uniqid("OT-");
        $stmt = $this->db->prepare("SELECT name FROM users WHERE user_id = ?");
        $stmt->execute([$this->lineId]);
        $name = $stmt->fetchColumn() ?: "員工";

        // 🔥 2. 存入 DB (包含 hours 欄位)
        $stmt = $this->db->prepare("
            INSERT INTO overtime_requests (overtime_uuid, user_id, user_name, start_at, end_at, hours, reason, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$uuid, $this->lineId, $name, "$date $start", "$date $end", $otHours, $reason]);

        // 🔥 3. 產生商務版審核連結 (使用 line://)
        $botId = getenv("LINE_BOT_ID"); 
        $approvalCommand = "/同意加班 {$uuid}";
        $approvalLink = "line://oaMessage/@{$botId}/?" . rawurlencode($approvalCommand);

        // 🔥 4. 第一則訊息：正式簽核通知 (無表情符號)
        $mainMsgText = "【加班簽核通知】\n" .
                       "────────────────\n" .
                       "申請人員｜{$name}\n" .
                       "加班時數｜{$otHours} 小時\n" .
                       "加班時段｜{$date} {$start} ~ {$end}\n" .
                       "加班內容｜{$reason}\n" .
                       "────────────────\n" .
                       "若同意申請，請點擊下方連結簽核：\n" .
                       $approvalLink;

        // 🔥 5. 第二則訊息：主管提示
        $supStmt = $this->db->prepare("SELECT supervisor_id FROM user_supervisors WHERE user_id = ?");
        $supStmt->execute([$this->lineId]);
        $supervisorIds = $supStmt->fetchAll(PDO::FETCH_COLUMN);

        $supervisorMsgText = "【系統提示】\n────────────────\n請將上方訊息轉傳給：";
        if (!empty($supervisorIds)) {
            $names = getSupervisorNames($supervisorIds);
            $supervisorMsgText .= "\n─ " . implode("\n─ ", $names);
        } else {
            $supervisorMsgText .= "\n尚未設定您的直屬主管，請聯繫管理員。";
        }

        $messages = [
            ['type' => 'text', 'text' => $mainMsgText],
            ['type' => 'text', 'text' => $supervisorMsgText]
        ];

        replyMessage($this->event['replyToken'], $messages);
        
        $this->session->clear();
        return true;
    }
}