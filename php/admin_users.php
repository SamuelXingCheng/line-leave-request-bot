<?php
session_start();
// 系統管理員密碼
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
        $error_msg = "密碼驗證失敗，請重新輸入。";
    }
}

// ========================================================
// 🛡️ 保護牆：如果尚未登入，顯示純淨版商務登入畫面
// ========================================================
if (empty($_SESSION['admin_logged_in'])) {
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR 管理中心 - 登入</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">
    <div class="card shadow-sm border-0" style="width: 100%; max-width: 380px;">
        <div class="card-body p-5">
            <h4 class="text-center mb-4 fw-bold text-dark">HR 管理中心</h4>
            <?php if($error_msg): ?>
                <div class="alert alert-danger py-2 text-center" role="alert" style="font-size: 0.9rem;">
                    <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>
            <form method="POST">
                <div class="mb-4">
                    <label class="form-label text-muted small fw-bold">存取密碼</label>
                    <input type="password" name="password" class="form-control" placeholder="請輸入管理員密碼" required autofocus>
                </div>
                <button type="submit" class="btn btn-dark w-100 fw-bold">安全登入</button>
            </form>
        </div>
    </div>
</body>
</html>
<?php 
    exit; 
} 
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR 管理中心</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #F8F9FA; font-family: -apple-system, "Segoe UI", Roboto, sans-serif; padding-bottom: 50px; }
        .navbar-brand { font-weight: 700; letter-spacing: 0.5px; }
        .kpi-card { border-left: 4px solid #0d6efd; transition: transform 0.2s; }
        .kpi-card.success { border-left-color: #198754; }
        .kpi-card.warning { border-left-color: #ffc107; }
        .table > :not(caption) > * > * { padding: 1rem 0.75rem; vertical-align: middle; }
        .nav-tabs .nav-link { font-weight: 600; color: #6c757d; border: none; padding: 1rem 1.5rem; }
        .nav-tabs .nav-link.active { color: #0d6efd; border-bottom: 3px solid #0d6efd; background: transparent; }
        .filter-box { background: #fff; padding: 1.5rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; border: 1px solid #e9ecef; }
        .table-container { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border: 1px solid #e9ecef; overflow: hidden; }
        #backToTopBtn { display: none; position: fixed; bottom: 30px; right: 30px; z-index: 99; opacity: 0.8; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 shadow-sm">
        <div class="container-fluid px-4">
            <span class="navbar-brand">企業 HR 管理中心</span>
            <div class="d-flex">
                <a href="?logout=1" class="btn btn-outline-light btn-sm fw-bold">安全登出</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <ul class="nav nav-tabs mb-4" id="hrTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#dashboard" type="button" onclick="loadDashboard()">今日出勤看板</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#records" type="button" onclick="loadRecords()">紀錄總表</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#balances" type="button" onclick="loadBalances()">休假時數管理</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#users" type="button" onclick="loadUsers()">員工資料維護</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#supervisors" type="button" onclick="loadUsers().then(loadSupervisors)">組織架構設定</button>
            </li>
        </ul>

        <div class="tab-content">
            
            <div class="tab-pane fade show active" id="dashboard">
                <div class="row g-4 mb-4">
                    <div class="col-md-3">
                        <div class="card kpi-card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <div class="text-muted small fw-bold text-uppercase">應到總人數</div>
                                <h3 class="mt-2 mb-0 fw-bold" id="dashTotalEmp">-</h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card kpi-card success h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <div class="text-muted small fw-bold text-uppercase">今日實到 (已打卡)</div>
                                <h3 class="mt-2 mb-0 fw-bold text-success" id="dashClockin">-</h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card kpi-card warning h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <div class="text-muted small fw-bold text-uppercase">今日請假人數</div>
                                <h3 class="mt-2 mb-2 fw-bold text-warning" id="dashLeaveCount">-</h3>
                                <div class="small text-muted border-top pt-2" id="dashLeaveList" style="min-height: 20px;">無人請假</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card kpi-card border-0 shadow-sm" style="border-left-color: #dc3545;">
                            <div class="card-body">
                                <div class="text-muted small fw-bold text-uppercase">待處理異常 / 待簽核</div>
                                <h3 class="mt-2 mb-0 fw-bold text-danger" id="dashPending">-</h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="records">
                <div class="filter-box">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">開始日期</label>
                            <input type="date" id="filterStart" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">結束日期</label>
                            <input type="date" id="filterEnd" class="form-control">
                        </div>
                        <div class="col-md-6 d-flex gap-2">
                            <button class="btn btn-primary px-4 fw-bold" onclick="loadRecords()">查詢紀錄</button>
                            <button class="btn btn-light border fw-bold" onclick="resetFilter()">重置</button>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mb-4">
                    <a href="#titleClockin" class="btn btn-sm btn-outline-secondary">打卡紀錄 ↓</a>
                    <a href="#titleLeave" class="btn btn-sm btn-outline-secondary">假單紀錄 ↓</a>
                    <a href="#titleOvertime" class="btn btn-sm btn-outline-secondary">加班紀錄 ↓</a>
                </div>

                <div class="table-container mb-5">
                    <h5 id="titleClockin" class="px-4 pt-4 mb-3 fw-bold text-dark" style="scroll-margin-top: 20px;">打卡紀錄</h5>
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>員工</th><th>類型</th><th>打卡時間</th><th>系統判定</th><th>主管審核</th><th>操作</th></tr></thead>
                        <tbody id="recClockinBody"></tbody>
                    </table>
                </div>

                <div class="table-container mb-5">
                    <h5 id="titleLeave" class="px-4 pt-4 mb-3 fw-bold text-dark" style="scroll-margin-top: 20px;">假單紀錄</h5>
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>員工</th><th>假別</th><th>起始時間</th><th>結束時間</th><th>狀態</th><th>操作</th></tr></thead>
                        <tbody id="recLeaveBody"></tbody>
                    </table>
                </div>

                <div class="table-container mb-5">
                    <h5 id="titleOvertime" class="px-4 pt-4 mb-3 fw-bold text-dark" style="scroll-margin-top: 20px;">加班紀錄</h5>
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>員工</th><th>起始時間</th><th>結束時間</th><th>時數</th><th>狀態</th><th>操作</th></tr></thead>
                        <tbody id="recOvertimeBody"></tbody>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade" id="balances">
                <div class="alert alert-light border text-muted small mb-4">
                    <strong>操作提示：</strong>系統會根據簽核狀態自動計算時數。若遇特殊情況（如結算誤差），可使用右側按鈕進行人工校正。
                </div>
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>員工姓名</th><th>剩餘特休 (h)</th><th>剩餘補休 (h)</th><th>本年事假 (h)</th><th>本年病假 (h)</th><th>操作</th></tr></thead>
                        <tbody id="balanceTableBody"></tbody>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade" id="users">
                <div class="d-flex justify-content-end mb-3">
                    <button class="btn btn-primary fw-bold" onclick="openUserModal('add')">新增員工資料</button>
                </div>
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>狀態/編號</th><th>姓名</th><th>LINE 識別碼</th><th>到職日期</th><th>操作</th></tr></thead>
                        <tbody id="userTableBody"></tbody>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade" id="supervisors">
                <div class="filter-box bg-light">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">員工姓名 (下屬)</label>
                            <select id="selEmployee" class="form-select"><option value="">-- 請選擇 --</option></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">直屬主管</label>
                            <select id="selSupervisor" class="form-select"><option value="">-- 請選擇 --</option></select>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-primary fw-bold" onclick="assignSupervisor()">確認指派</button>
                        </div>
                    </div>
                </div>
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>部門主管</th><th>所屬員工</th><th>操作</th></tr></thead>
                        <tbody id="supTableBody"></tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalTitle">新增員工資料</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="editId">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">員工姓名</label>
                        <input type="text" id="userName" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">LINE 識別碼</label>
                        <input type="text" id="userLineId" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">到職日期</label>
                        <input type="date" id="userStartDate" class="form-control">
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-light border fw-bold" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary fw-bold" onclick="saveUser()">確認儲存</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="hoursModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">手動校正休假時數</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="hoursEditId">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">員工姓名</label>
                        <input type="text" id="hoursUserName" class="form-control bg-light" disabled>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">剩餘特休 (h)</label>
                            <input type="number" step="0.5" id="valAnnual" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">剩餘補休 (h)</label>
                            <input type="number" step="0.5" id="valComp" class="form-control">
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">事假已用 (h)</label>
                            <input type="number" step="0.5" id="valPersonal" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">病假已用 (h)</label>
                            <input type="number" step="0.5" id="valSick" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-light border fw-bold" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary fw-bold" onclick="saveHours()">確認更新</button>
                </div>
            </div>
        </div>
    </div>

    <button id="backToTopBtn" class="btn btn-dark fw-bold rounded-pill" onclick="window.scrollTo({top: 0, behavior: 'smooth'})">↑ 回頂部</button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let allUsers = [];
        let bsUserModal, bsHoursModal;
        let currentMode = 'add';

        document.addEventListener("DOMContentLoaded", function() {
            bsUserModal = new bootstrap.Modal(document.getElementById('userModal'));
            bsHoursModal = new bootstrap.Modal(document.getElementById('hoursModal'));
            loadDashboard();
        });

        window.addEventListener('scroll', function() {
            const btn = document.getElementById("backToTopBtn");
            if (document.body.scrollTop > 300 || document.documentElement.scrollTop > 300) btn.style.display = "block";
            else btn.style.display = "none";
        });

        function getStatusBadge(status) {
            const map = {
                'pending': '<span class="badge bg-warning text-dark px-2 py-1">待審核</span>',
                'approved': '<span class="badge bg-success px-2 py-1">已核准</span>',
                'normal': '<span class="badge bg-success px-2 py-1">正常</span>',
                'success': '<span class="badge bg-success px-2 py-1">成功</span>',
                'fail': '<span class="badge bg-danger px-2 py-1">異常</span>',
                'rejected': '<span class="badge bg-danger px-2 py-1">已退件</span>',
                'cancelled': '<span class="badge bg-secondary px-2 py-1">已註銷</span>'
            };
            return map[status] || `<span class="badge bg-light text-dark border">${status}</span>`;
        }

        function getActionButtons(type, id, currentStatus) {
            let buttons = '';
            if (currentStatus === 'pending') {
                buttons += `<button class="btn btn-sm btn-outline-success fw-bold me-1" onclick="forceAudit('${type}', ${id}, 'approved')">核准</button>`;
                buttons += `<button class="btn btn-sm btn-outline-danger fw-bold" onclick="forceAudit('${type}', ${id}, 'rejected')">退件</button>`;
            } else if (currentStatus === 'approved') {
                buttons += `<button class="btn btn-sm btn-outline-danger fw-bold" onclick="forceAudit('${type}', ${id}, 'rejected')">強制退件</button>`;
            } else {
                buttons = '<span class="text-muted small">無</span>';
            }
            return buttons;
        }

        async function forceAudit(type, id, newStatus) {
            const actionName = newStatus === 'approved' ? '核准' : '退件';
            if (!confirm(`系統確認：確定要執行「${actionName}」操作嗎？\n(系統將自動回收/發放時數，並通知員工)`)) return;
            try {
                const res = await fetch('admin_api.php?action=force_audit', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ type: type, id: id, status: newStatus }) });
                const json = await res.json();
                if (json.status === 'success') { alert("系統提示：" + json.message); loadRecords(); loadDashboard(); } 
                else alert("操作失敗：" + json.message);
            } catch (err) { alert("系統錯誤，無法連線。"); }
        }

        async function loadDashboard() {
            try {
                const res = await fetch('admin_api.php?action=dashboard'); const json = await res.json();
                if (json.status === 'success') {
                    const data = json.data;
                    document.getElementById('dashTotalEmp').innerText = data.total_emp + " 人"; 
                    document.getElementById('dashClockin').innerText = data.clock_in_count + " 人";
                    document.getElementById('dashPending').innerText = data.total_pending + " 筆"; 
                    document.getElementById('dashLeaveCount').innerText = data.leave_count + " 人";
                    document.getElementById('dashLeaveList').innerHTML = data.leaves_today.length > 0 ? data.leaves_today.map(l => `- ${l.name} (${l.leave_type})`).join('<br>') : "全體出勤正常";
                }
            } catch (err) { console.error(err); }
        }

        function resetFilter() { document.getElementById('filterStart').value = ''; document.getElementById('filterEnd').value = ''; loadRecords(); }

        async function loadRecords() {
            try {
                const start = document.getElementById('filterStart').value; const end = document.getElementById('filterEnd').value;
                let url = 'admin_api.php?action=all_records';
                if (start || end) {
                    if (!start || !end) return alert("系統提示：請完整選擇起始與結束日期。");
                    if (start > end) return alert("系統提示：起始日期不能晚於結束日期。");
                    url += `&start=${start}&end=${end}`;
                }
                const res = await fetch(url); const json = await res.json();
                if (json.status === 'success') {
                    const emptyRow = '<tr><td colspan="6" align="center" class="text-muted py-4">查無紀錄</td></tr>';
                    document.getElementById('recClockinBody').innerHTML = json.data.clockins.map(r => `<tr><td><span class="fw-bold text-dark">${r.user_name}</span></td><td>${r.mode}</td><td class="font-monospace text-muted">${r.clock_time}</td><td>${getStatusBadge(r.status)}</td><td>${getStatusBadge(r.approval_status)}</td><td>${getActionButtons('clockin', r.id, r.approval_status)}</td></tr>`).join('') || emptyRow;
                    document.getElementById('recLeaveBody').innerHTML = json.data.leaves.map(r => `<tr><td><span class="fw-bold text-dark">${r.user_name}</span></td><td>${r.leave_type}</td><td class="font-monospace text-muted">${r.start_at}</td><td class="font-monospace text-muted">${r.end_at}</td><td>${getStatusBadge(r.status)}</td><td>${getActionButtons('leave', r.id, r.status)}</td></tr>`).join('') || emptyRow;
                    document.getElementById('recOvertimeBody').innerHTML = json.data.overtimes.map(r => `<tr><td><span class="fw-bold text-dark">${r.user_name}</span></td><td class="font-monospace text-muted">${r.start_at}</td><td class="font-monospace text-muted">${r.end_at}</td><td class="fw-bold">${r.hours}h</td><td>${getStatusBadge(r.status)}</td><td>${getActionButtons('overtime', r.id, r.status)}</td></tr>`).join('') || emptyRow;
                }
            } catch (err) { console.error(err); }
        }

        async function loadUsers() {
            const res = await fetch('admin_api.php?action=list'); const json = await res.json();
            if (json.status === 'success') {
                allUsers = json.data;
                document.getElementById('userTableBody').innerHTML = json.data.map(u => {
                    const isArchived = u.is_archived == 1;
                    const statusHtml = isArchived ? '<span class="badge bg-secondary">已離職</span>' : '<span class="badge bg-success">在職</span>';
                    const opacityClass = isArchived ? 'opacity-50' : '';
                    const resignText = isArchived && u.resign_date ? `<br><small class="text-danger">離職日: ${u.resign_date}</small>` : '';

                    let buttons = '';
                    if (isArchived) {
                        buttons = `<button class="btn btn-sm btn-outline-danger fw-bold" onclick="deleteUser(${u.id}, '${u.name}')">刪除</button>`;
                    } else {
                        buttons = `
                            <button class="btn btn-sm btn-outline-primary fw-bold" onclick='openUserModal("edit", ${JSON.stringify(u)})'>編輯</button>
                            <button class="btn btn-sm btn-outline-secondary fw-bold mx-1" onclick="archiveUser(${u.id}, '${u.name}')">封存(離職)</button>
                            <button class="btn btn-sm btn-outline-danger fw-bold" onclick="deleteUser(${u.id}, '${u.name}')">刪除</button>
                        `;
                    }

                    return `<tr class="${opacityClass}">
                        <td>${statusHtml} <span class="text-muted small ms-1">#${u.id}</span></td>
                        <td><span class="fw-bold text-dark">${u.name}</span> ${resignText}</td>
                        <td class="font-monospace text-muted">${u.user_id}</td>
                        <td>${u.start_date || '-'}</td>
                        <td>${buttons}</td>
                    </tr>`;
                }).join('') || '<tr><td colspan="5" align="center" class="text-muted py-4">無資料</td></tr>';
                populateDropdowns();
            }
        }

        async function archiveUser(id, name) {
            const now = new Date();
            const defaultDate = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
            const resignDate = prompt(`【員工離職封存作業】\n請輸入「${name}」的最後在職日 (格式：YYYY-MM-DD)：`, defaultDate);
            
            if (!resignDate) return; 
            if (!confirm(`系統確認：確定將「${name}」變更為離職封存狀態？\n\n系統將會自動解除該員工的主管與下屬權限。`)) return;

            try {
                const res = await fetch(`admin_api.php?action=archive`, {
                    method: 'PUT', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id, resign_date: resignDate })
                });
                const json = await res.json();
                if (json.status === 'success') { alert("系統提示：" + json.message); loadUsers(); loadDashboard(); } 
                else { alert("操作失敗：" + json.message); }
            } catch (e) { alert("連線失敗，請重試。"); }
        }

        async function loadBalances() {
            const res = await fetch('admin_api.php?action=list'); const json = await res.json();
            if (json.status === 'success') {
                document.getElementById('balanceTableBody').innerHTML = json.data.map(u => `<tr><td><span class="fw-bold text-dark">${u.name}</span></td><td class="font-monospace text-primary fw-bold">${u.annual_leave_hours || 0}</td><td class="font-monospace" style="color: #6610f2; font-weight: bold;">${u.comp_leave_hours || 0}</td><td class="font-monospace text-muted">${u.used_personal_hours || 0}</td><td class="font-monospace text-muted">${u.used_sick_hours || 0}</td><td><button class="btn btn-sm btn-outline-dark fw-bold" onclick='openHoursForm(${JSON.stringify(u)})'>手動校正</button></td></tr>`).join('') || '<tr><td colspan="6" align="center" class="text-muted py-4">無資料</td></tr>';
            }
        }

        function openHoursForm(userData) {
            document.getElementById('hoursEditId').value = userData.id; 
            document.getElementById('hoursUserName').value = userData.name;
            document.getElementById('valAnnual').value = userData.annual_leave_hours || 0; 
            document.getElementById('valComp').value = userData.comp_leave_hours || 0;
            document.getElementById('valPersonal').value = userData.used_personal_hours || 0; 
            document.getElementById('valSick').value = userData.used_sick_hours || 0;
            bsHoursModal.show();
        }

        async function saveHours() {
            const payload = { id: document.getElementById('hoursEditId').value, annual: document.getElementById('valAnnual').value, comp: document.getElementById('valComp').value, personal: document.getElementById('valPersonal').value, sick: document.getElementById('valSick').value };
            const res = await fetch('admin_api.php?action=update_hours', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const json = await res.json(); 
            if (json.status === 'success') { alert("更新成功"); bsHoursModal.hide(); loadBalances(); } else alert("錯誤：" + json.message);
        }

        function openUserModal(mode, userData = null) { 
            currentMode = mode; 
            document.getElementById('modalTitle').innerText = mode === 'add' ? "新增員工資料" : "編輯員工資料"; 
            document.getElementById('editId').value = mode === 'edit' ? userData.id : ""; 
            document.getElementById('userName').value = mode === 'edit' ? userData.name : ""; 
            document.getElementById('userLineId').value = mode === 'edit' ? userData.user_id : ""; 
            document.getElementById('userStartDate').value = mode === 'edit' ? (userData.start_date || "") : ""; 
            bsUserModal.show(); 
        }

        async function saveUser() { 
            const payload = { id: document.getElementById('editId').value, name: document.getElementById('userName').value, user_id: document.getElementById('userLineId').value, start_date: document.getElementById('userStartDate').value }; 
            const res = await fetch(`admin_api.php?action=${currentMode === 'add' ? 'add' : 'edit'}`, { method: currentMode === 'add' ? 'POST' : 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }); 
            const json = await res.json(); 
            if (json.status === 'success') { alert("儲存成功"); bsUserModal.hide(); loadUsers(); } else alert("錯誤：" + json.message); 
        }

        async function deleteUser(id, name) { 
            if (!confirm(`系統確認：確定徹底刪除員工「${name}」？此操作無法復原。`)) return; 
            const res = await fetch(`admin_api.php?action=delete&id=${id}`, { method: 'DELETE' }); 
            const json = await res.json(); 
            if (json.status === 'success') loadUsers(); else alert("錯誤：" + json.message); 
        }

        function populateDropdowns() { 
            let ops = '<option value="">-- 請選擇 --</option>'; 
            [...allUsers].filter(u => u.is_archived != 1).sort((a,b)=>a.name.localeCompare(b.name)).forEach(u => ops += `<option value="${u.user_id}">${u.name}</option>`); 
            document.getElementById('selEmployee').innerHTML = ops; 
            document.getElementById('selSupervisor').innerHTML = ops; 
        }

        async function loadSupervisors() { 
            const res = await fetch('admin_api.php?action=supervisor_list'); const json = await res.json(); 
            if (json.status === 'success') document.getElementById('supTableBody').innerHTML = json.data.map(rel => `<tr><td class="fw-bold text-dark">${rel.sup_name || '查無此人'}</td><td>${rel.emp_name || '查無此人'}</td><td><button class="btn btn-sm btn-outline-danger fw-bold" onclick="removeSupervisor('${rel.user_id}', '${rel.supervisor_id}')">解除權限</button></td></tr>`).join('') || '<tr><td colspan="3" align="center" class="text-muted py-4">無資料</td></tr>'; 
        }

        async function assignSupervisor() { 
            const empId = document.getElementById('selEmployee').value; const supId = document.getElementById('selSupervisor').value; 
            if (!empId || !supId) return alert("請完整選擇員工與主管。"); 
            const res = await fetch('admin_api.php?action=assign_supervisor', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ user_id: empId, supervisor_id: supId }) }); 
            const json = await res.json(); 
            if (json.status === 'success') { alert("指派成功"); loadSupervisors(); } else alert("錯誤：" + json.message); 
        }

        async function removeSupervisor(empId, supId) { 
            if (!confirm("系統確認：確定解除此管理權限設定嗎？")) return; 
            const res = await fetch(`admin_api.php?action=remove_supervisor&user_id=${empId}&supervisor_id=${supId}`, { method: 'DELETE' }); 
            const json = await res.json(); 
            if (json.status === 'success') loadSupervisors(); 
        }
    </script>
</body>
</html>