<?php
// attendance_api.php

// 允許跨域請求
header("Access-Control-Allow-Origin: https://churchintaichung.org");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php'; // 務必確認 utils.php 有 createBusinessFlex 函式

header('Content-Type: application/json; charset=utf-8');

// --- 讀取環境變數 ---
$locations = [
    [
        'lat' => (float) getenv("COMPANY_LAT"),
        'lng' => (float) getenv("COMPANY_LNG"),
        'name' => '辦公室 A'
    ],
    [
        'lat' => (float) getenv("COMPANY_LAT_2"),
        'lng' => (float) getenv("COMPANY_LNG_2"),
        'name' => '辦公室 B'
    ]
];

define("ALLOWED_RADIUS", (float) getenv("ALLOWED_RADIUS"));
define("OFFICE_QR_TOKEN", getenv("OFFICE_QR_TOKEN")); 

// 讀取前端 JSON
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "無效的輸入"]);
    exit;
}

$userId  = $data['userId'] ?? null;
$mode    = $data['mode'] ?? null; // 前端通常傳 "in" 或 "out"
$lat     = isset($data['latitude']) ? floatval($data['latitude']) : null;
$lng     = isset($data['longitude']) ? floatval($data['longitude']) : null;
$reason  = $data['reason'] ?? null;
$qrToken = $data['qr_token'] ?? null; 

if (empty($userId) || empty($mode) || !isset($lat) || !isset($lng)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "缺少必要參數"]);
    exit;
}

$db = Database::getConnection();
$uuid = $db->query("SELECT UUID()")->fetchColumn();

// 撈出員工姓名
$stmt = $db->prepare("SELECT name FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$row = $stmt->fetch();
$userName = $row ? $row['name'] : "未知使用者";

$now = date("Y-m-d H:i:s");
$displayTime = date("Y-m-d H:i");

// 轉換打卡類型顯示文字
// 1. 定義要存入資料庫的短名稱 (配合截圖資料：只有 "上班" 或 "下班")
$dbMode = ($mode === 'out') ? '下班' : '上班';

// 2. 定義要顯示在卡片上的長名稱 (為了好看：顯示 "上班打卡")
$displayMode = ($mode === 'out') ? '下班打卡' : '上班打卡';

// --- 判定打卡是否合規 ---
$is_in_range = false;
$min_distance = 999999;
$matched_location = "未知地點";

// 1. 檢查 GPS
foreach ($locations as $loc) {
    $d = calculateDistance($lat, $lng, $loc['lat'], $loc['lng']);
    if ($d < $min_distance) {
        $min_distance = $d;
        $matched_location = $loc['name']; // 紀錄最近的辦公室名稱
    }
    if ($d <= ALLOWED_RADIUS) {
        $is_in_range = true;
        $matched_location = $loc['name']; // 確定在範圍內
        break; 
    }
}

// 2. 檢查 QR Code
$is_qr_valid = (!empty(OFFICE_QR_TOKEN) && $qrToken === OFFICE_QR_TOKEN);

$supervisors = [];
$approvalUrl = null;

// 準備變數
$dbStatus = "";
$dbApproval = "";
$employeeFlex = []; // 給員工的卡片
$isSuccess = false;

if ($is_in_range || $is_qr_valid) {
    // === 成功情境 ===
    $isSuccess = true;
    $dbStatus = "success"; // 配合您的資料庫 ENUM
    $dbApproval = "normal"; // 或 valid
    
    $locationDisplay = $is_qr_valid ? "辦公室 (QR驗證)" : "$matched_location (GPS)";
    
    // 產生給員工的成功卡片
    $employeeFlex = createBusinessFlex(
        "SUCCESS",
        "打卡成功",
        [
            "員工姓名" => $userName,
            "打卡類型" => $displayMode, // 🔥 修正：使用 $displayMode
            "打卡時間" => $displayTime,
            "打卡地點" => $locationDisplay,
            "考勤狀態" => "準時 (Normal)"
        ],
        "#06C755" // 綠色
    );

} else {
    // === 異常情境 (距離太遠) ===
    $isSuccess = false;
    $dbStatus = "fail";    
    $dbApproval = "pending";

    // 處理主管簽核名單
    $stmt = $db->prepare("
        SELECT u.user_id, u.name 
        FROM user_supervisors us
        JOIN users u ON us.supervisor_id = u.user_id
        WHERE us.user_id = ?
    ");
    $stmt->execute([$userId]);
    $supervisors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 產生商務版審核連結
    $botId = getenv("LINE_BOT_ID");
    $approvalCommand = "/同意補卡 {$uuid}"; 
    $approvalLink = "line://oaMessage/@{$botId}/?" . rawurlencode($approvalCommand);
    
    // 取得備註原因
    $finalReason = $is_qr_valid ? "QR Code 驗證" : ($reason ?: "無");

    // 🔥 1. 第一則訊息：正式簽核通知 (給員工轉傳用)
    $mainMsgText = "【打卡異常簽核通知】\n" .
                   "────────────────\n" .
                   "申請人員｜{$userName}\n" .
                   "打卡類型｜{$displayMode}\n" .
                   "異常原因｜不在允許範圍內\n" .
                   "距離差距｜" . intval($min_distance) . " 公尺\n" .
                   "打卡時間｜{$displayTime}\n" .
                   "備註說明｜{$finalReason}\n" .
                   "────────────────\n" .
                   "若同意補卡，請點擊下方連結簽核：\n" .
                   $approvalLink;

    // 🔥 2. 第二則訊息：主管提示
    $supervisorMsgText = "【系統提示】\n────────────────\n請將上方訊息轉傳給：";
    if (!empty($supervisors)) {
        // 抓取所有主管的姓名並串接
        $names = array_column($supervisors, 'name');
        $supervisorMsgText .= "\n─ " . implode("\n─ ", $names);
    } else {
        $supervisorMsgText .= "\n尚未設定您的直屬主管，請聯繫管理員。";
    }
}

// --- 寫入資料庫 ---
try {
    // 🔥 新增：5分鐘冷卻時間防護 (防連點洗單)
    $checkSpam = $db->prepare("
        SELECT COUNT(*) FROM attendance_logs 
        WHERE user_id = ? AND mode = ? 
          AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $checkSpam->execute([$userId, $dbMode]);
    if ($checkSpam->fetchColumn() > 0) {
        echo json_encode([
            "status" => "error", 
            "message" => "您已在近期內完成{$displayMode}，請勿頻繁連點。",
            "messages" => []
        ]);
        exit;
    }

    $stmt = $db->prepare("
        INSERT INTO attendance_logs 
        (attendance_uuid, user_id, mode, latitude, longitude, distance, status, reason, approval_status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    // 注意：reason 如果是 QR 打卡要自動填寫
    $finalReason = $is_qr_valid ? "QR Code 驗證" : $reason;

    $stmt->execute([
        $uuid, 
        $userId, 
        $dbMode,   // ✅ 正確：存入資料庫使用 "上班" 或 "下班" (兩個字)
        $lat, 
        $lng, 
        intval($min_distance), 
        $dbStatus, 
        $finalReason, 
        $dbApproval
    ]);

    // --- 推播通知 (Flex Message) ---
    // ==========================================
    // 🔥 省錢修改：把訊息打包傳給前端發送，不用 pushMessage
    // ==========================================
    $responseMessages = [];
    
    // 1. 如果有成功綠卡，加入陣列
    if ($userId && !empty($employeeFlex)) {
        $responseMessages[] = $employeeFlex;
    }

    // 2. 如果異常，加入轉傳文字與主管提示
    if (!$isSuccess && $userId) {
        $responseMessages[] = ['type' => 'text', 'text' => $mainMsgText];
        $responseMessages[] = ['type' => 'text', 'text' => $supervisorMsgText];
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "存檔失敗: " . $e->getMessage()]);
    exit;
}

// 回傳結果給前端，並附帶要發送的訊息陣列
echo json_encode([
    "status" => $dbStatus,
    "message" => $isSuccess ? "打卡成功" : "打卡異常，已通知主管",
    "distance" => intval($min_distance),
    "messages" => $responseMessages // 🔥 把訊息包裝丟給前端
]);

/**
 * 計算距離函式
 */
function calculateDistance($lat1, $lng1, $lat2, $lng2) {
    $R = 6371000; 
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dPhi = deg2rad($lat2 - $lat1);
    $dLambda = deg2rad($lng2 - $lng1);
    $a = sin($dPhi/2)**2 + cos($phi1) * cos($phi2) * sin($dLambda/2)**2;
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}