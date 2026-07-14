<?php
// revoke_api.php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');

class BusinessException extends Exception {}

function generateUuid(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * 透過 LINE Platform API 驗證 Access Token，回傳對應的 userId。
 * 若 Token 無效或不屬於本應用程式，丟出例外。
 */
function verifyLineAccessToken(string $accessToken): string {
    $ch = curl_init('https://api.line.me/oauth2/v2.1/verify?access_token=' . urlencode($accessToken));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) {
        throw new Exception("LINE Access Token 驗證失敗");
    }

    $data = json_decode($response, true);

    $expectedClientId = getenv('LINE_CHANNEL_ID');
    if (!empty($expectedClientId) && isset($data['client_id']) && (string)$data['client_id'] !== (string)$expectedClientId) {
        throw new Exception("Access Token 不屬於本應用程式");
    }

    if (empty($data['client_id'])) {
        throw new Exception("無法從 LINE 驗證結果取得 client_id");
    }

    $ch2 = curl_init('https://api.line.me/v2/profile');
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken
    ]);
    $profileResponse = curl_exec($ch2);
    $profileHttpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if ($profileHttpCode !== 200 || empty($profileResponse)) {
        throw new Exception("無法取得 LINE 使用者資料");
    }

    $profileData = json_decode($profileResponse, true);

    if (empty($profileData['userId'])) {
        throw new Exception("LINE 回傳資料中缺少 userId");
    }

    return $profileData['userId'];
}

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/utils.php';
    if (!file_exists(__DIR__ . '/Db.php')) throw new Exception("找不到 Db.php");
    require_once __DIR__ . '/Db.php';

    $db = Database::getConnection();

    $action = $_GET['action'] ?? '';
    
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader) || stripos($authHeader, 'Bearer ') !== 0) {
        http_response_code(401);
    echo json_encode([
            'status' => 'error',
            'message' => '缺少 Authorization Header，請提供 LINE Access Token',
            'leaves' => []
    ]);
        exit;
}

    $accessToken = substr($authHeader, 7);
    if (empty($accessToken)) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Authorization Header 中的 Token 為空',
            'leaves' => []
        ]);
        exit;
    }

        try {
        $verifiedUserId = verifyLineAccessToken($accessToken);
        } catch (Exception $e) {
            http_response_code(401);
            echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'leaves' => []
                ]);
            exit;
        }

    // ==========================================
    // 功能 A: 取得假單列表 (List)
    // ==========================================
    if ($action === 'list') {
        $requestedUserId = $_GET['userId'] ?? '';

        if (empty($requestedUserId)) {
            echo json_encode(['leaves' => []]);
            exit;
        }

        // 授權驗證：只允許查詢自己的假單
        // 注意：$requestedUserId 僅用於授權比對，通過後查詢固定使用 $verifiedUserId，
        // 此為刻意設計的防禦性做法，確保資料庫查詢參數不受用戶端控制。
        if ($requestedUserId !== $verifiedUserId) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => '無權查詢他人的假單列表',
                'leaves' => []
            ]);
            exit;
        }

        $stmt = $db->prepare("
            SELECT id, request_group_id, leave_type, start_at, end_at, leave_hours, status
            FROM leave_requests
            WHERE user_id = ?
              AND status IN ('pending', 'approved')
              AND end_at > DATE_SUB(NOW(), INTERVAL 60 DAY)
            ORDER BY created_at DESC
        ");
        $stmt->execute([$verifiedUserId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['leaves' => $rows]);
        exit;
    }

    // ==========================================
    // 功能 B: 撤回申請 (Pending Delete)
    // ==========================================
    if ($action === 'delete') {
        $groupId = $_GET['groupId'] ?? '';
        if (empty($groupId)) throw new BusinessException("缺少 request_group_id");

        $db->beginTransaction();
        
        $stmt = $db->prepare("
            SELECT user_id, status, deduct_annual, deduct_comp
            FROM leave_requests
            WHERE request_group_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$groupId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
        $db->rollBack();
            throw new BusinessException("找不到該筆紀錄");
    }

        $ownerUserId = $rows[0]['user_id'];
        if ($ownerUserId !== $verifiedUserId) {
            $db->rollBack();
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => '無權操作此假單',
                'leaves' => []
            ]);
            exit;
        }

        $totalAnnual = 0;
        $totalComp = 0;
        $userId = $ownerUserId;

        foreach ($rows as $row) {
            if ($row['status'] !== 'pending') {
                $db->rollBack();
                throw new BusinessException("只能撤回待審核的假單");
            }
            $totalAnnual += floatval($row['deduct_annual'] ?? 0);
            $totalComp += floatval($row['deduct_comp'] ?? 0);
        }

        if ($totalAnnual > 0 || $totalComp > 0) {
            $upd = $db->prepare("UPDATE users SET annual_leave_hours = annual_leave_hours + ?, comp_leave_hours = comp_leave_hours + ? WHERE user_id = ?");
            $upd->execute([$totalAnnual, $totalComp, $userId]);
        }

        $db->prepare("DELETE FROM leave_approvals WHERE request_id IN (SELECT id FROM leave_requests WHERE request_group_id = ?)")->execute([$groupId]);
        $db->prepare("DELETE FROM leave_requests WHERE request_group_id = ?")->execute([$groupId]);

        $db->commit();
        echo json_encode(['status' => 'success', 'message' => '✅ 已撤回申請並退還時數']);
        exit;
    }
    
    // ==========================================
    // 功能 C: 整筆銷假 (Full Revoke Request)
    // ==========================================
    if ($action === 'full_revoke') {
        $leaveId = $_GET['leaveId'] ?? '';
        if (empty($leaveId)) throw new BusinessException("缺少 leaveId");

        // TODO: 此功能尚未實作。
        // 注意：授權驗證結構已正確（Transaction + FOR UPDATE），實作時請在 rollBack() 之前
        // 插入實際的業務邏輯，並將 rollBack() 改為 commit()，不得改變現有的鎖定結構。
        $db->beginTransaction();
        $stmtOwner = $db->prepare("SELECT user_id FROM leave_requests WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmtOwner->execute([$leaveId]);
        $ownerUserId = $stmtOwner->fetchColumn();

        if ($ownerUserId === false) {
        $db->rollBack();
            throw new BusinessException("找不到該假單");
        }

        if ($ownerUserId !== $verifiedUserId) {
            $db->rollBack();
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => '無權操作此假單',
                'leaves' => []
            ]);
            exit;
        }

        $db->rollBack();
        echo json_encode(['status' => 'success', 'message' => '功能開發中']);
        exit;
    }

    // ==========================================
    // 功能 D: 處理修改/銷假申請 (商務版)
    // ==========================================
    if ($action === 'request_modification') {
        $input = json_decode(file_get_contents('php://input'), true);
        $userId = $input['userId'] ?? '';
        $leaveId = $input['leaveId'] ?? '';
        $modType = $input['modType'] ?? ''; 
        $targetDate = $input['targetDate'] ?? '';

        if (empty($userId) || empty($leaveId) || empty($modType)) {
            throw new BusinessException("缺少必要參數");
        }

        if ($userId !== $verifiedUserId) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => '無權以他人身份提交申請',
                'leaves' => []
            ]);
            exit;
        }

        $stmtOwner = $db->prepare("SELECT user_id FROM leave_requests WHERE id = ? LIMIT 1");
        $stmtOwner->execute([$leaveId]);
        $ownerUserId = $stmtOwner->fetchColumn();

        if ($ownerUserId === false) {
            throw new BusinessException("找不到原始假單資料");
        }

        if ($ownerUserId !== $verifiedUserId) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => '無權對他人假單提交變更申請',
                'leaves' => []
            ]);
            exit;
        }

        $stmt = $db->prepare("SELECT user_name, leave_type, start_at, end_at FROM leave_requests WHERE id = ?");
        $stmt->execute([$leaveId]);
        $original = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$original) throw new BusinessException("找不到原始假單資料");

        // 🔥 新增：檢查是否已有尚未處理的變更單，防止重複送出
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM leave_modifications WHERE leave_request_id = ? AND status = 'pending'");
        $checkStmt->execute([$leaveId]);
        if ($checkStmt->fetchColumn() > 0) {
            throw new BusinessException("此假單目前已有「審核中」的變更或註銷申請，請等待主管處理完畢。");
        }

        $uuid = generateUuid();
        $ins = $db->prepare("
            INSERT INTO leave_modifications (modification_uuid, leave_request_id, user_id, type, target_date, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $ins->execute([$uuid, $leaveId, $userId, $modType, $targetDate]);

        $supNames = [];
        try {
            $stmtSup = $db->prepare("SELECT supervisor_id FROM user_supervisors WHERE user_id = ?");
            $stmtSup->execute([$userId]);
            $supIds = $stmtSup->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($supIds)) {
                $placeholders = implode(',', array_fill(0, count($supIds), '?'));
                $stmtName = $db->prepare("SELECT name FROM users WHERE user_id IN ($placeholders)");
                $stmtName->execute($supIds);
                $supNames = $stmtName->fetchAll(PDO::FETCH_COLUMN);
            }
        } catch (Exception $e) {
            // 查不到主管不影響主流程
        }

        $menuLiffId = getenv("MENU_LIFF_ID");
        $approvalLink = "https://liff.line.me/{$menuLiffId}/?tab=mod&highlight={$uuid}";

        $typeMapping = [
            'modify_range' => '變更休假時段',
            'full_revoke' => '註銷假單 (全額退回)'
        ];
        $typeText = $typeMapping[$modType] ?? '變更申請';

        $detailText = "";
        if ($modType === 'full_revoke') {
            $detailText = "說明：申請註銷本筆假單，並退還已扣除之時數。";
        } elseif ($modType === 'modify_range') {
            $parts = explode('~', $targetDate);
            if (count($parts) === 2) {
                $detailText = "變更後時段：\n" . $parts[0] . " 至 " . $parts[1];
            } else {
                $detailText = "變更後時間：" . $targetDate;
            }
        } else {
            $detailText = "變更內容：" . $targetDate;
        }

        $msgText = 
            "【系統通知】假單變更申請\n" .
            "────────────────\n" .
            "申請人員：" . $original['user_name'] . "\n" .
            "原始假別：" . $original['leave_type'] . "\n" .
            "原定期間：" . $original['start_at'] . " ~ " . $original['end_at'] . "\n" .
            "────────────────\n" .
            "變更項目：" . $typeText . "\n" .
            $detailText . "\n" .
            "────────────────\n" .
            "若同意此變更，請點擊下方連結進行簽核：\n" .
            $approvalLink;

        $mainMessage = [
            "type" => "text",
            "text" => $msgText
        ];

        $hintText = "【系統提示】\n請將上方訊息轉傳給：";
        if (!empty($supNames)) {
            $hintText .= "\n─ " . implode("\n─ ", $supNames);
        } else {
            $hintText .= "\n(尚未設定直屬主管)";
        }
        
        $hintMessage = [
            "type" => "text",
            "text" => $hintText
        ];

    echo json_encode([
            'status' => 'success',
            'message' => '申請已建立，請將訊息傳送至聊天室',
            'forward_message' => [$mainMessage, $hintMessage]
        ]);
        exit;
    }

    throw new BusinessException("未知的 action: " . htmlspecialchars($action));
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[revoke_api] ' . $e->getMessage());
    http_response_code(400);
        $isBusinessError = $e instanceof BusinessException;
    echo json_encode([
        "status" => "error",
        "message" => $isBusinessError ? $e->getMessage() : "系統錯誤，請稍後再試",
        "leaves" => []
    ]);
}
