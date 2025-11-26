<?php
// LeaveFlowHandler.php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/Session.php';

class LeaveFlowHandler {
    private $lineId;
    private $event;
    private $session;

    public function __construct($lineId, $event, $db) {
        $this->lineId = $lineId;
        $this->event = $event;
        $this->session = new UserSession($lineId, $db);
        $this->db = $db;
    }

    public function handle() {
        $userText = $this->event['message']['text'];
        $step = $this->session->getStep();

        if ($userText === "/請假") {
            return $this->startLeaveFlow();
        } elseif ($step === "leave_date") {
            return $this->handleLeaveDate($userText);
        } elseif ($step === "leave_time") {
            return $this->handleLeaveTime($userText);
        } elseif ($step === "leave_custom_time") {
            return $this->handleLeaveCustomTime($userText);
        } elseif ($step === "leave_type") {
            return $this->selectLeaveType($userText);
        } elseif ($step === "leave_reason_detail") {
            return $this->handleLeaveReasonDetail($userText);
        }

        return false;
    }

    /** 第一步：開始流程，選擇日期 */
    private function startLeaveFlow() {
        // 測試 DB 連線
        $stmt = $this->db->query("SELECT NOW()");
        $now = $stmt->fetchColumn();
        error_log("✅ DB connected, current time: " . $now);

        $this->session->reset();
        $this->session->setStep("leave_date");

        $today = date("Y/m/d");
        $tomorrow = date("Y/m/d", strtotime("+1 day"));
        $dayAfter = date("Y/m/d", strtotime("+2 day"));

        replyQuickReply(
            $this->event['replyToken'],
            "📅 請選擇請假日期或自訂輸入：",
            [
                ["📆 今天 ($today)", $today],
                ["📆 明天 ($tomorrow)", $tomorrow],
                ["📆 後天 ($dayAfter)", $dayAfter],
                ["✏️ 自訂日期", "自訂日期"],
                ["❌ 取消請假", "/取消請假"]
            ]
        );
        return true;
    }

    /** 第二步：處理請假日期 */
    private function handleLeaveDate($userText) {
        if ($userText === "自訂日期") {
            replyTextMessage($this->event['replyToken'], "請輸入請假日期（例如：2025/09/06 或 2025/09/06-2025/09/07）：");
            return true;
        }

        // 判斷是否為區間
        if (strpos($userText, "-") !== false) {
            $parts = explode("-", $userText);
            if (count($parts) === 2) {
                $startDate = trim($parts[0]);
                $endDate   = trim($parts[1]);
                $this->session->set("start_date", $startDate);
                $this->session->set("end_date", $endDate);
            } else {
                replyTextMessage($this->event['replyToken'], "❌ 日期格式錯誤，請輸入：2025/09/06-2025/09/07");
                return true;
            }
        } else {
            // 單日
            $this->session->set("start_date", $userText);
            $this->session->set("end_date", $userText);
        }

        $this->session->setStep("leave_time");

        replyQuickReply(
            $this->event['replyToken'],
            "🕒 請選擇請假時段：",
            [
                ["整天", "整天"],
                ["上午", "上午"],
                ["下午", "下午"],
                ["自訂", "自訂時段"],
                ["❌ 取消請假", "/取消請假"]
            ]
        );
        return true;
    }

    /** 第三步：處理時段 */
    private function handleLeaveTime($userText) {
        if ($userText === "整天") {
            $this->session->set("start_time", "08:30");
            $this->session->set("end_time", "17:30");
        } elseif ($userText === "上午") {
            $this->session->set("start_time", "08:30");
            $this->session->set("end_time", "12:00");
        } elseif ($userText === "下午") {
            $this->session->set("start_time", "13:30");
            $this->session->set("end_time", "17:30");
        } elseif ($userText === "自訂時段") {
            $this->session->setStep("leave_custom_time");
            replyTextMessage($this->event['replyToken'], "請輸入時間範圍（格式：08:30-12:00）：");
            return true;
        } else {
            replyTextMessage($this->event['replyToken'], "❌ 請選擇有效的時段或輸入自訂時段。");
            return true;
        }

        $this->session->setStep("leave_type");
        replyQuickReply(
            $this->event['replyToken'],
            "📝 請選擇請假類型：",
            $this->getLeaveTypes()
        );
        return true;
    }

    /** 第四步：處理自訂時段 */
    private function handleLeaveCustomTime($userText) {
        if (strpos($userText, "-") !== false) {
            $parts = explode("-", $userText);
            if (count($parts) === 2) {
                $start = trim($parts[0]);
                $end   = trim($parts[1]);
                $this->session->set("start_time", $start);
                $this->session->set("end_time", $end);
                $this->session->setStep("leave_type");

                replyQuickReply(
                    $this->event['replyToken'],
                    "📝 請選擇請假類型：",
                    $this->getLeaveTypes()
                );
                return true;
            }
        }
        replyTextMessage($this->event['replyToken'], "❌ 時間格式錯誤，請重新輸入：08:30-12:00");
        return true;
    }

    /** 第五步：使用者選擇假別 */
    private function selectLeaveType($userText) {
        $this->session->set("leave_type", $userText);
        $this->session->setStep("leave_reason_detail");

        replyTextMessage($this->event['replyToken'], "✏️ 請填寫請假事由：");
        return true;
    }

    /** 第六步：輸入原因 → 存 DB + 產生訊息 */
    private function handleLeaveReasonDetail($userText) {
        $this->session->set("reason", $userText);

        $startDate = $this->session->get("start_date");
        $endDate   = $this->session->get("end_date");
        $startTime = $this->session->get("start_time");
        $endTime   = $this->session->get("end_time");
        $leaveType = $this->session->get("leave_type");
        $reason    = $this->session->get("reason");

        // 1. 查員工姓名與角色 (Role)
        // [修改] 多撈一個 role 欄位
        $stmt = $this->db->prepare("SELECT name, role FROM users WHERE user_id = ?");
        $stmt->execute([$this->lineId]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $userName = $userRow['name'] ?? "未知姓名";
        $userRole = $userRow['role'] ?? "employee"; // 預設為一般員工

        // 判斷是否為最高主管
        $isBoss = ($userRole === 'boss');

        // 2. 決定初始狀態
        // 如果是 Boss，直接 approved；否則 pending
        $initialStatus = $isBoss ? 'approved' : 'pending';

        // 3. 查主管（如果是 Boss 就不需要查主管，但為了避免變數未定義，給空陣列）
        $supervisors = [];
        if (!$isBoss) {
            $stmt = $this->db->prepare("SELECT supervisor_id FROM user_supervisors WHERE user_id = ?");
            $stmt->execute([$this->lineId]);
            $supervisors = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // [防呆] 如果是一般員工但沒設定主管
            if (empty($supervisors)) {
                replyTextMessage($this->event['replyToken'], "⚠️ 系統未設定您的主管，無法送出請假申請。請聯繫管理員。");
                return true; 
            }
        }

        $requestGroupId = $this->generateUuid();

        // 4. 存請假紀錄
        // [修改] status 欄位改用變數 $initialStatus
        $stmt = $this->db->prepare("
            INSERT INTO leave_requests (
                request_group_id, user_id, user_name, leave_type, reason, start_at, end_at, status, created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $requestGroupId,
            $this->lineId,
            $userName,
            $leaveType,
            $reason,
            $startDate . ' ' . $startTime,
            $endDate . ' ' . $endTime,
            $initialStatus // 這裡帶入變數
        ]);
        $leaveId = $this->db->lastInsertId();

        // 5. 初始化主管簽核
        // [修改] 只有一般員工 ($isBoss == false) 才需要寫入 leave_approvals
        if (!$isBoss) {
            foreach ($supervisors as $supId) {
                $stmt = $this->db->prepare("
                    INSERT INTO leave_approvals (request_id, supervisor_id, status)
                    VALUES (?, ?, 'pending')
                ");
                $stmt->execute([$leaveId, $supId]);
            }
        }

        // 6. 回覆訊息
        if ($isBoss) {
            // [新增] 如果是 Boss，直接回覆成功訊息，不需要轉傳
            $msg = "✅ 您的請假申請已自動核准歸檔。\n" .
                   "──────────────\n" .
                   "📅 日期：{$startDate} {$startTime} ~ {$endDate} {$endTime}\n" .
                   "假別：{$leaveType}\n" .
                   "事由：{$reason}";
            replyTextMessage($this->event['replyToken'], $msg);
        } else {
            // [原有邏輯] 一般員工，產生轉傳訊息
            $requests = [[
                "start_at"       => $startDate . ' ' . $startTime,
                "end_at"         => $endDate . ' ' . $endTime,
                "start_date"     => $startDate,
                "start_time"     => $startTime,
                "end_date"       => $endDate,
                "end_time"       => $endTime,
                "leave_type"     => $leaveType,
                "reason"         => $reason,
                "name"           => $userName,
                "supervisor_ids" => $supervisors
            ]];

            $messages = buildForwardMessage($requests, $requestGroupId);
            replyMessage($this->event['replyToken'], $messages);
        }

        // 7. 清理 session
        $this->session->clear();
        return true;
    }


    /** 假別 Quick Reply */
    private function getLeaveTypes() {
        return [
            ["🌴 特休", "特休"],
            ["📌 事假", "事假"],
            ["🤒 病假", "病假"],
            ["🏛️ 公假", "公假"],
            ["💒 婚假", "婚假"],
            ["🤰 產假", "產假"],
            ["🖤 喪假", "喪假"],
            ["❌ 取消請假", "/取消請假"]
        ];
    }

    private function generateUuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

}
