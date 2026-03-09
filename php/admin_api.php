<?php
// admin_api.php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';

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
        $stmt = $db->query("SELECT * FROM users ORDER BY id DESC");
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

    throw new Exception("無效的請求");

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}