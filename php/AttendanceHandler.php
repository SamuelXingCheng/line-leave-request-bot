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
        $liffId  = getenv("LIFF_ID");
        $liffUrl = "line://app/" . $liffId;

        $templateMessage = [
            "type" => "template",
            "altText" => "出勤打卡",
            "template" => [
                "type" => "buttons",
                "title" => "台中市召會行政人員出勤系統",
                "text"  => "點下方按鈕進入打卡頁面",
                "actions" => [
                    [
                        "type" => "uri",
                        "label" => "📍上下班打卡",
                        "uri"  => $liffUrl
                    ]
                ]
            ]
        ];

        replyMessage($this->replyToken, $templateMessage);
        error_log("✅ Sent 打卡 LIFF button to userId=" . $this->userId);
    }
}
