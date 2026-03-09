<?php
session_start();
// 🔥 這是你的後台專屬密碼，可以自行修改
$ADMIN_PASSWORD = "churchadmin2026"; 

// 處理登出邏輯
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin_users.php");
    exit;
}

// 處理登入邏輯
$error_msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        header("Location: admin_users.php"); 
        exit;
    } else {
        $error_msg = "密碼錯誤，請重新輸入。";
    }
}

// ========================================================
// 🛡️ 保護牆：如果尚未登入，顯示登入畫面，並「攔截」下方內容載入
// ========================================================
if (empty($_SESSION['admin_logged_in'])) {
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>系統管理後台登入</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #F3F4F6; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); text-align: center; width: 100%; max-width: 320px; border: 1px solid #E5E7EB; }
        .login-box h2 { margin-top: 0; color: #111827; font-size: 1.25rem; }
        .login-box input { width: 100%; box-sizing: border-box; padding: 10px; margin: 15px 0; border: 1px solid #D1D5DB; border-radius: 4px; font-size: 0.95rem; }
        .login-box button { width: 100%; background: #06C755; color: #fff; border: none; padding: 10px; border-radius: 4px; font-weight: 600; cursor: pointer; font-size: 0.95rem; }
        .login-box button:hover { background: #05b04a; }
        .error { color: #DC2626; font-size: 0.85rem; margin-bottom: 10px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>系統管理後台</h2>
        <?php if($error_msg) echo "<div class='error'>$error_msg</div>"; ?>
        <form method="POST">
            <input type="password" name="password" placeholder="請輸入管理員密碼" required autofocus>
            <button type="submit">登入</button>
        </form>
    </div>
</body></html>
<?php 
    exit; // ⚠️ 非常重要：這行會讓程式停在這裡，沒密碼的人絕對看不到下面的機密資料！
} 
?>


<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>系統管理後台</title>
    <style>
        :root { --primary: #06C755; --bg: #F3F4F6; --text-main: #111827; --text-sub: #6B7280; --border: #E5E7EB; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text-main); padding: 20px; margin: 0; max-width: 1100px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
        h1 { margin: 0; font-size: 1.25rem; border-left: 4px solid var(--primary); padding-left: 12px; color: var(--text-main); }
        
        /* 頁籤設計 */
        .tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 8px; overflow-x: auto; }
        .tab { padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: 600; color: var(--text-sub); font-size: 0.95rem; transition: 0.2s; white-space: nowrap; }
        .tab:hover { background: #E5E7EB; }
        .tab.active { background: var(--primary); color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* 儀表板卡片設計 */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; border: 1px solid var(--border); box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .stat-title { font-size: 0.85rem; color: var(--text-sub); font-weight: 600; text-transform: uppercase; margin-bottom: 8px; }
        .stat-value { font-size: 2rem; font-weight: 700; color: var(--text-main); }
        .stat-value.highlight { color: var(--primary); }
        .stat-value.danger { color: #DC2626; }
        .stat-list { margin-top: 12px; font-size: 0.85rem; color: var(--text-sub); border-top: 1px dashed var(--border); padding-top: 8px; }

        /* 標籤設計 */
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
        .badge-pending { background: #FEF3C7; color: #92400E; }
        .badge-approved { background: #D1FAE5; color: #065F46; }
        .badge-rejected { background: #FEE2E2; color: #991B1B; }

        /* 按鈕設計 */
        .btn { padding: 6px 14px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-weight: 600; transition: 0.2s; }
        .btn-add { background: var(--primary); color: white; }
        .btn-edit { background: #F3F4F6; color: #374151; border: 1px solid #D1D5DB; }
        .btn-delete { background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA; }
        .btn-logout { background: transparent; color: var(--text-sub); font-size: 0.85rem; padding: 6px 12px; }
        .btn-cancel { background: #F3F4F6; color: #374151; border: 1px solid #D1D5DB; }

        /* 表格設計 */
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border: 1px solid var(--border); margin-bottom: 20px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
        th { background: #F9FAFB; font-weight: 600; color: var(--text-sub); font-size: 0.85rem; }
        tr:hover { background: #F9FAFB; }

        /* 主管設定區塊 */
        .assign-box { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap; border: 1px solid var(--border); margin-bottom: 20px; }
        .assign-box .group { flex: 1; min-width: 200px; }
        .assign-box label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; color: var(--text-sub); }
        .assign-box select { width: 100%; padding: 8px 12px; border: 1px solid #D1D5DB; border-radius: 4px; font-size: 0.95rem; background-color: #fff; }

        /* 彈出視窗 */
        .modal-overlay { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:100; }
        .modal { background: white; padding: 24px; border-radius: 8px; width: 90%; max-width: 400px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-size: 0.85rem; font-weight: 600; color: var(--text-sub); }
        .form-group input { width: 100%; padding: 8px 12px; border: 1px solid #D1D5DB; border-radius: 4px; box-sizing: border-box; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; }

        /* 懸浮按鈕 HTML */
        #backToTopBtn {
            display: none; position: fixed; bottom: 30px; right: 30px; z-index: 99;
            background: var(--text-main); color: white; border: none; padding: 12px 16px;
            border-radius: 4px; font-weight: 600; cursor: pointer;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: 0.2s; font-size: 0.9rem;
        }
        #backToTopBtn:hover { background: #374151; transform: translateY(-2px); box-shadow: 0 6px 8px rgba(0,0,0,0.15); }
    </style>
</head>
<body>

    <div class="header">
        <h1>系統管理後台</h1>
        <a href="?logout=1" class="btn btn-logout">安全登出</a>
    </div>

    <div class="tabs">
        <div class="tab active" onclick="switchTab('dashboard')">今日出勤看板</div>
        <div class="tab" onclick="switchTab('records')">紀錄總表</div>
        <div class="tab" onclick="switchTab('users')">員工資料維護</div>
        <div class="tab" onclick="switchTab('supervisors')">組織架構設定</div>
    </div>

    <div id="tab-dashboard" class="tab-content active">
        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="stat-title">應到總人數</div>
                <div class="stat-value" id="dashTotalEmp">-</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">今日實到 (已打卡)</div>
                <div class="stat-value highlight" id="dashClockin">-</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">待處理異常 / 簽核</div>
                <div class="stat-value danger" id="dashPending">-</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">今日請假人數</div>
                <div class="stat-value" id="dashLeaveCount">-</div>
                <div class="stat-list" id="dashLeaveList">無人請假</div>
            </div>
        </div>
    </div>

    <div id="tab-records" class="tab-content">
        <div style="background: white; padding: 16px; border-radius: 8px; border: 1px solid var(--border); margin-bottom: 16px; display: flex; gap: 12px; align-items: center; box-shadow: 0 1px 2px rgba(0,0,0,0.05); flex-wrap: wrap;">
            <label style="font-size: 0.9rem; font-weight: 600; color: var(--text-main);">區間篩選：</label>
            <input type="date" id="filterStart" style="padding: 8px 12px; border: 1px solid var(--border); border-radius: 4px; font-size: 0.9rem; outline: none;">
            <span style="color: var(--text-sub); font-size: 0.9rem;">至</span>
            <input type="date" id="filterEnd" style="padding: 8px 12px; border: 1px solid var(--border); border-radius: 4px; font-size: 0.9rem; outline: none;">
            
            <button class="btn btn-add" onclick="loadRecords()" style="margin-left: auto;">查詢紀錄</button>
            <button class="btn btn-cancel" onclick="resetFilter()">清除重置</button>
        </div>

        <div style="display: flex; gap: 10px; margin-bottom: 24px;">
            <button class="btn btn-edit" style="background: white;" onclick="document.getElementById('titleClockin').scrollIntoView({behavior: 'smooth', block: 'start'})">打卡紀錄 ↓</button>
            <button class="btn btn-edit" style="background: white;" onclick="document.getElementById('titleLeave').scrollIntoView({behavior: 'smooth', block: 'start'})">假單紀錄 ↓</button>
            <button class="btn btn-edit" style="background: white;" onclick="document.getElementById('titleOvertime').scrollIntoView({behavior: 'smooth', block: 'start'})">加班紀錄 ↓</button>
        </div>

        <h3 id="titleClockin" style="font-size: 1.05rem; color: var(--text-main); margin-bottom: 12px; font-weight: 600; scroll-margin-top: 20px;">打卡紀錄</h3>
        <table>
            <thead><tr><th>員工</th><th>類型</th><th>打卡時間</th><th>系統判定</th><th>主管審核</th><th>操作</th></tr></thead>
            <tbody id="recClockinBody"></tbody>
        </table>

        <h3 id="titleLeave" style="font-size: 1.05rem; color: var(--text-main); margin-bottom: 12px; font-weight: 600; margin-top: 40px; scroll-margin-top: 20px;">假單紀錄</h3>
        <table>
            <thead><tr><th>員工</th><th>假別</th><th>起始時間</th><th>結束時間</th><th>狀態</th><th>操作</th></tr></thead>
            <tbody id="recLeaveBody"></tbody>
        </table>

        <h3 id="titleOvertime" style="font-size: 1.05rem; color: var(--text-main); margin-bottom: 12px; font-weight: 600; margin-top: 40px; scroll-margin-top: 20px;">加班紀錄</h3>
        <table>
            <thead><tr><th>員工</th><th>起始時間</th><th>結束時間</th><th>時數</th><th>狀態</th><th>操作</th></tr></thead>
            <tbody id="recOvertimeBody"></tbody>
        </table>
    </div>

    <div id="tab-users" class="tab-content">
        <div style="display: flex; justify-content: flex-end; margin-bottom: 10px;">
            <button class="btn btn-add" onclick="openModal('add')">+ 新增員工資料</button>
        </div>
        <table>
            <thead><tr><th>員工編號</th><th>姓名</th><th>LINE 識別碼</th><th>到職日期</th><th>操作</th></tr></thead>
            <tbody id="userTableBody"></tbody>
        </table>
    </div>

    <div id="tab-supervisors" class="tab-content">
        <div class="assign-box">
            <div class="group"><label>員工姓名 (下屬)</label><select id="selEmployee"><option value="">-- 請選擇 --</option></select></div>
            <div class="group"><label>直屬主管</label><select id="selSupervisor"><option value="">-- 請選擇 --</option></select></div>
            <button class="btn btn-add" style="padding: 8px 16px;" onclick="assignSupervisor()">確認指派</button>
        </div>
        <table>
            <thead><tr><th>部門主管</th><th>所屬員工</th><th>操作</th></tr></thead>
            <tbody id="supTableBody"></tbody>
        </table>
    </div>

    <div class="modal-overlay" id="userModal">
        <div class="modal">
            <h2 id="modalTitle">新增員工資料</h2>
            <input type="hidden" id="editId">
            <div class="form-group"><label>員工姓名</label><input type="text" id="userName"></div>
            <div class="form-group"><label>LINE 識別碼</label><input type="text" id="userLineId"></div>
            <div class="form-group"><label>到職日期</label><input type="date" id="userStartDate"></div>
            <div class="modal-actions">
                <button class="btn btn-cancel" onclick="closeModal()">取消</button>
                <button class="btn btn-add" onclick="saveUser()">確認儲存</button>
            </div>
        </div>
    </div>

    <button id="backToTopBtn" onclick="window.scrollTo({top: 0, behavior: 'smooth'})">↑ 回頂部</button>

    <script>
        let allUsers = [];

        // 監聽捲動事件，控制回到頂部按鈕顯示/隱藏
        window.addEventListener('scroll', function() {
            const btn = document.getElementById("backToTopBtn");
            if (document.body.scrollTop > 300 || document.documentElement.scrollTop > 300) {
                btn.style.display = "block";
            } else {
                btn.style.display = "none";
            }
        });

        function switchTab(tabId) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            event.target.classList.add('active');
            document.getElementById('tab-' + tabId).classList.add('active');

            if (tabId === 'dashboard') loadDashboard();
            if (tabId === 'records') loadRecords();
            if (tabId === 'users') loadUsers();
            if (tabId === 'supervisors') { loadUsers().then(loadSupervisors); }
            
            window.scrollTo(0, 0);
        }

        function getStatusBadge(status) {
            const map = {
                'pending': '<span class="badge badge-pending">待審核</span>',
                'approved': '<span class="badge badge-approved">已核准</span>',
                'normal': '<span class="badge badge-approved">正常</span>',
                'success': '<span class="badge badge-approved">成功</span>',
                'fail': '<span class="badge badge-rejected">異常</span>',
                'rejected': '<span class="badge badge-rejected">已退件</span>',
                'cancelled': '<span class="badge" style="background:#E5E7EB; color:#4B5563;">已註銷</span>'
            };
            return map[status] || status;
        }

        function getActionButtons(type, id, currentStatus) {
            if (currentStatus === 'pending') {
                return `
                    <button class="btn btn-add" style="padding:4px 8px; font-size:0.75rem; margin-right:4px;" onclick="forceAudit('${type}', ${id}, 'approved')">核准</button>
                    <button class="btn btn-delete" style="padding:4px 8px; font-size:0.75rem;" onclick="forceAudit('${type}', ${id}, 'rejected')">退件</button>
                `;
            }
            return '<span style="color:#D1D5DB; font-size:0.8rem;">無</span>';
        }

        async function forceAudit(type, id, newStatus) {
            const actionName = newStatus === 'approved' ? '核准' : '退件';
            if (!confirm(`系統確認：確定要由管理員強制「${actionName}」這筆紀錄嗎？\n(系統將自動發送 LINE 通知給員工)`)) return;

            try {
                const res = await fetch('admin_api.php?action=force_audit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ type: type, id: id, status: newStatus })
                });
                const json = await res.json();
                if (json.status === 'success') {
                    alert("系統提示：" + json.message);
                    loadRecords();
                    loadDashboard();
                } else {
                    alert("系統錯誤：" + json.message);
                }
            } catch (err) {
                alert("系統提示：更新失敗");
            }
        }

        async function loadDashboard() {
            try {
                const res = await fetch('admin_api.php?action=dashboard');
                const json = await res.json();
                if (json.status === 'success') {
                    const data = json.data;
                    document.getElementById('dashTotalEmp').innerText = data.total_emp;
                    document.getElementById('dashClockin').innerText = data.clock_in_count;
                    document.getElementById('dashPending').innerText = data.total_pending;
                    document.getElementById('dashLeaveCount').innerText = data.leave_count;
                    
                    if (data.leaves_today.length > 0) {
                        const listHtml = data.leaves_today.map(l => `• ${l.name} (${l.leave_type})`).join('<br>');
                        document.getElementById('dashLeaveList').innerHTML = listHtml;
                    } else {
                        document.getElementById('dashLeaveList').innerHTML = "今日全體出勤";
                    }
                }
            } catch (err) { console.error("Dashboard error", err); }
        }

        function resetFilter() {
            document.getElementById('filterStart').value = '';
            document.getElementById('filterEnd').value = '';
            loadRecords();
        }

        async function loadRecords() {
            try {
                const start = document.getElementById('filterStart').value;
                const end = document.getElementById('filterEnd').value;
                let url = 'admin_api.php?action=all_records';
                
                if (start || end) {
                    if (!start || !end) {
                        alert("系統提示：請完整選擇起始與結束日期。");
                        return;
                    }
                    if (start > end) {
                        alert("系統提示：起始日期不能晚於結束日期。");
                        return;
                    }
                    url += `&start=${start}&end=${end}`;
                }

                const titleSuffix = (start && end) ? ` <span style="font-size:0.85rem; color:var(--text-sub); font-weight:normal;">(${start} 至 ${end})</span>` : ' <span style="font-size:0.85rem; color:var(--text-sub); font-weight:normal;">(最新 100 筆)</span>';
                document.getElementById('titleClockin').innerHTML = '打卡紀錄' + titleSuffix;
                document.getElementById('titleLeave').innerHTML = '假單紀錄' + titleSuffix;
                document.getElementById('titleOvertime').innerHTML = '加班紀錄' + titleSuffix;

                const res = await fetch(url);
                const json = await res.json();
                if (json.status === 'success') {
                    document.getElementById('recClockinBody').innerHTML = json.data.clockins.map(r => `
                        <tr><td><b style="color:var(--text-main);">${r.user_name}</b></td><td>${r.mode}</td><td style="font-family:monospace">${r.clock_time}</td>
                        <td>${getStatusBadge(r.status)}</td><td>${getStatusBadge(r.approval_status)}</td>
                        <td>${getActionButtons('clockin', r.id, r.approval_status)}</td></tr>
                    `).join('') || '<tr><td colspan="6" align="center" style="color:#6B7280; padding:20px;">此區間無打卡資料</td></tr>';

                    document.getElementById('recLeaveBody').innerHTML = json.data.leaves.map(r => `
                        <tr><td><b style="color:var(--text-main);">${r.user_name}</b></td><td>${r.leave_type}</td><td style="font-family:monospace">${r.start_at}</td><td style="font-family:monospace">${r.end_at}</td>
                        <td>${getStatusBadge(r.status)}</td>
                        <td>${getActionButtons('leave', r.id, r.status)}</td></tr>
                    `).join('') || '<tr><td colspan="6" align="center" style="color:#6B7280; padding:20px;">此區間無假單資料</td></tr>';

                    document.getElementById('recOvertimeBody').innerHTML = json.data.overtimes.map(r => `
                        <tr><td><b style="color:var(--text-main);">${r.user_name}</b></td><td style="font-family:monospace">${r.start_at}</td><td style="font-family:monospace">${r.end_at}</td><td>${r.hours}h</td>
                        <td>${getStatusBadge(r.status)}</td>
                        <td>${getActionButtons('overtime', r.id, r.status)}</td></tr>
                    `).join('') || '<tr><td colspan="6" align="center" style="color:#6B7280; padding:20px;">此區間無加班資料</td></tr>';
                }
            } catch (err) { console.error("Records error", err); }
        }

        async function loadUsers() {
            const res = await fetch('admin_api.php?action=list'); const json = await res.json();
            if (json.status === 'success') {
                allUsers = json.data;
                document.getElementById('userTableBody').innerHTML = json.data.map(u => `
                    <tr><td style="color:#6B7280;">${u.id}</td><td style="font-weight:600;">${u.name}</td>
                    <td style="color:#6B7280; font-family:monospace;">${u.user_id}</td><td>${u.start_date || '-'}</td>
                    <td><button class="btn btn-edit" onclick='openModal("edit", ${JSON.stringify(u)})'>編輯</button>
                    <button class="btn btn-delete" onclick="deleteUser(${u.id}, '${u.name}')">刪除</button></td></tr>
                `).join('') || '<tr><td colspan="5" align="center" style="color:#6B7280; padding:20px;">無資料</td></tr>';
                populateDropdowns();
            }
        }

        let currentMode = 'add';
        function openModal(mode, userData = null) {
            currentMode = mode; document.getElementById('modalTitle').innerText = mode === 'add' ? "新增員工資料" : "編輯員工資料";
            document.getElementById('editId').value = mode === 'edit' ? userData.id : ""; document.getElementById('userName').value = mode === 'edit' ? userData.name : "";
            document.getElementById('userLineId').value = mode === 'edit' ? userData.user_id : ""; document.getElementById('userStartDate').value = mode === 'edit' ? (userData.start_date || "") : "";
            document.getElementById('userModal').style.display = 'flex';
        }
        function closeModal() { document.getElementById('userModal').style.display = 'none'; }

        async function saveUser() {
            const payload = { id: document.getElementById('editId').value, name: document.getElementById('userName').value, user_id: document.getElementById('userLineId').value, start_date: document.getElementById('userStartDate').value };
            const res = await fetch(`admin_api.php?action=${currentMode === 'add' ? 'add' : 'edit'}`, { method: currentMode === 'add' ? 'POST' : 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const json = await res.json(); if (json.status === 'success') { alert("系統提示：" + json.message); closeModal(); loadUsers(); } else alert("系統錯誤：" + json.message);
        }

        async function deleteUser(id, name) {
            if (!confirm(`系統確認：確定刪除員工「${name}」？此操作無法復原。`)) return;
            const res = await fetch(`admin_api.php?action=delete&id=${id}`, { method: 'DELETE' }); const json = await res.json();
            if (json.status === 'success') { loadUsers(); } else alert("系統錯誤：" + json.message);
        }

        function populateDropdowns() {
            let ops = '<option value="">-- 請選擇 --</option>'; [...allUsers].sort((a,b)=>a.name.localeCompare(b.name)).forEach(u => ops += `<option value="${u.user_id}">${u.name}</option>`);
            document.getElementById('selEmployee').innerHTML = ops; document.getElementById('selSupervisor').innerHTML = ops;
        }

        async function loadSupervisors() {
            const res = await fetch('admin_api.php?action=supervisor_list'); const json = await res.json();
            if (json.status === 'success') {
                document.getElementById('supTableBody').innerHTML = json.data.map(rel => `
                    <tr><td style="font-weight:600;">${rel.sup_name || '查無此人'}</td><td>${rel.emp_name || '查無此人'}</td>
                    <td><button class="btn btn-delete" onclick="removeSupervisor('${rel.user_id}', '${rel.supervisor_id}')">解除設定</button></td></tr>
                `).join('') || '<tr><td colspan="3" align="center" style="color:#6B7280; padding:20px;">無資料</td></tr>';
            }
        }

        async function assignSupervisor() {
            const empId = document.getElementById('selEmployee').value; const supId = document.getElementById('selSupervisor').value;
            if (!empId || !supId) return alert("系統提示：請選擇員工與主管。");
            const res = await fetch('admin_api.php?action=assign_supervisor', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ user_id: empId, supervisor_id: supId }) });
            const json = await res.json(); if (json.status === 'success') { alert("系統提示：" + json.message); loadSupervisors(); } else alert("系統錯誤：" + json.message);
        }

        async function removeSupervisor(empId, supId) {
            if (!confirm("系統確認：確定解除此從屬關係設定嗎？")) return;
            const res = await fetch(`admin_api.php?action=remove_supervisor&user_id=${empId}&supervisor_id=${supId}`, { method: 'DELETE' });
            const json = await res.json(); if (json.status === 'success') { loadSupervisors(); }
        }

        window.onload = loadDashboard;
    </script>
</body>
</html>