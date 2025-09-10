<?php
// Session.php
require_once __DIR__ . '/Db.php';

class UserSession {
    private $lineId;  // ✅ 移除 typed property
    private $db;

    public function __construct($lineId) {
        $this->lineId = $lineId;
        $this->db = Database::getConnection(); // ✅ 確保一致
    }

    public function getStep() {
        $stmt = $this->db->prepare("SELECT step FROM sessions WHERE line_id = ?");
        $stmt->execute([$this->lineId]);
        $row = $stmt->fetch();
        return $row ? $row['step'] : null;
    }

    public function setStep($step) {
        $stmt = $this->db->prepare("
            INSERT INTO sessions (line_id, step, data)
            VALUES (?, ?, '{}')
            ON DUPLICATE KEY UPDATE step = VALUES(step), updated_at = NOW()
        ");
        $stmt->execute([$this->lineId, $step]);
    }

    public function set($key, $value) {
        $currentData = $this->getData();
        $currentData[$key] = $value;
        $json = json_encode($currentData, JSON_UNESCAPED_UNICODE);

        $stmt = $this->db->prepare("
            INSERT INTO sessions (line_id, step, data)
            VALUES (?, '', ?)
            ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = NOW()
        ");
        $stmt->execute([$this->lineId, $json]);
    }

    public function get($key) {
        $data = $this->getData();
        return isset($data[$key]) ? $data[$key] : null;
    }

    public function reset() {
        $stmt = $this->db->prepare("
            INSERT INTO sessions (line_id, step, data)
            VALUES (?, '', '{}')
            ON DUPLICATE KEY UPDATE step = '', data = '{}', updated_at = NOW()
        ");
        $stmt->execute([$this->lineId]);
    }

    // 清空內容（保留 row）
    public function clear() {
        $stmt = $this->db->prepare("UPDATE sessions SET step = NULL, data = '{}' WHERE line_id = ?");
        $stmt->execute([$this->lineId]);
    }

    private function getData() {
        $stmt = $this->db->prepare("SELECT data FROM sessions WHERE line_id = ?");
        $stmt->execute([$this->lineId]);
        $row = $stmt->fetch();
        if ($row && $row['data']) {
            $decoded = json_decode($row['data'], true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    // 完全刪除 row
    public function delete() {
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE line_id = ?");
        $stmt->execute([$this->lineId]);
    }
}
