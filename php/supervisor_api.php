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

        // 1. 直接透過 LINE ID 查詢該主管的所有下屬
        $sqlSub = "SELECT user_id FROM user_supervisors WHERE supervisor_id = ?";
        $stmtSub = $db->prepare($sqlSub);
        $stmtSub->execute([$lineId]);
        $subLineIds = $stmtSub->fetchAll(PDO::FETCH_COLUMN);
        
        // 確保他真的有被設為主管，若沒有下屬，直接回傳空陣列
        if (empty($subLineIds)) { 
            echo json_encode(['status'=>'success', 'leaves'=>[], 'mods'=>[], 'overtimes'=>[], 'clockins'=>[], 'history'=>[]]); 
            exit; 
        }

        $leaves = []; $mods = []; $overtimes = []; $clockins = []; $history = [];

        if (!empty($subLineIds)) {
            $placeholders = implode(',', array_fill(0, count($subLineIds), '?'));

            // A. 待審核 - 新假單 (🔥 加入 leave_approvals 關聯，過濾掉自己已經簽過的單)
            $sqlLeave = "SELECT lr.id, u.name AS user_name, lr.leave_type, lr.start_at, lr.end_at, lr.reason, lr.created_at 
                         FROM leave_requests lr 
                         JOIN users u ON lr.user_id = u.user_id 
                         JOIN leave_approvals la ON lr.id = la.request_id
                         WHERE lr.status = 'pending' 
                           AND lr.user_id IN ($placeholders) 
                           AND la.supervisor_id = ? 
                           AND la.status = 'pending' 
                         ORDER BY lr.start_at ASC";
            $stmtL = $db->prepare($sqlLeave);
            
            // 將主管自己的 lineId 加到查詢參數的最後面
            $paramsL = $subLineIds;
            $paramsL[] = $lineId;
            $stmtL->execute($paramsL);
            
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
            $sqlCk = "SELECT a.id, u.name AS user_name, a.created_at AS clock_time, a.reason, a.created_at FROM attendance_logs a JOIN users u ON a.user_id = u.user_id WHERE a.approval_status = 'pending' AND a.status IN ('fail', 'pending') AND a.user_id IN ($placeholders) ORDER BY a.created_at ASC";
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
        // 🔥 移除了原本包在外面的 $db->beginTransaction();
        
        $count = 0;
        foreach ($items as $item) {
            $id = $item['id'];
            
            if ($item['type'] === 'mod') {
                // 變更單 (mod) 內部已經寫好交易保護了，直接呼叫即可
                require_once __DIR__ . '/ModificationApprovalHandler.php';
                $modHandler = new ModificationApprovalHandler($lineId, "/同意銷假 " . $id);
                $modHandler->handle(); 
            } else {
                // 其他單據，幫它們獨立包裝一個小交易
                $db->beginTransaction();
                try {
                    if ($item['type'] === 'leave') {
                        // 1. 先更新這位主管的簽核狀態
                        $db->prepare("UPDATE leave_approvals SET status = 'approved', updated_at = NOW() WHERE request_id = ? AND supervisor_id = ? AND status = 'pending'")->execute([$id, $lineId]);

                        // 2. 檢查是否所有主管都簽核完畢
                        $checkStmt = $db->prepare("SELECT COUNT(*) FROM leave_approvals WHERE request_id = ? AND status != 'approved'");
                        $checkStmt->execute([$id]);
                        if ($checkStmt->fetchColumn() == 0) {
                            // 3. 全員通過才正式核准假單
                            $db->prepare("UPDATE leave_requests SET status = 'approved' WHERE id = ? AND status = 'pending'")->execute([$id]);
                            
                            // 🔥 補上：發送核准卡片給員工
                            $lrReq = $db->prepare("SELECT user_id, start_at FROM leave_requests WHERE id = ?");
                            $lrReq->execute([$id]);
                            $lr = $lrReq->fetch();
                            if ($lr) {
                                $flexCard = createBusinessFlex(
                                    "APPROVED", "請假單已核准", 
                                    ["單據類型" => "請假單", "開始時間" => $lr['start_at'], "審核狀態" => "主管核准 (Approved)"], 
                                    "#06C755"
                                );
                                pushMessage($lr['user_id'], $flexCard);
                            }
                        }
                    } elseif ($item['type'] === 'overtime') {
                        $otReq = $db->prepare("SELECT user_id, start_at, hours FROM overtime_requests WHERE id = ? AND status = 'pending' FOR UPDATE"); 
                        $otReq->execute([$id]); 
                        $ot = $otReq->fetch();
                        if ($ot) {
                            $db->prepare("UPDATE overtime_requests SET status = 'approved' WHERE id = ?")->execute([$id]);
                            $db->prepare("UPDATE users SET comp_leave_hours = comp_leave_hours + ? WHERE user_id = ?")->execute([$ot['hours'], $ot['user_id']]);
                            
                            // 🔥 補上：發送核准卡片給員工
                            $flexCard = createBusinessFlex(
                                "APPROVED", "加班單已核准", 
                                ["單據類型" => "加班單", "開始時間" => $ot['start_at'], "核准時數" => $ot['hours'] . " 小時", "審核狀態" => "主管核准 (Approved)"], 
                                "#06C755"
                            );
                            pushMessage($ot['user_id'], $flexCard);
                        }
                    } elseif ($item['type'] === 'clockin') {
                        $ckReq = $db->prepare("SELECT user_id, created_at FROM attendance_logs WHERE id = ?"); 
                        $ckReq->execute([$id]); 
                        $ck = $ckReq->fetch();
                        if ($ck) {
                            $db->prepare("UPDATE attendance_logs SET approval_status = 'approved', status = 'success', approved_at = NOW() WHERE id = ?")->execute([$id]);
                            
                            // 🔥 補上：發送核准卡片給員工
                            $flexCard = createBusinessFlex(
                                "APPROVED", "補打卡已核准", 
                                ["單據類型" => "異常打卡補登", "打卡時間" => $ck['created_at'], "審核狀態" => "主管核准 (Approved)"], 
                                "#06C755"
                            );
                            pushMessage($ck['user_id'], $flexCard);
                        }
                    }
                    $db->commit();
                } catch (Exception $e) {
                    $db->rollBack();
                }
            }
            $count++;
        }
        echo json_encode(['status' => 'success', 'message' => "成功處理 {$count} 筆項目"]);
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
        
        $count = 0;
        foreach ($items as $item) {
            $id = $item['id'];
            $db->beginTransaction();
            try {
                if ($item['type'] === 'leave') {
                    // 1. 查詢假單資料並鎖定 (加上 pending 防呆，防止重複退還)
                    $req = $db->prepare("SELECT user_id, start_at, deduct_annual, deduct_comp FROM leave_requests WHERE id = ? AND status = 'pending' FOR UPDATE");
                    $req->execute([$id]); 
                    $res = $req->fetch(PDO::FETCH_ASSOC);
                    
                    if ($res) {
                        // 2. 退還已扣除的時數到 users 表
                        $refundAnnual = floatval($res['deduct_annual'] ?? 0);
                        $refundComp   = floatval($res['deduct_comp'] ?? 0);
                        if ($refundAnnual > 0 || $refundComp > 0) {
                            $db->prepare("UPDATE users SET annual_leave_hours = annual_leave_hours + ?, comp_leave_hours = comp_leave_hours + ? WHERE user_id = ?")
                            ->execute([$refundAnnual, $refundComp, $res['user_id']]);
                        }
                        
                        // 3. 更新假單與簽核狀態為 rejected
                        $db->prepare("UPDATE leave_requests SET status = 'rejected' WHERE id = ?")->execute([$id]);
                        $db->prepare("UPDATE leave_approvals SET status = 'rejected', updated_at = NOW() WHERE request_id = ? AND supervisor_id = ?")->execute([$id, $lineId]);
                        
                        // 4. 發送通知給員工
                        $flexCard = createBusinessFlex(
                            "REJECTED", "假單已被駁回", 
                            ["單據類型" => "請假單", "開始時間" => $res['start_at'], "審核狀態" => "主管退件 (Rejected)", "備註" => "已退還扣抵時數"], 
                            "#DC3545"
                        );
                        pushMessage($res['user_id'], $flexCard);
                    }
                } elseif ($item['type'] === 'overtime') {
                    // 🔥 修正：這裡是退件(駁回)，加上 FOR UPDATE 防呆，並設為 rejected (不給時數)
                    $otReq = $db->prepare("SELECT user_id, start_at FROM overtime_requests WHERE id = ? AND status = 'pending' FOR UPDATE"); 
                    $otReq->execute([$id]); 
                    $ot = $otReq->fetch();
                    if ($ot) {
                        $db->prepare("UPDATE overtime_requests SET status = 'rejected' WHERE id = ?")->execute([$id]);
                        $flexCard = createBusinessFlex(
                            "REJECTED", "加班單已被駁回", 
                            ["單據類型" => "加班單", "開始時間" => $ot['start_at'], "審核狀態" => "主管退件 (Rejected)"], 
                            "#DC3545"
                        );
                        pushMessage($ot['user_id'], $flexCard);
                    }
                } elseif ($item['type'] === 'clockin') {
                    $db->prepare("UPDATE attendance_logs SET approval_status = 'rejected' WHERE id = ?")->execute([$id]);
                    $ckReq = $db->prepare("SELECT user_id, created_at FROM attendance_logs WHERE id = ?"); $ckReq->execute([$id]); $ck = $ckReq->fetch();
                    if ($ck) {
                        $flexCard = createBusinessFlex(
                            "REJECTED", "補打卡已被駁回", 
                            ["單據類型" => "異常打卡補登", "打卡時間" => $ck['created_at'], "審核狀態" => "主管退件 (Rejected)"], 
                            "#DC3545"
                        );
                        pushMessage($ck['user_id'], $flexCard);
                    }
                } elseif ($item['type'] === 'mod') {
                    $db->prepare("UPDATE leave_modifications SET status = 'rejected' WHERE modification_uuid = ?")->execute([$id]);
                    $modReq = $db->prepare("SELECT user_id FROM leave_modifications WHERE modification_uuid = ?"); 
                    $modReq->execute([$id]); 
                    $mod = $modReq->fetch();
                    if ($mod) {
                        $flexCard = createBusinessFlex(
                            "REJECTED", "申請已被駁回", 
                            ["單據類型" => "假單變更 / 銷假", "審核狀態" => "主管退件 (Rejected)"], 
                            "#DC3545"
                        );
                        pushMessage($mod['user_id'], $flexCard);
                    }
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
            }
            $count++;
        }
        echo json_encode(['status' => 'success', 'message' => "已駁回 {$count} 筆項目"]);
        exit;
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}