<?php
// AttendanceHandler.php
require_once __DIR__ . '/utils.php';

class AttendanceHandler {
    private $userId;
    private $userText;
    private $replyToken;

    public function __construct($userId, $userText, $replyToken) {
        $this->userId     = $userId;
        $this->userText   = trim($userText);
        $this->replyToken = $replyToken;
    }

    public function handle() {
        if ($this->userText === "/打卡") {
            $this->sendLiffButton();
            return true;
        }
        return false;
    }

    private function sendLiffButton() {
        $liffId  = getenv("GPS_CHECKIN_LIFF_ID");
        // 建議改用標準 HTTPS 格式，相容性較好
        $liffUrl = "https://liff.line.me/" . $liffId;

        // 建構商務版 Flex Message (Call to Action 卡片)
        $flexMessage = [
            "type" => "flex",
            "altText" => "請進行出勤打卡",
            "contents" => [
                "type" => "bubble",
                "size" => "kilo", // 卡片尺寸：kilo (適中), mega (大)
                "header" => [
                    "type" => "box",
                    "layout" => "vertical",
                    "backgroundColor" => "#06C755", // 商務綠
                    "paddingAll" => "lg",
                    "contents" => [
                        [
                            "type" => "text",
                            "text" => "ATTENDANCE SYSTEM",
                            "color" => "#ffffff",
                            "weight" => "bold",
                            "size" => "xs",
                            "align" => "center",
                            "letterSpacing" => "1px"
                        ]
                    ]
                ],
                "body" => [
                    "type" => "box",
                    "layout" => "vertical",
                    "paddingAll" => "xl",
                    "contents" => [
                        [
                            "type" => "text",
                            "text" => "台中市召會行政人員",
                            "weight" => "bold",
                            "size" => "md",
                            "align" => "center",
                            "color" => "#333333"
                        ],
                        [
                            "type" => "text",
                            "text" => "請點擊下方按鈕開啟打卡頁面",
                            "size" => "sm",
                            "color" => "#888888",
                            "align" => "center",
                            "margin" => "md"
                        ]
                    ]
                ],
                "footer" => [
                    "type" => "box",
                    "layout" => "vertical",
                    "paddingAll" => "lg",
                    "contents" => [
                        [
                            "type" => "button",
                            "style" => "primary",
                            "color" => "#06C755", // 按鈕顏色與標題一致
                            "height" => "sm",
                            "action" => [
                                "type" => "uri",
                                "label" => "開啟打卡頁面",
                                "uri"  => $liffUrl
                            ]
                        ]
                    ]
                ]
            ]
        ];

        // 呼叫 utils.php 裡的 Flex 發送函式 (如果您剛剛有新增 replyFlexMessage)
        // 如果沒有，也可以繼續用原本的 replyMessage
        if (function_exists('replyFlexMessage')) {
            // 直接傳入 content 物件 (不需要外層的 type: flex)
            replyFlexMessage($this->replyToken, $flexMessage);
        } else {
            // 相容舊版 utils
            replyMessage($this->replyToken, $flexMessage);
        }

        error_log("✅ Sent 打卡 Flex Message to userId=" . $this->userId);
    }
}