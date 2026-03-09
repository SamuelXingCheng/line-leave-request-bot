<?php
session_start();
// 🔥 這是你的後台專屬密碼
$ADMIN_PASSWORD = "churchadmin2026"; 

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin_users.php");
    exit;
}

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
<?php exit; } ?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>員工與組織管理</title>
    <style>
        :root { --primary: #06C755; --bg: #F3F4F6; --text-main: #111827; --text-sub: #6B7280; --border: #E5E7EB; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text-main); padding: 20px; margin: 0; max-width: 1000px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
        h1 { margin: 0; font-size: 1.25rem; border-left: 4px solid var(--primary); padding-left: 12px; color: var(--text-main); }
        
        /* 頁籤設計 */
        .tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 8px; }
        .tab { padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: 600; color: var(--text-sub); font-size: 0.95rem; transition: 0.2s; }
        .tab:hover { background: #E5E7EB; }
        .tab.active { background: var(--primary); color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* 按鈕設計 */
        .btn { padding: 6px 14px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-weight: 600; text-decoration: none; display: inline-block; transition: 0.2s; }
        .btn-add { background: var(--primary); color: white; }
        .btn-edit { background: #F3F4F6; color: #374151; border: 1px solid #D1D5DB; }
        .btn-edit:hover { background: #E5E7EB; }
        .btn-delete { background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA; }
        .btn-delete:hover { background: #FEE2E2; }
        .btn-logout { background: transparent; color: var(--text-sub); font-size: 0.85rem; padding: 6px 12px; }
        .btn-logout:hover { color: var(--text-main); }
        .btn-cancel { background: #F3F4F6; color: #374151; border: 1px solid #D1D5DB; }

        /* 表格設計 */
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.05); margin-top: 16px; border: 1px solid var(--border); }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border); font-size: 0.95rem; }
        th { background: #F9FAFB; font-weight: 600; color: var(--text-sub); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background: #F9FAFB; }

        /* 主管設定區塊 */
        .assign-box { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap; border: 1px solid var(--border); }
        .assign-box .group { flex: 1; min-width: 200px; }
        .assign-box label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; color: var(--text-sub); }
        .assign-box select { width: 100%; padding: 8px 12px; border: 1px solid #D1D5DB; border-radius: 4px; font-size: 0.95rem; background-color: #fff; }

        /* 彈出視窗 */
        .modal-overlay { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:100; }
        .modal { background: white; padding: 24px; border-radius: 8px; width: 90%; max-width: 400px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        .modal h2 { margin-top: 0; margin-bottom: 20px; font-size: 1.15rem; color: var(--text-main); }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-size: 0.85rem; font-weight: 600; color: var(--text-sub); }
        .form-group input { width: 100%; padding: 8px 12px; border: 1px solid #D1D5DB; border-radius: 4px; box-sizing: border-box; font-size: 0.95rem; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; }
    </style>
</head>
<body>

    <div class="header">
        <h1>系統管理後台</h1>
        <a href="?logout=1" class="btn btn-logout">登出</a>
    </div>

    <div class="tabs">
        <div class="tab active" onclick="switchTab('users')">員工資料維護</div>
        <div class="tab" onclick="switchTab('supervisors')">組織架構設定</div>
    </div>

    <div id="tab-users" class="tab-content active">
        <div style="display: flex; justify-content: flex-end; margin-bottom: 10px;">
            <button class="btn btn-add" onclick="openModal('add')">+ 新增員工資料</button>
        </div>
        <table>
            <thead><tr><th>員工編號 (ID)</th><th>姓名</th><th>LINE 識別碼</th><th>到職日期</th><th>操作</th></tr></thead>
            <tbody id="userTableBody"><tr><td colspan="5" align="center" style="color: #6B7280;">資料載入中...</td></tr></tbody>
        </table>
    </div>

    <div id="tab-supervisors" class="tab-content">
        
        <div class="assign-box">
            <div class="group">
                <label>員工姓名 (下屬)</label>
                <select id="selEmployee"><option value="">-- 請選擇 --</option></select>
            </div>
            <div class="group">
                <label>直屬主管</label>
                <select id="selSupervisor"><option value="">-- 請選擇 --</option></select>
            </div>
            <button class="btn btn-add" style="padding: 8px 16px;" onclick="assignSupervisor()">確認指派</button>
        </div>

        <table>
            <thead><tr><th>部門主管</th><th>所屬員工</th><th>操作</th></tr></thead>
            <tbody id="supTableBody"><tr><td colspan="3" align="center" style="color: #6B7280;">資料載入中...</td></tr></tbody>
        </table>
    </div>

    <div class="modal-overlay" id="userModal">
        <div class="modal">
            <h2 id="modalTitle">新增員工資料</h2>
            <input type="hidden" id="editId">
            <div class="form-group"><label>員工姓名</label><input type="text" id="userName"></div>
            <div class="form-group"><label>LINE 識別碼 (user_id)</label><input type="text" id="userLineId"></div>
            <div class="form-group"><label>到職日期</label><input type="date" id="userStartDate"></div>
            <div class="modal-actions">
                <button class="btn btn-cancel" onclick="closeModal()">取消</button>
                <button class="btn btn-add" onclick="saveUser()">確認儲存</button>
            </div>
        </div>
    </div>

    <script>
        let allUsers = [];

        function switchTab(tabId) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            event.target.classList.add('active');
            document.getElementById('tab-' + tabId).classList.add('active');

            if (tabId === 'supervisors') loadSupervisors();
        }

        async function loadUsers() {
            try {
                const res = await fetch('admin_api.php?action=list');
                const json = await res.json();
                if (json.status === 'success') {
                    allUsers = json.data;
                    const tbody = document.getElementById('userTableBody');
                    tbody.innerHTML = '';
                    if(json.data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" align="center" style="color: #6B7280;">目前無員工資料</td></tr>';
                        return;
                    }
                    json.data.forEach(u => {
                        tbody.innerHTML += `
                            <tr>
                                <td style="color: #6B7280;">${u.id}</td>
                                <td style="font-weight: 600;">${u.name}</td>
                                <td style="color: #6B7280; font-family: monospace;">${u.user_id}</td>
                                <td>${u.start_date || '-'}</td>
                                <td>
                                    <button class="btn btn-edit" onclick='openModal("edit", ${JSON.stringify(u)})'>編輯</button>
                                    <button class="btn btn-delete" onclick="deleteUser(${u.id}, '${u.name}')">刪除</button>
                                </td>
                            </tr>`;
                    });
                    populateDropdowns();
                }
            } catch (err) { alert("系統提示：員工資料載入失敗。"); }
        }

        let currentMode = 'add';
        function openModal(mode, userData = null) {
            currentMode = mode;
            document.getElementById('modalTitle').innerText = mode === 'add' ? "新增員工資料" : "編輯員工資料";
            document.getElementById('editId').value = mode === 'edit' ? userData.id : "";
            document.getElementById('userName').value = mode === 'edit' ? userData.name : "";
            document.getElementById('userLineId').value = mode === 'edit' ? userData.user_id : "";
            document.getElementById('userStartDate').value = mode === 'edit' ? (userData.start_date || "") : "";
            document.getElementById('userModal').style.display = 'flex';
        }
        function closeModal() { document.getElementById('userModal').style.display = 'none'; }

        async function saveUser() {
            const payload = {
                id: document.getElementById('editId').value,
                name: document.getElementById('userName').value,
                user_id: document.getElementById('userLineId').value,
                start_date: document.getElementById('userStartDate').value
            };
            try {
                const res = await fetch(`admin_api.php?action=${currentMode === 'add' ? 'add' : 'edit'}`, {
                    method: currentMode === 'add' ? 'POST' : 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const json = await res.json();
                if (json.status === 'success') {
                    alert("系統提示：" + json.message); closeModal(); loadUsers();
                } else alert("系統錯誤：" + json.message);
            } catch (err) { alert("系統提示：資料儲存失敗。"); }
        }

        async function deleteUser(id, name) {
            if (!confirm(`系統確認：確定刪除員工「${name}」的資料？此操作無法復原。`)) return;
            try {
                const res = await fetch(`admin_api.php?action=delete&id=${id}`, { method: 'DELETE' });
                const json = await res.json();
                if (json.status === 'success') { alert("系統提示：資料已刪除。"); loadUsers(); } 
                else alert("系統錯誤：" + json.message);
            } catch (err) { alert("系統提示：資料刪除失敗。"); }
        }

        function populateDropdowns() {
            let options = '<option value="">-- 請選擇 --</option>';
            const sortedUsers = [...allUsers].sort((a, b) => a.name.localeCompare(b.name));
            sortedUsers.forEach(u => {
                options += `<option value="${u.user_id}">${u.name} (${u.user_id.substring(0,8)}...)</option>`;
            });
            document.getElementById('selEmployee').innerHTML = options;
            document.getElementById('selSupervisor').innerHTML = options;
        }

        async function loadSupervisors() {
            try {
                const res = await fetch('admin_api.php?action=supervisor_list');
                const json = await res.json();
                if (json.status === 'success') {
                    const tbody = document.getElementById('supTableBody');
                    if(json.data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="3" align="center" style="color: #6B7280;">目前無組織架構設定紀錄</td></tr>';
                        return;
                    }
                    tbody.innerHTML = '';
                    json.data.forEach(rel => {
                        const supName = rel.sup_name || '<span style="color:#DC2626">資料異常 (查無此人)</span>';
                        const empName = rel.emp_name || '<span style="color:#DC2626">資料異常 (查無此人)</span>';
                        tbody.innerHTML += `
                            <tr>
                                <td style="font-weight: 600;">${supName}</td>
                                <td>${empName}</td>
                                <td>
                                    <button class="btn btn-delete" onclick="removeSupervisor('${rel.user_id}', '${rel.supervisor_id}')">解除設定</button>
                                </td>
                            </tr>`;
                    });
                }
            } catch (err) { alert("系統提示：組織資料載入失敗。"); }
        }

        async function assignSupervisor() {
            const empId = document.getElementById('selEmployee').value;
            const supId = document.getElementById('selSupervisor').value;
            if (!empId || !supId) return alert("系統提示：請同時選擇員工與主管。");

            try {
                const res = await fetch('admin_api.php?action=assign_supervisor', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: empId, supervisor_id: supId })
                });
                const json = await res.json();
                if (json.status === 'success') {
                    alert("系統提示：" + json.message);
                    document.getElementById('selEmployee').value = ""; 
                    loadSupervisors(); 
                } else alert("系統錯誤：" + json.message);
            } catch (err) { alert("系統提示：指派作業失敗。"); }
        }

        async function removeSupervisor(empId, supId) {
            if (!confirm("系統確認：確定解除此從屬關係設定嗎？")) return;
            try {
                const res = await fetch(`admin_api.php?action=remove_supervisor&user_id=${empId}&supervisor_id=${supId}`, { method: 'DELETE' });
                const json = await res.json();
                if (json.status === 'success') { alert("系統提示：" + json.message); loadSupervisors(); } 
                else alert("系統錯誤：" + json.message);
            } catch (err) { alert("系統提示：解除作業失敗。"); }
        }

        window.onload = loadUsers;
    </script>
</body>
</html>