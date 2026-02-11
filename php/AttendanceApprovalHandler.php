<?php
// AttendanceApprovalHandler.php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

class AttendanceApprovalHandler {
    private $lineId;   // 主管 ID
    private $userText; // 使用者傳來的文字
    private $db;

    public function __construct($lineId, $userText) {
        $this->lineId   = $lineId;
        $this->userText = trim($userText);
        $this->db       = Database::getConnection();
    }

    public function handle($replyToken) {
        // 配合 LIFF 連結指令：/同意補卡
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
        // 1. 撈出待審核紀錄
        $stmt = $this->db->prepare("
            SELECT al.*, u.name AS employee_name
            FROM attendance_logs al
            JOIN users u ON al.user_id = u.user_id
            WHERE al.attendance_uuid = ? AND al.approval_status = 'pending'
        ");
        $stmt->execute([$uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$row) {
            replyTextMessage($replyToken, "【系統提示】找不到此補卡申請，或該申請已完成簽核。");
            return;
        }
    
        // 2. 權限檢查
        $checkStmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM user_supervisors 
            WHERE user_id = ? AND supervisor_id = ?
        ");
        $checkStmt->execute([$row['user_id'], $this->lineId]);
        $isSupervisor = $checkStmt->fetchColumn();
    
        if (!$isSupervisor) {
            replyTextMessage($replyToken, "【權限警告】您不是該員工的直屬主管，無法執行簽核。");
            return;
        }
    
        // 3. 執行核准 (status 改為 valid 代表生效)
        $updateStmt = $this->db->prepare("
            UPDATE attendance_logs 
            SET approval_status = 'approved', 
                status = 'success',  
                approved_at = NOW()
            WHERE attendance_uuid = ?
        ");
        
        if ($updateStmt->execute([$uuid])) {
            $time = substr($row['created_at'], 0, 16); // 格式 YYYY-MM-DD HH:mm
            $typeStr = $row['mode']; // 上班 或 下班
            $reason = $row['reason'] ?? "（未填寫）";

            // 回覆主管 (商務版格式)
            $msg = "【補打卡簽核通知】\n" .
                   "────────────────\n" .
                   "簽核狀態｜已核准 (Approved)\n" .
                   "員工姓名｜{$row['employee_name']}\n" .
                   "補卡類別｜{$typeStr}\n" .
                   "補卡時間｜{$time}\n" .
                   "補卡原因｜{$reason}\n" .
                   "────────────────\n" .
                   "系統提示｜資料已正式寫入考勤紀錄。";
            
            replyTextMessage($replyToken, $msg);

            // 推播通知員工 (商務版格式)
            $employeeMsg = "【系統通知】\n" .
                           "────────────────\n" .
                           "您的「{$typeStr}」補打卡申請已通過核准。\n" .
                           "生效時間｜{$time}";
                           
            pushMessage($row['user_id'], ["type" => "text", "text" => $employeeMsg]);
        } else {
            replyTextMessage($replyToken, "【系統錯誤】資料庫更新失敗，請聯繫管理員。");
        }
    }
}