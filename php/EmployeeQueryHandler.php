<?php
// EmployeeQueryHandler.php
require_once __DIR__ . '/Db.php';

class EmployeeQueryHandler {
    private $lineId;
    private $text;
    private $replyToken;
    private $db;

    public function __construct($lineId, $text, $replyToken) {
        $this->lineId     = $lineId;
        $this->text       = $text;
        $this->replyToken = $replyToken;
        $this->db         = Database::getConnection();
    }

    public function handle() {
        if ($this->text !== "/查詢同事") {
            return false;
        }

        $stmt = $this->db->prepare("SELECT name, phone, email, extension FROM users ORDER BY name ASC");
        $stmt->execute();
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$employees) {
            $message = [
                "type" => "text",
                "text" => "目前尚未有員工資料。"
            ];
            replyMessage($this->replyToken, $message);
            return true;
        }

        // 建立內容（由上到下）
        $contents = [];
        foreach ($employees as $emp) {
            $contents[] = [
                "type" => "box",
                "layout" => "vertical",
                "margin" => "md",
                "spacing" => "sm",
                "contents" => [
                    [
                        "type" => "text",
                        "text" => $emp['name'] ?? '未填寫',
                        "weight" => "bold",
                        "size" => "md",
                        "color" => "#111111"
                    ],
                    [
                        "type" => "text",
                        "text" => "📞 " . ($emp['phone'] ?? '未填寫'),
                        "size" => "sm",
                        "color" => "#555555"
                    ],
                    [
                        "type" => "text",
                        "text" => "✉️ " . ($emp['email'] ?? '未填寫'),
                        "size" => "sm",
                        "color" => "#555555",
                        "wrap" => true
                    ],
                    [
                        "type" => "text",
                        "text" => "☎️ 分機: " . ($emp['extension'] ?? '未填寫'),
                        "size" => "sm",
                        "color" => "#555555"
                    ],
                    [
                        "type" => "separator",
                        "margin" => "md"
                    ]
                ]
            ];
        }

        // Flex Message
        $flexMessage = [
            "type" => "flex",
            "altText" => "員工通訊錄",
            "contents" => [
                "type" => "bubble",
                "size" => "mega",
                "body" => [
                    "type" => "box",
                    "layout" => "vertical",
                    "contents" => array_merge(
                        [[
                            "type" => "text",
                            "text" => "員工通訊錄",
                            "weight" => "bold",
                            "size" => "lg",
                            "margin" => "none"
                        ]],
                        $contents
                    )
                ]
            ]
        ];

        replyMessage($this->replyToken, $flexMessage);
        return true;
    }
}
