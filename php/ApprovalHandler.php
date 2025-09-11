<?php
// ApprovalHandler.php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

class ApprovalHandler {
    private $lineId;   // 主管 ID
    private $userText;
    private $db;

    public function __construct($lineId, $userText) {
        $this->lineId   = $lineId;
        $this->userText = trim($userText);
        $this->db       = Database::getConnection();
    }

    public function handle($replyToken) {
        if (strpos($this->userText, "/同意請假") === 0) {
            $parts = explode(" ", $this->userText);
            if (count($parts) === 2) {
                $groupId  = $parts[1];
                $userName = $parts[2];
                $this->handleApproveLeave($replyToken, $groupId);
            } else {
                replyTextMessage($replyToken, "❗請使用格式：/同意請假 請假編號");
            }
            return true;
        }

        if (strpos($this->userText, "/同意補打卡") === 0) {
            $parts = explode(" ", $this->userText);
            if (count($parts) === 2) {
                $correctionId = $parts[1];
                $this->handleApproveCorrection($replyToken, $correctionId);
            } else {
                replyTextMessage($replyToken, "❗請使用格式：/同意補打卡 補打卡ID");
            }
            return true;
        }

        return false; // 非簽核指令
    }

    private function handleApproveLeave($replyToken, $groupId) {
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
            replyTextMessage($replyToken, "❌ 找不到你需要簽核的請假單（編號 {$groupId}）。");
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
            $checkStmt = $this->db->prepare("SELECT COUNT(*) FROM leave_approvals WHERE request_id = ? AND status != 'approved'");
            $checkStmt->execute([$row['id']]);
            $remaining = $checkStmt->fetchColumn();
    
            if ($remaining == 0) {
                $this->db->prepare("UPDATE leave_requests SET status = 'approved' WHERE id = ?")
                         ->execute([$row['id']]);
            }
        }
    
        replyTextMessage($replyToken, "✅ 已完成請假簽核（編號 {$groupId}，共 {$approvedCount} 筆）。");
    }
    

    private function handleApproveCorrection($replyToken, $correctionId) {
        // 撈取補打卡資料
        $stmt = $this->db->prepare("SELECT * FROM corrections WHERE id = ? AND status = 'pending'");
        $stmt->execute([$correctionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            replyTextMessage($replyToken, "❌ 找不到待簽核的補打卡資料（ID: {$correctionId}）。");
            return;
        }

        // 更新狀態
        $updateStmt = $this->db->prepare("UPDATE corrections SET status = 'approved', updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$correctionId]);

        replyTextMessage($replyToken, "✅ 已完成補打卡簽核（ID: {$correctionId}）。");

        // TODO: 可加上推播給員工
    }
}
