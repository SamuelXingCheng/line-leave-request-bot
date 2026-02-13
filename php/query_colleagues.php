<?php
require_once __DIR__ . '/config.php';
$liffId = getenv('MENU_LIFF_ID');
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>同事出勤查詢</title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <style>
        :root {
            --primary: #06C755;
            --text-main: #111111;
            --text-sub: #888888;
            --bg: #F5F6F8;
            --radius: 12px;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            margin: 0; padding: 20px;
        }

        /* 標題與搜尋列 (微調 H1 margin) */
        .header { margin-bottom: 20px; }
        .header h1 { 
            font-size: 1.4rem; font-weight: 700; margin: 0; /* 移除原本的 margin-bottom */
            color: var(--text-main); border-left: 4px solid var(--primary); 
            padding-left: 12px; line-height: 1.2; 
        }

        /* 🔥 新增回選單按鈕樣式 */
        .btn-back {
            text-decoration: none;
            font-size: 0.9rem;
            color: var(--primary); /* 綠色文字 */
            font-weight: 600;
            background: #fff;
            padding: 6px 12px;
            border-radius: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            white-space: nowrap;
        }

        .btn-back:active {
            background-color: #f0fdf4;
            transform: translateY(1px);
        }
        
        .search-box { position: relative; }
        .search-input {
            width: 100%; padding: 14px 16px 14px 44px;
            border: none; border-radius: var(--radius);
            font-size: 1rem; background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            box-sizing: border-box; outline: none;
        }
        .search-icon {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            width: 20px; height: 20px; fill: #ccc;
        }

        /* 列表樣式 */
        .list-container { display: grid; grid-template-columns: 1fr; gap: 12px; }
        
        .colleague-card {
            background: #fff; padding: 16px 20px; border-radius: var(--radius);
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: transform 0.1s;
        }
        .colleague-card:active { transform: scale(0.99); background-color: #fafafa; }

        /* 左側：姓名與頭像 */
        .user-info { display: flex; align-items: center; gap: 12px; }
        .avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background-color: #e2e8f0; color: #64748b;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.1rem;
        }
        /* 若是在勤，頭像框變成綠色 */
        .status-work .avatar { background-color: #dcfce7; color: #166534; }
        
        .name { font-weight: 600; font-size: 1rem; color: #333; }
        
        /* 右側：狀態標籤 */
        .status-badge {
            font-size: 0.85rem; padding: 4px 10px; border-radius: 20px; font-weight: 500;
        }
        .status-work .status-badge { background-color: #f0fdf4; color: #15803d; }
        .status-leave .status-badge { background-color: #fef2f2; color: #b91c1c; }
        
        .return-time { display: block; font-size: 0.75rem; color: #ef4444; margin-top: 4px; text-align: right; }
        
        .hidden { display: none !important; }
    </style>
</head>
<body>

    <div class="header">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h1>同事查詢</h1>
            <a href="menu.php" class="btn-back">回選單</a>
        </div>

        <div class="search-box">
            <svg class="search-icon" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
            <input type="text" id="searchInput" class="search-input" placeholder="輸入姓名快速搜尋..." oninput="filterList()">
        </div>
    </div>

    <div id="loading" style="text-align:center; color:#999; margin-top:40px;">讀取中...</div>
    <div id="listContainer" class="list-container"></div>

    <a href="menu.php" class="btn-back">回選單</a>

    <script>
        const LIFF_ID = "<?php echo $liffId; ?>";
        let colleagues = []; // 儲存原始資料供搜尋用

        async function init() {
            try {
                await liff.init({ liffId: LIFF_ID });
                if (!liff.isLoggedIn()) { liff.login(); return; }
                fetchColleagues();
            } catch (err) {
                document.getElementById('loading').innerText = "初始化失敗";
            }
        }

        async function fetchColleagues() {
            try {
                const res = await fetch(`query_api.php?action=colleague_status`);
                const json = await res.json();
                
                document.getElementById('loading').style.display = 'none';
                
                if (json.status === 'success') {
                    colleagues = json.data;
                    renderList(colleagues);
                } else {
                    alert("讀取失敗");
                }
            } catch (e) {
                console.error(e);
            }
        }

        function renderList(data) {
            const container = document.getElementById('listContainer');
            container.innerHTML = "";

            if (data.length === 0) {
                container.innerHTML = "<div style='text-align:center; color:#ccc; padding:20px;'>無符合資料</div>";
                return;
            }

            data.forEach(user => {
                const isLeave = user.status === 'leave';
                const statusClass = isLeave ? 'status-leave' : 'status-work';
                const initial = user.name.charAt(0); // 取姓名第一個字當頭像

                // 如果是請假，顯示預計回來時間
                let statusHtml = `<div class="status-badge">${user.status_text}</div>`;
                if (isLeave && user.return_time) {
                    statusHtml += `<span class="return-time">至 ${user.return_time.substring(5)}</span>`; // 只顯示 MM-DD HH:mm
                }

                const html = `
                    <div class="colleague-card ${statusClass}">
                        <div class="user-info">
                            <div class="avatar">${initial}</div>
                            <span class="name">${user.name}</span>
                        </div>
                        <div style="text-align:right;">
                            ${statusHtml}
                        </div>
                    </div>
                `;
                container.innerHTML += html;
            });
        }

        // 前端即時搜尋
        function filterList() {
            // 🔥 加入 .trim() 去除前後空白，體驗更好
            const input = document.getElementById('searchInput').value.trim().toLowerCase();
            
            const filtered = colleagues.filter(user => 
                user.name.toLowerCase().includes(input)
            );
            renderList(filtered);
        }

        init();
    </script>
</body>
</html>