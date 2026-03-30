<?php
require_once __DIR__ . '/config.php';
$liffId = getenv('MENU_LIFF_ID');
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>功能選單</title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <style>
        :root {
            --primary: #06C755;
            --text-main: #111111;
            --text-sub: #888888;
            --bg: #F5F6F8;
            --border: #E0E0E0;
            --radius: 12px;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            margin: 0; padding: 20px;
            -webkit-font-smoothing: antialiased;
        }

        /* Header Styles 保持與請假單一致 */
        .header { margin-bottom: 24px; padding: 0 4px; }
        .header h1 { 
            font-size: 1.4rem; font-weight: 700; margin: 0; 
            color: var(--text-main); border-left: 4px solid var(--primary); 
            padding-left: 12px; line-height: 1.2; 
        }
        .header p { margin: 6px 0 0 16px; font-size: 0.85rem; color: var(--text-sub); }

        /* 選單網格 */
        .menu-grid { display: flex; flex-direction: column; gap: 12px; }

        /* 選單項目卡片 */
        .menu-item {
            background: #fff;
            padding: 20px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid transparent;
            transition: all 0.2s;
        }
        .menu-item:active {
            background-color: #f9f9f9;
            transform: translateY(1px);
            border-color: var(--primary);
        }

        /* 幾何圖示盒 */
        .icon-box {
            width: 44px; height: 44px;
            border-radius: 10px;
            background-color: #f0fdf4;
            display: flex; align-items: center; justify-content: center;
            margin-right: 16px;
            flex-shrink: 0;
        }
        .icon-box svg { width: 22px; height: 22px; fill: var(--primary); }

        /* 文字內容 */
        .info { flex: 1; }
        .info h2 { font-size: 1rem; font-weight: 700; margin: 0; color: var(--text-main); }
        .info p { font-size: 0.8rem; margin: 4px 0 0 0; color: var(--text-sub); line-height: 1.4; }

        /* 右側箭頭 */
        .chevron { color: #ccc; font-size: 1.2rem; font-weight: 300; }
    </style>
</head>
<body>

    <div class="header">
        <h1>功能選單</h1>
        <p>Employee Management System</p>
    </div>

    <div class="menu-grid">

        <a href="supervisor_index.php" class="menu-item">
            <div class="icon-box">
                <svg viewBox="0 0 24 24"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm-2 14l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
            </div>
            <div class="info">
                <h2>主管審核中心</h2>
                <p>簽核團隊成員的新假單、變更申請及歷史紀錄。</p>
            </div>
            <div class="chevron">›</div>
        </a>
        
        <a href="revoke_form.php" class="menu-item">
            <div class="icon-box">
                <svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm-7-7h-5v2h5v-2z"/></svg>
            </div>
            <div class="info">
                <h2>請假變更與銷假</h2>
                <p>調整已核准之假單時段或申請註銷紀錄。</p>
            </div>
            <div class="chevron">›</div>
        </a>

        <a href="query_leave.php" class="menu-item">
            <div class="icon-box">
                <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
            </div>
            <div class="info">
                <h2>個人請假紀錄</h2>
                <p>查詢歷史假單狀態、審核進度與時數明細。</p>
            </div>
            <div class="chevron">›</div>
        </a>

        <a href="query_colleagues.php" class="menu-item">
            <div class="icon-box">
                <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
            </div>
            <div class="info">
                <h2>同事出勤查詢</h2>
                <p>確認團隊成員今日之出勤狀態與預計返崗時間。</p>
            </div>
            <div class="chevron">›</div>
        </a>
        <a href="admin_users.php" class="menu-item">
            <div class="icon-box">
                <svg viewBox="0 0 24 24"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm-2 14l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
            </div>
            <div class="info">
                <h2>HR管理中心</h2>
                <p>管理員工資料、設定主管與審核流程。</p>
            </div>
            <div class="chevron">›</div>
        </a>
    </div>

    <script>
        const LIFF_ID = "<?php echo $liffId; ?>";
        liff.init({ liffId: LIFF_ID }).then(() => {
            if (!liff.isLoggedIn()) {
                liff.login();
                return;
            }
            
            // 🔥 自動路由：如果網址有帶跳轉參數，像接駁車一樣自動送往審核頁面
            const urlParams = new URLSearchParams(window.location.search);
            let state = urlParams.get('liff.state');
            
            // 解析被 LINE 隱藏的參數
            if (state && state.includes('tab=')) {
                window.location.href = 'supervisor_index.php' + (state.startsWith('?') ? state : '?' + state);
                return;
            }
            // 解析一般參數
            if (urlParams.get('tab')) {
                window.location.href = 'supervisor_index.php' + window.location.search;
                return;
            }
        }).catch(err => {
            console.error("LIFF Initialization failed", err);
        });
    </script>
</body>
</html>