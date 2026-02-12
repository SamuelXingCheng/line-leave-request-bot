<?php
// overtime_form.php
require_once __DIR__ . '/config.php';
$liffId = getenv('OVERTIME_LIFF_ID');
if (!$liffId) die("系統錯誤：未設定 OVERTIME_LIFF_ID");
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>加班申請單</title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <style>
        /* 🔥 直接沿用 leave_form.php 的 CSS，保證版面一致 */
        :root {
            --primary: #06C755;
            --text-main: #111111;
            --text-sub: #888888;
            --bg: #F5F6F8;
            --border: #E0E0E0;
            --radius: 8px;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            margin: 0; padding: 16px;
            -webkit-font-smoothing: antialiased;
        }
        /* Header & Form Styles */
        .header { margin-bottom: 20px; padding: 0 4px; }
        .header h1 { font-size: 1.4rem; font-weight: 700; margin: 0; color: var(--text-main); border-left: 4px solid var(--primary); padding-left: 12px; line-height: 1.2; }
        .header p { margin: 6px 0 0 16px; font-size: 0.85rem; color: var(--text-sub); }
        .card { background: #fff; border-radius: 12px; padding: 24px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
        .section-title { font-size: 0.85rem; font-weight: 600; color: var(--text-sub); margin-bottom: 12px; display: block; letter-spacing: 0.5px; }
        .form-row { display: flex; gap: 12px; margin-bottom: 20px; }
        .form-col { flex: 1; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-size: 0.95rem; font-weight: 500; color: #333; }
        .optional-tag { font-size: 0.8rem; color: #999; font-weight: normal; margin-left: 4px; }
        input[type="date"], input[type="time"], select, textarea { width: 100%; padding: 12px; font-size: 1rem; border: 1px solid var(--border); border-radius: var(--radius); background-color: #fff; color: var(--text-main); box-sizing: border-box; appearance: none; -webkit-appearance: none; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(6, 199, 85, 0.1); }
        textarea { resize: none; min-height: 80px; line-height: 1.5; }
        .btn-submit { width: 100%; padding: 14px; background-color: var(--primary); color: white; border: none; border-radius: var(--radius); font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 24px; box-shadow: 0 4px 10px rgba(6, 199, 85, 0.2); transition: background-color 0.2s; }
        .btn-submit:disabled { background-color: #ccc; box-shadow: none; }
        #loading { display: none; margin-top: 12px; text-align: center; font-size: 0.85rem; color: var(--text-sub); }

        /* Success Page Styles */
        #successPage { display: none; text-align: center; padding-top: 40px; }
        .success-icon { font-size: 4rem; color: var(--primary); margin-bottom: 16px; }
        .success-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 8px; color: var(--text-main); }
        .success-desc { color: var(--text-sub); margin-bottom: 40px; font-size: 1rem; line-height: 1.6; }
        .btn-group { display: flex; flex-direction: column; gap: 12px; }
    </style>
</head>
<body>

<div id="formContainer">
    <div class="header">
        <h1>加班申請單</h1>
        <p>Overtime Application Form</p>
    </div>

    <div class="card">
        <form id="otForm">
            <span class="section-title">日期與時間</span>
            
            <div class="form-group">
                <label>加班日期</label>
                <input type="date" id="date" required>
            </div>

            <div class="form-row">
                <div class="form-col">
                    <label>開始時間</label>
                    <input type="time" id="start" value="18:00" required>
                </div>
                <div class="form-col">
                    <label>結束時間</label>
                    <input type="time" id="end" value="20:00" required>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px dashed #eee; margin: 10px 0 20px 0;">

            <span class="section-title">詳細資訊</span>
            <div class="form-group">
                <label>加班事由 / 工作內容</label>
                <textarea id="reason" placeholder="請簡述加班原因..." required></textarea>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">送出申請</button>
            <div id="loading">系統處理中，請稍候...</div>
        </form>
    </div>
</div>

<div id="successPage">
    <div class="success-icon">✔</div>
    <div class="success-title">申請已建立</div>
    <div class="success-desc">
        系統已將「加班申請通知」發送至此聊天室。<br>
        請<strong>關閉此視窗</strong>，並將該訊息<strong>轉傳給主管</strong>。
    </div>
    <div class="btn-group">
        <button id="btnClose" class="btn-submit">關閉視窗</button>
    </div>
</div>

<script>
    const LIFF_ID = "<?php echo $liffId; ?>";
    const API_URL = "overtime_api.php";

    // 預設填入今天日期
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('date').value = today;

    // LIFF 初始化
    liff.init({ liffId: LIFF_ID }).then(() => {
        if (!liff.isLoggedIn()) liff.login();
    });

    // 表單送出
    document.getElementById('otForm').addEventListener('submit', async (e) => {
        e.preventDefault();

        const btn = document.getElementById('submitBtn');
        const loading = document.getElementById('loading');
        
        // 取得欄位
        const date = document.getElementById('date').value;
        const start = document.getElementById('start').value;
        const end = document.getElementById('end').value;
        const reason = document.getElementById('reason').value;
        
        if (start >= end) {
            alert('系統訊息：結束時間必須晚於開始時間。');
            return;
        }

        btn.disabled = true;
        btn.innerText = "資料傳送中...";
        loading.style.display = 'block';

        try {
            const profile = await liff.getProfile();
            const payload = {
                userId: profile.userId,
                date: date,
                start: start,
                end: end,
                reason: reason
            };

            const response = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const result = await response.json();

            if (result.status === 'success') {
                // 🔥 修改重點：接收並發送多則訊息 (messages)
                if (result.messages && result.messages.length > 0) {
                    try {
                        if (liff.isInClient()) {
                            // liff.sendMessages 接受陣列，所以這裡會發送 [申請單, 系統提示]
                            await liff.sendMessages(result.messages);
                            console.log("Messages sent to chat");
                        }
                    } catch (err) {
                        console.error("Send message failed", err);
                    }
                }

                // 切換顯示成功頁面
                document.getElementById('formContainer').style.display = 'none';
                document.getElementById('successPage').style.display = 'block';

            } else {
                throw new Error(result.message || '未知錯誤');
            }
        } catch (err) {
            alert("系統訊息：申請失敗 (" + err.message + ")");
            btn.disabled = false;
            btn.innerText = "送出申請";
            loading.style.display = 'none';
        }
    });

    // 關閉視窗
    document.getElementById('btnClose').addEventListener('click', () => {
        liff.closeWindow();
    });

</script>
</body>
</html>