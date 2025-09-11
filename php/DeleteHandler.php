<?php
// DeleteHandler.php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/LeaveQueryHandler.php';

class DeleteHandler {
    private $userId;
    private $event;
    private $userText;

    public function __construct($userId, $event) {
        $this->userId = $userId;
        $this->event = $event;
        $this->userText = $event['message']['text'];
    }

    public function handle() {
        if (strpos($this->userText, "/刪除請假") === 0) {
            $parts = explode(" ", $this->userText);

            if (count($parts) === 2) {
                $requestGroupId = $parts[1];
                $this->handleDeleteRequest($requestGroupId);
            } else {
                replyTextMessage($this->event['replyToken'], "❗請使用格式：/刪除請假 [請假ID]");
            }
            return true;
        }
        return false;
    }

    private function handleDeleteRequest($requestGroupId) {
        $userId = $this->event['source']['userId'];
        $db = Database::getConnection();

        // 1. 查詢整組請假紀錄
        $stmt = $db->prepare("SELECT * FROM leave_requests WHERE request_group_id = ?");
        $stmt->execute([$requestGroupId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            replyTextMessage($this->event['replyToken'], "❌ 找不到該筆請假紀錄。");
            return;
        }

        // 2. 確認紀錄屬於本人
        foreach ($rows as $row) {
            if ($row['user_id'] !== $userId) {
                replyTextMessage($this->event['replyToken'], "⚠️ 你無權刪除此請假紀錄。");
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
            replyTextMessage($this->event['replyToken'], "❌ 此請假已簽核完成，無法刪除。");
            return;
        }

        // 4. 先刪除 leave_approvals（避免外鍵限制）
        $stmt = $db->prepare("
            DELETE FROM leave_approvals 
            WHERE request_id IN (
                SELECT id FROM leave_requests WHERE request_group_id = ?
            )
        ");
        $stmt->execute([$requestGroupId]);

        // 5. 再刪除 leave_requests
        $stmt = $db->prepare("DELETE FROM leave_requests WHERE request_group_id = ?");
        $stmt->execute([$requestGroupId]);

        // 6. 再次查詢使用者上次的範圍
        $session = new UserSession($userId);
        $startStr = $session->get("last_query_start");
        $endStr   = $session->get("last_query_end");

        if ($startStr && $endStr) {
            $startDate = new DateTimeImmutable($startStr);
            $endDate   = new DateTimeImmutable($endStr);

            // ✅ 重用 LeaveQueryHandler，顯示最新紀錄
            $queryHandler = new LeaveQueryHandler($userId, "");
            $queryHandler->handleWithDateRange(
                $this->event['replyToken'],
                $startDate,
                $endDate,
                "🗑️ 請假紀錄已成功刪除。以下為最新紀錄："
            );
        } else {
            replyTextMessage($this->event['replyToken'], "🗑️ 請假紀錄已刪除");
        }
    }
}
