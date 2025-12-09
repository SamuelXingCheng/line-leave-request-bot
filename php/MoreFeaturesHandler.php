<?php
// MoreFeaturesHandler.php
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/config.php'; // 確保能讀取 .env

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
        
        // 從環境變數讀取
        $calendarLiffId = getenv('CALENDAR_LIFF_ID');
        
        // 🔥 這裡做了關鍵修改：使用進階格式定義 URI Action
        replyQuickReply($this->replyToken, "請選擇功能：", [
            ["查詢同事", "/查詢同事"],  
            ["查詢請假","/查詢請假"],        
            ["初次註冊", "/註冊"]
        ]);

        return true;
    }
}