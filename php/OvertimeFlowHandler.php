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
            [
                "type" => "action",
                "action" => [
                    "type" => "message",
                    "label" => "今日",
                    "text" => $today
                ]
            ],
            [
                "type" => "action",
                "action" => [
                    "type" => "datetimepicker",
                    "label" => "選擇日期",
                    "data" => "action=select_ot_date",
                    "mode" => "date"
                ]
            ],
            [
                "type" => "action",
                "action" => [
                    "type" => "message",
                    "label" => "取消",
                    "text" => "/取消"
                ]
            ]
        ]);
        return true;
    }

    private function handleDate($text) {
        $this->session->set("ot_date", $text);
        $this->session->setStep("ot_start");
        
        replyQuickReply($this->event['replyToken'], "請選擇「開始」加班時間：", [
            [
            "type" => "action",
                "action" => [
                    "type" => "datetimepicker",
                    "label" => "選擇開始時間",
                    "data" => "action=select_ot_start",
                    "mode" => "time"
                ]
            ]
        ]);
        return true;
    }

    private function handleStartTime($text) {
        $this->session->set("ot_start", $text);
        $this->session->setStep("ot_end");
        replyQuickReply($this->event['replyToken'], "請選擇「結束」加班時間：", [
            [
                "type" => "action",
                "action" => [
                    "type" => "datetimepicker",
                    "label" => "選擇結束時間",
                    "data" => "action=select_ot_end",
                    "mode" => "time"
                ]
            ]
        ]);
        return true;
    }

    private function handleEndTime($text) {
        $this->session->set("ot_end", $text);
        $this->session->setStep("ot_reason");
        replyTextMessage($this->event['replyToken'], "請簡述加班原因或內容：");
            return true;
    }

    private function getSupervisorNamesForUser($userId) {
        $stmt = $this->db->prepare("
            SELECT u.name
            FROM user_supervisors us
            JOIN users u ON u.user_id = us.supervisor_id
            WHERE us.user_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function handleReason($text) {
        $date = $this->session->get("ot_date");
        $start = $this->session->get("ot_start");
        $end = $this->session->get("ot_end");
        $reason = $text;

        $otHours = calculateOvertimeHours("$date $start", "$date $end");

        if ($otHours <= 0) {
            replyTextMessage($this->event['replyToken'], "加班時數計算結果異常（結束時間需晚於開始時間），請重新申請。");
            $this->session->clear();
            return true;
        }

        $startAt = "$date $start:00";
        $endAt   = "$date $end:00";

        try {
            // 🔥 1. 開啟交易防護
            $this->db->beginTransaction();

            // 🔥 2. 鎖定使用者資料列，防止併發重疊申請
            $stmt = $this->db->prepare("SELECT name FROM users WHERE user_id = ? FOR UPDATE");
            $stmt->execute([$this->lineId]);
            $name = $stmt->fetchColumn();

            if (!$name) {
                $this->db->rollBack();
                replyTextMessage($this->event['replyToken'], "⚠️ 系統找不到您的員工資料，請先聯繫管理員完成註冊。");
                $this->session->clear();
                return true;
            }

            // 🔥 3. 執行重疊防呆檢查
            $overlapStmt = $this->db->prepare("
                SELECT COUNT(*) FROM overtime_requests
                WHERE user_id = ?
                  AND status IN ('pending', 'approved')
                  AND start_at < ? AND end_at > ?
            ");
            $overlapStmt->execute([$this->lineId, $endAt, $startAt]);
            if ($overlapStmt->fetchColumn() > 0) {
                $this->db->rollBack();
                replyTextMessage($this->event['replyToken'], "⚠️ 申請失敗：您申請的時段與現有的加班單重疊，請確認後再送出。");
                $this->session->clear();
                return true;
            }

            $uuid = generateOvertimeUuid();

            $insStmt = $this->db->prepare("
                INSERT INTO overtime_requests (overtime_uuid, user_id, user_name, start_at, end_at, hours, reason, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $insStmt->execute([$uuid, $this->lineId, $name, $startAt, $endAt, $otHours, $reason]);
            // 🔥 4. 提交交易
            $this->db->commit();

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("OvertimeFlowHandler INSERT error: " . $e->getMessage());
            replyTextMessage($this->event['replyToken'], "申請寫入失敗，請稍後再試。");
            $this->session->clear();
            return true;
        }

        $liffId = getenv("MENU_LIFF_ID");
        $approvalLink = "https://liff.line.me/{$liffId}/?page=supervisor&tab=overtime&highlight={$uuid}";

        $mainMsgText = "【加班簽核通知】\n" .
                    "────────────────\n" .
                    "申請人員｜{$name}\n" .
                    "加班時數｜{$otHours} 小時\n" .
                    "加班時段｜{$date} {$start} ~ {$end}\n" .
                    "加班內容｜{$reason}\n" .
                    "────────────────\n" .
                    "請點選下方連結進入審核中心簽核：\n" .
                    $approvalLink;

        $supervisorNames = $this->getSupervisorNamesForUser($this->lineId);

        $supStr = !empty($supervisorNames) ? implode("、", $supervisorNames) : "系統管理員 (未設定主管)";
        $hintMsg = createBusinessFlex(
            "SYSTEM", "系統提示", 
            ["簽核主管" => $supStr, "後續動作" => "請將上方申請單「轉傳」給簽核主管"], 
            "#17A2B8"
        );

        $messages = [
            // 將原本的純文字主訊息包裝回去，搭配新的卡片提示
            ['type' => 'text', 'text' => $mainMsgText], 
            $hintMsg
        ];

        replyMessage($this->event['replyToken'], $messages);
        
        $this->session->clear();
        return true;
    }
}
