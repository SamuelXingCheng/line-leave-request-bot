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

        // 1. 查主管 id
        $stmtSup = $db->prepare("SELECT id FROM users WHERE user_id = ?");
        $stmtSup->execute([$lineId]);
        $supervisor = $stmtSup->fetch(PDO::FETCH_ASSOC);
        
        if (!$supervisor) { echo json_encode(['status'=>'success', 'leaves'=>[], 'mods'=>[], 'overtimes'=>[], 'clockins'=>[], 'history'=>[]]); exit; }
        $supNumericId = $supervisor['id'];

        // 2. 查下屬
        $sqlSub = "SELECT u.user_id FROM user_supervisors us JOIN users u ON us.user_id = u.id WHERE us.supervisor_id = ?";
        $stmtSub = $db->prepare($sqlSub);
        $stmtSub->execute([$supNumericId]);
        $subLineIds = $stmtSub->fetchAll(PDO::FETCH_COLUMN);

        $leaves = []; $mods = []; $overtimes = []; $clockins = []; $history = [];

        if (!empty($subLineIds)) {
            $placeholders = implode(',', array_fill(0, count($subLineIds), '?'));

            // A. 待審核 - 新假單
            $sqlLeave = "SELECT lr.id, u.name AS user_name, lr.leave_type, lr.start_at, lr.end_at, lr.reason, lr.created_at FROM leave_requests lr JOIN users u ON lr.user_id = u.user_id WHERE lr.status = 'pending' AND lr.user_id IN ($placeholders) ORDER BY lr.start_at ASC";
            $stmtL = $db->prepare($sqlLeave);
            $stmtL->execute($subLineIds);
            $leaves = $stmtL->fetchAll(PDO::FETCH_ASSOC);
            foreach ($leaves as &$row) { $row['date_display'] = substr($row['start_at'], 0, 16) . ' ~ ' . substr($row['end_at'], 11, 5); }

            // B. 待審核 - 變更/銷假
            $sqlMod = "SELECT lm.id, lm.modification_uuid, lm.type, lm.target_date, lm.created_at, u.name AS user_name, lr.leave_type FROM leave_modifications lm JOIN users u ON lm.user_id = u.user_id JOIN leave_requests lr ON lm.leave_request_id = lr.id WHERE lm.status = 'pending' AND lm.user_id IN ($placeholders) ORDER BY lm.created_at DESC";
            $stmtM = $db->prepare($sqlMod);
            $stmtM->execute($subLineIds);
            $mods = $stmtM->fetchAll(PDO::FETCH_ASSOC);
            $typeMap = ['full_revoke'=>'註銷假單', 'modify_range'=>'修改時段', 'delay_start'=>'延後開始', 'early_return'=>'提早結束'];
            foreach ($mods as &$row) { $row['type_text'] = $typeMap[$row['type']] ?? $row['type']; $row['date_display'] = substr($row['created_at'], 0, 10); }

            // C. 待審核 - 加班單
            $sqlOt = "SELECT o.id, u.name AS user_name, o.start_at AS start_time, o.end_at AS end_time, o.hours, o.reason, o.created_at FROM overtime_requests o JOIN users u ON o.user_id = u.user_id WHERE o.status = 'pending' AND o.user_id IN ($placeholders) ORDER BY o.created_at ASC";
            $stmtOt = $db->prepare($sqlOt);
            $stmtOt->execute($subLineIds);
            $overtimes = $stmtOt->fetchAll(PDO::FETCH_ASSOC);

            // D. 待審核 - 打卡異常
            $sqlCk = "SELECT a.id, u.name AS user_name, a.created_at AS clock_time, a.reason, a.created_at FROM attendance_logs a JOIN users u ON a.user_id = u.user_id WHERE a.approval_status = 'pending' AND a.status = 'fail' AND a.user_id IN ($placeholders) ORDER BY a.created_at ASC";
            $stmtCk = $db->prepare($sqlCk);
            $stmtCk->execute($subLineIds);
            $clockins = $stmtCk->fetchAll(PDO::FETCH_ASSOC);
        }

        // 歷史紀錄 (先只抓假單)
        $sqlHistL = "SELECT lr.id, u.name AS user_name, lr.leave_type, lr.start_at, la.status, la.updated_at AS action_time, 'leave' AS source_type FROM leave_approvals la JOIN leave_requests lr ON la.request_id = lr.id JOIN users u ON lr.user_id = u.user_id WHERE la.supervisor_id = ? AND la.status != 'pending' ORDER BY la.updated_at DESC LIMIT 50";
        $stmtHL = $db->prepare($sqlHistL);
        $stmtHL->execute([$lineId]); 
        $history = $stmtHL->fetchAll(PDO::FETCH_ASSOC);
        $statusMap = ['approved'=>'已核准', 'rejected'=>'已駁回', 'cancelled'=>'已註銷'];
        foreach ($history as &$h) {
            $h['date_display'] = substr($h['action_time'] ?? date('Y-m-d H:i:s'), 0, 10);
            $h['status_text'] = $statusMap[$h['status']] ?? $h['status'];
            $h['type_display'] = $h['leave_type'];
        }

        echo json_encode(['status' => 'success', 'leaves' => $leaves, 'mods' => $mods, 'overtimes' => $overtimes, 'clockins' => $clockins, 'history' => $history]);
        exit;
    }

    // ==========================================
    // 批次核准 (Approve)
    // ==========================================
    if ($action === 'approve') {
        $input = json_decode(file_get_contents('php://input'), true);
        $items = $input['items'] ?? [];
        $lineId = $input['lineId'] ?? '';

        if (empty($items)) throw new Exception("未選擇項目");
        $db->beginTransaction();
        
        $count = 0;
        foreach ($items as $item) {
            $id = $item['id'];
            if ($item['type'] === 'leave') {
                $db->prepare("UPDATE leave_requests SET status = 'approved' WHERE id = ?")->execute([$id]);
                $db->prepare("UPDATE leave_approvals SET status = 'approved', updated_at = NOW() WHERE request_id = ? AND supervisor_id = ?")->execute([$id, $lineId]);
                $req = $db->prepare("SELECT user_id, start_at FROM leave_requests WHERE id = ?"); $req->execute([$id]); $res = $req->fetch();
                if ($res) pushMessage($res['user_id'], ['type'=>'text', 'text'=>"【主管核准】您於 {$res['start_at']} 的假單已核准。"]);
            } elseif ($item['type'] === 'overtime') {
                $otReq = $db->prepare("SELECT user_id, start_at, hours FROM overtime_requests WHERE id = ?"); $otReq->execute([$id]); $ot = $otReq->fetch();
                if ($ot) {
                    $db->prepare("UPDATE overtime_requests SET status = 'approved' WHERE id = ?")->execute([$id]);
                    $db->prepare("UPDATE users SET comp_leave_hours = comp_leave_hours + ? WHERE user_id = ?")->execute([$ot['hours'], $ot['user_id']]);
                    pushMessage($ot['user_id'], ['type'=>'text', 'text'=>"【主管核准】您於 {$ot['start_at']} 的加班單已核准，共計 {$ot['hours']} 小時已存入補休餘額。"]);
                }
            } elseif ($item['type'] === 'clockin') {
                $ckReq = $db->prepare("SELECT user_id, created_at FROM attendance_logs WHERE id = ?"); $ckReq->execute([$id]); $ck = $ckReq->fetch();
                if ($ck) {
                    $db->prepare("UPDATE attendance_logs SET approval_status = 'approved' WHERE id = ?")->execute([$id]);
                    pushMessage($ck['user_id'], ['type'=>'text', 'text'=>"【主管核准】您於 {$ck['created_at']} 的打卡異常已核准補登。"]);
                }
            }
            $count++;
        }
        $db->commit();
        echo json_encode(['status' => 'success', 'message' => "成功核准 {$count} 筆項目"]);
        exit;
    }

    // ==========================================
    // 批次駁回 (Reject)
    // ==========================================
    if ($action === 'reject') {
        $input = json_decode(file_get_contents('php://input'), true);
        $items = $input['items'] ?? [];
        $lineId = $input['lineId'] ?? '';

        if (empty($items)) throw new Exception("未選擇項目");
        $db->beginTransaction();
        
        $count = 0;
        foreach ($items as $item) {
            $id = $item['id'];
            if ($item['type'] === 'leave') {
                $db->prepare("UPDATE leave_requests SET status = 'rejected' WHERE id = ?")->execute([$id]);
                $db->prepare("UPDATE leave_approvals SET status = 'rejected', updated_at = NOW() WHERE request_id = ? AND supervisor_id = ?")->execute([$id, $lineId]);
                $req = $db->prepare("SELECT user_id, start_at FROM leave_requests WHERE id = ?"); $req->execute([$id]); $res = $req->fetch();
                if ($res) pushMessage($res['user_id'], ['type'=>'text', 'text'=>"【主管退件】您於 {$res['start_at']} 的假單已被駁回。"]);
            } elseif ($item['type'] === 'overtime') {
                $db->prepare("UPDATE overtime_requests SET status = 'rejected' WHERE id = ?")->execute([$id]);
                $otReq = $db->prepare("SELECT user_id, start_at FROM overtime_requests WHERE id = ?"); $otReq->execute([$id]); $ot = $otReq->fetch();
                if ($ot) pushMessage($ot['user_id'], ['type'=>'text', 'text'=>"【主管退件】您於 {$ot['start_at']} 的加班單已被駁回。"]);
            } elseif ($item['type'] === 'clockin') {
                $db->prepare("UPDATE attendance_logs SET approval_status = 'rejected' WHERE id = ?")->execute([$id]);
                $ckReq = $db->prepare("SELECT user_id, created_at FROM attendance_logs WHERE id = ?"); $ckReq->execute([$id]); $ck = $ckReq->fetch();
                if ($ck) pushMessage($ck['user_id'], ['type'=>'text', 'text'=>"【主管退件】您於 {$ck['created_at']} 的異常打卡補登已被駁回。"]);
            }
            $count++;
        }
        $db->commit();
        echo json_encode(['status' => 'success', 'message' => "已駁回 {$count} 筆項目"]);
        exit;
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}