<?php
// php/leave_form.php
require_once __DIR__ . '/config.php';
$liffId = getenv('LEAVE_FORM_LIFF_ID');
$ver = time();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>請假申請單</title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <style>
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
        #successPage { display: none; text-align: center; padding-top: 40px; } /* 增加上方留白 */
        .success-icon { font-size: 4rem; color: var(--primary); margin-bottom: 16px; }
        .success-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 8px; color: var(--text-main); }
        .success-desc { color: var(--text-sub); margin-bottom: 40px; font-size: 1rem; line-height: 1.6; }
        .btn-group { display: flex; flex-direction: column; gap: 12px; }
    </style>
</head>
<body>

<div id="formContainer">
    <div class="header">
        <h1>請假申請表</h1>
        <p>Leave Application Form</p>
    </div>

    <div class="card">
        <form id="leaveForm">
            <span class="section-title">日期與時間</span>
            <div class="form-row">
                <div class="form-col">
                    <label>起始日期</label>
                    <input type="date" id="startDate" required>
                </div>
                <div class="form-col" style="flex: 0.6;">
                    <label>時間</label>
                    <input type="time" id="startTime" value="08:30" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-col">
                    <label>結束日期</label>
                    <input type="date" id="endDate" required>
                </div>
                <div class="form-col" style="flex: 0.6;">
                    <label>時間</label>
                    <input type="time" id="endTime" value="17:30" required>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px dashed #eee; margin: 10px 0 20px 0;">

            <span class="section-title">詳細資訊</span>
            <div class="form-group">
                <label>假別類型</label>
                <select id="leaveType" required>
                    <option value="" disabled selected>請選擇假別</option>
                    <option value="特休假">特休假 (Annual Leave)</option>
                    <option value="補休假">補休假 (Compensatory Leave)</option>
                    <option value="事假">事假 (Personal Leave)</option>
                    <option value="病假">病假 (Sick Leave)</option>
                    <option value="公差假">公差假 (Official Leave)</option>
                    <option value="婚假">婚假 (Marriage Leave)</option>
                    <option value="喪假">喪假 (Funeral Leave)</option>
                    <option value="產假">產假 (Maternity Leave)</option>
                </select>
            </div>
            <div class="form-group">
                <label>請假事由 <span class="optional-tag">(選填)</span></label>
                <textarea id="reason" placeholder="如需補充說明，可在此填寫..."></textarea>
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
        系統已將「簽核通知」發送至此聊天室。<br>
        請<strong>關閉此視窗</strong>，並將該訊息<strong>轉傳給主管</strong>。
    </div>
    <div class="btn-group">
        <button id="btnClose" class="btn-submit">關閉視窗</button>
    </div>
</div>

<script>
    const LIFF_ID = "<?php echo $liffId; ?>";
    
    // 初始化日期 (修正時差問題，強制使用台灣/當地設備時間)
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const localToday = `${year}-${month}-${day}`;

    document.getElementById('startDate').value = localToday;
    document.getElementById('endDate').value = localToday;

    liff.init({ liffId: LIFF_ID }).then(() => {
        if (!liff.isLoggedIn()) liff.login();
    });

    document.getElementById('leaveForm').addEventListener('submit', async (e) => {
        e.preventDefault();

        const sDate = document.getElementById('startDate').value;
        const sTime = document.getElementById('startTime').value;
        const eDate = document.getElementById('endDate').value;
        const eTime = document.getElementById('endTime').value;
        
        if (new Date(sDate + 'T' + sTime) >= new Date(eDate + 'T' + eTime)) {
            alert('系統訊息：結束時間必須晚於起始時間。');
            return;
        }

        const btn = document.getElementById('submitBtn');
        const loading = document.getElementById('loading');
        
        btn.disabled = true;
        btn.innerText = "資料傳送中...";
        loading.style.display = 'block';

        try {
            const profile = await liff.getProfile();
            const data = {
                userId: profile.userId,
                startDate: sDate,
                startTime: sTime,
                endDate: eDate,
                endTime: eTime,
                leaveType: document.getElementById('leaveType').value,
                reason: document.getElementById('reason').value
            };

            const response = await fetch('leave_form_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.status === 'success') {
                // 自動發送訊息到聊天室
                await liff.sendMessages(result.forward_message)
                    .then(() => {
                        console.log("Message sent to chat");
                    })
                    .catch((err) => {
                        console.error("Send message failed", err);
                        // 雖然複製按鈕移除了，但如果自動發送失敗，還是要提示一下
                        alert("系統提示：自動發送訊息失敗 (可能權限不足)，請檢查 LIFF 設定。");
                    });

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