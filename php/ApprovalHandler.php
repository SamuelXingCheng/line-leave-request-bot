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
                replyTextMessage($this->replyToken, "❗請使用格式：/同意請假 請假編號");
            }
            return true;
        }

        return false; // 非簽核指令
    }

    private function handleApproveLeave($groupId) {
        // 找出該 group 的待簽核請假單
        $stmt = $this->db->prepare("
            SELECT lr.id, lr.user_id, lr.user_name, lr.reason, la.status AS approval_status
            FROM leave_requests lr
            JOIN leave_approvals la ON lr.id = la.request_id
            WHERE lr.request_group_id = ? AND la.supervisor_id = ? AND la.status = 'pending'
        ");
        $stmt->execute([$groupId, $this->lineId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            replyTextMessage($this->replyToken, "❌ 找不到你需要簽核的請假單（編號 {$groupId}）。");
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
                $this->db->prepare("UPDATE leave_requests SET status = 'approved' WHERE id = ?")
                         ->execute([$row['id']]);
            }
        }

        $userName = $rows[0]['user_name'] ?? '';
        replyTextMessage(
            $this->replyToken,
            "✅ 已完成 {$userName} 的請假簽核（編號 {$groupId}，共 {$approvedCount} 筆）。"
        );
    }
}
