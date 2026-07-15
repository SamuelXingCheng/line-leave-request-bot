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
        $this->session = new UserSession($lineId);
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
                        "label" => "選擇日期",
                        "data" => "action=select_start_date",
                        "mode" => "date"
                    ]
                ],
                ["取消申請", "/取消請假"]
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
                ["整天 (08:30-17:30)", "整天"],
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
            replyTextMessage($this->event['replyToken'], "請點擊上方按鈕選擇日期，或輸入格式：2025/09/06");
            return true;
        }

        if (strpos($userText, "-") !== false) {
            $parts = explode("-", $userText);
            if (count($parts) === 2) {
                $startDate = trim($parts[0]);
                $endDate   = trim($parts[1]);
                $this->session->set("start_date", $startDate);
                $this->session->set("end_date", $endDate);
            } else {
                $this->session->set("start_date", $userText);
                $this->session->set("end_date", $userText);
            }
        } else {
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

        $this->session->setStep("leave_type");
        replyQuickReply(
            $this->event['replyToken'],
            "【請假申請】STEP 4/4\n已選擇時段｜{$userText}\n\n請選擇假別類別：",
            $this->getLeaveTypes()
        );
        return true;
    }

    /** 處理開始時間 */
    private function handleCustomStartTime($time) {
        if (!preg_match("/^\d{2}:\d{2}$/", $time)) {
             replyTextMessage($this->event['replyToken'], "時間格式錯誤，請重試 (例如 09:00)");
             return true;
        }

        $this->session->set("start_time", $time);
        $this->session->setStep("leave_custom_end");

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

    /** 處理結束時間 */
    private function handleCustomEndTime($time) {
        if (!preg_match("/^\d{2}:\d{2}$/", $time)) {
             replyTextMessage($this->event['replyToken'], "時間格式錯誤，請重試 (例如 18:00)");
             return true;
        }

        $this->session->set("end_time", $time);
        
        $this->session->setStep("leave_type");

        $start = $this->session->get("start_time");
        
        replyQuickReply(
            $this->event['replyToken'],
            "【請假申請】STEP 4/4\n自訂時段｜{$start} ~ {$time}\n\n請選擇假別類別：",
            $this->getLeaveTypes()
        );
        return true;
    }

    /** 第五步：使用者選擇假別 */
    private function selectLeaveType($userText) {
        $this->session->set("leave_type", $userText);
        
        $defaultReason = "（未填寫）";
        $this->session->set("reason", $defaultReason);

        return $this->finishLeaveRequest($defaultReason);
    }

    private function finishLeaveRequest($reason) {
        $startDate = $this->session->get("start_date");
        $endDate   = $this->session->get("end_date");
        $startTime = $this->session->get("start_time");
        $endTime   = $this->session->get("end_time");
        $leaveType = $this->session->get("leave_type");

        // 1. 準備要傳給內部 API 的資料 (統一交由 leave_form_api 處理扣抵)
        $payload = [
            'userId'    => $this->lineId,
            'startDate' => $startDate,
            'startTime' => $startTime,
            'endDate'   => $endDate,
            'endTime'   => $endTime,
            'leaveType' => $leaveType,
            'reason'    => $reason
        ];

        // 2. 動態組裝內部 API 網址
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        $apiUrl = $protocol . "://" . $host . $path . "/leave_form_api.php";

        // 3. 透過 cURL 發送內部請求
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 4. 解析結果並直接回覆訊息給使用者
        if ($httpCode === 200 && $response) {
            $result = json_decode($response, true);
            if (isset($result['status']) && $result['status'] === 'success') {
                // 將 API 產生的商務版 Flex Message 陣列，直接回覆到聊天室
                if (!empty($result['forward_message'])) {
                    replyMessage($this->event['replyToken'], $result['forward_message']);
                } else {
                    replyTextMessage($this->event['replyToken'], "✅ 請假申請已成功送出！");
                }
            } else {
                replyTextMessage($this->event['replyToken'], "⚠️ 申請失敗：" . ($result['message'] ?? '未知錯誤'));
            }
        } else {
            error_log("Leave API Call Failed: HTTP $httpCode, Response: $response");
            replyTextMessage($this->event['replyToken'], "⚠️ 系統內部連線異常，請稍後再試。");
        }

        // 清除階段狀態
        $this->session->clear();
        return true;
    }

    /**
     * 修改版：支援跨日計算，自動排除假日與週末，並扣除每日午休
     */
    private function calculateHours($startStr, $endStr) {
        $start = strtotime($startStr);
        $end   = strtotime($endStr);

        if ($end <= $start) return 0;

        $totalHours = 0;
        
        $workStartHour = "08:30";
        $workEndHour   = "17:30";
        $lunchStart    = "12:00";
        $lunchEnd      = "13:00";

        $currDate = new DateTime(date('Y-m-d', $start));
        $endDate  = new DateTime(date('Y-m-d', $end));
        
        while ($currDate <= $endDate) {
            $dateString = $currDate->format('Y-m-d');
            
            $dayOfWeek = (int)$currDate->format('N');
            $specialType = getHolidayType($dateString);

            $isWorkDay = true;
            if ($specialType === 'holiday') {
                $isWorkDay = false;
            } elseif ($specialType === 'workday') {
                $isWorkDay = true;
            } else {
                if ($dayOfWeek >= 6) $isWorkDay = false;
            }

            if (!$isWorkDay) {
                $currDate->modify('+1 day');
                continue;
            }

            $s = ($dateString === date('Y-m-d', $start)) ? date('H:i', $start) : $workStartHour;
            $e = ($dateString === date('Y-m-d', $end)) ? date('H:i', $end) : $workEndHour;

            if ($s < $workStartHour) $s = $workStartHour;
            if ($e > $workEndHour)   $e = $workEndHour;

            if ($e > $s) {
                $daySeconds = strtotime("$dateString $e") - strtotime("$dateString $s");
                $dayHours = $daySeconds / 3600;

                if ($s < $lunchStart && $e >= $lunchEnd) {
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
            ["特休假", "特休假"],
            ["補休假", "補休假"],
            ["事假", "事假"],
            ["病假", "病假"],
            ["公差假", "公差假"],
            ["婚假", "婚假"],
            ["產假", "產假"],
            ["喪假", "喪假"],
            ["取消作業", "/取消請假"]
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