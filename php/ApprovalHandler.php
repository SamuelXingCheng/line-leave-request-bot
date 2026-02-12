<?php
// ApprovalHandler.php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

class ApprovalHandler {
    private $lineId;     // 主管 ID
    private $userText;
    private $db;
    private $replyToken;

    public function __construct($lineId, $userText, $replyToken) {
        $this->lineId     = $lineId;
        $this->userText   = trim($userText);
        $this->replyToken = $replyToken;
        $this->db         = Database::getConnection();
    }

    public function handle() {
        // 為了配合 LIFF 連結或指令習慣，支援多種觸發關鍵字
        if (strpos($this->userText, "/同意請假") === 0 || strpos($this->userText, "/核准請假") === 0) {
            $parts = preg_split('/\s+/', $this->userText);
            if (count($parts) >= 2) {
                $groupId = $parts[1];
                $this->handleApproveLeave($groupId);
            } else {
                replyTextMessage($this->replyToken, "【系統提示】格式錯誤，請使用連結點擊或輸入：/同意請假 請假編號");
            }
            return true;
        }

        return false;
    }

    private function handleApproveLeave($groupId) {
        // 1. 撈取資料 (包含假別、時間等資訊)
        $stmt = $this->db->prepare("
            SELECT lr.id, lr.user_id, lr.user_name, lr.reason, lr.leave_type, lr.start_at, lr.end_at, la.status AS approval_status
            FROM leave_requests lr
            JOIN leave_approvals la ON lr.id = la.request_id
            WHERE lr.request_group_id = ? AND la.supervisor_id = ? AND la.status = 'pending'
        ");
        $stmt->execute([$groupId, $this->lineId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            replyTextMessage($this->replyToken, "【系統提示】找不到此請假單（編號 {$groupId}），或該單據已完成簽核。");
            return;
        }

        // 2. 更新主管簽核狀態
        $updateStmt = $this->db->prepare("
            UPDATE leave_approvals
            SET status = 'approved', updated_at = NOW()
            WHERE supervisor_id = ? AND request_id = ?
        ");

        $approvedCount = 0;
        $lastRow = null; // 用來抓取最後一筆資料做為顯示代表

        foreach ($rows as $row) {
            $updateStmt->execute([$this->lineId, $row['id']]);
            $approvedCount++;
            $lastRow = $row;

            // 3. 檢查是否所有主管都簽完 (決定是否讓假單正式生效)
            $checkStmt = $this->db->prepare("
                SELECT COUNT(*) 
                FROM leave_approvals 
                WHERE request_id = ? AND status != 'approved'
            ");
            $checkStmt->execute([$row['id']]);
            $remaining = $checkStmt->fetchColumn();

            if ($remaining == 0) {
                // (A) 正式生效：更新 leave_requests
                $this->db->prepare("UPDATE leave_requests SET status = 'approved' WHERE id = ?")
                         ->execute([$row['id']]);

                // (B) 通知員工 (Flex Message)
                $this->sendApprovalNotificationToApplicant($row);
            }
        }

        // 4. 回覆主管 (改用商務 Flex Message)
        if ($lastRow) {
            $startStr = date('Y-m-d H:i', strtotime($lastRow['start_at']));
            $endStr   = date('Y-m-d H:i', strtotime($lastRow['end_at']));

            // 呼叫 utils.php 的產生器
            $managerFlex = createBusinessFlex(
                "APPROVED",           // 頂部狀態
                "請假簽核成功",         // 主標題
                [                     // 內容
                    "申請員工" => $lastRow['user_name'],
                    "假別"     => $lastRow['leave_type'],
                    "開始時間" => $startStr,
                    "結束時間" => $endStr,
                    "簽核筆數" => "共 {$approvedCount} 筆",
                    "簽核狀態" => "已核准 (Approved)"
                ],
                "#06C755"             // 綠色
            );

            if (function_exists('replyFlexMessage')) {
                replyFlexMessage($this->replyToken, $managerFlex);
            } else {
                replyTextMessage($this->replyToken, "【簽核成功】已核准 {$lastRow['user_name']} 的請假申請。");
            }
        }
    }

    private function sendApprovalNotificationToApplicant($row) {
        $applicantId = $row['user_id'];
        
        $startStr = date('Y-m-d H:i', strtotime($row['start_at']));
        $endStr   = date('Y-m-d H:i', strtotime($row['end_at']));
        $reason   = $row['reason'] ?: "（未填寫）";

        // 呼叫 utils.php 的產生器 (統一風格)
        $employeeFlex = createBusinessFlex(
            "NOTIFICATION",           // 頂部狀態
            "請假申請已通過",           // 主標題
            [
                "假別"     => $row['leave_type'],
                "開始時間" => $startStr,
                "結束時間" => $endStr,
                "請假原因" => $reason,
                "審核結果" => "通過 (Approved)"
            ],
            "#06C755"                 // 綠色
        );

        // 呼叫 utils.php 的推播函式
        if (function_exists('pushFlexMessage')) {
            pushFlexMessage($applicantId, $employeeFlex);
        } else {
            pushMessage($applicantId, [
                "type" => "text", 
                "text" => "【系統通知】您的 {$row['leave_type']} 申請已通過核准。\n時間：{$startStr} ~ {$endStr}"
            ]);
        }
    }
}