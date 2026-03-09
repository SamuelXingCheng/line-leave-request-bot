<?php
require_once __DIR__ . '/config.php';
$liffId = getenv('SUPERVISOR_LIFF_ID');
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

        /* 頂部 Tabs (增加為三個) */
        .tabs { display: flex; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 10; }
        .tab { flex: 1; text-align: center; padding: 15px 0; font-weight: bold; color: #999; cursor: pointer; border-bottom: 3px solid transparent; transition: all 0.2s; font-size: 0.9rem; }
        .tab.active { color: var(--primary); border-bottom-color: var(--primary); }
        .badge { background: #ef4444; color: #fff; font-size: 0.7rem; padding: 2px 6px; border-radius: 10px; margin-left: 2px; display: none; }
        
        .container { padding: 15px; display: none; }
        .container.active { display: block; }

        /* 卡片樣式 */
        .card { background: #fff; border-radius: 10px; padding: 15px; margin-bottom: 15px; display: flex; align-items: flex-start; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .card.highlight { border: 2px solid var(--primary); background: #f0fdf4; scroll-margin-top: 80px; }
        
        .checkbox-area { padding-top: 5px; margin-right: 15px; }
        .checkbox { width: 22px; height: 22px; accent-color: var(--primary); }
        
        .info { flex: 1; }
        .row-top { display: flex; justify-content: space-between; margin-bottom: 5px; }
        .name { font-weight: bold; font-size: 1rem; color: #333; }
        .tag { font-size: 0.75rem; padding: 2px 8px; border-radius: 4px; font-weight: bold; }
        
        .tag-leave { background: #e8f5e9; color: #2e7d32; }
        .tag-mod { background: #fff3e0; color: #e65100; }
        
        /* 🔥 新增狀態標籤樣式 */
        .tag-approved { background: #dcfce7; color: #15803d; } /* 綠底深綠字 */
        .tag-rejected { background: #fee2e2; color: #b91c1c; } /* 紅底深紅字 */
        
        .date { font-size: 0.85rem; color: #666; font-family: monospace; margin-top: 4px; }
        .desc { font-size: 0.85rem; color: #888; margin-top: 4px; }

        .fab-bar { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; padding: 15px; box-shadow: 0 -2px 10px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; }
        .btn-submit { background: var(--primary); color: #fff; border: none; padding: 12px 24px; border-radius: 30px; font-weight: bold; font-size: 1rem; flex: 1; margin-left: 10px; }
        .btn-submit:disabled { background: #ccc; }
        
        .empty-msg { text-align: center; color: #aaa; margin-top: 50px; }
    </style>
</head>
<body>

    <div class="tabs">
        <div class="tab active" onclick="switchTab('leave')">
            新假單 <span id="badge-leave" class="badge">0</span>
        </div>
        <div class="tab" onclick="switchTab('mod')">
            變更/銷假 <span id="badge-mod" class="badge">0</span>
        </div>
        <div class="tab" onclick="switchTab('history')">
            已審核
        </div>
    </div>

    <div id="view-leave" class="container active">
        <div id="list-leave"></div>
    </div>

    <div id="view-mod" class="container">
        <div id="list-mod"></div>
    </div>

    <div id="view-history" class="container">
        <div id="list-history"></div>
    </div>

    <div id="fab-bar" class="fab-bar">
        <div style="font-size:0.9rem; color:#555;">已選 <span id="count" style="font-weight:bold;">0</span> 筆</div>
        <button id="btnSubmit" class="btn-submit" onclick="submitBatch()" disabled>確認核准</button>
    </div>

    <script>
        const LIFF_ID = "<?php echo $liffId; ?>";
        const urlParams = new URLSearchParams(window.location.search);
        
        const initTab = urlParams.get('tab') || 'leave';
        const highlightId = urlParams.get('highlight'); 

        let currentLineId = "";
        let currentTab = 'leave'; 

        async function init() {
            await liff.init({ liffId: LIFF_ID });
            if (!liff.isLoggedIn()) { liff.login(); return; }
            const profile = await liff.getProfile();
            currentLineId = profile.userId;
            
            switchTab(initTab);
            loadData();
        }

        async function loadData() {
            try {
                const res = await fetch(`supervisor_api.php?action=list&lineId=${currentLineId}`);
                const json = await res.json();
                
                if (json.status === 'success') {
                    renderLeaves(json.leaves);
                    renderMods(json.mods);
                    renderHistory(json.history); // 🔥 渲染歷史資料
                    
                    updateBadge('leave', json.leaves.length);
                    updateBadge('mod', json.mods.length);

                    if (highlightId) {
                        setTimeout(() => {
                            const el = document.getElementById(`card-${highlightId}`);
                            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }, 300);
                    }
                }
            } catch (e) {
                alert("讀取失敗");
            }
        }

        function renderLeaves(data) {
            const div = document.getElementById('list-leave');
            if (data.length === 0) { div.innerHTML = "<div class='empty-msg'>無待審核假單</div>"; return; }
            
            let html = "";
            data.forEach(item => {
                const isTarget = (currentTab === 'leave' && String(item.id) === String(highlightId));
                html += buildCard('leave', item.id, item.user_name, item.leave_type, item.date_display, item.reason, isTarget, 'tag-leave');
            });
            div.innerHTML = html;
        }

        function renderMods(data) {
            const div = document.getElementById('list-mod');
            if (data.length === 0) { div.innerHTML = "<div class='empty-msg'>無待審核變更</div>"; return; }

            let html = "";
            data.forEach(item => {
                const isTarget = (currentTab === 'mod' && item.modification_uuid === highlightId);
                html += buildCard('mod', item.modification_uuid, item.user_name, item.type_text, `目標：${item.target_date}`, item.leave_type, isTarget, 'tag-mod');
            });
            div.innerHTML = html;
        }

        // 🔥 渲染歷史紀錄 (沒有 Checkbox)
        function renderHistory(data) {
            const div = document.getElementById('list-history');
            if (!data || data.length === 0) { div.innerHTML = "<div class='empty-msg'>尚無近期審核紀錄</div>"; return; }

            let html = "";
            data.forEach(item => {
                // 決定標籤顏色
                let statusClass = 'tag-leave';
                if (item.status === 'approved') statusClass = 'tag-approved';
                else if (item.status === 'rejected') statusClass = 'tag-rejected';
                else if (item.status === 'cancelled') statusClass = 'tag-mod'; // 灰色系

                html += `
                    <div class="card" style="opacity: 0.8; background: #fafafa;">
                        <div class="info">
                            <div class="row-top">
                                <span class="name">${item.user_name}</span>
                                <span class="tag ${statusClass}">${item.status_text}</span>
                            </div>
                            <div style="font-size:0.9rem; margin-top:4px; font-weight:bold;">${item.type_display}</div>
                            <div class="date">${item.date_display}</div>
                        </div>
                    </div>
                `;
            });
            div.innerHTML = html;
        }

        // 通用卡片 (帶 Checkbox)
        function buildCard(type, id, name, tagText, dateText, descText, isHighlight, tagClass) {
            const checked = isHighlight ? "checked" : "";
            const hlClass = isHighlight ? "highlight" : "";
            
            return `
                <div id="card-${id}" class="card ${hlClass}">
                    <div class="checkbox-area">
                        <input type="checkbox" class="checkbox" value="${id}" data-type="${type}" ${checked} onchange="updateCount()">
                    </div>
                    <div class="info">
                        <div class="row-top">
                            <span class="name">${name}</span>
                            <span class="tag ${tagClass}">${tagText}</span>
                        </div>
                        <div class="date">${dateText}</div>
                        <div class="desc">${descText || ''}</div>
                    </div>
                </div>
            `;
        }

        function switchTab(tabName) {
            currentTab = tabName;
            
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.container').forEach(c => c.classList.remove('active'));
            
            const tabs = document.querySelectorAll('.tab');
            if(tabName === 'leave') tabs[0].classList.add('active');
            else if(tabName === 'mod') tabs[1].classList.add('active');
            else tabs[2].classList.add('active'); // history

            document.getElementById(`view-${tabName}`).classList.add('active');
            
            // 🔥 如果是 History 分頁，隱藏底部按鈕，因為不需要操作
            const fab = document.getElementById('fab-bar');
            if (tabName === 'history') {
                fab.style.display = 'none';
            } else {
                fab.style.display = 'flex';
                updateCount(); // 切換回操作分頁時，恢復計數顯示
            }
        }

        function updateBadge(type, count) {
            const el = document.getElementById(`badge-${type}`);
            if(el) {
                el.innerText = count;
                el.style.display = count > 0 ? 'inline-block' : 'none';
            }
        }

        function updateCount() {
            const container = document.getElementById(`view-${currentTab}`);
            // 防止在 history 分頁報錯
            if (!container) return; 
            
            const checked = container.querySelectorAll('.checkbox:checked');
            
            document.getElementById('count').innerText = checked.length;
            document.getElementById('btnSubmit').disabled = (checked.length === 0);
        }

        async function submitBatch() {
            const container = document.getElementById(`view-${currentTab}`);
            const checked = container.querySelectorAll('.checkbox:checked');
            
            const items = Array.from(checked).map(cb => ({
                type: cb.getAttribute('data-type'),
                id: cb.value
            }));

            if (!confirm(`確定核准這 ${items.length} 筆項目嗎？`)) return;

            const btn = document.getElementById('btnSubmit');
            btn.disabled = true;
            btn.innerText = "處理中...";

            try {
                const res = await fetch('supervisor_api.php?action=approve', {
                    method: 'POST',
                    body: JSON.stringify({ lineId: currentLineId, items: items })
                });
                const json = await res.json();
                
                if (json.status === 'success') {
                    alert(json.message);
                    location.reload();
                } else {
                    alert("錯誤：" + json.message);
                    btn.disabled = false;
                }
            } catch (e) {
                alert("送出失敗");
                btn.disabled = false;
            }
        }

        init();
    </script>
</body>
</html>