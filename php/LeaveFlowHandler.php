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

        if (isCommand($userText) && $userText !== "/請假") {
            $this->session->clearStep();
            return false; 
        }
        
        // 1. 處理 Postback (修正 Action 名稱對齊)
        if ($this->event['type'] === 'postback') {
            $data = $this->event['postback']['data'];
            parse_str($data, $params);
            $action = $params['action'] ?? '';
            $selectedDate = $this->event['postback']['params']['date'] ?? '';
            $selectedTime = $this->event['postback']['params']['time'] ?? '';

            if ($action === 'select_start_date') return $this->handleLeaveStartDate($selectedDate);
            if ($action === 'select_end_date') return $this->handleLeaveEndDate($selectedDate);
            if ($action === 'select_start_time') return $this->handleCustomStartTime($selectedTime);
            if ($action === 'select_end_time') return $this->handleCustomEndTime($selectedTime);
        }

        $step = $this->session->getStep();

        // 2. 處理文字訊息路由 (新增 start_date 與 end_date 步驟)
        if ($userText === "/請假") {
            return $this->startLeaveFlow();
        } elseif ($step === "leave_start_date") {
            return $this->handleLeaveStartDate($userText);
        } elseif ($step === "leave_end_date") {
            return $this->handleLeaveEndDate($userText);
        } elseif ($step === "leave_time") {
            return $this->handleLeaveTime($userText);
        } elseif ($step === "leave_custom_start") {
            return $this->handleCustomStartTime($userText);
        } elseif ($step === "leave_custom_end") {
            return $this->handleCustomEndTime($userText);
        } elseif ($step === "leave_type") {
            return $this->selectLeaveType($userText);
        }

        return false;
    }

    /** 修改後的第一步：選擇開始日期 */
    private function startLeaveFlow() {
        $this->session->reset();
        $this->session->setStep("leave_start_date");

        replyQuickReply(
            $this->event['replyToken'],
            "【請假申請】STEP 1/4\n請選擇或輸入「開始」日期：",
            [
                [
                    "type" => "action",
                    "action" => [
                        "type" => "datetimepicker",
                        "label" => "選擇日期", // 去除 ✏️
                        "data" => "action=select_start_date",
                        "mode" => "date"
                    ]
                ],
                ["取消申請", "/取消請假"] // 去除 ❌
            ]
        );
        return true;
    }

    /** 處理開始日期並詢問結束日期 */
    private function handleLeaveStartDate($userText) {
        $this->session->set("start_date", $userText);
        $this->session->setStep("leave_end_date");

        replyQuickReply(
            $this->event['replyToken'],
            "【請假申請】STEP 2/4\n已設定開始日：$userText\n\n請選擇「結束」日期：",
            [
                ["同開始日期", $userText],
                [
                    "type" => "action",
                    "action" => [
                        "type" => "datetimepicker",
                        "label" => "選擇結束日期",
                        "data" => "action=select_end_date",
                        "mode" => "date"
                    ]
                ],
                ["取消申請", "/取消請假"]
            ]
        );
        return true;
    }

    /** 新增的步驟：處理結束日期選擇 */
    private function handleLeaveEndDate($userText) {
        $this->session->set("end_date", $userText);
        $this->session->setStep("leave_time");

        replyQuickReply(
            $this->event['replyToken'],
            "【請假申請】STEP 3/4\n日期區間｜" . $this->session->get("start_date") . " ~ " . $userText . "\n\n請選擇休假時段：",
            [
                ["整天 (08:30-17:30)", "整天"], // 加上時間說明更清楚
                ["上午 (08:30-12:00)", "上午"],
                ["下午 (13:30-17:30)", "下午"],
                ["自訂時間", "自訂時段"],
                ["取消", "/取消請假"]
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
            // 🔥 修改：自訂時間的引導
            $this->session->setStep("leave_custom_start");
            
            replyQuickReply(
                $this->event['replyToken'],
                "【請假申請】STEP 3-1\n請選擇「開始」時間：",
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
                    ["取消作業", "/取消請假"]
                ]
            );
            return true;
        } else {
            replyTextMessage($this->event['replyToken'], "請選擇有效的時段。");
            return true;
        }

        // 🔥 修改：標準時段選擇後，進入最後一步
        $this->session->setStep("leave_type");
        replyQuickReply(
            $this->event['replyToken'],
            "【請假申請】STEP 4/4\n已選擇時段｜{$userText}\n\n請選擇假別類別：",
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

        // 🔥 修改：顯示已選的開始時間
        replyQuickReply(
            $this->event['replyToken'],
            "【請假申請】STEP 3-2\n起始時間｜{$time}\n\n請繼續選擇「結束」時間：",
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
                ["取消作業", "/取消請假"]
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
        
        // 🔥 修改：顯示完整自訂時段，並請使用者選假別
        replyQuickReply(
            $this->event['replyToken'],
            "【請假申請】STEP 4/4\n自訂時段｜{$start} ~ {$time}\n\n請選擇假別類別：",
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

    private function finishLeaveRequest($reason) {
        $startDate = $this->session->get("start_date");
        $endDate   = $this->session->get("end_date");
        $startTime = $this->session->get("start_time");
        $endTime   = $this->session->get("end_time");
        $leaveType = $this->session->get("leave_type");

        // 🔥【新增】自動切換邏輯
        $autoSwitchMsg = "";
        if ($leaveType === "特休") {
            // 1. 計算本次請假時數
            $requestHours = $this->calculateHours("$startDate $startTime", "$endDate $endTime");
            
            // 2. 查詢餘額
            $stats = getLeaveSummary($this->lineId);
            $compBalance = $stats['remainingComp']; // 補休餘額

            // 3. 判斷是否足夠
            if ($compBalance >= $requestHours && $requestHours > 0) {
                $leaveType = "補休"; // ✅ 強制切換
                $autoSwitchMsg = "\n💡 系統偵測到您有補休額度，已自動為您優先使用補休。";
            }
        }

        // 1. 查員工資訊
        $stmt = $this->db->prepare("SELECT name, role FROM users WHERE user_id = ?");
        $stmt->execute([$this->lineId]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $userName = $userRow['name'] ?? "未知姓名";
        $userRole = $userRow['role'] ?? "employee"; 
        $isBoss = ($userRole === 'boss');

        // 2. 決定初始狀態
        $initialStatus = $isBoss ? 'approved' : 'pending';

        // 3. 查主管
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

        // 4. 存檔
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
            $leaveType, // 這裡可能已經被換成「補休」了
            $reason,
            $startDate . ' ' . $startTime,
            $endDate . ' ' . $endTime,
            $initialStatus
        ]);
        $leaveId = $this->db->lastInsertId();

        // 5. 簽核關聯
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
            $msg = "【申請已歸檔】\n" .
                "日期｜{$startDate} {$startTime} ~ {$endDate} {$endTime}\n" .
                "假別｜{$leaveType}{$autoSwitchMsg}";
            replyTextMessage($this->event['replyToken'], $msg);
        } else {
            // 產生轉傳訊息
            $requests = [[
                "start_at"       => $startDate . ' ' . $startTime,
                "end_at"         => $endDate . ' ' . $endTime,
                "start_date"     => $startDate,
                "start_time"     => $startTime,
                "end_date"       => $endDate,
                "end_time"       => $endTime,
                "leave_type"     => $leaveType, // 顯示最終假別
                "reason"         => $reason . $autoSwitchMsg, // 把提示加在原因或另外顯示
                "name"           => $userName,
                "supervisor_ids" => $supervisors
            ]];

            $messages = buildForwardMessage($requests, $requestGroupId);
            
            // 如果有自動切換，我們在回覆給使用者的第一則訊息前，多插一句提示
            if (!empty($autoSwitchMsg)) {
                $hintMsg = ["type" => "text", "text" => "💡 溫馨提醒：已優先扣除您的加班補休時數。"];
                array_unshift($messages, $hintMsg);
            }

            replyMessage($this->event['replyToken'], $messages);
        }

        $this->session->clear();
        return true;
    }

    /** * 修改版：支援跨日計算，自動排除假日與週末，並扣除每日午休 
     */
    private function calculateHours($startStr, $endStr) {
        $start = strtotime($startStr);
        $end   = strtotime($endStr);

        if ($end <= $start) return 0;

        $totalHours = 0;
        
        // 設定標準工作與午休時間
        $workStartHour = "08:30";
        $workEndHour   = "17:30";
        $lunchStart    = "12:00";
        $lunchEnd      = "13:00";

        $currDate = new DateTime(date('Y-m-d', $start));
        $endDate  = new DateTime(date('Y-m-d', $end));
        
        while ($currDate <= $endDate) {
            $dateString = $currDate->format('Y-m-d');
            
            // 判斷當天是否為工作日
            $dayOfWeek = (int)$currDate->format('N'); // 1(一) ~ 7(日)
            $specialType = getHolidayType($dateString); // 呼叫 utils.php 函式

            $isWorkDay = true;
            if ($specialType === 'holiday') {
                $isWorkDay = false;
            } elseif ($specialType === 'workday') {
                $isWorkDay = true;
            } else {
                if ($dayOfWeek >= 6) $isWorkDay = false; // 一般週末
            }

            if (!$isWorkDay) {
                $currDate->modify('+1 day');
                continue;
            }

            // 決定當天計算區間
            $s = ($dateString === date('Y-m-d', $start)) ? date('H:i', $start) : $workStartHour;
            $e = ($dateString === date('Y-m-d', $end)) ? date('H:i', $end) : $workEndHour;

            // 限制在上班時間內
            if ($s < $workStartHour) $s = $workStartHour;
            if ($e > $workEndHour)   $e = $workEndHour;

            if ($e > $s) {
                $daySeconds = strtotime("$dateString $e") - strtotime("$dateString $s");
                $dayHours = $daySeconds / 3600;

                // 扣除午休 (12:00~13:00)
                if ($s < $lunchStart && $e > $lunchEnd) {
                    $dayHours -= 1;
                }
                $totalHours += $dayHours;
            }
            $currDate->modify('+1 day');
        }
        return max(0, $totalHours);
    }


    /** 假別 Quick Reply */
    private function getLeaveTypes() {
        return [
            ["特休假", "特休"],
            ["補休假", "補休"], 
            ["事假", "事假"],
            ["病假", "病假"],
            ["公差假", "公差"], 
            ["婚假", "婚假"],
            ["產假", "產假"],
            ["喪假", "喪假"],
            ["取消作業", "/取消請假"] // 去除 Emoji，改用正式用語
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