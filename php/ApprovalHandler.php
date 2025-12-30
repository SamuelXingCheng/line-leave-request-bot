<?php
// ApprovalHandler.php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

class ApprovalHandler {
    private $lineId;     // 主管 ID
    private $userText;
    private $db;
    private $replyToken; // ⭐ 加入 replyToken

    public function __construct($lineId, $userText, $replyToken) {
        $this->lineId     = $lineId;
        $this->userText   = trim($userText);
        $this->replyToken = $replyToken;
        $this->db         = Database::getConnection();
    }

    public function handle() {
        if (strpos($this->userText, "/同意請假") === 0) {
            $parts = preg_split('/\s+/', $this->userText); // 支援多空格
            if (count($parts) >= 2) {
                $groupId = $parts[1];
                $this->handleApproveLeave($groupId);
            } else {
                replyTextMessage($this->replyToken, "請使用格式：/同意請假 請假編號");
            }
            return true;
        }

        return false; // 非簽核指令
    }

    private function handleApproveLeave($groupId) {
        // 🔥 修改 1：SQL 增加撈取 leave_type, start_at, end_at 以便製作通知
        $stmt = $this->db->prepare("
            SELECT lr.id, lr.user_id, lr.user_name, lr.reason, lr.leave_type, lr.start_at, lr.end_at, la.status AS approval_status
            FROM leave_requests lr
            JOIN leave_approvals la ON lr.id = la.request_id
            WHERE lr.request_group_id = ? AND la.supervisor_id = ? AND la.status = 'pending'
        ");
        $stmt->execute([$groupId, $this->lineId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            replyTextMessage($this->replyToken, "找不到你需要簽核的請假單（編號 {$groupId}）。");
            return;
        }

        // 更新主管的簽核狀態
        $updateStmt = $this->db->prepare("
            UPDATE leave_approvals
            SET status = 'approved'
            WHERE supervisor_id = ? AND request_id = ?
        ");

        $approvedCount = 0;
        foreach ($rows as $row) {
            $updateStmt->execute([$this->lineId, $row['id']]);
            $approvedCount++;

            // 檢查這筆請假單是否所有主管都簽完
            $checkStmt = $this->db->prepare("
                SELECT COUNT(*) 
                FROM leave_approvals 
                WHERE request_id = ? AND status != 'approved'
            ");
            $checkStmt->execute([$row['id']]);
            $remaining = $checkStmt->fetchColumn();

            if ($remaining == 0) {
                // 1. 更新資料庫狀態
                $this->db->prepare("UPDATE leave_requests SET status = 'approved' WHERE id = ?")
                         ->execute([$row['id']]);

                // 🔥 修改 2：發送 Flex Message 通知申請人
                $this->sendApprovalNotificationToApplicant($row);
            }
        }

        $userName = $rows[0]['user_name'] ?? '';
        replyTextMessage(
            $this->replyToken,
            "已完成 {$userName} 的請假簽核（編號 {$groupId}，共 {$approvedCount} 筆）。"
        );
    }
    private function sendApprovalNotificationToApplicant($row) {
        $applicantId = $row['user_id'];
        
        // 格式化時間，去掉秒數
        $startStr = date('Y-m-d H:i', strtotime($row['start_at']));
        $endStr   = date('Y-m-d H:i', strtotime($row['end_at']));

        $flexMessage = [
            "type" => "flex",
            "altText" => "您的請假申請已通過",
            "contents" => [
                "type" => "bubble",
                "size" => "kilo",
                "header" => [
                    "type" => "box",
                    "layout" => "vertical",
                    "backgroundColor" => "#E8F5E9", // 淺綠色背景
                    "paddingAll" => "15px",
                    "contents" => [
                        [
                            "type" => "text",
                            "text" => "假單已核准",
                            "weight" => "bold",
                            "size" => "lg",
                            "color" => "#2E7D32"
                        ]
                    ]
                ],
                "body" => [
                    "type" => "box",
                    "layout" => "vertical",
                    "contents" => [
                        [
                            "type" => "box",
                            "layout" => "vertical",
                            "margin" => "md",
                            "spacing" => "sm",
                            "contents" => [
                                $this->buildDetailRow("假別", $row['leave_type']),
                                $this->buildDetailRow("開始", $startStr),
                                $this->buildDetailRow("結束", $endStr),
                                $this->buildDetailRow("原因", $row['reason']),
                            ]
                        ],
                        [
                            "type" => "separator",
                            "margin" => "lg"
                        ],
                        [
                            "type" => "text",
                            "text" => "系統已自動歸檔，祝您休假愉快！",
                            "size" => "xs",
                            "color" => "#aaaaaa",
                            "margin" => "md",
                            "align" => "center"
                        ]
                    ]
                ]
            ]
        ];

        // 呼叫 utils.php 的 pushMessage
        pushMessage($applicantId, $flexMessage);
    }
    /**
     * 輔助函式：建立 Flex Message 的欄位列
     */
    private function buildDetailRow($label, $text) {
        return [
            "type" => "box",
            "layout" => "baseline",
            "spacing" => "sm",
            "contents" => [
                [
                    "type" => "text",
                    "text" => $label,
                    "color" => "#aaaaaa",
                    "size" => "sm",
                    "flex" => 2
                ],
                [
                    "type" => "text",
                    "text" => $text ?: "無",
                    "wrap" => true,
                    "color" => "#666666",
                    "size" => "sm",
                    "flex" => 5
                ]
            ]
        ];
    }
}
