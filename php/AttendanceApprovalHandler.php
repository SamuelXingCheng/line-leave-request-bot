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
    
        // 3. 執行核准 (status 改為 success)
        $updateStmt = $this->db->prepare("
            UPDATE attendance_logs 
            SET approval_status = 'approved', 
                status = 'success', 
                approved_at = NOW()
            WHERE attendance_uuid = ?
        ");
        
        if ($updateStmt->execute([$uuid])) {
            $time = substr($row['created_at'], 0, 16); 
            $typeStr = $row['mode']; 
            $reason = $row['reason'] ?? "（未填寫）";

            // 🔥 升級 1：回覆主管 (Flex Message)
            // 使用 utils.php 裡的產生器
            $managerFlex = createBusinessFlex(
                "APPROVED",          // 頂部狀態
                "補打卡核准成功",      // 主標題
                [                    // 內容列表
                    "員工姓名" => $row['employee_name'],
                    "補卡類別" => $typeStr,
                    "補卡時間" => $time,
                    "補卡原因" => $reason,
                    "資料狀態" => "已生效 (Success)"
                ],
                "#06C755"            // 綠色 (成功)
            );
            
            replyFlexMessage($replyToken, $managerFlex);

            // 🔥 升級 2：推播通知員工 (Flex Message)
            $employeeFlex = createBusinessFlex(
                "NOTIFICATION",
                "補打卡申請已通過",
                [
                    "補卡類別" => $typeStr,
                    "核准時間" => date("Y-m-d H:i"),
                    "生效時間" => $time,
                    "說明" => "您的考勤紀錄已更新。"
                ],
                "#06C755"
            );
                           
            pushFlexMessage($row['user_id'], $employeeFlex);

        } else {
            replyTextMessage($replyToken, "【系統錯誤】資料庫更新失敗，請聯繫管理員。");
        }
    }
}