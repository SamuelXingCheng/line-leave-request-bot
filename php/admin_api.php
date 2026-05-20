<?php
// admin_api.php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(401);
        throw new Exception("拒絕存取：請先登入管理後台");
    }

    $db = Database::getConnection();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    // =======================================
    // 區塊一：員工基本資料 (Users)
    // =======================================
    if ($method === 'GET' && $action === 'list') {
        $stmt = $db->query("SELECT * FROM users ORDER BY is_archived ASC, id DESC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $users]);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if ($method === 'POST' && $action === 'add') {
        $userId = $input['user_id'] ?? '';
        $name = $input['name'] ?? '';
        $startDate = $input['start_date'] ?? null;
        if (empty($userId) || empty($name)) throw new Exception("LINE ID 與姓名為必填欄位");

        $stmt = $db->prepare("INSERT INTO users (user_id, name, start_date, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$userId, $name, $startDate]);
        echo json_encode(['status' => 'success', 'message' => '新增成功']);
        exit;
    }

    if ($method === 'PUT' && $action === 'edit') {
        $id = $input['id'] ?? '';
        $userId = $input['user_id'] ?? '';
        $name = $input['name'] ?? '';
        $startDate = $input['start_date'] ?? null;
        if (empty($id) || empty($userId) || empty($name)) throw new Exception("缺少必要更新資料");

        $stmt = $db->prepare("UPDATE users SET user_id = ?, name = ?, start_date = ? WHERE id = ?");
        $stmt->execute([$userId, $name, $startDate, $id]);
        echo json_encode(['status' => 'success', 'message' => '更新成功']);
        exit;
    }

    if ($method === 'DELETE' && $action === 'delete') {
        $id = $_GET['id'] ?? '';
        if (empty($id)) throw new Exception("缺少要刪除的 ID");

        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['status' => 'success', 'message' => '刪除成功']);
        exit;
    }

    // =======================================
    // 區塊二：主管組織架構 (user_supervisors)
    // =======================================
    
    // 1. 讀取目前的「員工-主管」對應表
    if ($method === 'GET' && $action === 'supervisor_list') {
        $sql = "
            SELECT us.user_id, us.supervisor_id, 
                   u1.name AS emp_name, u2.name AS sup_name
            FROM user_supervisors us
            LEFT JOIN users u1 ON us.user_id = u1.user_id
            LEFT JOIN users u2 ON us.supervisor_id = u2.user_id
            ORDER BY u2.name ASC, u1.name ASC
        ";
        $stmt = $db->query($sql);
        $relations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $relations]);
        exit;
    }

    // 2. 指派主管
    if ($method === 'POST' && $action === 'assign_supervisor') {
        $empId = $input['user_id'] ?? '';
        $supId = $input['supervisor_id'] ?? '';

        if (empty($empId) || empty($supId)) throw new Exception("請選擇員工與主管");
        if ($empId === $supId) throw new Exception("員工不能設定自己為自己的主管");

        // 檢查是否已經設定過相同的對應
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM user_supervisors WHERE user_id = ? AND supervisor_id = ?");
        $checkStmt->execute([$empId, $supId]);
        if ($checkStmt->fetchColumn() > 0) {
            throw new Exception("此員工的主管設定已存在，請勿重複指派");
        }

        $stmt = $db->prepare("INSERT INTO user_supervisors (user_id, supervisor_id) VALUES (?, ?)");
        $stmt->execute([$empId, $supId]);
        echo json_encode(['status' => 'success', 'message' => '指派成功']);
        exit;
    }

    // 3. 刪除主管設定
    if ($method === 'DELETE' && $action === 'remove_supervisor') {
        $empId = $_GET['user_id'] ?? '';
        $supId = $_GET['supervisor_id'] ?? '';

        if (empty($empId) || empty($supId)) throw new Exception("缺少必要的 ID");

        $stmt = $db->prepare("DELETE FROM user_supervisors WHERE user_id = ? AND supervisor_id = ?");
        $stmt->execute([$empId, $supId]);
        echo json_encode(['status' => 'success', 'message' => '解除主管設定成功']);
        exit;
    }

    // =======================================
    // 區塊三：今日出勤儀表板 (Dashboard)
    // =======================================
    if ($method === 'GET' && $action === 'dashboard') {
        $today = date('Y-m-d');
        
        // 1. 總員工數 (有設定到職日的視為正式員工，且未離職)
        $stmtEmp = $db->query("SELECT COUNT(*) FROM users WHERE start_date IS NOT NULL AND is_archived = 0");
        $totalEmp = $stmtEmp->fetchColumn();

        // 2. 今日請假人數與名單
        $stmtLeave = $db->prepare("
            SELECT u.name, lr.leave_type 
            FROM leave_requests lr 
            JOIN users u ON lr.user_id = u.user_id 
            WHERE lr.status = 'approved' 
            AND DATE(lr.start_at) <= ? AND DATE(lr.end_at) >= ?
        ");
        $stmtLeave->execute([$today, $today]);
        $leavesToday = $stmtLeave->fetchAll(PDO::FETCH_ASSOC);
        $leaveCount = count($leavesToday);

        // 3. 今日實到人數 (今天有上班打卡，且狀態為成功或主管已核准的)
        $stmtClock = $db->prepare("
            SELECT COUNT(DISTINCT user_id) 
            FROM attendance_logs 
            WHERE DATE(created_at) = ? AND mode = '上班' AND (status = 'success' OR approval_status = 'approved')
        ");
        $stmtClock->execute([$today]);
        $clockInCount = $stmtClock->fetchColumn();

        // 4. 待處理異常/待簽核單據數
        $pendingLeaves = $db->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
        $pendingOvertimes = $db->query("SELECT COUNT(*) FROM overtime_requests WHERE status = 'pending'")->fetchColumn();
        $pendingClockins = $db->query("SELECT COUNT(*) FROM attendance_logs WHERE approval_status = 'pending'")->fetchColumn();
        $totalPending = $pendingLeaves + $pendingOvertimes + $pendingClockins;

        echo json_encode([
            'status' => 'success',
            'data' => [
                'total_emp' => $totalEmp,
                'leave_count' => $leaveCount,
                'leaves_today' => $leavesToday,
                'clock_in_count' => $clockInCount,
                'total_pending' => $totalPending
            ]
        ]);
        exit;
    }

    // =======================================
    // 區塊四：全公司紀錄總表 (All Records) - 支援日期區間篩選
    // =======================================
    if ($method === 'GET' && $action === 'all_records') {
        $startDate = $_GET['start'] ?? '';
        $endDate = $_GET['end'] ?? '';

        $whereLeave = ""; $whereOt = ""; $whereCk = "";
        $params = [];
        $limitClause = " LIMIT 100 "; // 若無篩選，預設 100 筆

        if (!empty($startDate) && !empty($endDate)) {
            // 使用 DATE() 函數精準比對年月日
            $whereLeave = "WHERE DATE(lr.start_at) BETWEEN ? AND ?";
            $whereOt = "WHERE DATE(o.start_at) BETWEEN ? AND ?";
            $whereCk = "WHERE DATE(a.created_at) BETWEEN ? AND ?";
            $params = [$startDate, $endDate];
            $limitClause = ""; // 有選日期區間，就解除 100 筆限制
        }

        // 1. 抓取假單
        $stmtL = $db->prepare("
            SELECT lr.id, lr.leave_type, lr.start_at, lr.end_at, lr.status, lr.created_at, u.name AS user_name 
            FROM leave_requests lr JOIN users u ON lr.user_id = u.user_id 
            $whereLeave ORDER BY lr.start_at DESC $limitClause
        ");
        $stmtL->execute($params);
        $leaves = $stmtL->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. 抓取加班單
        $stmtO = $db->prepare("
            SELECT o.id, o.start_at, o.end_at, o.hours, o.status, o.created_at, u.name AS user_name 
            FROM overtime_requests o JOIN users u ON o.user_id = u.user_id 
            $whereOt ORDER BY o.start_at DESC $limitClause
        ");
        $stmtO->execute($params);
        $overtimes = $stmtO->fetchAll(PDO::FETCH_ASSOC);
        
        // 3. 抓取打卡紀錄
        $stmtC = $db->prepare("
            SELECT a.id, a.mode, a.created_at AS clock_time, a.status, a.approval_status, u.name AS user_name 
            FROM attendance_logs a JOIN users u ON a.user_id = u.user_id 
            $whereCk ORDER BY a.created_at DESC $limitClause
        ");
        $stmtC->execute($params);
        $clockins = $stmtC->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data' => [
                'leaves' => $leaves,
                'overtimes' => $overtimes,
                'clockins' => $clockins
            ]
        ]);
        exit;
    }

    // =======================================
    // 區塊五：管理員強制審核單據 (代簽核)
    // =======================================
    if ($method === 'POST' && $action === 'force_audit') {
        $type = $input['type'] ?? '';
        $id = $input['id'] ?? '';
        $newStatus = $input['status'] ?? ''; // 'approved' 或 'rejected'

        if (empty($type) || empty($id) || empty($newStatus)) {
            throw new Exception("缺少必要參數");
        }

        $db->beginTransaction();
        $userIdToNotify = '';
        $notifyMsg = '';

        if ($type === 'leave') {
            $db->prepare("UPDATE leave_requests SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            $db->prepare("UPDATE leave_approvals SET status = ?, updated_at = NOW() WHERE request_id = ?")->execute([$newStatus, $id]);
            
            $req = $db->prepare("SELECT user_id, start_at FROM leave_requests WHERE id = ?"); 
            $req->execute([$id]); $res = $req->fetch(PDO::FETCH_ASSOC);
            if ($res) {
                $userIdToNotify = $res['user_id'];
                $statusText = $newStatus === 'approved' ? '核准' : '退件';
                $notifyMsg = "【管理員通知】您於 {$res['start_at']} 的假單，已由系統管理員強制{$statusText}。";
            }
            
        } elseif ($type === 'overtime') {
            $stmt = $db->prepare("SELECT user_id, start_at, status, hours FROM overtime_requests WHERE id = ?");
            $stmt->execute([$id]);
            $ot = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($ot && $ot['status'] !== $newStatus) {
                $db->prepare("UPDATE overtime_requests SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
                
                // 如果是改成核准，自動加時數進補休存摺
                if ($newStatus === 'approved') {
                    $db->prepare("UPDATE users SET comp_leave_hours = comp_leave_hours + ? WHERE user_id = ?")->execute([$ot['hours'], $ot['user_id']]);
                } 
                // 防呆：如果原本是核准，不小心被管理員改回退件，則把時數扣回來
                elseif ($ot['status'] === 'approved' && $newStatus === 'rejected') {
                    $db->prepare("UPDATE users SET comp_leave_hours = comp_leave_hours - ? WHERE user_id = ?")->execute([$ot['hours'], $ot['user_id']]);
                }

                $userIdToNotify = $ot['user_id'];
                $statusText = $newStatus === 'approved' ? '核准' : '退件';
                $notifyMsg = "【管理員通知】您於 {$ot['start_at']} 的加班單，已由系統管理員強制{$statusText}。";
            }
            
        } elseif ($type === 'clockin') {
            $db->prepare("UPDATE attendance_logs SET approval_status = ? WHERE id = ?")->execute([$newStatus, $id]);
            // 打卡異常被核准後，狀態改回 normal
            if ($newStatus === 'approved') {
                $db->prepare("UPDATE attendance_logs SET status = 'success' WHERE id = ?")->execute([$id]);
            }
            
            $ckReq = $db->prepare("SELECT user_id, created_at FROM attendance_logs WHERE id = ?"); 
            $ckReq->execute([$id]); $ck = $ckReq->fetch(PDO::FETCH_ASSOC);
            if ($ck) {
                $userIdToNotify = $ck['user_id'];
                $statusText = $newStatus === 'approved' ? '補登核准' : '退件';
                $notifyMsg = "【管理員通知】您於 {$ck['created_at']} 的打卡異常，已由系統管理員強制{$statusText}。";
            }
        }

        $db->commit();

        // 自動推播 LINE 通知給該員工
        if ($userIdToNotify && $notifyMsg && function_exists('pushMessage')) {
            try { pushMessage($userIdToNotify, ['type' => 'text', 'text' => $notifyMsg]); } catch(Exception $e) {}
        }

        echo json_encode(['status' => 'success', 'message' => '狀態已成功更新']);
        exit;
    }

    // =======================================
    // 區塊六：手動校正員工休假時數 (Update Hours)
    // =======================================
    if ($method === 'POST' && $action === 'update_hours') {
        $id = $input['id'] ?? '';
        $annual = isset($input['annual']) ? floatval($input['annual']) : 0;
        $comp = isset($input['comp']) ? floatval($input['comp']) : 0;
        $personal = isset($input['personal']) ? floatval($input['personal']) : 0;
        $sick = isset($input['sick']) ? floatval($input['sick']) : 0;

        if (empty($id)) {
            throw new Exception("缺少員工 ID");
        }

        $stmt = $db->prepare("
            UPDATE users 
            SET annual_leave_hours = ?, 
                comp_leave_hours = ?, 
                used_personal_hours = ?, 
                used_sick_hours = ? 
            WHERE id = ?
        ");
        $stmt->execute([$annual, $comp, $personal, $sick, $id]);

        echo json_encode(['status' => 'success', 'message' => '時數校正成功']);
        exit;
    }

    // =======================================
    // 區塊七：封存 / 員工離職處理 (Archive)
    // =======================================
    if ($method === 'PUT' && $action === 'archive') {
        $id = $input['id'] ?? '';
        $resignDate = $input['resign_date'] ?? date('Y-m-d');
        if (empty($id)) throw new Exception("缺少必要資料");

        $db->beginTransaction();
        try {
            // 1. 變更員工狀態為已封存(1)，並寫入離職日期
            $stmt = $db->prepare("UPDATE users SET is_archived = 1, resign_date = ? WHERE id = ?");
            $stmt->execute([$resignDate, $id]);

            // 2. 查出該員工的 LINE ID
            $stmtUser = $db->prepare("SELECT user_id FROM users WHERE id = ?");
            $stmtUser->execute([$id]);
            $userLineId = $stmtUser->fetchColumn();

            // 3. 自動解除組織架構設定 (清空他身為別人主管、或別人是他主管的紀錄)
            if ($userLineId) {
                $db->prepare("DELETE FROM user_supervisors WHERE user_id = ? OR supervisor_id = ?")->execute([$userLineId, $userLineId]);
            }

            $db->commit();
            echo json_encode(['status' => 'success', 'message' => '該員工已成功標記離職，其主管與下屬關聯已自動解除。']);
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        exit;
    }

    throw new Exception("無效的請求");

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}