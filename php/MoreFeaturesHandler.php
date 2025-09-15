<?php
// MoreFeaturesHandler.php
require_once __DIR__ . '/utils.php';

class MoreFeaturesHandler {
    private $lineId;
    private $text;
    private $replyToken;

    public function __construct($lineId, $text, $replyToken) {
        $this->lineId     = $lineId;
        $this->text       = $text;
        $this->replyToken = $replyToken;
    }

    public function handle() {
        if ($this->text !== "/更多功能") {
            return false;
        }

        replyQuickReply($this->replyToken, "請選擇功能：", [
            ["補打卡", "/補打卡"],
            ["查詢同事", "/查詢同事"],
            ["初次註冊", "/註冊"]
        ]);

        return true;
    }
}
