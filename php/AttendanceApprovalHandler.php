<?php
// AttendanceApprovalHandler.php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

class AttendanceApprovalHandler {
    private $lineId;   // 主管 ID
    private $userText;
    private $db;

    public function __construct($lineId, $userText) {
        $this->lineId   = $lineId;
        $this->userText = trim($userText);
        $this->db       = Database::getConnection();
    }

    public function handle($replyToken) {
        if (strpos($this->userText, "/審核打卡") === 0) {
            $parts = explode(" ", $this->userText);
            if (count($parts) === 2) {
                $uuid = $parts[1];
                $this->handleApproveAttendance($replyToken, $uuid);
            } else {
                replyTextMessage($replyToken, "❗請使用格式：/審核打卡 打卡編號");
            }
            return true;
        }
        return false; // 不是打卡審核指令
    }

    private function handleApproveAttendance($replyToken, $uuid) {
        // 撈出待審核的打卡紀錄
        $stmt = $this->db->prepare("
            SELECT al.*, u.name AS employee_name
            FROM attendance_logs al
            JOIN users u ON al.user_id = u.user_id
            WHERE al.attendance_uuid = ? AND al.approval_status = 'pending'
        ");
        $stmt->execute([$uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$row) {
            replyTextMessage($replyToken, "❌ 找不到待簽核的打卡紀錄（編號: {$uuid}）。");
            return;
        }
    
        // 確認主管關係
        $checkStmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM user_supervisors 
            WHERE user_id = ? AND supervisor_id = ?
        ");
        $checkStmt->execute([$row['user_id'], $this->lineId]);
        $isSupervisor = $checkStmt->fetchColumn();
    
        if (!$isSupervisor) {
            replyTextMessage($replyToken, "⛔ 你不是員工 {$row['employee_name']} 的主管，無法簽核這筆打卡。");
            return;
        }
    
        // 更新為 approved
        $updateStmt = $this->db->prepare("
            UPDATE attendance_logs 
            SET approval_status = 'approved', approved_at = NOW()
            WHERE attendance_uuid = ?
        ");
        $updateStmt->execute([$uuid]);
    
        replyTextMessage($replyToken, "✅ 已完成打卡簽核（編號 {$uuid}，員工：{$row['employee_name']}）。");
    
        // TODO: 可加上推播通知員工
    }
    
}
