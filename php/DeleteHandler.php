<?php
// DeleteHandler.php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/LeaveQueryHandler.php';
require_once __DIR__ . '/utils.php';

class DeleteHandler {
    private $userId;
    private $userText;
    private $replyToken;
    private $db;

    public function __construct($userId, $userText, $replyToken) {
        $this->userId     = $userId;
        $this->userText   = trim($userText);
        $this->replyToken = $replyToken;
        $this->db         = Database::getConnection();
    }

    public function handle() {
        if (strpos($this->userText, "/刪除請假") === 0) {
            $parts = explode(" ", $this->userText);
            if (count($parts) === 2) {
                $this->handleDeleteRequest($parts[1]);
            } else {
                replyTextMessage($this->replyToken, "❗請使用格式：/刪除請假 [請假ID]");
            }
            return true;
        }

        if (strpos($this->userText, "/刪除打卡") === 0) {
            $parts = explode(" ", $this->userText);
            if (count($parts) === 2) {
                $this->handleDeleteAttendance($parts[1]);
            }
            return true;
        }

        if (strpos($this->userText, "/刪除加班") === 0) {
            $parts = explode(" ", $this->userText);
            if (count($parts) === 2) {
                $this->handleDeleteOvertime($parts[1]);
            }
            return true;
        }

        return false;
    }

    /**
     * 刪除請假並退還時數
     */
    private function handleDeleteRequest($requestGroupId) {
        // 1. 查詢紀錄與扣抵明細
        $stmt = $this->db->prepare("SELECT * FROM leave_requests WHERE request_group_id = ?");
        $stmt->execute([$requestGroupId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            replyTextMessage($this->replyToken, "❌ 找不到該筆請假紀錄。");
            return;
        }

        // 2. 檢查權限與狀態
        $totalRefundAnnual = 0;
        $totalRefundComp = 0;

        foreach ($rows as $row) {
            if ($row['user_id'] !== $this->userId) {
                replyTextMessage($this->replyToken, "⚠️ 你無權刪除此請假紀錄。");
                return;
            }
            if ($row['status'] !== "pending") {
                replyTextMessage($this->replyToken, "❌ 此請假已簽核完成，無法撤回。");
                return;
            }
            // 累計需要退還的時數
            $totalRefundAnnual += floatval($row['deduct_annual'] ?? 0);
            $totalRefundComp   += floatval($row['deduct_comp'] ?? 0);
        }

        try {
            // 3. 🔥 開啟交易進行退款與刪除
            $this->db->beginTransaction();

            // (A) 退還時數到使用者的存摺
            if ($totalRefundAnnual > 0 || $totalRefundComp > 0) {
                $updUser = $this->db->prepare("
                    UPDATE users 
                    SET annual_leave_hours = annual_leave_hours + ?, 
                        comp_leave_hours = comp_leave_hours + ? 
                    WHERE user_id = ?
                ");
                $updUser->execute([$totalRefundAnnual, $totalRefundComp, $this->userId]);
            }

            // (B) 刪除簽核關聯
            $delApp = $this->db->prepare("
                DELETE FROM leave_approvals 
                WHERE request_id IN (SELECT id FROM leave_requests WHERE request_group_id = ?)
            ");
            $delApp->execute([$requestGroupId]);

            // (C) 刪除假單紀錄
            $delReq = $this->db->prepare("DELETE FROM leave_requests WHERE request_group_id = ?");
            $delReq->execute([$requestGroupId]);

            $this->db->commit();

            // 成功訊息並載入最新列表
            $msg = "✅ 申請已撤回，時數已退還：\n- 特休：" . $totalRefundAnnual . " 小時\n- 補休：" . $totalRefundComp . " 小時";
            $this->reloadList($msg);

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            replyTextMessage($this->replyToken, "【系統錯誤】刪除失敗：" . $e->getMessage());
        }
    }

    /**
     * 輔助函式：重新整理請假列表
     */
    private function reloadList($prefix) {
            $session  = new UserSession($this->userId, $this->db);
        $startStr = $session->get("last_query_start");
        $endStr   = $session->get("last_query_end");

        if ($startStr && $endStr) {
            $queryHandler = new LeaveQueryHandler($this->userId, "", $this->replyToken);
            $queryHandler->handleWithDateRange(new DateTimeImmutable($startStr), new DateTimeImmutable($endStr), $prefix);
        } else {
            replyTextMessage($this->replyToken, $prefix);
        }
    }

    private function handleDeleteAttendance($uuid) {
        $stmt = $this->db->prepare("SELECT user_id, approval_status FROM attendance_logs WHERE attendance_uuid = ?");
        $stmt->execute([$uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['user_id'] !== $this->userId) {
            replyTextMessage($this->replyToken, "❌ 找不到紀錄或權限不足。");
            return;
        }

        if ($row['approval_status'] !== 'pending') {
            replyTextMessage($this->replyToken, "❌ 只能刪除「待審核」的紀錄。");
            return;
        }

        $this->db->prepare("DELETE FROM attendance_logs WHERE attendance_uuid = ?")->execute([$uuid]);
        replyTextMessage($this->replyToken, "已成功刪除該筆打卡申請。");
    }

    private function handleDeleteOvertime($uuid) {
        $stmt = $this->db->prepare("SELECT user_id, status FROM overtime_requests WHERE overtime_uuid = ?");
        $stmt->execute([$uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['user_id'] !== $this->userId) {
            replyTextMessage($this->replyToken, "❌ 找不到紀錄或權限不足。");
            return;
        }

        if ($row['status'] !== 'pending') {
            replyTextMessage($this->replyToken, "❌ 只能刪除「待審核」的紀錄。");
            return;
        }

        // 加班刪除不需退款，因為加班是「核准後」才加時數
        $this->db->prepare("DELETE FROM overtime_requests WHERE overtime_uuid = ?")->execute([$uuid]);
        replyTextMessage($this->replyToken, "🗑️ 已成功刪除該筆加班申請。");
    }
}