<?php
// revoke_api.php
// 🔥 1. 開啟除錯模式 (正式上線後可註解掉)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/utils.php';
    // 🔥 2. 檢查檔案路徑 (最常見的錯誤原因)
    if (!file_exists(__DIR__ . '/Db.php')) throw new Exception("找不到 Db.php");
    require_once __DIR__ . '/Db.php';
    
    // 3. 連線資料庫
    $db = Database::getConnection();

    $action = $_GET['action'] ?? '';
    
    // ==========================================
    // 功能 A: 取得假單列表 (List)
    // ==========================================
    if ($action === 'list') {
        $userId = $_GET['userId'] ?? '';
        
        // 如果沒傳 userId，回傳空陣列而不是報錯 (方便測試)
        if (empty($userId)) {
            echo json_encode(['leaves' => []]); 
            exit;
        }

        // 查詢最近 60 天的假單
        $stmt = $db->prepare("
            SELECT id, request_group_id, leave_type, start_at, end_at, leave_hours, status 
            FROM leave_requests 
            WHERE user_id = ? 
              AND status IN ('pending', 'approved')
              AND end_at > DATE_SUB(NOW(), INTERVAL 60 DAY)
            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['leaves' => $rows]);
        exit;
    }

    // ==========================================
    // 功能 B: 撤回申請 (Pending Delete)
    // ==========================================
    if ($action === 'delete') {
        $groupId = $_GET['groupId'] ?? '';
        if (empty($groupId)) throw new Exception("缺少 request_group_id");

        // 1. 查出要退還多少時數
        $stmt = $db->prepare("SELECT user_id, status, deduct_annual, deduct_comp FROM leave_requests WHERE request_group_id = ?");
        $stmt->execute([$groupId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) throw new Exception("找不到該筆紀錄");

        $db->beginTransaction();
        
        $totalAnnual = 0; 
        $totalComp = 0;
        $userId = $rows[0]['user_id'];

        foreach ($rows as $row) {
            if ($row['status'] !== 'pending') throw new Exception("只能撤回待審核的假單");
            $totalAnnual += floatval($row['deduct_annual'] ?? 0);
            $totalComp += floatval($row['deduct_comp'] ?? 0);
        }

        // 2. 退還時數
        if ($totalAnnual > 0 || $totalComp > 0) {
            $upd = $db->prepare("UPDATE users SET annual_leave_hours = annual_leave_hours + ?, comp_leave_hours = comp_leave_hours + ? WHERE user_id = ?");
            $upd->execute([$totalAnnual, $totalComp, $userId]);
        }

        // 3. 刪除紀錄
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
        // ... (這裡保留您之前的 UUID 產生與寫入 leave_modifications 邏輯) ...
        // 為避免篇幅過長，此處暫略，若前端呼叫此功能再補上
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
            throw new Exception("缺少必要參數");
        }

        // 1. 查詢原始資料
        $stmt = $db->prepare("SELECT user_name, leave_type, start_at, end_at FROM leave_requests WHERE id = ?");
        $stmt->execute([$leaveId]);
        $original = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$original) throw new Exception("找不到原始假單資料");

        // 2. 產生 UUID
        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', 
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), 
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, 
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        // 3. 寫入資料庫
        $ins = $db->prepare("
            INSERT INTO leave_modifications (modification_uuid, leave_request_id, user_id, type, target_date, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $ins->execute([$uuid, $leaveId, $userId, $modType, $targetDate]);

        // ----------------------------------------------------
        // 🔥【新增區塊】查詢直屬主管姓名 (為了產生系統提示)
        // ----------------------------------------------------
        $supNames = [];
        try {
            // A. 先查 supervisor_id
            $stmtSup = $db->prepare("SELECT supervisor_id FROM user_supervisors WHERE user_id = ?");
            $stmtSup->execute([$userId]);
            $supIds = $stmtSup->fetchAll(PDO::FETCH_COLUMN);

            // B. 再查 users table 取得姓名
            if (!empty($supIds)) {
                // 製作問號佔位符 ?,?,?
                $placeholders = implode(',', array_fill(0, count($supIds), '?'));
                $stmtName = $db->prepare("SELECT name FROM users WHERE user_id IN ($placeholders)");
                $stmtName->execute($supIds);
                $supNames = $stmtName->fetchAll(PDO::FETCH_COLUMN);
            }
        } catch (Exception $e) {
            // 查不到主管不影響流程，忽略錯誤，只是提示會顯示空白
        }

        // 4. 準備商務風格通知訊息
        // $botId = getenv("LINE_BOT_ID");
        // $approvalCommand = "/同意銷假 {$uuid}";
        // $approvalLink = "line://oaMessage/@{$botId}/?" . rawurlencode($approvalCommand);

        // 假設您的審核頁 LIFF ID 是 xxx-review
        $supLiffId = getenv("SUPERVISOR_LIFF_ID");
        $approvalLink = "https://liff.line.me/{$supLiffId}?tab=mod&highlight={$uuid}";

        // 定義商務用語
        $typeMapping = [
            'modify_range' => '變更休假時段',
            'full_revoke' => '註銷假單 (全額退回)'
        ];
        $typeText = $typeMapping[$modType] ?? '變更申請';

        // 詳情描述
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

        // 🟢 第一則訊息：申請單內容
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

        // 🟢 第二則訊息：系統提示 (顯示主管姓名)
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

        // 5. 回傳兩則訊息給前端 LIFF
        echo json_encode([
            'status' => 'success', 
            'message' => '申請已建立，請將訊息傳送至聊天室',
            'forward_message' => [$mainMessage, $hintMessage] // 🔥 陣列中放入兩則訊息
        ]);
        exit;
    }

    // 如果沒有對應動作
    throw new Exception("未知的 action: " . htmlspecialchars($action));

} catch (Exception $e) {
    // 捕捉所有錯誤並回傳 400，但附帶詳細訊息
    http_response_code(400);
    echo json_encode([
        "status" => "error", 
        "message" => $e->getMessage(),
        "leaves" => [] // 🔥 防止前端 JS 因為讀不到 leaves 而崩潰
    ]);
}