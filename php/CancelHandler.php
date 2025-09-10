<?php
// CancelHandler.php
require_once __DIR__ . '/Session.php';

class CancelHandler {
    private $lineId;
    private $session;
    private $userText;

    public function __construct($lineId, $userText) {
        $this->lineId = $lineId;
        $this->userText = $userText;
        $this->session = new UserSession($lineId);
    }

    public function handle() {
        $commands = [
            "/取消請假" => "請假",
            "/取消查詢" => "查詢",
            "/取消補打卡" => "補打卡"
        ];

        if (array_key_exists($this->userText, $commands)) {
            $action = $commands[$this->userText];
            $this->session->clear();
            return [[
                "type" => "text",
                "text" => "✅ 已取消" . $action
            ]];
        }

        return false;
    }
}
