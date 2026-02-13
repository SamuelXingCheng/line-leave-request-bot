<?php
require_once __DIR__ . '/config.php';
$liffId = getenv('MENU_LIFF_ID');
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>個人請假紀錄</title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <style>
        :root {
            --primary: #06C755;
            --text-main: #111111;
            --text-sub: #888888;
            --bg: #F5F6F8;
            --radius: 12px;
            
            /* 狀態顏色 */
            --st-pending-bg: #fff7ed; --st-pending-text: #c2410c;
            --st-approved-bg: #f0fdf4; --st-approved-text: #15803d;
            --st-rejected-bg: #fef2f2; --st-rejected-text: #b91c1c;
            --st-cancelled-bg: #f1f5f9; --st-cancelled-text: #64748b;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            margin: 0; padding: 20px;
        }

        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .header h1 { 
            font-size: 1.4rem; font-weight: 700; margin: 0; 
            color: var(--text-main); border-left: 4px solid var(--primary); 
            padding-left: 12px; line-height: 1.2; 
        }
        .btn-back {
            text-decoration: none; font-size: 0.9rem; color: var(--primary); font-weight: 600;
            background: #fff; padding: 6px 12px; border-radius: 20px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); white-space: nowrap;
        }

        .filter-bar {
            display: flex; gap: 8px; margin-bottom: 20px;
            background: #e2e8f0; padding: 4px; border-radius: 10px;
        }
        .filter-btn {
            flex: 1; border: none; background: transparent;
            padding: 8px 0; font-size: 0.9rem; font-weight: 600; color: #64748b;
            border-radius: 8px; cursor: pointer; transition: all 0.2s;
        }
        .filter-btn.active {
            background: #fff; color: var(--primary);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .stats-container {
            display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #fff; padding: 16px; border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            border-top: 4px solid #ddd; position: relative;
        }
        
        /* 卡片顏色定義 */
        .stat-card.annual { border-top-color: #3b82f6; } /* 藍: 特休 */
        .stat-card.comp   { border-top-color: #8b5cf6; } /* 紫: 補休 */
        .stat-card.personal { border-top-color: #f59e0b; } /* 橘: 事假 */
        .stat-card.sick     { border-top-color: #ef4444; } /* 紅: 病假 */
        .stat-card.other    { border-top-color: #64748b; } /* 灰: 其他 */

        .stat-title { font-size: 0.9rem; font-weight: 700; color: #333; margin-bottom: 8px; display: flex; justify-content: space-between; }
        .stat-limit-badge { font-size: 0.7rem; background: #eee; color: #666; padding: 2px 6px; border-radius: 4px; font-weight: normal; }

        .stat-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; font-size: 0.8rem; }
        .stat-row.main { margin-top: 8px; font-size: 0.95rem; font-weight: 700; color: #333; }
        .stat-label { color: #888; }
        .stat-val { font-family: monospace; font-weight: 600; color: #555; }
        
        /* 🔥 進度條樣式通用設定 */
        .progress-bg { height: 4px; background: #f0f0f0; border-radius: 2px; margin-top: 8px; overflow: hidden; }
        .progress-bar { height: 100%; width: 0%; transition: width 0.5s ease; }
        
        /* 各假別進度條顏色 */
        .annual .progress-bar { background: #3b82f6; } /* 藍 */
        .comp .progress-bar { background: #8b5cf6; }   /* 紫 */
        .personal .progress-bar { background: #f59e0b; } /* 橘 */
        .sick .progress-bar { background: #ef4444; }     /* 紅 */

        /* 列表樣式 */
        .leave-list { display: flex; flex-direction: column; gap: 16px; }
        .card {
            background: #fff; border-radius: var(--radius); padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid transparent; 
        }
        .card.pending { border-left: 4px solid #f97316; }
        .card.approved { border-left: 4px solid var(--primary); }
        .card.rejected { border-left: 4px solid #ef4444; }
        .card.cancelled { border-left: 4px solid #94a3b8; background-color: #f8fafc; }

        .card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
        .leave-type { font-size: 1.1rem; font-weight: 700; color: var(--text-main); }
        .status-badge { font-size: 0.75rem; padding: 4px 8px; border-radius: 6px; font-weight: 600; margin-left: 8px; display: inline-block; vertical-align: text-bottom; }
        .bg-pending { background: var(--st-pending-bg); color: var(--st-pending-text); }
        .bg-approved { background: var(--st-approved-bg); color: var(--st-approved-text); }
        .bg-rejected { background: var(--st-rejected-bg); color: var(--st-rejected-text); }
        .bg-cancelled { background: var(--st-cancelled-bg); color: var(--st-cancelled-text); }
        
        .hours { font-family: monospace; font-size: 0.9rem; color: #555; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; font-weight: 600; }
        .card.cancelled .hours { text-decoration: line-through; color: #cbd5e1; }
        
        .info-row { display: flex; margin-top: 8px; align-items: center; }
        .icon { width: 16px; height: 16px; margin-right: 8px; fill: #94a3b8; }
        .text { font-size: 0.9rem; color: #555; line-height: 1.4; }
        .reason { color: #888; font-size: 0.85rem; margin-top: 8px; padding-top: 8px; border-top: 1px dashed #eee; }
        .card.cancelled .leave-type, .card.cancelled .text { color: #94a3b8; }

        #loading { text-align: center; color: var(--text-sub); margin-top: 40px; font-size: 0.9rem; }
        .empty-state { text-align: center; padding: 60px 0; color: #aaa; }
    </style>
</head>
<body>

    <div class="header">
        <h1>請假紀錄</h1>
        <a href="menu.php" class="btn-back">回選單</a>
    </div>

    <div id="statsContainer" class="stats-container"></div>

    <div class="filter-bar">
        <button class="filter-btn active" onclick="setFilter('all', this)">全部</button>
        <button class="filter-btn" onclick="setFilter('month', this)">本月</button>
        <button class="filter-btn" onclick="setFilter('year', this)">今年</button>
    </div>

    <div id="loading">載入資料中...</div>
    <div id="leaveList" class="leave-list" style="display: none;"></div>

    <script>
        const LIFF_ID = "<?php echo $liffId; ?>";
        let allLeaves = [];
        let statsData = {};

        const fmt = (num) => parseFloat(num || 0).toFixed(1).replace(/\.0$/, '');

        async function init() {
            try {
                await liff.init({ liffId: LIFF_ID });
                if (!liff.isLoggedIn()) { liff.login(); return; }
                fetchHistory();
            } catch (err) {
                document.getElementById('loading').innerText = "初始化失敗";
            }
        }

        async function fetchHistory() {
            try {
                const profile = await liff.getProfile();
                const res = await fetch(`query_api.php?action=user_history&userId=${profile.userId}`);
                const json = await res.json();
                
                document.getElementById('loading').style.display = 'none';
                document.getElementById('leaveList').style.display = 'flex';

                if (json.status === 'success') {
                    allLeaves = json.data;
                    statsData = json.stats;
                    renderDashboard(statsData); 
                    const yearBtn = document.querySelector("button[onclick*='year']");
                    setFilter('year', yearBtn); 
                } else {
                    renderList([]);
                }
            } catch (e) {
                alert("讀取失敗：" + e.message);
            }
        }

        function renderDashboard(stats) {
            const container = document.getElementById('statsContainer');
            container.innerHTML = "";

            // 🔥 1. 特休假 (倒扣式: 剩餘/應得)
            const ann = stats.annual;
            // 防呆: 應得如果是0, 進度條就是0
            const annPct = ann.entitled > 0 ? (ann.remaining / ann.entitled) * 100 : 0;
            
            container.innerHTML += `
                <div class="stat-card annual">
                    <div class="stat-title">特休假</div>
                    <div class="stat-row"><span class="stat-label">應得</span><span class="stat-val">${fmt(ann.entitled)}h</span></div>
                    <div class="stat-row"><span class="stat-label">已用</span><span class="stat-val">${fmt(ann.used)}h</span></div>
                    <div class="stat-row main"><span class="stat-label" style="color:#333">剩餘</span><span class="stat-val" style="color:#3b82f6">${fmt(ann.remaining)}h</span></div>
                    <div class="progress-bg"><div class="progress-bar" style="width:${Math.max(0, Math.min(annPct, 100))}%"></div></div>
                </div>
            `;

            // 🔥 2. 補休假 (滿條式: 可用/累積)
            const comp = stats.comp;
            // 邏輯: 累積多少，如果沒用掉就是滿的。用掉一半就剩一半。
            const compPct = comp.earned > 0 ? (comp.remaining / comp.earned) * 100 : 0;

            container.innerHTML += `
                <div class="stat-card comp">
                    <div class="stat-title">補休假</div>
                    <div class="stat-row"><span class="stat-label">累積</span><span class="stat-val">${fmt(comp.earned)}h</span></div>
                    <div class="stat-row"><span class="stat-label">已用</span><span class="stat-val">${fmt(comp.used)}h</span></div>
                    <div class="stat-row main"><span class="stat-label" style="color:#333">可用</span><span class="stat-val" style="color:#8b5cf6">${fmt(comp.remaining)}h</span></div>
                    <div class="progress-bg"><div class="progress-bar" style="width:${Math.max(0, Math.min(compPct, 100))}%"></div></div>
                </div>
            `;

            // 3. 事假 (限14天)
            const personalUsed = parseFloat(stats.others['事假'] || 0);
            const personalLimit = 112; 
            const personalPct = Math.min((personalUsed / personalLimit) * 100, 100);

            container.innerHTML += `
                <div class="stat-card personal">
                    <div class="stat-title">事假 <span class="stat-limit-badge">限14天</span></div>
                    <div class="stat-row"><span class="stat-label">上限</span><span class="stat-val">${personalLimit}h</span></div>
                    <div class="stat-row main"><span class="stat-label" style="color:#333">已用</span><span class="stat-val" style="color:#f59e0b">${fmt(personalUsed)}h</span></div>
                    <div class="progress-bg"><div class="progress-bar" style="width:${personalPct}%"></div></div>
                </div>
            `;

            // 4. 病假 (限30天)
            const sickUsed = parseFloat(stats.others['病假'] || 0);
            const sickLimit = 240; 
            const sickPct = Math.min((sickUsed / sickLimit) * 100, 100);

            container.innerHTML += `
                <div class="stat-card sick">
                    <div class="stat-title">病假 <span class="stat-limit-badge">限30天</span></div>
                    <div class="stat-row"><span class="stat-label">上限</span><span class="stat-val">${sickLimit}h</span></div>
                    <div class="stat-row main"><span class="stat-label" style="color:#333">已用</span><span class="stat-val" style="color:#ef4444">${fmt(sickUsed)}h</span></div>
                    <div class="progress-bg"><div class="progress-bar" style="width:${sickPct}%"></div></div>
                </div>
            `;

            // 5. 其他
            const exclude = ['特休', '特休假', '補休', '補休假', '事假', '病假'];
            for (const [key, val] of Object.entries(stats.others)) {
                if (!exclude.includes(key) && parseFloat(val) > 0) {
                    container.innerHTML += `
                        <div class="stat-card other">
                            <div class="stat-title">${key}</div>
                            <div class="stat-row main" style="margin-top:12px;">
                                <span class="stat-label" style="color:#333">已用</span>
                                <span class="stat-val" style="color:#64748b">${fmt(val)}h</span>
                            </div>
                        </div>
                    `;
                }
            }
        }

        function setFilter(type, btn) {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            if(btn) btn.classList.add('active');

            const now = new Date();
            const currentYear = now.getFullYear().toString();
            const currentYM = `${currentYear}-${(now.getMonth() + 1).toString().padStart(2, '0')}`;

            let filtered = [];
            if (type === 'all') filtered = allLeaves;
            else if (type === 'year') filtered = allLeaves.filter(item => item.start_at.startsWith(currentYear));
            else if (type === 'month') filtered = allLeaves.filter(item => item.start_at.startsWith(currentYM));

            renderList(filtered);
        }

        function renderList(data) {
            const listDiv = document.getElementById('leaveList');
            if (data.length === 0) {
                listDiv.innerHTML = `<div class="empty-state"><span class="empty-icon">📭</span>此區間無請假紀錄</div>`;
                return;
            }

            const statusMap = {
                'pending': { label: '審核中', css: 'bg-pending', card: 'pending' },
                'approved': { label: '已核准', css: 'bg-approved', card: 'approved' },
                'rejected': { label: '已退回', css: 'bg-rejected', card: 'rejected' },
                'cancelled': { label: '已註銷', css: 'bg-cancelled', card: 'cancelled' }
            };

            let html = "";
            data.forEach(item => {
                const st = statusMap[item.status] || statusMap['pending'];
                const start = item.start_at.substring(0, 16);
                const end = item.end_at.substring(0, 16);
                const reason = item.reason ? item.reason : "無備註";

                html += `
                    <div class="card ${st.card}">
                        <div class="card-header">
                            <div>
                                <span class="leave-type">${item.leave_type}</span>
                                <span class="status-badge ${st.css}">${st.label}</span>
                            </div>
                            <span class="hours">${item.leave_hours}h</span>
                        </div>
                        <div class="info-row">
                            <svg class="icon" viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm-7-7h-5v2h5v-2z"/></svg>
                            <span class="text">${start} ~ ${end}</span>
                        </div>
                        <div class="reason">備註：${reason}</div>
                    </div>
                `;
            });
            listDiv.innerHTML = html;
        }

        init();
    </script>
</body>
</html>