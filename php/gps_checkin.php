<?php
// 🔥 必須先加入這幾行，才能讀取 .env 或環境變數
require_once __DIR__ . '/config.php'; 

// 確保有抓到 ID，否則給一個預設錯誤提示以便除錯
$liffId = getenv('GPS_CHECKIN_LIFF_ID');
if (!$liffId) {
    die("系統錯誤：未設定 GPS_CHECKIN_LIFF_ID，請檢查 .env 或 config.php 設定。");
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>出勤打卡系統</title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .btn-disabled { opacity: 0.5; cursor: not-allowed; }
        
        /* 商務風格讀取條 */
        .loader {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: inline-block;
            vertical-align: middle;
            margin-right: 8px;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        [v-cloak] { display: none; }
    </style>
</head>
<body class="flex flex-col items-center justify-center min-h-screen p-4">

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 w-full max-w-sm">
        
        <div class="border-b border-gray-100 pb-4 mb-4 text-center">
            <h2 class="text-lg font-bold text-gray-800">台中市召會出勤系統</h2>
            <p class="text-xs text-gray-400 mt-1">ATTENDANCE CHECK-IN</p>
        </div>

        <div id="qrAlert" class="hidden mb-4 p-3 bg-blue-50 border-l-4 border-blue-500 text-sm text-blue-700 text-left">
            <span class="font-bold block mb-1">QR Code 驗證模式</span>
            已偵測到辦公室 QR Code，將優先進行掃碼打卡。
        </div>

        <div id="statusArea" class="text-center text-sm text-gray-500 mb-6 py-4 bg-gray-50 rounded-lg">
            <div class="loader"></div> 系統初始化中...
        </div>

        <div id="actionArea" class="hidden transition-opacity duration-500 ease-in-out">
            <div class="flex justify-between items-end mb-2">
                <p class="text-sm font-semibold text-gray-700 border-l-4 border-green-500 pl-2">目前位置</p>
                <span class="text-xs text-gray-400">GPS 定位已更新</span>
            </div>
            
            <div class="w-full h-48 rounded-lg overflow-hidden border border-gray-200 mb-6 relative bg-gray-100">
                <iframe id="mapFrame" class="w-full h-full border-0" loading="lazy" allowfullscreen></iframe>
                <div class="absolute inset-0 pointer-events-none border border-black/5 rounded-lg"></div>
            </div>

            <div class="space-y-3">
                <button id="btnIn" onclick="submitAttendance('上班')" 
                    class="w-full bg-green-600 text-white py-3 px-4 rounded-lg hover:bg-green-700 font-medium shadow-sm active:transform active:scale-[0.98] transition-all">
                    上班打卡
                </button>

                <button id="btnOut" onclick="submitAttendance('下班')" 
                    class="w-full bg-white text-gray-700 border border-gray-300 py-3 px-4 rounded-lg hover:bg-gray-50 font-medium shadow-sm active:transform active:scale-[0.98] transition-all">
                    下班打卡
                </button>
            </div>
        </div>
        
        <div class="mt-6 text-center">
            <p class="text-xs text-gray-300">© Taichung Church Administration</p>
        </div>
    </div>

    <script>
        // --- 設定區 ---
        const LIFF_ID = "<?php echo $liffId; ?>";
        const API_URL = "attendance_api.php";

        const LOCATIONS = [
            { lat: <?php echo getenv('COMPANY_LAT') ?: 24.13384; ?>, lng: <?php echo getenv('COMPANY_LNG') ?: 120.68162; ?> },
            { lat: <?php echo getenv('COMPANY_LAT_2') ?: 24.188632; ?>, lng: <?php echo getenv('COMPANY_LNG_2') ?: 120.607218; ?> }
        ];
        const ALLOWED_RADIUS = <?php echo getenv('ALLOWED_RADIUS') ?: 100; ?>;

        let currentLat = null;
        let currentLng = null;
        let qrToken = null;
        let isSubmitting = false;

        // --- 初始化 ---
        window.onload = async function() {
            const urlParams = new URLSearchParams(window.location.search);
            qrToken = urlParams.get('qr_token');
            
            if (qrToken) {
                document.getElementById('qrAlert').classList.remove('hidden');
            }

            try {
                await liff.init({ liffId: LIFF_ID });
                if (!liff.isLoggedIn()) {
                    // 🔥 加入 chat_message.write 權限要求
                    liff.login({ scope: "profile chat_message.write" });
                    return;
                } else {
                    // 檢查是否已授權發送訊息
                    const context = liff.getContext();
                    if (context && context.scope && !context.scope.includes("chat_message.write")) {
                        liff.login({ scope: "profile chat_message.write" });
                        return;
                    }
                }
                updateStatus("正在取得 GPS 定位...", true);
                getLocation();
            } catch (err) {
                updateStatus("系統錯誤：LIFF 初始化失敗 (" + err + ")", false, true);
            }
        };

        // --- 取得定位 ---
        function getLocation() {
            if (!navigator.geolocation) {
                updateStatus("您的裝置不支援地理定位功能", false, true);
                return;
            }
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    currentLat = pos.coords.latitude;
                    currentLng = pos.coords.longitude;
                    
                    const mapUrl = `https://maps.google.com/maps?q=${currentLat},${currentLng}&z=16&output=embed`;
                    document.getElementById('mapFrame').src = mapUrl;
                    
                    // 隱藏狀態條，顯示操作區
                    document.getElementById('statusArea').classList.add('hidden');
                    document.getElementById('actionArea').classList.remove('hidden');
                },
                (err) => {
                    let msg = "定位失敗";
                    if (err.code === 1) msg = "請允許瀏覽器存取您的位置資訊";
                    updateStatus(msg, false, true);
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
            );
        }

        // --- 計算距離 ---
        function getMinDistance(lat, lng) {
            let min = Infinity;
            LOCATIONS.forEach(loc => {
                const d = calculateDistance(lat, lng, loc.lat, loc.lng);
                if (d < min) min = d;
            });
            return min;
        }

        function calculateDistance(lat1, lng1, lat2, lng2) {
            const R = 6371000;
            const phi1 = lat1 * Math.PI / 180;
            const phi2 = lat2 * Math.PI / 180;
            const dPhi = (lat2 - lat1) * Math.PI / 180;
            const dLambda = (lng2 - lng1) * Math.PI / 180;
            const a = Math.sin(dPhi/2)**2 + Math.cos(phi1)*Math.cos(phi2)*Math.sin(dLambda/2)**2;
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            return R * c;
        }

        // --- 送出打卡 ---
        async function submitAttendance(modeName) {
            if (isSubmitting) return;
            
            const modeCode = (modeName === '上班') ? 'in' : 'out';
            const dist = getMinDistance(currentLat, currentLng);
            let reason = null;

            if (!qrToken && dist > ALLOWED_RADIUS) {
                reason = prompt(`【系統提示】\n您距離辦公室 ${Math.round(dist)} 公尺，不在允許範圍內。\n\n請輸入外地打卡原因（如：外出洽公）：`);
                if (!reason) return; 
            }

            isSubmitting = true;
            updateBtnStatus(true, modeName);

            try {
                const profile = await liff.getProfile();
                
                const payload = {
                    userId: profile.userId,
                    mode: modeCode,
                    latitude: currentLat,
                    longitude: currentLng,
                    reason: reason,
                    qr_token: qrToken
                };

                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                
                const data = await res.json();

                if (data.status === 'success' || data.status === 'fail') {
                    // 🔥 新增：讓 LIFF 代勞發送訊息 (免扣額度！)
                    if (data.messages && data.messages.length > 0) {
                        try {
                            if (liff.isInClient()) {
                                await liff.sendMessages(data.messages);
                            }
                        } catch (err) {
                            console.error("Send message failed", err);
                        }
                    }

                    alert(`【打卡完成】\n請查看聊天室訊息。`);
                    liff.closeWindow();
                } else {
                    throw new Error(data.message || '未知錯誤');
                }

            } catch (err) {
                alert("【打卡失敗】" + err.message);
                updateBtnStatus(false); 
            }
        }

        // --- UI 輔助 ---
        function updateStatus(text, isLoading = false, isError = false) {
            const el = document.getElementById('statusArea');
            let content = text;
            
            if (isLoading) {
                content = `<div class="loader"></div> ${text}`;
            }
            
            el.innerHTML = content;
            el.className = `text-center text-sm mb-6 py-4 rounded-lg border ${isError ? 'bg-red-50 text-red-600 border-red-100' : 'bg-gray-50 text-gray-500 border-gray-100'}`;
            el.classList.remove('hidden');
        }

        function updateBtnStatus(disabled, activeMode = null) {
            const btnIn = document.getElementById('btnIn');
            const btnOut = document.getElementById('btnOut');
            
            if (disabled) {
                btnIn.classList.add('opacity-50', 'cursor-not-allowed');
                btnOut.classList.add('opacity-50', 'cursor-not-allowed');
                btnIn.disabled = true;
                btnOut.disabled = true;
                
                if (activeMode === '上班') btnIn.innerText = "資料傳送中...";
                if (activeMode === '下班') btnOut.innerText = "資料傳送中...";
            } else {
                btnIn.classList.remove('opacity-50', 'cursor-not-allowed');
                btnOut.classList.remove('opacity-50', 'cursor-not-allowed');
                btnIn.disabled = false;
                btnOut.disabled = false;
                btnIn.innerText = "上班打卡";
                btnOut.innerText = "下班打卡";
            }
        }
    </script>
</body>
</html>