<?php
// RegisterHandler.php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/utils.php'; // replyMessage() function

class RegisterHandler {
    private $lineId;
    private $userText;
    private $replyToken;
    private $db;
    private $session;

    public function __construct($lineId, $userText, $replyToken) {
        $this->lineId = $lineId;
        $this->userText = trim($userText);
        $this->replyToken = $replyToken;
        $this->db = Database::getConnection();
        $this->session = new UserSession($lineId);
    }

    public function handle() {
        $state = $this->session->get("state");

        // Step 1: 啟動註冊
        if ($this->userText === "/註冊") {
            $this->session->reset();
            $this->session->set("state", "awaiting_name");
            $this->reply("請輸入你的姓名，例如：王小明");
            return true;
        }

        // Step 2: 輸入姓名
        if ($state === "awaiting_name") {
            $name = $this->userText;
            if (empty($name)) {
                $this->reply("⚠️ 請輸入有效的姓名，例如：王小明");
                return true;
            }
            $this->session->set("name", $name);
            $this->session->set("state", "awaiting_start_date");
            $this->reply(
                "已記錄資訊\n".
                "──────────────\n".
                "姓名：{$name}\n".
                "──────────────\n".
                "請輸入到職日 (格式：YYYY-MM-DD)"
            );
            return true;
        }

        // Step 3: 輸入到職日
        if ($state === "awaiting_start_date") {
            $startDate = $this->userText;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
                $this->reply("⚠️ 請輸入正確格式的日期，例如：2025-09-12");
                return true;
            }
            $this->session->set("start_date", $startDate);
            $this->session->set("state", "awaiting_phone");
            $this->reply(
                "已記錄資訊\n".
                "──────────────\n".
                "到職日：{$startDate}\n".
                "──────────────\n".
                "請輸入聯絡電話"
            );
            return true;
        }

        // Step 4: 輸入電話
        if ($state === "awaiting_phone") {
            $phone = $this->userText;
            if (!preg_match('/^[0-9\-+]{6,15}$/', $phone)) {
                $this->reply("⚠️ 請輸入正確的電話號碼，例如：0912345678");
                return true;
            }
            $this->session->set("phone", $phone);
            $this->session->set("state", "awaiting_email");
            $this->reply(
                "已記錄資訊\n".
                "──────────────\n".
                "聯絡電話：{$phone}\n".
                "──────────────\n".
                "請輸入電子郵件"
            );
            return true;
        }

        // Step 5: 輸入電子郵件
        if ($state === "awaiting_email") {
            $email = $this->userText;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->reply("⚠️ 請輸入正確的電子郵件，例如：example@mail.com");
                return true;
            }
            $this->session->set("email", $email);

            $this->registerUser(
                $this->lineId,
                $this->session->get("name"),
                $this->session->get("start_date"),
                $this->session->get("phone"),
                $email
            );

            $this->reply(
                "註冊完成！\n".
                "──────────────\n".
                "姓名：{$this->session->get("name")}\n".
                "到職日：{$this->session->get("start_date")}\n".
                "電話：{$this->session->get("phone")}\n".
                "✉Email：{$email}\n".
                "──────────────\n".
                "你的資料已經成功建立！"
            );

            $this->session->reset();
            return true;
        }


        return false;
    }

    private function registerUser($lineId, $name, $startDate, $phone, $email) {
        // 檢查是否已存在
        $stmt = $this->db->prepare("SELECT id FROM users WHERE user_id = ?");
        $stmt->execute([$lineId]);
        $user = $stmt->fetch();

        if ($user) {
            return false; // 已存在
        }

        // 插入新使用者
        $stmt = $this->db->prepare("
            INSERT INTO users (user_id, name, start_date, phone, email)
            VALUES (?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$lineId, $name, $startDate, $phone, $email]);
    }

    private function reply($text) {
        replyMessage($this->replyToken, [
            "type" => "text",
            "text" => $text
        ]);
    }
}
