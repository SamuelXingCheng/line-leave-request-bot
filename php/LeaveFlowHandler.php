<?php
// LeaveFlowHandler.php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/Session.php';

class LeaveFlowHandler {
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

        // 🔥【修改這段】通用判斷：如果是指令 (以 / 開頭)，且不是 "/請假" 本身，就中斷流程
        if (isCommand($userText) && $userText !== "/請假") {
            $this->session->clearStep();
            return false; 
        }
        
        // 1. 處理 Postback 事件 (日期與時間選擇器回傳)
        if ($this->event['type'] === 'postback') {
            $data = $this->event['postback']['data'];
            parse_str($data, $params);

            // 📅 選擇日期
            if (isset($params['action']) && $params['action'] === 'select_leave_date') {
                $selectedDate = $this->event['postback']['params']['date'];
                return $this->handleLeaveDate($selectedDate);
            }
            // 🕒 選擇開始時間
            if (isset($params['action']) && $params['action'] === 'select_start_time') {
                $selectedTime = $this->event['postback']['params']['time'];
                return $this->handleCustomStartTime($selectedTime);
            }
            // 🕒 選擇結束時間
            if (isset($params['action']) && $params['action'] === 'select_end_time') {
                $selectedTime = $this->event['postback']['params']['time'];
                return $this->handleCustomEndTime($selectedTime);
            }
        }

        // 2. 處理一般文字訊息
        if (!isset($this->event['message']['text'])) {
            return false;
        }
        $userText = $this->event['message']['text'];

        // 檢查全域指令 (排除 /請假 本身)
        if (in_array($userText, getGlobalCommands()) && $userText !== "/請假") {
            $this->session->clearStep();
            return false; 
        }
        
        $step = $this->session->getStep();

        if ($userText === "/請假") {
            return $this->startLeaveFlow();
        } elseif ($step === "leave_date") {
            return $this->handleLeaveDate($userText);
        } elseif ($step === "leave_time") {
            return $this->handleLeaveTime($userText);
        // 🔥 防止使用者不按按鈕直接打字，這兩步也要能接文字輸入
        } elseif ($step === "leave_custom_start") {
            return $this->handleCustomStartTime($userText);
        } elseif ($step === "leave_custom_end") {
            return $this->handleCustomEndTime($userText);
        } elseif ($step === "leave_type") {
            return $this->selectLeaveType($userText);
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

        // 使用 datetimepicker 動作
        replyQuickReply(
            $this->event['replyToken'],
            "📅 請選擇請假日期或自訂輸入：",
            [
                ["📆 今天 ($today)", $today],
                ["📆 明天 ($tomorrow)", $tomorrow],
                ["📆 後天 ($dayAfter)", $dayAfter],
                [
                    "type" => "action",
                    "action" => [
                        "type" => "datetimepicker",
                        "label" => "✏️ 選擇日期",
                        "data" => "action=select_leave_date",
                        "mode" => "date"
                    ]
                ],
                ["取消請假", "/取消請假"]
            ]
        );
        return true;
    }

    /** 第二步：處理請假日期 */
    private function handleLeaveDate($userText) {
        if ($userText === "自訂日期") {
            // 保留這個文字回應，防止使用者用舊的按鈕或手動打字
            replyTextMessage($this->event['replyToken'], "請點擊上方按鈕選擇日期，或輸入格式：2025/09/06");
            return true;
        }

        // 判斷是否為區間
        if (strpos($userText, "-") !== false) {
            $parts = explode("-", $userText);
            // DatePicker 回傳 YYYY-MM-DD
            
            if (count($parts) === 2) {
                // 這是區間輸入 (Start - End)
                $startDate = trim($parts[0]);
                $endDate   = trim($parts[1]);
                $this->session->set("start_date", $startDate);
                $this->session->set("end_date", $endDate);
            } else {
                // 這是單日 (YYYY-MM-DD)
                $this->session->set("start_date", $userText);
                $this->session->set("end_date", $userText);
            }
        } else {
            // 單日 (YYYY/MM/DD)
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
                ["取消請假", "/取消請假"]
            ]
        );
        return true;
    }

    /** 第三步：處理時段選擇 (若是自訂，則跳出 TimePicker) */
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
            // 🔥 改為設定步驟並彈出 TimePicker
            $this->session->setStep("leave_custom_start");
            
            replyQuickReply(
                $this->event['replyToken'],
                "🕒 請選擇「開始」時間：",
                [
                    [
                        "type" => "action",
                        "action" => [
                            "type" => "datetimepicker",
                            "label" => "選擇開始時間",
                            "data" => "action=select_start_time",
                            "mode" => "time"
                        ]
                    ],
                    ["取消", "/取消請假"]
                ]
            );
            return true;
        } else {
            replyTextMessage($this->event['replyToken'], "請選擇有效的時段。");
            return true;
        }

        // 如果不是自訂時段，直接跳下一步
        $this->session->setStep("leave_type");
        replyQuickReply(
            $this->event['replyToken'],
            "📝 請選擇請假類型：",
            $this->getLeaveTypes()
        );
        return true;
    }

    /** 🔥 新增：處理開始時間 */
    private function handleCustomStartTime($time) {
        // 簡單驗證時間格式 HH:mm
        if (!preg_match("/^\d{2}:\d{2}$/", $time)) {
             replyTextMessage($this->event['replyToken'], "時間格式錯誤，請重試 (例如 09:00)");
             return true;
        }

        $this->session->set("start_time", $time);
        $this->session->setStep("leave_custom_end");

        replyQuickReply(
            $this->event['replyToken'],
            "🕒 起始時間：{$time}\n請繼續選擇「結束」時間：",
            [
                [
                    "type" => "action",
                    "action" => [
                        "type" => "datetimepicker",
                        "label" => "選擇結束時間",
                        "data" => "action=select_end_time",
                        "mode" => "time"
                    ]
                ],
                ["取消", "/取消請假"]
            ]
        );
        return true;
    }

    /** 🔥 新增：處理結束時間 */
    private function handleCustomEndTime($time) {
        if (!preg_match("/^\d{2}:\d{2}$/", $time)) {
             replyTextMessage($this->event['replyToken'], "時間格式錯誤，請重試 (例如 18:00)");
             return true;
        }

        $this->session->set("end_time", $time);
        
        // 完成時間選擇，跳去選假別
        $this->session->setStep("leave_type");

        $start = $this->session->get("start_time");
        replyQuickReply(
            $this->event['replyToken'],
            "⏰ 已設定時段：{$start} ~ {$time}\n\n📝 接下來，請選擇請假類型：",
            $this->getLeaveTypes()
        );
        return true;
    }

    /** 第五步：使用者選擇假別 */
    private function selectLeaveType($userText) {
        // 1. 設定假別
        $this->session->set("leave_type", $userText);
        
        // 2. 設定預設原因 (因為資料庫欄位可能需要)
        $defaultReason = "（未填寫）";
        $this->session->set("reason", $defaultReason);

        // 3. 直接執行原本 "handleLeaveReasonDetail" 的存檔邏輯
        return $this->finishLeaveRequest($defaultReason);
    }

    /** 第六步：輸入原因 → 存 DB + 產生訊息 */
    private function finishLeaveRequest($reason) {
        $startDate = $this->session->get("start_date");
        $endDate   = $this->session->get("end_date");
        $startTime = $this->session->get("start_time");
        $endTime   = $this->session->get("end_time");
        $leaveType = $this->session->get("leave_type");
        // $reason 已由參數傳入

        // 1. 查員工姓名與角色 (整合 Boss 邏輯)
        $stmt = $this->db->prepare("SELECT name, role FROM users WHERE user_id = ?");
        $stmt->execute([$this->lineId]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $userName = $userRow['name'] ?? "未知姓名";
        $userRole = $userRow['role'] ?? "employee"; 
        $isBoss = ($userRole === 'boss');

        // 2. 決定初始狀態
        $initialStatus = $isBoss ? 'approved' : 'pending';

        // 3. 查主管 (Boss 免查)
        $supervisors = [];
        if (!$isBoss) {
            $stmt = $this->db->prepare("SELECT supervisor_id FROM user_supervisors WHERE user_id = ?");
            $stmt->execute([$this->lineId]);
            $supervisors = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($supervisors)) {
                replyTextMessage($this->event['replyToken'], "⚠️ 系統未設定您的主管，無法送出請假申請。");
                return true; 
            }
        }

        $requestGroupId = $this->generateUuid();

        // 4. 存請假紀錄
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
            $initialStatus
        ]);
        $leaveId = $this->db->lastInsertId();

        // 5. 寫入簽核關聯 (Boss 免簽)
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
            $msg = "您的請假申請已自動核准歸檔。\n" .
                   "📅 {$startDate} {$startTime} ~ {$endDate} {$endTime}\n" .
                   "假別：{$leaveType}";
            replyTextMessage($this->event['replyToken'], $msg);
        } else {
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
            ["特休", "特休"],
            ["補休", "補休"], // 🔥 新增：補休選項
            ["事假", "事假"],
            ["公差", "公差"], // 🔥 修改：公假 -> 公差
            ["病假", "病假"],
            ["婚假", "婚假"],
            ["產假", "產假"],
            ["喪假", "喪假"],
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