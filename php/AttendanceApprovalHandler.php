<?php
// AttendanceApprovalHandler.php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

class AttendanceApprovalHandler {
    private $lineId;
    private $userText;
    private $db;

    public function __construct($lineId, $userText) {
        $this->lineId   = $lineId;
        $this->userText = trim($userText);
        $this->db       = Database::getConnection();
    }

    public function handle($replyToken) {
        if (strpos($this->userText, "/同意補卡") === 0) {
            $parts = explode(" ", $this->userText);
            if (count($parts) === 2) {
                $uuid = $parts[1];
                $this->handleApproveAttendance($replyToken, $uuid);
            } else {
                replyTextMessage($replyToken, "【系統提示】格式錯誤，請使用連結點擊。");
            }
            return true;
        }
        return false; 
    }

    private function handleApproveAttendance($replyToken, $uuid) {
        try {
            // 🔥 1. 開啟交易防護
            $this->db->beginTransaction();

            // 🔥 2. 撈出待審核紀錄並加上 FOR UPDATE 鎖定
            $stmt = $this->db->prepare("
                SELECT al.*, u.name AS employee_name
                FROM attendance_logs al
                JOIN users u ON al.user_id = u.user_id
                WHERE al.attendance_uuid = ? AND al.approval_status = 'pending'
                FOR UPDATE
            ");
            $stmt->execute([$uuid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
            if (!$row) {
                $this->db->rollBack();
                replyTextMessage($replyToken, "【系統提示】找不到此補卡申請，或該申請已完成簽核。");
                return;
            }
        
            // 3. 權限檢查 (包含老闆特權)
            $checkStmt = $this->db->prepare("SELECT COUNT(*) FROM user_supervisors WHERE user_id = ? AND supervisor_id = ?");
            $checkStmt->execute([$row['user_id'], $this->lineId]);
            $isSupervisor = $checkStmt->fetchColumn();

            $bossStmt = $this->db->prepare("SELECT role FROM users WHERE user_id = ?");
            $bossStmt->execute([$this->lineId]);
            $isBoss = ($bossStmt->fetchColumn() === 'boss');
        
            if (!$isSupervisor && !$isBoss) {
                $this->db->rollBack();
                replyTextMessage($replyToken, "【權限警告】您不是該員工的直屬主管，無法執行簽核。");
                return;
            }
        
            // 🔥 4. 執行核准 (拔除錯誤的 start_time/end_time 邏輯，單純更新狀態)
            $updateStmt = $this->db->prepare("
                UPDATE attendance_logs 
                SET approval_status = 'approved', 
                    status = 'success',
                    approved_at = NOW()
                WHERE attendance_uuid = ?
            ");
            $updateStmt->execute([$uuid]);

            $this->db->commit();

            // 5. 處理回覆與推播通知
            $dt = date('Y-m-d H:i', strtotime($row['created_at']));

            $flexData = [
                "員工姓名" => $row['employee_name'],
                "打卡類型" => $row['mode'],
                "打卡時間" => $dt,
                "審核狀態" => "主管核准 (Approved)"
            ];

            // 給主管的回覆卡片
            replyMessage($replyToken, createBusinessFlex("APPROVED", "補登已核准", $flexData, "#06C755"));

            // 發送給員工的結果卡片
            if (function_exists('pushMessage')) {
                pushMessage($row['user_id'], createBusinessFlex("NOTIFICATION", "補打卡已核准", $flexData, "#06C755"));
            }

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            replyTextMessage($replyToken, "【系統錯誤】簽核過程發生錯誤，請稍後再試。");
        }
    }

}
