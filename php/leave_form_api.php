<?php
// php/leave_form_api.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php'; 
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("請求方法錯誤");
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['userId'])) {
        throw new Exception("無效的輸入資料");
    }

    $db = Database::getConnection();
    $userId = $input['userId'];

    // 1. 取得員工姓名 & 目前餘額 (新增：查餘額)
    // 假設 users 表有 annual_leave_hours (特休) 和 comp_leave_hours (補休) 欄位
    $stmt = $db->prepare("SELECT name, annual_leave_hours, comp_leave_hours FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $userName = $user['name'] ?? "員工";
    $currentComp = floatval($user['comp_leave_hours'] ?? 0);
    $currentAnnual = floatval($user['annual_leave_hours'] ?? 0);

    // 2. 查詢直屬主管
    $stmtSup = $db->prepare("SELECT supervisor_id FROM user_supervisors WHERE user_id = ?");
    $stmtSup->execute([$userId]);
    $supervisorIds = $stmtSup->fetchAll(PDO::FETCH_COLUMN);
    $supervisorNames = !empty($supervisorIds) ? getSupervisorNames($supervisorIds) : [];

    // 3. 計算請假時數
    $startAt = $input['startDate'] . ' ' . $input['startTime'];
    $endAt   = $input['endDate'] . ' ' . $input['endTime'];
    
    // 注意：這裡假設 calculateHours 回傳的是小時數 (float)
    $leaveHours = calculateHours($startAt, $endAt); 

    // 時數零值防護
    if ($leaveHours <= 0) {
        throw new Exception("請假時數必須大於 0，請確認起訖時間是否正確。");
    }

    // ----------------------------------------------------
    // 🔥 核心修改：特休優先抵扣補休邏輯（含浮點數安全比較）
    // ----------------------------------------------------
    $leaveType = $input['leaveType'];
    $reason = trim($input['reason'] ?? '');
    $finalLeaveType = $leaveType;
    $deductComp = 0.0;
    $deductAnnual = 0.0;
    $systemNote = "";

    // 浮點數安全比較輔助函式（epsilon = 0.001 小時）
    $floatGte = function($a, $b) { return ($a - $b) >= -0.001; };
    $floatGt  = function($a, $b) { return ($a - $b) >   0.001; };

    if ($leaveType === '特休假') {
        if ($floatGte($currentComp, $leaveHours)) {
            // 補休夠扣：全扣補休
            $deductComp = $leaveHours;
            $finalLeaveType = "補休假 (特休轉用)";
            $systemNote = "(系統：優先抵扣補休 {$leaveHours} 小時)";
        } else if ($floatGt($currentComp, 0)) {
            // 補休不夠：先扣光補休，剩下扣特休
            $deductComp   = $currentComp;
            $deductAnnual = round($leaveHours - $currentComp, 4);
            $finalLeaveType = "特休/補休";
            $systemNote = "(系統：抵扣補休 {$deductComp} 小時，特休 {$deductAnnual} 小時)";
        } else {
            // 沒補休：全扣特休
            $deductAnnual = $leaveHours;
        }

        // 檢查特休餘額是否足夠
        if ($deductAnnual > $currentAnnual) {
            throw new Exception("特休餘額不足！剩餘 {$currentAnnual} 小時，需扣 {$deductAnnual} 小時。");
        }
    } else if ($leaveType === '補休假') {
        $deductComp = $leaveHours;
        if ($deductComp > $currentComp) {
            throw new Exception("補休餘額不足！剩餘 {$currentComp} 小時。");
        }
    }

    // 將系統備註加到原因
    if ($systemNote) {
        $reason .= ($reason ? "\n" : "") . $systemNote;
    }

    // ----------------------------------------------------
    // 4. 開啟交易，執行扣款與寫入
    // ----------------------------------------------------
    $db->beginTransaction();

    // (A) 扣除餘額 (UPDATE users)
    if ($deductComp > 0 || $deductAnnual > 0) {
        $upd = $db->prepare("UPDATE users SET comp_leave_hours = comp_leave_hours - ?, annual_leave_hours = annual_leave_hours - ? WHERE user_id = ?");
        $upd->execute([$deductComp, $deductAnnual, $userId]);
    }

    // (B) 寫入假單 (INSERT leave_requests)
    // 建議多存 deduct_annual, deduct_comp 欄位以便駁回時退還
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

    $stmt = $db->prepare("
        INSERT INTO leave_requests (
            request_group_id, user_id, user_name, leave_type, reason, start_at, end_at, leave_hours, 
            deduct_annual, deduct_comp, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
        $uuid, $userId, $userName, $finalLeaveType, $reason, $startAt, $endAt, $leaveHours,
        $deductAnnual, $deductComp // 紀錄實際扣了多少
    ]);
    $leaveId = $db->lastInsertId();

    // (C) 寫入簽核關聯
    if (!empty($supervisorIds)) {
        $stmtApp = $db->prepare("INSERT INTO leave_approvals (request_id, supervisor_id, status) VALUES (?, ?, 'pending')");
        foreach ($supervisorIds as $sid) {
            $stmtApp->execute([$leaveId, $sid]);
        }
    }

    $db->commit(); // 提交交易

    // ----------------------------------------------------
    // 5. 產生訊息 (保持原本邏輯)
    // ----------------------------------------------------
    $botId = getenv("LINE_BOT_ID"); 
    $approvalCommand = "/同意請假 {$uuid}";
    $approvalLink = "line://oaMessage/@{$botId}/?" . rawurlencode($approvalCommand);

    $mainMessage = [
        "type" => "text",
        "text" => 
            "【請假申請】\n" .
            "────────────────\n" .
            "申請人員｜{$userName}\n" .
            "假別類別｜{$finalLeaveType}\n" . // 這裡會顯示自動轉換後的假別
            "請假事由｜{$reason}\n" .
            "────────────────\n" .
            "申請時段｜\n" .
            "{$input['startDate']} {$input['startTime']} ~ {$input['endDate']} {$input['endTime']}\n" .
            "請假時數｜{$leaveHours} 小時\n\n" .
            "若同意申請，請點擊下方連結簽核：\n" .
            $approvalLink
    ];

    $hintText = "【系統提示】\n請將上方訊息轉傳給：";
    $hintText .= !empty($supervisorNames) ? "\n─ " . implode("\n─ ", $supervisorNames) : "\n尚未設定您的直屬主管。";

    echo json_encode([
        "status" => "success",
        "forward_message" => [$mainMessage, ["type" => "text", "text" => $hintText]]
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}