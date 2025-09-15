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
                $requestGroupId = $parts[1];
                $this->handleDeleteRequest($requestGroupId);
            } else {
                replyTextMessage($this->replyToken, "❗請使用格式：/刪除請假 [請假ID]");
            }
            return true;
        }
        return false;
    }

    private function handleDeleteRequest($requestGroupId) {
        // 1. 查詢整組請假紀錄
        $stmt = $this->db->prepare("SELECT * FROM leave_requests WHERE request_group_id = ?");
        $stmt->execute([$requestGroupId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            replyTextMessage($this->replyToken, "❌ 找不到該筆請假紀錄。");
            return;
        }

        // 2. 確認紀錄屬於本人
        foreach ($rows as $row) {
            if ($row['user_id'] !== $this->userId) {
                replyTextMessage($this->replyToken, "⚠️ 你無權刪除此請假紀錄。");
                return;
            }
        }

        // 3. 確認是否還有 pending
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

        // 4. 先刪除 leave_approvals（避免外鍵限制）
        $stmt = $this->db->prepare("
            DELETE FROM leave_approvals 
            WHERE request_id IN (
                SELECT id FROM leave_requests WHERE request_group_id = ?
            )
        ");
        $stmt->execute([$requestGroupId]);

        // 5. 再刪除 leave_requests
        $stmt = $this->db->prepare("DELETE FROM leave_requests WHERE request_group_id = ?");
        $stmt->execute([$requestGroupId]);

        // 6. 查詢使用者上次的範圍
        $session  = new UserSession($this->userId);
        $startStr = $session->get("last_query_start");
        $endStr   = $session->get("last_query_end");

        if ($startStr && $endStr) {
            $startDate = new DateTimeImmutable($startStr);
            $endDate   = new DateTimeImmutable($endStr);
            
            // ✅ 重用 LeaveQueryHandler，但先抓資料
            $queryHandler = new LeaveQueryHandler($this->userId, "", $this->replyToken);
        
            $stmt = $this->db->prepare("
                SELECT request_group_id, start_at, end_at, leave_type, status
                FROM leave_requests
                WHERE user_id = ? AND start_at >= ? AND end_at <= ?
                ORDER BY start_at DESC
            ");
            $stmt->execute([$this->userId, $startDate->format("Y-m-d 00:00:00"), $endDate->format("Y-m-d 23:59:59")]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("🗑️ DeleteHandler 呼叫 handleWithDateRange, start=" . $startDate->format("Y-m-d") . ", end=" . $endDate->format("Y-m-d"));

            if ($rows) {
                $queryHandler->handleWithDateRange(
                    $startDate,
                    $endDate,
                    "🗑️ 請假紀錄已成功刪除。以下為最新紀錄："
                );
            } else {
                replyTextMessage($this->replyToken, "🗑️ 請假紀錄已成功刪除，目前已無其他紀錄。");
            }
        } else {
            replyTextMessage($this->replyToken, "🗑️ 請假紀錄已刪除");
        }
        
    }
}
