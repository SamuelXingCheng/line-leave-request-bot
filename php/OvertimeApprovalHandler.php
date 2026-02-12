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
                replyTextMessage($replyToken, "【系統提示】格式錯誤，請使用：/同意加班 加班編號");
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
            replyTextMessage($replyToken, "【系統提示】找不到此加班申請，或該單據已完成簽核。");
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
                replyTextMessage($replyToken, "【權限不足】您不是員工 {$row['user_name']} 的主管，無法簽核。");
                return;
            }
        }
    
        // 3. 更新狀態為 approved
        $updateStmt = $this->db->prepare("
            UPDATE overtime_requests 
            SET status = 'approved'
            WHERE overtime_uuid = ?
        ");
        
        if ($updateStmt->execute([$uuid])) {
            // 4. 計算與格式化時間 (準備顯示在卡片上)
            $start = new DateTime($row['start_at']);
            $end   = new DateTime($row['end_at']);
            $diff  = $start->diff($end);
            $hours = $diff->h + ($diff->i / 60); // 計算時數

            $dateStr = $start->format('Y-m-d');
            $timeRange = $start->format('H:i') . ' ~ ' . $end->format('H:i');

            // 🔥 升級 1：回覆主管 (Flex Message)
            $managerFlex = createBusinessFlex(
                "SUCCESS",           // 頂部狀態
                "加班簽核成功",        // 主標題
                [                    // 內容列表
                    "申請員工" => $row['user_name'],
                    "加班日期" => $dateStr,
                    "加班時段" => $timeRange,
                    "核准時數" => number_format($hours, 1) . " 小時",
                    "簽核狀態" => "已核准 (Approved)"
                ],
                "#06C755"            // 綠色
            );
            
            // 使用 utils.php 的 Flex 回覆函式
            if (function_exists('replyFlexMessage')) {
                replyFlexMessage($replyToken, $managerFlex);
            } else {
                replyTextMessage($replyToken, "加班簽核成功！");
            }

            // 🔥 升級 2：推播通知員工 (Flex Message)
            $employeeFlex = createBusinessFlex(
                "NOTIFICATION",      // 頂部狀態
                "加班申請已通過",      // 主標題
                [                    // 內容列表
                    "加班日期" => $dateStr,
                    "加班時段" => $timeRange,
                    "核准時數" => number_format($hours, 1) . " 小時",
                    "說明"     => "您的加班申請已核准，時數已存入補休。"
                ],
                "#06C755"            // 綠色
            );

            // 使用 utils.php 的 Flex 推播函式
            if (function_exists('pushFlexMessage')) {
                pushFlexMessage($row['user_id'], $employeeFlex);
            } else {
                pushMessage($row['user_id'], ["type" => "text", "text" => "您的加班申請已通過核准。"]);
            }

        } else {
            replyTextMessage($replyToken, "【系統錯誤】資料庫更新失敗，請聯繫管理員。");
        }
    }
}