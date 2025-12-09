<?php
// OvertimeApprovalHandler.php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

class OvertimeApprovalHandler {
    private $lineId;   // 主管 ID
    private $userText;
    private $db;

    public function __construct($lineId, $userText) {
        $this->lineId   = $lineId;
        $this->userText = trim($userText);
        $this->db       = Database::getConnection();
    }

    public function handle($replyToken) {
        // 指令格式：/同意加班 {UUID}
        if (strpos($this->userText, "/同意加班") === 0) {
            $parts = explode(" ", $this->userText);
            if (count($parts) === 2) {
                $uuid = $parts[1];
                $this->handleApproveOvertime($replyToken, $uuid);
            } else {
                replyTextMessage($replyToken, "❗請使用格式：/同意加班 加班編號");
            }
            return true;
        }
        return false;
    }

    private function handleApproveOvertime($replyToken, $uuid) {
        // 1. 撈出待審核的加班紀錄
        $stmt = $this->db->prepare("
            SELECT * FROM overtime_requests 
            WHERE overtime_uuid = ? AND status = 'pending'
        ");
        $stmt->execute([$uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$row) {
            replyTextMessage($replyToken, "❌ 找不到待簽核的加班紀錄（編號: {$uuid}）或已簽核。");
            return;
        }
    
        // 2. 權限檢查：是否為該員工的主管？
        // 先查執行者是否為 Boss
        $bossStmt = $this->db->prepare("SELECT role FROM users WHERE user_id = ?");
        $bossStmt->execute([$this->lineId]);
        $userRole = $bossStmt->fetchColumn();

        if ($userRole !== 'boss') {
            // 如果不是 Boss，檢查是否為直屬主管
            $checkStmt = $this->db->prepare("
                SELECT COUNT(*) 
                FROM user_supervisors 
                WHERE user_id = ? AND supervisor_id = ?
            ");
            $checkStmt->execute([$row['user_id'], $this->lineId]);
            $isSupervisor = $checkStmt->fetchColumn();
        
            if (!$isSupervisor) {
                replyTextMessage($replyToken, "⛔ 你不是員工 {$row['user_name']} 的主管，無法簽核此加班。");
                return;
            }
        }
    
        // 3. 更新狀態為 approved
        $updateStmt = $this->db->prepare("
            UPDATE overtime_requests 
            SET status = 'approved'
            WHERE overtime_uuid = ?
        ");
        $updateStmt->execute([$uuid]);

        // 4. 計算時數 (僅供顯示用)
        $start = new DateTime($row['start_at']);
        $end   = new DateTime($row['end_at']);
        $diff  = $start->diff($end);
        $hours = $diff->h + ($diff->i / 60); // 簡單計算小時

        replyTextMessage($replyToken, 
            "✅ 已核准加班申請\n" .
            "員工：{$row['user_name']}\n" .
            "時間：{$row['start_at']} ~ {$row['end_at']}\n" .
            "時數：約 " . number_format($hours, 1) . " 小時\n" .
            "說明：{$row['reason']}\n\n" .
            "此時數已存入補休帳戶。"
        );
    }
}