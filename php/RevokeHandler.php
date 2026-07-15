<?php
// php/RevokeHandler.php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/Session.php';

class RevokeHandler {
    private $lineId;
    private $db;
    private $session;
    private $event;
    private $replyToken;

    public function __construct($lineId, $event) {
        $this->lineId = $lineId;
        $this->event = $event;
        $this->replyToken = $event['replyToken'];
        $this->db = Database::getConnection();
        $this->session = new UserSession($lineId, $this->db);
    }

    public function handle() {
        if ($this->event['type'] === 'postback') {
            $data = $this->event['postback']['data'];
            parse_str($data, $params);

            if (isset($params['action']) && $params['action'] === 'revoke_select_leave') {
                $this->askModificationType($params['id']);
                return true;
            }
            if (isset($params['action']) && $params['action'] === 'revoke_select_type') {
                $this->askDate($params['leave_id'], $params['mod_type']);
                return true;
            }
            if (isset($params['action']) && $params['action'] === 'revoke_confirm_date') {
                $selectedDate = $this->event['postback']['params']['datetime'] ?? $this->event['postback']['params']['date'];
                $this->createRequest($params['leave_id'], $params['mod_type'], $selectedDate);
                return true;
            }
        }

        $text = $this->event['message']['text'] ?? '';
        if ($text === '/修改請假' || $text === '/銷假') {
            $this->listActiveLeaves();
            return true;
        }

        return false;
    }

    private function listActiveLeaves() {
        $stmt = $this->db->prepare("
            SELECT * FROM leave_requests 
            WHERE user_id = ? 
              AND status IN ('approved', 'pending') 
              AND end_at > DATE_SUB(NOW(), INTERVAL 30 DAY) 
            ORDER BY start_at DESC 
        ");
        $stmt->execute([$this->lineId]);
        $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$leaves) {
            replyTextMessage($this->replyToken, "您目前沒有可修改的假單（僅顯示最近 30 天內的紀錄）。");
            return;
        }

        $bubbles = [];
        foreach ($leaves as $row) {
            $start = substr($row['start_at'], 5, 11);
            $end = substr($row['end_at'], 5, 11);
            $isExpired = (strtotime($row['end_at']) < time());
            $statusLabel = $isExpired ? " (已過期)" : " (進行中/未來)";
            $statusColor = $isExpired ? "#999999" : "#1DB446";

            $bubbles[] = [
                "type" => "bubble",
                "body" => [
                    "type" => "box", "layout" => "vertical",
                    "contents" => [
                        ["type" => "text", "text" => "{$row['leave_type']}{$statusLabel}", "weight" => "bold", "color" => $statusColor],
                        ["type" => "text", "text" => "$start ~ $end", "size" => "sm", "margin" => "sm"],
                    ]
                ],
                "footer" => [
                    "type" => "box", "layout" => "vertical",
                    "contents" => [
                        [
                            "type" => "button", "style" => "primary", "height" => "sm",
                            "action" => ["type" => "postback", "label" => "修改/銷假", "data" => "action=revoke_select_leave&id={$row['id']}"]
                        ]
                    ]
                ]
            ];
        }
        replyMessage($this->replyToken, ["type" => "flex", "altText" => "選擇要修改的假單", "contents" => ["type" => "carousel", "contents" => $bubbles]]);
    }

    private function askModificationType($leaveId) {
        replyQuickReply($this->replyToken, "請選擇修改方式：", [
            ["type" => "action", "action" => ["type" => "postback", "label" => "🕙 延後開始放假", "data" => "action=revoke_select_type&mod_type=delay_start&leave_id=$leaveId"]],
            ["type" => "action", "action" => ["type" => "postback", "label" => "🔚 提早回來上班", "data" => "action=revoke_select_type&mod_type=early_return&leave_id=$leaveId"]],
            ["type" => "action", "action" => ["type" => "postback", "label" => "✂️ 中途銷假(拆單)", "data" => "action=revoke_select_type&mod_type=split&leave_id=$leaveId"]],
        ]);
    }

    private function askDate($leaveId, $type) {
        $label = "";
        $mode = "datetime"; 

        if ($type === 'delay_start') $label = "請選擇「新的開始放假時間」";
        if ($type === 'early_return') $label = "請選擇「實際結束放假時間」";
        if ($type === 'split') {
            $label = "請選擇「回來上班的那一天」";
            $mode = "date"; 
        }

        replyQuickReply($this->replyToken, $label, [
            [
                "type" => "action", 
                "action" => [
                    "type" => "datetimepicker", 
                    "label" => "📅 點此選擇時間", 
                    "data" => "action=revoke_confirm_date&mod_type=$type&leave_id=$leaveId",
                    "mode" => $mode
                ]
            ],
            ["❌ 取消", "/取消"]
        ]);
    }

    private function createRequest($leaveId, $type, $date) {
        // 1. 先在 Transaction 外部查詢 split 預覽所需資料
        $splitPreview = '';
        if ($type === 'split') {
            $stmt = $this->db->prepare("SELECT start_at, end_at FROM leave_requests WHERE id = ?");
            $stmt->execute([$leaveId]);
            $leave = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($leave) {
                    $part1End   = date('Y-m-d', strtotime($date . ' -1 day')) . ' 17:30';
                    $part2Start = $date . ' 08:30';
                $part2End   = substr($leave['end_at'], 0, 16);
                $splitPreview =
                    "\n【拆單預覽】\n" .
                    "✅ 保留段：" . substr($leave['start_at'], 0, 16) . " ~ {$part1End}\n" .
                    "❌ 銷假段：{$part2Start} ~ {$part2End}\n";
            }
        }

        // 2. 查詢員工姓名（Transaction 外部）
        $stmt = $this->db->prepare("SELECT name FROM users WHERE user_id = ?");
        $stmt->execute([$this->lineId]);
        $userName = $stmt->fetchColumn();

        try {
            $this->db->beginTransaction();

            // 🔥 1. 鎖定原始假單並檢查狀態
            $stmtLock = $this->db->prepare("SELECT status FROM leave_requests WHERE id = ? FOR UPDATE");
            $stmtLock->execute([$leaveId]);
            $original = $stmtLock->fetch(PDO::FETCH_ASSOC);

            if (!$original || !in_array($original['status'], ['approved', 'pending'])) {
                $this->db->rollBack();
                replyTextMessage($this->replyToken, "⚠️ 只能變更「已核准」或「待審核」的假單，此假單可能已被註銷。");
                return;
            }

            // 🔥 2. 檢查重複的變更單
            $checkStmt = $this->db->prepare("SELECT COUNT(*) FROM leave_modifications WHERE leave_request_id = ? AND status = 'pending'");
            $checkStmt->execute([$leaveId]);
            if ($checkStmt->fetchColumn() > 0) {
                $this->db->rollBack();
                replyTextMessage($this->replyToken, "⚠️ 此假單已有「審核中」的變更申請，請勿重複送出。");
                return;
            }

            // 3. 生成安全 UUID
            $uuid = $this->generateUuid();

            // 4. 寫入資料庫
            $stmt = $this->db->prepare("
                INSERT INTO leave_modifications (modification_uuid, leave_request_id, user_id, type, target_date, status)
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$uuid, $leaveId, $this->lineId, $type, $date]);

            $this->db->commit();

            // 5. 組裝訊息並回覆（commit 之後）
            $typeText = [
                'delay_start' => '延後放假 (修改開始時間)',
                'early_return' => '提早結束 (修改結束時間)',
                'split' => '中途銷假 (拆單)'
            ][$type];

            $botId = getenv("LINE_BOT_ID");
            $approvalCommand = "/同意銷假 $uuid";
            $encodedQuery = rawurlencode($approvalCommand);
            $approvalLink = "line://oaMessage/@" . $botId . "/?" . $encodedQuery;

            $msgText =
            "🔔 銷假/修改申請\n\n" .
            "員工：{$userName}\n" .
            "類型：{$typeText}\n" .
            "變更內容：{$date}" .
            $splitPreview .
            "\n\n👉 點擊以下連結，系統將自動填入「/同意銷假」指令，請直接送出即可完成簽核：\n" .
            $approvalLink .
            "\n\n若不同意，請口頭告知申請者即可，無需操作此連結。";

        replyMessage($this->replyToken, [
            [
                "type" => "text", 
                "text" => "✅ 申請已建立！\n\n請「長按」下方訊息並「轉傳」給您的主管簽核👇"
            ],
            [
                "type" => "text", 
                "text" => $msgText
            ]
        ]);
        } catch (Exception $e) {
            $this->db->rollBack();
            replyTextMessage($this->replyToken, "❌ 申請建立失敗，請稍後再試。");
        }
    }

    // 使用 random_bytes() 產生密碼學安全的 UUID v4
    private function generateUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
