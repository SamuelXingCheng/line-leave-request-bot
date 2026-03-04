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
        try {
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
            $bossStmt = $this->db->prepare("SELECT role FROM users WHERE user_id = ?");
            $bossStmt->execute([$this->lineId]);
            $userRole = $bossStmt->fetchColumn();

            if ($userRole !== 'boss') {
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
        
            // 3. 開啟交易 (Transaction) 確保資料一致性
            $this->db->beginTransaction();

            // (A) 更新加班單狀態
            $updateStmt = $this->db->prepare("
                UPDATE overtime_requests 
                SET status = 'approved'
                WHERE overtime_uuid = ?
            ");
            $updateStmt->execute([$this->lineId, $uuid]);

            // (B) 🔥 關鍵修正：將加班時數加入員工的「補休存摺」
            $addHours = floatval($row['hours']); // 確保是數字
            $updateUser = $this->db->prepare("
                UPDATE users 
                SET comp_leave_hours = comp_leave_hours + ? 
                WHERE user_id = ?
            ");
            $updateUser->execute([$addHours, $row['user_id']]);

            // 提交交易
            $this->db->commit();

            // ----------------------------------------------------
            // 4. 發送通知
            // ----------------------------------------------------
            
            // 準備顯示用的時間字串
            $start = new DateTime($row['start_at']);
            $end   = new DateTime($row['end_at']);
            $dateStr = $start->format('Y-m-d');
            $timeRange = $start->format('H:i') . ' ~ ' . $end->format('H:i');

            // 回覆主管 (Flex Message)
            $managerFlex = createBusinessFlex(
                "SUCCESS",
                "加班簽核成功",
                [
                    "申請員工" => $row['user_name'],
                    "加班日期" => $dateStr,
                    "加班時段" => $timeRange,
                    "核准時數" => number_format($addHours, 1) . " 小時",
                    "目前餘額" => "已自動存入員工補休帳戶" // 提示主管已入帳
                ],
                "#06C755"
            );
            
            if (function_exists('replyFlexMessage')) {
                replyFlexMessage($replyToken, $managerFlex);
            } else {
                replyTextMessage($replyToken, "加班簽核成功！時數已存入。");
            }

            // 推播通知員工 (Flex Message)
            $employeeFlex = createBusinessFlex(
                "NOTIFICATION",
                "加班申請已通過",
                [
                    "加班日期" => $dateStr,
                    "加班時段" => $timeRange,
                    "核准時數" => number_format($addHours, 1) . " 小時",
                    "說明"     => "時數已存入您的補休存摺，可至「查詢請假」查看餘額。"
                ],
                "#06C755"
            );

            if (function_exists('pushFlexMessage')) {
                pushFlexMessage($row['user_id'], $employeeFlex);
            } else {
                pushMessage($row['user_id'], ["type" => "text", "text" => "您的加班申請已核准，時數已存入。"]);
            }

        } catch (Exception $e) {
            // 如果發生錯誤，回滾交易 (取消所有變更)
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Overtime Approval Error: " . $e->getMessage());
            replyTextMessage($replyToken, "【系統錯誤】簽核失敗，請稍後再試。");
        }
    }
}