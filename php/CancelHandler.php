<?php
// CancelHandler.php
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/utils.php';

class CancelHandler {
    private $lineId;
    private $userText;
    private $replyToken;
    private $session;

    public function __construct($lineId, $userText, $replyToken) {
        $this->lineId     = $lineId;
        $this->userText   = trim($userText);
        $this->replyToken = $replyToken;
        $this->session    = new UserSession($lineId);
    }

    public function handle() {
        $commands = [
            "/取消請假"   => "請假",
            "/取消查詢"   => "查詢",
            "/取消補打卡" => "補打卡"
        ];

        if (isset($commands[$this->userText])) {
            $action = $commands[$this->userText];
            $this->session->clear();
            replyTextMessage($this->replyToken, "✅ 已取消{$action}");
            return true;
        }

        return false;
    }
}
