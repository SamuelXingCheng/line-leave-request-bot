<?php
// php/ModificationApprovalHandler.php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

class ModificationApprovalHandler {
    private $lineId;
    private $text;
    private $db;
    private $replyToken;

    public function __construct($lineId, $text, $replyToken = null) {
        $this->lineId = $lineId;
        $this->text = trim($text);
        $this->replyToken = $replyToken;
        $this->db = Database::getConnection();
    }

    public function handle() {
        // 指令格式: /同意銷假 {UUID}
        if (strpos($this->text, '/同意銷假') !== 0) return false;

        $parts = explode(' ', $this->text);
        if (!isset($parts[1])) return false;
        $uuid = trim($parts[1]); // 這是 UUID

        // 檢查權限 (建議加上主管權限檢查)
        
        $resultMsg = $this->approveModification($uuid);
        
        if ($this->replyToken) {
            replyTextMessage($this->replyToken, $resultMsg);
        }
        return true;
    }

    private function approveModification($uuid) {
        // 🔥 1. 改用 modification_uuid 查詢
        $stmt = $this->db->prepare("SELECT * FROM leave_modifications WHERE modification_uuid = ?");
        $stmt->execute([$uuid]);
        $mod = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$mod || $mod['status'] !== 'pending') return "⚠️ 此申請不存在或已處理過。";

        // 2. 撈出原始假單
        $stmt = $this->db->prepare("SELECT * FROM leave_requests WHERE id = ?");
        $stmt->execute([$mod['leave_request_id']]);
        $original = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$original) return "⚠️ 原始假單已找不到。";

        try {
            $this->db->beginTransaction();

            // --- A. 延後放假 (修改 Start) ---
            if ($mod['type'] === 'delay_start') {
                $newStart = str_replace('T', ' ', $mod['target_date']); 
                if (strlen($newStart) == 10) $newStart .= " 09:00:00"; 
                
                $update = $this->db->prepare("UPDATE leave_requests SET start_at = ? WHERE id = ?");
                $update->execute([$newStart, $original['id']]);
            }

            // --- B. 提早結束 (修改 End) ---
            elseif ($mod['type'] === 'early_return') {
                $newEnd = str_replace('T', ' ', $mod['target_date']);
                if (strlen($newEnd) == 10) $newEnd .= " 18:00:00"; 

                $update = $this->db->prepare("UPDATE leave_requests SET end_at = ? WHERE id = ?");
                $update->execute([$newEnd, $original['id']]);
            }

            // --- C. 中途銷假 (拆單) ---
            elseif ($mod['type'] === 'split') {
                $workDate = new DateTime($mod['target_date']);
                
                $prevDay = clone $workDate;
                $prevDay->modify('-1 day');
                $newEndForOld = $prevDay->format('Y-m-d 17:30:00'); // 改成貴公司下班時間

                $nextDay = clone $workDate;
                $nextDay->modify('+1 day');
                $newStartForNew = $nextDay->format('Y-m-d 08:30:00'); // 改成貴公司上班時間

                // 更新舊單
                $update = $this->db->prepare("UPDATE leave_requests SET end_at = ? WHERE id = ?");
                $update->execute([$newEndForOld, $original['id']]);

                // 只有當「新開始時間」還早於「原結束時間」時，才需要插入新單
                if ($newStartForNew < $original['end_at']) {
                    $insert = $this->db->prepare("
                        INSERT INTO leave_requests 
                        (user_id, leave_type, start_at, end_at, reason, request_group_id, supervisor_ids, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', NOW())
                    ");
                    $insert->execute([
                        $original['user_id'],
                        $original['leave_type'],
                        $newStartForNew,
                        $original['end_at'],
                        $original['reason'] . " (銷假拆單)",
                        $original['request_group_id'], // 繼承原本的 GroupID
                        $original['supervisor_ids']
                    ]);
                }
            }

            // 更新申請狀態
            // 🔥 這裡記得用 id (primary key) 更新，因為 $mod 已經撈到了
            $stmt = $this->db->prepare("UPDATE leave_modifications SET status = 'approved' WHERE id = ?");
            $stmt->execute([$mod['id']]);

            $this->db->commit();
            
            // 通知員工
            pushMessage($original['user_id'], [
                "type" => "text", 
                "text" => "✅ 您的銷假/修改申請已核准！\n假單已自動更新。"
            ]);

            return "✅ 核准成功！資料庫已更新。";

        } catch (Exception $e) {
            $this->db->rollBack();
            return "❌ 系統錯誤：" . $e->getMessage();
        }
    }
}