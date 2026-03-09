<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

header('Content-Type: application/json');
$db = Database::getConnection();
$action = $_GET['action'] ?? '';

try {
    if ($action === 'list') {
        $lineId = $_GET['lineId'] ?? '';
        if (!$lineId) throw new Exception("缺少 Line ID");

        // 1. 先查出主管自己的 數字ID (id)
        // 注意：這裡假設 users 表主鍵是 id (數字)，user_id 是 LINE ID
        $stmtSup = $db->prepare("SELECT id FROM users WHERE user_id = ?"); // 修正：用 user_id (LINE ID) 查 id
        $stmtSup->execute([$lineId]);
        $supervisor = $stmtSup->fetch(PDO::FETCH_ASSOC);
        
        // 如果查不到，可能是主管資料還沒建，回傳空陣列
        if (!$supervisor) { echo json_encode(['status'=>'success', 'leaves'=>[], 'mods'=>[], 'history'=>[]]); exit; }
        
        $supNumericId = $supervisor['id'];

        // 2. 查出下屬的 LINE ID (user_id) 清單
        // 我們 JOIN users 表，直接把下屬的 LINE ID 撈出來，方便後面查詢
        // 假設 user_supervisors 表結構是: supervisor_id (數字), user_id (數字)
        $sqlSub = "
            SELECT u.user_id 
            FROM user_supervisors us
            JOIN users u ON us.user_id = u.id
            WHERE us.supervisor_id = ?
        ";
        $stmtSub = $db->prepare($sqlSub);
        $stmtSub->execute([$supNumericId]);
        $subLineIds = $stmtSub->fetchAll(PDO::FETCH_COLUMN); // 這裡拿到的是 ['U123...', 'U456...']

        $leaves = [];
        $mods = [];

        if (!empty($subLineIds)) {
            // 準備好 IN (?,?,?) 的佔位符
            $placeholders = implode(',', array_fill(0, count($subLineIds), '?'));

            // -----------------------------------------------------------
            // B-1. 待審核 - 新假單
            // -----------------------------------------------------------
            // 修正：JOIN 條件改為 ON lr.user_id = u.user_id (都是 LINE ID)
            $sqlLeave = "
                SELECT lr.id, u.name AS user_name, lr.leave_type, lr.start_at, lr.end_at, lr.reason, lr.created_at 
                FROM leave_requests lr
                JOIN users u ON lr.user_id = u.user_id
                WHERE lr.status = 'pending' AND lr.user_id IN ($placeholders) 
                ORDER BY lr.start_at ASC";
            $stmtL = $db->prepare($sqlLeave);
            $stmtL->execute($subLineIds);
            $leaves = $stmtL->fetchAll(PDO::FETCH_ASSOC);
            foreach ($leaves as &$row) {
                $row['date_display'] = substr($row['start_at'], 0, 16) . ' ~ ' . substr($row['end_at'], 11, 5);
            }

            // -----------------------------------------------------------
            // B-2. 待審核 - 變更/銷假
            // -----------------------------------------------------------
            $sqlMod = "
                SELECT lm.id, lm.modification_uuid, lm.type, lm.target_date, lm.created_at, 
                       u.name AS user_name, lr.leave_type
                FROM leave_modifications lm
                JOIN users u ON lm.user_id = u.user_id
                JOIN leave_requests lr ON lm.leave_request_id = lr.id
                WHERE lm.status = 'pending' AND lm.user_id IN ($placeholders)
                ORDER BY lm.created_at DESC
            ";
            $stmtM = $db->prepare($sqlMod);
            $stmtM->execute($subLineIds);
            $mods = $stmtM->fetchAll(PDO::FETCH_ASSOC);
            
            $typeMap = ['full_revoke'=>'註銷假單', 'modify_range'=>'修改時段', 'delay_start'=>'延後開始', 'early_return'=>'提早結束'];
            foreach ($mods as &$row) {
                $row['type_text'] = $typeMap[$row['type']] ?? $row['type'];
                $row['date_display'] = substr($row['created_at'], 0, 10);
            }
        }

        // -----------------------------------------------------------
        // C. 歷史紀錄
        // -----------------------------------------------------------
        
        // C-1. 歷史假單 (查 leave_approvals)
        // 注意：leave_approvals 裡的 supervisor_id 通常存的是 LINE ID (如果不是請告訴我)
        // 這裡假設是存主管的 LINE ID
        $sqlHistL = "
            SELECT 
                lr.id, u.name AS user_name, lr.leave_type, lr.start_at, 
                la.status, la.updated_at AS action_time, 'leave' AS source_type
            FROM leave_approvals la
            JOIN leave_requests lr ON la.request_id = lr.id
            JOIN users u ON lr.user_id = u.user_id
            WHERE la.supervisor_id = ? 
            AND la.status != 'pending' 
            ORDER BY la.updated_at DESC LIMIT 50
        ";
        $stmtHL = $db->prepare($sqlHistL);
        // 如果您的 leave_approvals 存的是主管 LINE ID，就傳 $lineId
        // 如果存的是主管 數字ID，就傳 $supNumericId
        // 先假設存的是 LINE ID (最常見)
        $stmtHL->execute([$lineId]); 
        $histLeaves = $stmtHL->fetchAll(PDO::FETCH_ASSOC);

        // C-2. 歷史變更單
        $histMods = [];
        if (!empty($subLineIds)) {
            $placeholders = implode(',', array_fill(0, count($subLineIds), '?'));
            $sqlHistM = "
                SELECT lm.modification_uuid AS id, u.name AS user_name, lm.type AS leave_type, 
                       lm.created_at AS action_time, lm.status, 'mod' AS source_type
                FROM leave_modifications lm
                JOIN users u ON lm.user_id = u.user_id
                JOIN leave_requests lr ON lm.leave_request_id = lr.id
                WHERE lm.status IN ('approved', 'rejected') 
                AND lm.user_id IN ($placeholders)
                ORDER BY lm.created_at DESC LIMIT 50
            ";
            $stmtHM = $db->prepare($sqlHistM);
            $stmtHM->execute($subLineIds);
            $histMods = $stmtHM->fetchAll(PDO::FETCH_ASSOC);
        }

        $history = array_merge($histLeaves, $histMods);
        $statusMap = ['approved'=>'已核准', 'rejected'=>'已駁回', 'cancelled'=>'已註銷'];
        $modTypeMap = ['full_revoke'=>'註銷假單', 'modify_range'=>'修改時段', 'delay_start'=>'延後', 'early_return'=>'提早'];

        foreach ($history as &$h) {
            $ts = !empty($h['action_time']) ? $h['action_time'] : date('Y-m-d H:i:s');
            $h['date_display'] = substr($ts, 0, 10);
            $h['action_ts'] = strtotime($ts);
            $h['status_text'] = $statusMap[$h['status']] ?? $h['status'];
            
            if ($h['source_type'] === 'mod') {
                $h['type_display'] = ($modTypeMap[$h['leave_type']] ?? '變更') . " (" . $h['user_name'] . ")";
            } else {
                $h['type_display'] = $h['leave_type'];
            }
        }

        usort($history, function($a, $b) { return $b['action_ts'] - $a['action_ts']; });
        $history = array_slice($history, 0, 50);

        echo json_encode([
            'status' => 'success',
            'leaves' => $leaves,
            'mods'   => $mods,
            'history' => $history
        ]);
        exit;
    }

    // ==========================================
    // 2. 批次核准
    // ==========================================
    if ($action === 'approve') {
        $input = json_decode(file_get_contents('php://input'), true);
        $items = $input['items'] ?? [];
        $lineId = $input['lineId'] ?? '';

        if (empty($items)) throw new Exception("未選擇項目");

        $db->beginTransaction();
        
        $updReq = $db->prepare("UPDATE leave_requests SET status = 'approved' WHERE id = ?");
        
        // 核准時也要注意 ID 格式，這裡假設 supervisor_id 是 LINE ID
        $updApp = $db->prepare("UPDATE leave_approvals SET status = 'approved', updated_at = NOW() WHERE request_id = ? AND supervisor_id = ?");
        
        $stmtUser = $db->prepare("SELECT user_id, user_name, leave_type, start_at FROM leave_requests WHERE id = ?");

        require_once __DIR__ . '/ModificationApprovalHandler.php';

        $countLeave = 0;
        $countMod = 0;

        foreach ($items as $item) {
            if ($item['type'] === 'leave') {
                $id = $item['id'];
                $updReq->execute([$id]);
                // 傳入 $lineId (主管的 LINE ID)
                $updApp->execute([$id, $lineId]); 
                
                $stmtUser->execute([$id]);
                $req = $stmtUser->fetch(PDO::FETCH_ASSOC);
                if ($req) {
                    $countLeave++;
                    pushMessage($req['user_id'], ['type'=>'text', 'text'=>"【審核通過】假單 ({$req['start_at']}) 已由主管核准。"]);
                }
            } elseif ($item['type'] === 'mod') {
                $uuid = $item['id'];
                $handler = new ModificationApprovalHandler($lineId, "/同意銷假 $uuid");
                if ($handler->handle()) {
                    $countMod++;
                }
            }
        }
        $db->commit();
        echo json_encode(['status' => 'success', 'message' => "成功核准：假單 {$countLeave} 筆，變更 {$countMod} 筆"]);
        exit;
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>