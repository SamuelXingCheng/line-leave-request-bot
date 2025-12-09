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
        // 1. 刪除請假
        if (strpos($this->userText, "/刪除請假") === 0) {
            $parts = explode(" ", $this->userText);

            if (count($parts) === 2) {
                $requestGroupId = $parts[1];
                $this->handleDeleteRequest($requestGroupId);
            } else {
                replyTextMessage($this->replyToken, "❗請使用格式：/刪除請假 [請假ID]");
            }
            return true;
        }

        // 2. 🔥【新增】刪除打卡
        if (strpos($this->userText, "/刪除打卡") === 0) {
            $parts = explode(" ", $this->userText);

            if (count($parts) === 2) {
                $uuid = $parts[1];
                $this->handleDeleteAttendance($uuid);
            } else {
                replyTextMessage($this->replyToken, "❗請使用格式：/刪除打卡 [打卡編號]");
            }
            return true;
        }

        // 3. 🔥【新增】刪除加班
        if (strpos($this->userText, "/刪除加班") === 0) {
            $parts = explode(" ", $this->userText);
            if (count($parts) === 2) {
                $this->handleDeleteOvertime($parts[1]);
            } else {
                replyTextMessage($this->replyToken, "❗請使用格式：/刪除加班 [加班編號]");
            }
            return true;
        }

        return false;
    }

    /**
     * 刪除請假 (原有功能)
     */
    private function handleDeleteRequest($requestGroupId) {
        $stmt = $this->db->prepare("SELECT * FROM leave_requests WHERE request_group_id = ?");
        $stmt->execute([$requestGroupId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            replyTextMessage($this->replyToken, "❌ 找不到該筆請假紀錄。");
            return;
        }

        foreach ($rows as $row) {
            if ($row['user_id'] !== $this->userId) {
                replyTextMessage($this->replyToken, "⚠️ 你無權刪除此請假紀錄。");
                return;
            }
        }

        $hasPending = false;
        foreach ($rows as $row) {
            if ($row['status'] === "pending") {
                $hasPending = true;
                break;
            }
        }
        if (!$hasPending) {
            replyTextMessage($this->replyToken, "❌ 此請假已簽核完成，無法刪除。");
            return;
        }

        $stmt = $this->db->prepare("
            DELETE FROM leave_approvals 
            WHERE request_id IN (
                SELECT id FROM leave_requests WHERE request_group_id = ?
            )
        ");
        $stmt->execute([$requestGroupId]);

        $stmt = $this->db->prepare("DELETE FROM leave_requests WHERE request_group_id = ?");
        $stmt->execute([$requestGroupId]);

        // 嘗試重新載入列表
        $session  = new UserSession($this->userId);
        $startStr = $session->get("last_query_start");
        $endStr   = $session->get("last_query_end");

        if ($startStr && $endStr) {
            $startDate = new DateTimeImmutable($startStr);
            $endDate   = new DateTimeImmutable($endStr);
            $queryHandler = new LeaveQueryHandler($this->userId, "", $this->replyToken);
            $queryHandler->handleWithDateRange($startDate, $endDate, "請假紀錄已成功刪除。以下為最新紀錄：");
        } else {
            replyTextMessage($this->replyToken, "請假紀錄已刪除");
        }
    }

    /**
     * 🔥【新增】刪除打卡
     */
    private function handleDeleteAttendance($uuid) {
        // 1. 查詢紀錄
        $stmt = $this->db->prepare("SELECT user_id, approval_status FROM attendance_logs WHERE attendance_uuid = ?");
        $stmt->execute([$uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            replyTextMessage($this->replyToken, "❌ 找不到該筆打卡紀錄。");
            return;
        }

        // 2. 檢查權限
        if ($row['user_id'] !== $this->userId) {
            replyTextMessage($this->replyToken, "⚠️ 你無權刪除此紀錄。");
            return;
        }

        // 3. 檢查狀態
        if ($row['approval_status'] !== 'pending') {
            replyTextMessage($this->replyToken, "❌ 只能刪除「待審核」的打卡紀錄。");
            return;
        }

        // 4. 執行刪除
        $delStmt = $this->db->prepare("DELETE FROM attendance_logs WHERE attendance_uuid = ?");
        $delStmt->execute([$uuid]);

        replyTextMessage($this->replyToken, "已成功刪除該筆打卡申請。");
    }

    /**
     * 🔥【新增】刪除加班
     */
    private function handleDeleteOvertime($uuid) {
        // 1. 查詢
        $stmt = $this->db->prepare("SELECT user_id, status FROM overtime_requests WHERE overtime_uuid = ?");
        $stmt->execute([$uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            replyTextMessage($this->replyToken, "❌ 找不到該筆加班紀錄。");
            return;
        }

        // 2. 權限
        if ($row['user_id'] !== $this->userId) {
            replyTextMessage($this->replyToken, "⚠️ 你無權刪除此紀錄。");
            return;
        }

        // 3. 狀態
        if ($row['status'] !== 'pending') {
            replyTextMessage($this->replyToken, "❌ 只能刪除「待審核」的加班紀錄。");
            return;
        }

        // 4. 刪除
        $delStmt = $this->db->prepare("DELETE FROM overtime_requests WHERE overtime_uuid = ?");
        $delStmt->execute([$uuid]);

        replyTextMessage($this->replyToken, "🗑️ 已成功刪除該筆加班申請。");
    }
    
}