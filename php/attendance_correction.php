<?php
// php/attendance_correction.php
require_once __DIR__ . '/config.php';

// ★ 請確認 .env 或 config.php 中有設定這個變數
// 如果沒有專用的，可以暫時共用 LEAVE_FORM_LIFF_ID
$liffId = getenv('ATTENDANCE_LIFF_ID');
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>補打卡申請單</title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <style>
        /* 商務版極簡風格 CSS */
        :root {
            --primary: #06C755; /* LINE Green */
            --text-main: #333333;
            --text-sub: #888888;
            --bg: #F5F6F8;
            --border: #E0E0E0;
            --radius: 6px;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            margin: 0; padding: 20px;
            -webkit-font-smoothing: antialiased;
        }

        /* 標題區 */
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; padding: 0 4px; }
        .header-text { border-left: 4px solid var(--primary); padding-left: 8px; }
        .header-text h1 { font-size: 1.25rem; margin: 0; font-weight: 700; color: #000; line-height: 1.2; }
        .header-text p { margin: 4px 0 0 0; font-size: 0.85rem; color: var(--text-sub); }
        .btn-back {
            text-decoration: none; font-size: 0.9rem; color: var(--primary); font-weight: 600;
            background: #fff; padding: 6px 12px; border-radius: 20px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); white-space: nowrap; display: inline-block;
        }
        .btn-back:active { background-color: #f0fdf4; transform: translateY(1px); }
        /* 卡片容器 */
        .card { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }

        /* 表單元素 */
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-size: 0.9rem; font-weight: 600; color: #444; }
        
        input[type="date"], input[type="time"], textarea {
            width: 100%; padding: 12px; font-size: 1rem;
            border: 1px solid var(--border); border-radius: var(--radius);
            background-color: #fff; box-sizing: border-box;
            appearance: none; -webkit-appearance: none;
        }
        textarea { resize: none; min-height: 100px; line-height: 1.5; }
        input:focus, textarea:focus { outline: none; border-color: var(--primary); }

        /* 類型切換按鈕 (上班/下班) */
        .type-selector { display: flex; gap: 10px; }
        .type-btn {
            flex: 1; padding: 12px; text-align: center;
            border: 1px solid var(--border); border-radius: var(--radius);
            cursor: pointer; transition: all 0.2s; font-weight: 500;
            background: #fff; color: var(--text-sub);
        }
        .type-btn.active {
            background-color: var(--primary); color: white;
            border-color: var(--primary); font-weight: 700;
        }

        /* 送出按鈕 */
        .btn-submit {
            width: 100%; padding: 14px; margin-top: 10px;
            background-color: var(--primary); color: white;
            border: none; border-radius: var(--radius);
            font-size: 1rem; font-weight: 700; cursor: pointer;
        }
        .btn-submit:disabled { background-color: #ccc; cursor: not-allowed; }

        /* 載入與成功畫面 */
        #loading { display: none; text-align: center; margin-top: 12px; color: var(--text-sub); font-size: 0.9rem; }
        
        #successPage { display: none; text-align: center; padding-top: 60px; }
        .success-title { font-size: 1.4rem; font-weight: 700; margin-bottom: 12px; color: #000; }
        .success-desc { color: var(--text-sub); font-size: 0.95rem; line-height: 1.6; margin-bottom: 30px; }

    </style>
</head>
<body>

<div id="formContainer">
    <div class="header">
        <div class="header-text">
            <h1>補打卡申請</h1>
            <p>Missed Punch Correction Form</p>
        </div>
        <a href="menu.php" class="btn-back">回選單</a>
    </div>

    <div class="card">
        <form id="correctionForm">
            <div class="form-group">
                <label>補卡日期</label>
                <input type="date" id="targetDate" required>
            </div>

            <div class="form-group">
                <label>正確時間</label>
                <input type="time" id="correctTime" required>
            </div>

            <div class="form-group">
                <label>補卡類別</label>
                <div class="type-selector">
                    <div class="type-btn active" data-value="in">上班補卡</div>
                    <div class="type-btn" data-value="out">下班補卡</div>
                </div>
                <input type="hidden" id="type" value="in">
            </div>

            <div class="form-group">
                <label>補卡事由</label>
                <textarea id="reason" placeholder="請填寫未打卡或時間修正之詳細原因..." required></textarea>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">送出申請</button>
            <div id="loading">系統處理中，請稍候...</div>
        </form>
    </div>
</div>

<div id="successPage">
    <div class="success-title" style="color: var(--primary); font-size: 4rem; margin-bottom: 10px;">✔</div>
    <div class="success-title">申請已建立</div>
    <div class="success-desc" id="successDesc">
        系統已將「簽核通知」發送至此聊天室。<br>
        請<strong>關閉此視窗</strong>，並將該訊息<strong>轉傳給主管</strong>。
    </div>
    
    <div id="manualCopyArea" style="display: none; margin-bottom: 24px; text-align: left;">
        <p style="font-size: 0.85rem; color: #d97706; margin-bottom: 8px; font-weight: bold;">⚠️ 提示：無法自動傳送，請複製以下訊息並貼給主管：</p>
        <textarea id="copyTextarea" readonly style="width: 100%; height: 150px; font-size: 0.85rem; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px; margin-bottom: 12px; color: #333;"></textarea>
        <button type="button" id="btnCopy" class="btn-submit" style="background-color: #f59e0b; margin-top: 0; box-shadow: 0 4px 10px rgba(245, 158, 11, 0.2);">📋 一鍵複製訊息</button>
    </div>

    <div class="btn-group">
        <button type="button" id="btnClose" class="btn-submit">關閉視窗</button>
    </div>
</div>

<script>
    // 從 PHP 取得 LIFF ID
    const LIFF_ID = "<?php echo $liffId; ?>";

    // --- 1. 初始化預設值 ---
    const now = new Date();
    // 解決時區問題，取得當地時間的 YYYY-MM-DD
    const localDate = now.toLocaleDateString('en-CA'); // 格式: 2024-02-11
    const hh = String(now.getHours()).padStart(2, '0');
    const mm = String(now.getMinutes()).padStart(2, '0');

    document.getElementById('targetDate').value = localDate;
    document.getElementById('correctTime').value = `${hh}:${mm}`;

    // --- 2. 類型按鈕切換邏輯 ---
    const btns = document.querySelectorAll('.type-btn');
    btns.forEach(btn => {
        btn.addEventListener('click', () => {
            // 移除所有 active
            btns.forEach(b => b.classList.remove('active'));
            // 加上當前 active
            btn.classList.add('active');
            // 更新隱藏欄位
            document.getElementById('type').value = btn.getAttribute('data-value');
        });
    });

    // --- 3. LIFF 初始化 ---
    liff.init({ liffId: LIFF_ID }).then(() => {
        // 檢查是否已登入
        if (!liff.isLoggedIn()) {
            // 🔥 強制要求 profile 與 chat_message.write 權限
            liff.login({ scope: "profile chat_message.write" });
        } else {
            // 雖然已登入，但檢查是否有發送訊息的權限
            const context = liff.getContext();
            if (context && context.scope && !context.scope.includes("chat_message.write")) {
                // 如果沒有權限，再次觸發登入並要求權限
                liff.login({ scope: "profile chat_message.write" });
            }
        }
    }).catch(err => {
        console.error('LIFF Init failed', err);
        alert('系統錯誤：LIFF 初始化失敗 (' + err.message + ')');
    });

    // --- 4. 表單送出 ---
    document.getElementById('correctionForm').addEventListener('submit', async (e) => {
        e.preventDefault();

        const submitBtn = document.getElementById('submitBtn');
        const loading = document.getElementById('loading');

        // 鎖定按鈕
        submitBtn.disabled = true;
        submitBtn.innerText = "資料傳送中...";
        loading.style.display = 'block';

        try {
            // 取得使用者 Profile
            const profile = await liff.getProfile();
            
            // 準備資料
            const payload = {
                userId: profile.userId,
                targetDate: document.getElementById('targetDate').value,
                correctTime: document.getElementById('correctTime').value,
                type: document.getElementById('type').value,
                reason: document.getElementById('reason').value
            };

            // 呼叫後端 API
            const response = await fetch('attendance_correction_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const result = await response.json();

            if (result.status === 'success') {
                let needManualCopy = false;

                if (result.messages && result.messages.length > 0) {
                    if (liff.isInClient()) {
                        try {
                            await liff.sendMessages(result.messages);
                        } catch (err) {
                            console.error("Send message failed", err);
                            needManualCopy = true;
                        }
                    } else {
                        if (liff.isApiAvailable('shareTargetPicker')) {
                            try {
                                const shareRes = await liff.shareTargetPicker(result.messages);
                                if (!shareRes) needManualCopy = true;
                            } catch (err) {
                                needManualCopy = true;
                            }
                        } else {
                            needManualCopy = true;
                        }
                    }
                }
                
                document.getElementById('formContainer').style.display = 'none';
                document.getElementById('successPage').style.display = 'block';

                if (needManualCopy && result.messages) {
                    document.getElementById('successDesc').style.display = 'none';
                    const copyArea = document.getElementById('manualCopyArea');
                    const copyTextarea = document.getElementById('copyTextarea');
                    // 若為 Flex Message 將嘗試取其 altText 或預設文字
                    copyTextarea.value = result.messages.map(m => m.text || m.altText || '【請透過手機查看系統訊息】').join('\n\n');
                    copyArea.style.display = 'block';
                }
            } else {
                throw new Error(result.message || '未知錯誤');
            }

        } catch (error) {
            console.error('Submission Error:', error);
            alert("申請失敗：\n" + error.message);
            // 恢復按鈕
            submitBtn.disabled = false;
            submitBtn.innerText = "送出申請";
            loading.style.display = 'none';
        }
    });

    // 複製按鈕功能
    document.getElementById('btnCopy').addEventListener('click', () => {
        const text = document.getElementById('copyTextarea').value;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(() => {
                alert('✅ 訊息已成功複製！請切換至 LINE 貼上並傳送給您的主管。');
            });
        } else {
            const copyTextarea = document.getElementById('copyTextarea');
            copyTextarea.select();
            document.execCommand('copy');
            alert('✅ 訊息已成功複製！請切換至 LINE 貼上並傳送給您的主管。');
        }
    });

    // 關閉視窗 (支援外部瀏覽器防呆)
    document.getElementById('btnClose').addEventListener('click', () => {
        if (liff.isInClient()) {
            liff.closeWindow();
        } else {
            window.close(); // 嘗試關閉外部瀏覽器分頁
            alert("請手動關閉此瀏覽器分頁或點擊右上角「回選單」。");
        }
    });
    
</script>
</body>
</html>