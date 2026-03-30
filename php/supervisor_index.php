<?php
require_once __DIR__ . '/config.php';
$liffId = getenv('MENU_LIFF_ID');
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>主管審核中心</title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <style>
        :root { --primary: #06C755; --bg: #F5F6F8; --text: #333; }
        body { font-family: -apple-system, sans-serif; background: var(--bg); margin: 0; padding: 0; padding-bottom: 80px; }

        /* 頂部 Tabs (支援橫向滑動) */
        .tabs-wrapper { background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; overflow-x: auto; }
        .tabs { display: flex; width: max-content; min-width: 100%; }
        .tab { flex: 1; text-align: center; padding: 15px 12px; font-weight: bold; color: #999; cursor: pointer; border-bottom: 3px solid transparent; transition: all 0.2s; font-size: 0.9rem; white-space: nowrap; }
        .tab.active { color: var(--primary); border-bottom-color: var(--primary); }
        .badge { background: #ef4444; color: #fff; font-size: 0.7rem; padding: 2px 6px; border-radius: 10px; margin-left: 2px; display: none; }
        
        .container { padding: 15px; display: none; }
        .container.active { display: block; }

        /* 卡片樣式 */
        .card { background: #fff; border-radius: 10px; padding: 15px; margin-bottom: 15px; display: flex; align-items: flex-start; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .checkbox-area { padding-top: 5px; margin-right: 15px; }
        .checkbox { width: 22px; height: 22px; accent-color: var(--primary); }
        
        .info { flex: 1; }
        .row-top { display: flex; justify-content: space-between; margin-bottom: 5px; }
        .name { font-weight: bold; font-size: 1rem; color: #333; }
        .tag { font-size: 0.75rem; padding: 2px 8px; border-radius: 4px; font-weight: bold; }
        
        /* 狀態與假別顏色 */
        .tag-leave { background: #e8f5e9; color: #2e7d32; }
        .tag-mod { background: #fff3e0; color: #e65100; }
        .tag-ot { background: #e0e7ff; color: #4338ca; } /* 加班藍 */
        .tag-clock { background: #fce7f3; color: #be185d; } /* 異常粉 */
        .tag-approved { background: #dcfce7; color: #15803d; } 
        .tag-rejected { background: #fee2e2; color: #b91c1c; } 
        
        .date { font-size: 0.85rem; color: #666; font-family: monospace; margin-top: 4px; }
        .desc { font-size: 0.85rem; color: #888; margin-top: 4px; }

        .fab-bar { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; padding: 15px; box-shadow: 0 -2px 10px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; }
        .btn-group { display: flex; gap: 10px; flex: 1; margin-left: 15px; }
        .btn-submit { background: var(--primary); color: #fff; border: none; padding: 10px 0; border-radius: 30px; font-weight: bold; font-size: 1rem; flex: 1; cursor: pointer; }
        .btn-reject { background: #fff; color: #ef4444; border: 1px solid #ef4444; padding: 10px 0; border-radius: 30px; font-weight: bold; font-size: 1rem; flex: 1; cursor: pointer; }
        .btn-submit:disabled, .btn-reject:disabled { opacity: 0.5; cursor: not-allowed; }
        
        .empty-msg { text-align: center; color: #aaa; margin-top: 50px; }
    </style>
</head>
<body>

    <div class="tabs-wrapper">
        <div class="tabs">
            <div class="tab active" onclick="switchTab('leave')">新假單 <span id="badge-leave" class="badge">0</span></div>
            <div class="tab" onclick="switchTab('mod')">變更/銷假 <span id="badge-mod" class="badge">0</span></div>
            <div class="tab" onclick="switchTab('overtime')">加班單 <span id="badge-overtime" class="badge">0</span></div>
            <div class="tab" onclick="switchTab('clockin')">打卡異常 <span id="badge-clockin" class="badge">0</span></div>
            <div class="tab" onclick="switchTab('history')">已審核</div>
        </div>
    </div>

    <div id="view-leave" class="container active"><div id="list-leave"></div></div>
    <div id="view-mod" class="container"><div id="list-mod"></div></div>
    <div id="view-overtime" class="container"><div id="list-overtime"></div></div>
    <div id="view-clockin" class="container"><div id="list-clockin"></div></div>
    <div id="view-history" class="container"><div id="list-history"></div></div>

    <div id="fab-bar" class="fab-bar">
        <div style="font-size:0.9rem; color:#555; white-space: nowrap;">已選 <span id="count" style="font-weight:bold;">0</span> 筆</div>
        <div class="btn-group">
            <button id="btnReject" class="btn-reject" onclick="processBatch('reject')" disabled>駁回</button>
            <button id="btnApprove" class="btn-submit" onclick="processBatch('approve')" disabled>核准</button>
        </div>
    </div>

    <script>
        const LIFF_ID = "<?php echo $liffId; ?>";
        let currentLineId = "";
        let currentTab = 'leave'; 

        async function init() {
            await liff.init({ liffId: LIFF_ID });
            if (!liff.isLoggedIn()) { liff.login(); return; }
            const profile = await liff.getProfile();
            currentLineId = profile.userId;

            // 🔥 優化：讀取網址參數，自動切換分頁 (並支援破解 liff.state 隱藏參數)
            const urlParams = new URLSearchParams(window.location.search);
            let targetTab = urlParams.get('tab');
            
            if (!targetTab && urlParams.get('liff.state')) {
                const stateStr = urlParams.get('liff.state');
                if (stateStr.includes('tab=')) {
                    const stateParams = new URLSearchParams(stateStr.startsWith('?') ? stateStr : '?' + stateStr);
                    targetTab = stateParams.get('tab');
                }
            }

            if (targetTab) {
                switchTab(targetTab); // 精準切換到變更單
            } else {
                switchTab('leave');   // 預設切換到新假單
            }

            loadData();
        }

        async function loadData() {
            try {
                const res = await fetch(`supervisor_api.php?action=list&lineId=${currentLineId}`);
                const json = await res.json();
                
                if (json.status === 'success') {
                    renderLeaves(json.leaves || []);
                    renderMods(json.mods || []);
                    renderOvertimes(json.overtimes || []);
                    renderClockins(json.clockins || []);
                    renderHistory(json.history || []); 
                    
                    updateBadge('leave', (json.leaves || []).length);
                    updateBadge('mod', (json.mods || []).length);
                    updateBadge('overtime', (json.overtimes || []).length);
                    updateBadge('clockin', (json.clockins || []).length);
                }
            } catch (e) {
                alert("讀取資料失敗");
            }
        }

        function renderLeaves(data) {
            const div = document.getElementById('list-leave');
            if (data.length === 0) { div.innerHTML = "<div class='empty-msg'>無待審核假單</div>"; return; }
            let html = "";
            data.forEach(item => {
                html += buildCard('leave', item.id, item.user_name, item.leave_type, item.date_display, item.reason, 'tag-leave');
            });
            div.innerHTML = html;
        }

        function renderMods(data) {
            const div = document.getElementById('list-mod');
            if (data.length === 0) { div.innerHTML = "<div class='empty-msg'>無待審核變更</div>"; return; }
            let html = "";
            data.forEach(item => {
                html += buildCard('mod', item.modification_uuid, item.user_name, item.type_text, `原假單：${item.target_date}`, item.leave_type, 'tag-mod');
            });
            div.innerHTML = html;
        }

        function renderOvertimes(data) {
            const div = document.getElementById('list-overtime');
            if (data.length === 0) { div.innerHTML = "<div class='empty-msg'>無待審核加班單</div>"; return; }
            let html = "";
            data.forEach(item => {
                const dateText = `${item.start_time.substring(0, 16)} ~ ${item.end_time.substring(11, 16)} (${item.hours}h)`;
                html += buildCard('overtime', item.id, item.user_name, '加班申請', dateText, item.reason, 'tag-ot');
            });
            div.innerHTML = html;
        }

        function renderClockins(data) {
            const div = document.getElementById('list-clockin');
            if (data.length === 0) { div.innerHTML = "<div class='empty-msg'>無待審核打卡異常</div>"; return; }
            let html = "";
            data.forEach(item => {
                html += buildCard('clockin', item.id, item.user_name, '異常補登', item.clock_time, `備註：${item.reason}`, 'tag-clock');
            });
            div.innerHTML = html;
        }

        function renderHistory(data) {
            const div = document.getElementById('list-history');
            if (!data || data.length === 0) { div.innerHTML = "<div class='empty-msg'>尚無近期審核紀錄</div>"; return; }
            let html = "";
            data.forEach(item => {
                let statusClass = item.status === 'approved' ? 'tag-approved' : (item.status === 'rejected' ? 'tag-rejected' : 'tag-mod');
                html += `
                    <div class="card" style="opacity: 0.8; background: #fafafa;">
                        <div class="info">
                            <div class="row-top"><span class="name">${item.user_name}</span><span class="tag ${statusClass}">${item.status_text}</span></div>
                            <div style="font-size:0.9rem; margin-top:4px; font-weight:bold;">${item.type_display}</div>
                            <div class="date">${item.date_display}</div>
                        </div>
                    </div>
                `;
            });
            div.innerHTML = html;
        }

        function buildCard(type, id, name, tagText, dateText, descText, tagClass) {
            return `
                <div class="card">
                    <div class="checkbox-area"><input type="checkbox" class="checkbox" value="${id}" data-type="${type}" onchange="updateCount()"></div>
                    <div class="info">
                        <div class="row-top"><span class="name">${name}</span><span class="tag ${tagClass}">${tagText}</span></div>
                        <div class="date">${dateText}</div>
                        <div class="desc">${descText || '無備註'}</div>
                    </div>
                </div>
            `;
        }

        function switchTab(tabName) {
            currentTab = tabName;
            
            // 1. 移除所有 tab 和內容的 active 狀態
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.container').forEach(c => c.classList.remove('active'));
            
            // 🔥 修正：不要依賴滑鼠的 event.target，改用 querySelector 精準抓取元素
            const activeTabBtn = document.querySelector(`.tab[onclick="switchTab('${tabName}')"]`);
            if (activeTabBtn) activeTabBtn.classList.add('active');
            
            const activeContainer = document.getElementById(`view-${tabName}`);
            if (activeContainer) activeContainer.classList.add('active');
            
            // 2. 判斷是否要顯示底部的浮動按鈕 (已審核頁面不需要)
            const fab = document.getElementById('fab-bar');
            if (tabName === 'history') {
                fab.style.display = 'none';
            } else {
                fab.style.display = 'flex'; 
                updateCount(); 
            }
        }

        function updateBadge(type, count) {
            const el = document.getElementById(`badge-${type}`);
            if(el) { el.innerText = count; el.style.display = count > 0 ? 'inline-block' : 'none'; }
        }

        function updateCount() {
            const container = document.getElementById(`view-${currentTab}`);
            if (!container) return; 
            const checked = container.querySelectorAll('.checkbox:checked');
            const count = checked.length;
            document.getElementById('count').innerText = count;
            document.getElementById('btnApprove').disabled = (count === 0);
            document.getElementById('btnReject').disabled = (count === 0);
        }

        async function processBatch(actionType) {
            const container = document.getElementById(`view-${currentTab}`);
            const checked = container.querySelectorAll('.checkbox:checked');
            const items = Array.from(checked).map(cb => ({ type: cb.getAttribute('data-type'), id: cb.value }));

            const actionName = actionType === 'approve' ? '核准' : '駁回';
            if (!confirm(`確定要「${actionName}」這 ${items.length} 筆項目嗎？`)) return;

            const btnA = document.getElementById('btnApprove');
            const btnR = document.getElementById('btnReject');
            btnA.disabled = true; btnR.disabled = true;
            if (actionType === 'approve') btnA.innerText = "處理中..."; else btnR.innerText = "處理中...";

            try {
                const res = await fetch(`supervisor_api.php?action=${actionType}`, {
                    method: 'POST',
                    body: JSON.stringify({ lineId: currentLineId, items: items })
                });
                const json = await res.json();
                if (json.status === 'success') { alert(json.message); location.reload(); } 
                else { alert("錯誤：" + json.message); location.reload(); }
            } catch (e) {
                alert("送出失敗"); location.reload();
            }
        }

        init();
    </script>
</body>
</html>