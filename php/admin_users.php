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
// 🛡️ 保護牆：純淨版深色商務登入畫面
// ========================================================
if (empty($_SESSION['admin_logged_in'])) {
?>
<!DOCTYPE html>
<html lang="zh-TW" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR 管理中心 - 登入</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center" style="height: 100vh; background-color: var(--bs-body-bg);">
    <div class="card shadow-lg border-secondary" style="width: 100%; max-width: 380px;">
        <div class="card-body p-5">
            <h4 class="text-center mb-4 fw-bold">HR 管理中心</h4>
            <?php if($error_msg): ?>
                <div class="alert alert-danger py-2 text-center border-danger" role="alert" style="font-size: 0.9rem;">
                    <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>
            <form method="POST">
                <div class="mb-4">
                    <label class="form-label text-muted small fw-bold">存取密碼</label>
                    <input type="password" name="password" class="form-control border-secondary" placeholder="請輸入管理員密碼" required autofocus>
                </div>
                <button type="submit" class="btn btn-light w-100 fw-bold">安全登入</button>
            </form>
        </div>
    </div>
</body>
</html>
<?php 
    exit; 
} 
?>

<!-- ========================================================
     進入已登入的系統管理後台 (深色商務模式)
======================================================== -->
<!DOCTYPE html>
<html lang="zh-TW" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR 管理中心</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #121212; font-family: -apple-system, "Segoe UI", Roboto, sans-serif; padding-bottom: 50px; color: #e0e0e0; }
        .navbar { border-bottom: 1px solid #333; background-color: #000 !important; }
        .navbar-brand { font-weight: 700; letter-spacing: 0.5px; color: #fff !important; }
        
        .kpi-card { border-left: 4px solid #0d6efd; transition: transform 0.2s; background: #1e1e1e; }
        .kpi-card.success { border-left-color: #198754; }
        .kpi-card.warning { border-left-color: #ffc107; }
        
        .nav-tabs { border-bottom: 1px solid #333; }
        .nav-tabs .nav-link { font-weight: 600; color: #888; border: none; padding: 1rem 1.5rem; }
        .nav-tabs .nav-link:hover { color: #ccc; }
        .nav-tabs .nav-link.active { color: #fff; border-bottom: 3px solid #0d6efd; background: transparent; }
        
        .filter-box { background: #1e1e1e; padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #333; }
        .table-container { background: #1e1e1e; border-radius: 8px; border: 1px solid #333; overflow: hidden; padding-bottom: 0; }
        
        .table { margin-bottom: 0; --bs-table-bg: transparent; --bs-table-color: #e0e0e0; --bs-table-border-color: #333; }
        .table > :not(caption) > * > * { padding: 1rem 0.75rem; vertical-align: middle; }
        .table-dark-header th { background-color: #2a2a2a; color: #aaa; font-weight: 600; border-bottom: 1px solid #444; }
        
        /* 行事曆專用 CSS (深色版) */
        .admin-calendar { background: #1e1e1e; border: 1px solid #333; border-radius: 8px; overflow: hidden; }
        .admin-calendar-header { display: grid; grid-template-columns: repeat(7, 1fr); background: #2a2a2a; font-weight: bold; text-align: center; border-bottom: 1px solid #333; color: #aaa; }
        .admin-calendar-header div { padding: 10px; }
        .admin-calendar-body { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: #333; }
        .cal-day { background: #1e1e1e; min-height: 120px; padding: 8px; cursor: pointer; transition: background 0.2s; position: relative; }
        .cal-day:hover { background: #2a2a2a; }
        .cal-day.empty { background: #121212; cursor: default; }
        .cal-day.holiday-bg { background-color: #2c1a1c; } 
        .cal-day.holiday-bg:hover { background-color: #3a2225; }
        
        .cal-date-num { font-weight: 700; color: #888; margin-bottom: 8px; }
        .cal-day.today .cal-date-num { color: #fff; background: #0d6efd; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; }
        
        .cal-indicator { display: flex; gap: 4px; flex-wrap: wrap; margin-top: auto; }
        .cal-dot { padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: bold; color: white; display: inline-block; }
        .dot-clock { background-color: #0dcaf0; color: #000; }
        .dot-leave { background-color: #198754; }
        .dot-ot { background-color: #6f42c1; }
        .dot-warning { background-color: #dc3545; animation: pulse 2s infinite; }
        
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
        .popover { max-width: 350px !important; box-shadow: 0 4px 15px rgba(0,0,0,0.5); border: 1px solid #444; background-color: #1e1e1e; }
        .popover-header { background-color: #2a2a2a; border-bottom: 1px solid #444; color: #fff; }
        .popover-body { color: #e0e0e0; }

        #backToTopBtn { display: none; position: fixed; bottom: 30px; right: 30px; z-index: 99; opacity: 0.8; }
    </style>
</head>
<body>

    <!-- 頂部導覽列 -->
    <nav class="navbar navbar-expand-lg">
        <div class="container-fluid px-4">
            <span class="navbar-brand">企業 HR 管理中心</span>
            <div class="d-flex align-items-center">
                <a href="menu.php" class="btn btn-outline-info btn-sm fw-bold me-3">回選單</a>
                <a href="?logout=1" class="btn btn-outline-light btn-sm fw-bold">安全登出</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4 mt-4">
        <!-- 頁籤導覽 -->
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

        <!-- 頁籤內容區 -->
        <div class="tab-content">
            
            <!-- 1. 今日出勤看板 -->
            <div class="tab-pane fade show active" id="dashboard">
                <div class="row g-4 mb-4 align-items-stretch">
                    
                    <div class="col-md-3">
                        <div class="card kpi-card h-100 border-0 shadow-sm">
                            <div class="card-body d-flex flex-column">
                                <div class="text-muted small fw-bold text-uppercase">應到總人數</div>
                                <h3 class="mt-2 mb-3 fw-bold text-white" id="dashTotalEmp">-</h3>
                                <div class="border-top border-secondary pt-3 mt-auto w-100" id="dashExpectedList">
                                    <span class="text-muted small">讀取中...</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card kpi-card success h-100 border-0 shadow-sm">
                            <div class="card-body d-flex flex-column">
                                <div class="text-muted small fw-bold text-uppercase">今日實到 (已打卡)</div>
                                <h3 class="mt-2 mb-3 fw-bold text-success" id="dashClockin">-</h3>
                                <div class="border-top border-secondary pt-3 mt-auto w-100" id="dashClockinList">
                                    <span class="text-muted small">讀取中...</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card kpi-card warning h-100 border-0 shadow-sm">
                            <div class="card-body d-flex flex-column">
                                <div class="text-muted small fw-bold text-uppercase">今日請假人數</div>
                                <h3 class="mt-2 mb-3 fw-bold text-warning" id="dashLeaveCount">-</h3>
                                <div class="border-top border-secondary pt-3 mt-auto w-100" id="dashLeaveList">
                                    <span class="text-muted small">讀取中...</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card kpi-card border-0 shadow-sm" style="border-left-color: #dc3545;">
                            <div class="card-body d-flex flex-column">
                                <div class="text-muted small fw-bold text-uppercase">今日待處理異常</div>
                                <h3 class="mt-2 mb-3 fw-bold text-danger" id="dashPending">-</h3>
                                <div class="border-top border-secondary pt-3 mt-auto w-100" id="dashPendingList">
                                    <span class="text-muted small">讀取中...</span>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- 2. 紀錄總表 -->
            <div class="tab-pane fade" id="records">
                
                <!-- 視圖切換列 -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="mb-0 fw-bold text-white">紀錄總表</h4>
                    <div class="btn-group shadow-sm">
                        <button class="btn btn-primary fw-bold" id="btnViewList" onclick="switchRecordView('list')">列表模式</button>
                        <button class="btn btn-outline-primary fw-bold" id="btnViewCalendar" onclick="switchRecordView('calendar')">行事曆模式</button>
                    </div>
                </div>

                <!-- A. 列表模式 -->
                <div id="recordListView">

                    <div class="filter-box">
                        <div class="row g-3 align-items-end">
                            <!-- 新增員工篩選器 -->
                            <div class="col-md-2">
                                <label class="form-label small fw-bold text-muted">員工姓名</label>
                                <select id="filterEmp" class="form-select border-secondary bg-dark text-white">
                                    <option value="all">全體員工</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold text-muted">開始日期</label>
                                <input type="date" id="filterStart" class="form-control border-secondary bg-dark text-white">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold text-muted">結束日期</label>
                                <input type="date" id="filterEnd" class="form-control border-secondary bg-dark text-white">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold text-muted">單據狀態</label>
                                <select id="filterStatus" class="form-select border-secondary bg-dark text-white">
                                    <option value="all">所有狀態</option>
                                    <option value="pending">待審核</option>
                                    <option value="approved">已核准/正常</option>
                                    <option value="rejected">已退件/異常</option>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex gap-2">
                                <button class="btn btn-primary px-3 fw-bold" onclick="loadRecords()">查詢與篩選</button>
                                <button class="btn btn-outline-secondary fw-bold" onclick="resetFilter()">重置</button>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mb-4">
                        <a href="#titleClockin" class="btn btn-sm btn-outline-secondary">打卡紀錄 ↓</a>
                        <a href="#titleLeave" class="btn btn-sm btn-outline-secondary">假單紀錄 ↓</a>
                        <a href="#titleOvertime" class="btn btn-sm btn-outline-secondary">加班紀錄 ↓</a>
                    </div>

                    <div class="table-container mb-5">
                        <h5 id="titleClockin" class="px-4 pt-4 mb-3 fw-bold text-white" style="scroll-margin-top: 20px;">打卡紀錄</h5>
                        <table class="table table-hover mb-0">
                            <thead class="table-dark-header"><tr><th>員工</th><th>類型</th><th>打卡時間</th><th>系統判定</th><th>主管審核</th><th>原因</th><th>操作</th></tr></thead>
                            <tbody id="recClockinBody"></tbody>
                        </table>
                    </div>
                    <div class="table-container mb-5">
                        <h5 id="titleLeave" class="px-4 pt-4 mb-3 fw-bold text-white" style="scroll-margin-top: 20px;">假單紀錄</h5>
                        <table class="table table-hover mb-0">
                            <thead class="table-dark-header"><tr><th>員工</th><th>假別</th><th>起始時間</th><th>結束時間</th><th>狀態</th><th>原因</th><th>操作</th></tr></thead>
                            <tbody id="recLeaveBody"></tbody>
                        </table>
                    </div>
                    <div class="table-container mb-5">
                        <h5 id="titleOvertime" class="px-4 pt-4 mb-3 fw-bold text-white" style="scroll-margin-top: 20px;">加班紀錄</h5>
                        <table class="table table-hover mb-0">
                            <thead class="table-dark-header"><tr><th>員工</th><th>起始時間</th><th>結束時間</th><th>時數</th><th>狀態</th><th>原因</th><th>操作</th></tr></thead>
                            <tbody id="recOvertimeBody"></tbody>
                        </table>
                    </div>
                </div>

                <!-- B. 行事曆模式 -->
                <div id="recordCalendarView" class="d-none">
                    <div class="filter-box d-flex justify-content-between align-items-center mb-4 p-3">
                        <button class="btn btn-outline-secondary fw-bold" onclick="changeAdminCalMonth(-1)">&lt; 上個月</button>
                        <h4 class="mb-0 fw-bold text-primary" id="adminCalTitle">2026年 7月</h4>
                        <button class="btn btn-outline-secondary fw-bold" onclick="changeAdminCalMonth(1)">下個月 &gt;</button>
                    </div>

                    <div class="admin-calendar shadow-sm">
                        <div class="admin-calendar-header">
                            <div>日</div><div>一</div><div>二</div><div>三</div><div>四</div><div>五</div><div>六</div>
                        </div>
                        <div class="admin-calendar-body" id="adminCalGrid">
                            <!-- 透過 JS 動態產生格子 -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. 休假時數管理 -->
            <div class="tab-pane fade" id="balances">
                <div class="alert alert-dark border-secondary text-light small mb-4">
                    <strong>操作提示：</strong>系統會根據簽核狀態自動計算時數。若遇特殊情況（如結算誤差），可使用右側按鈕進行人工校正。
                </div>
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark-header"><tr><th>員工姓名</th><th>剩餘特休 (h)</th><th>剩餘補休 (h)</th><th>本年事假 (h)</th><th>本年病假 (h)</th><th>操作</th></tr></thead>
                        <tbody id="balanceTableBody"></tbody>
                    </table>
                </div>
            </div>

            <!-- 4. 員工資料維護 -->
            <div class="tab-pane fade" id="users">
                <div class="d-flex justify-content-end mb-3">
                    <button class="btn btn-primary fw-bold" onclick="openUserModal('add')">新增員工資料</button>
                </div>
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark-header"><tr><th>狀態/編號</th><th>姓名</th><th>LINE 識別碼</th><th>到職日期</th><th>操作</th></tr></thead>
                        <tbody id="userTableBody"></tbody>
                    </table>
                </div>
            </div>

            <!-- 5. 組織架構設定 -->
            <div class="tab-pane fade" id="supervisors">
                <div class="filter-box">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">員工姓名 (下屬)</label>
                            <select id="selEmployee" class="form-select border-secondary bg-dark text-white"><option value="">-- 請選擇 --</option></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">直屬主管</label>
                            <select id="selSupervisor" class="form-select border-secondary bg-dark text-white"><option value="">-- 請選擇 --</option></select>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-primary fw-bold" onclick="assignSupervisor()">確認指派</button>
                        </div>
                    </div>
                </div>
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark-header"><tr><th>部門主管</th><th>所屬員工</th><th>操作</th></tr></thead>
                        <tbody id="supTableBody"></tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <!-- Bootstrap Modals (深色) -->
    <!-- 員工編輯 Modal -->
    <div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark text-white border-secondary">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-bold" id="modalTitle">新增員工資料</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="editId">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">員工姓名</label>
                        <input type="text" id="userName" class="form-control border-secondary bg-dark text-white">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">LINE 識別碼</label>
                        <input type="text" id="userLineId" class="form-control border-secondary bg-dark text-white">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">到職日期</label>
                        <input type="date" id="userStartDate" class="form-control border-secondary bg-dark text-white">
                    </div>
                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" id="userIsExempt">
                        <label class="form-check-label small fw-bold text-info" for="userIsExempt">高階主管 / 免打卡人員</label>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-secondary fw-bold" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary fw-bold" onclick="saveUser()">確認儲存</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 時數校正 Modal -->
    <div class="modal fade" id="hoursModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark text-white border-secondary">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-bold">手動校正休假時數</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="hoursEditId">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">員工姓名</label>
                        <input type="text" id="hoursUserName" class="form-control border-secondary bg-secondary text-white" disabled>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">剩餘特休 (h)</label>
                            <input type="number" step="0.5" id="valAnnual" class="form-control border-secondary bg-dark text-white">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">剩餘補休 (h)</label>
                            <input type="number" step="0.5" id="valComp" class="form-control border-secondary bg-dark text-white">
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">事假已用 (h)</label>
                            <input type="number" step="0.5" id="valPersonal" class="form-control border-secondary bg-dark text-white">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">病假已用 (h)</label>
                            <input type="number" step="0.5" id="valSick" class="form-control border-secondary bg-dark text-white">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-secondary fw-bold" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary fw-bold" onclick="saveHours()">確認更新</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="dayDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content bg-dark text-white border-secondary shadow-lg">
                <div class="modal-header border-secondary bg-black">
                    <h5 class="modal-title fw-bold" id="dayDetailTitle">日期明細</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="dayDetailBody" style="max-height: 70vh; overflow-y: auto;">
                    </div>
            </div>
        </div>
    </div>

    <button id="backToTopBtn" class="btn btn-light fw-bold rounded-pill text-dark" onclick="window.scrollTo({top: 0, behavior: 'smooth'})">向上捲動</button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let allUsers = [];
        let bsUserModal, bsHoursModal;
        let currentMode = 'add';
        let adminCalYear = new Date().getFullYear();
        let adminCalMonth = new Date().getMonth() + 1;
        let adminCalData = {};
        let popoverList = [];

        let bsDayDetailModal;
        let currentDayDetailDate = null;

        // 🔥 關鍵修正：確保在載入任何資料前，先同步取得全體員工名單
        document.addEventListener("DOMContentLoaded", async function() {
            bsUserModal = new bootstrap.Modal(document.getElementById('userModal'));
            bsHoursModal = new bootstrap.Modal(document.getElementById('hoursModal'));
            
            // 🔥 補上這一行：讓系統認識單日明細的 Modal
            bsDayDetailModal = new bootstrap.Modal(document.getElementById('dayDetailModal'));

            await loadUsers(); // 確保名單建立，行事曆才能精準計算未打卡人員
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
                'cancelled': '<span class="badge bg-secondary px-2 py-1">已註銷</span>',
                'missing_punch': '<span class="badge bg-danger px-2 py-1">尚未打卡</span>' // 🔥 新增未打卡標籤
            };
            return map[status] || `<span class="badge border border-secondary text-light px-2 py-1">${status}</span>`;
        }

        function getActionButtons(type, id, currentStatus, r = null) {
            let buttons = '';
            if (currentStatus === 'pending') {
                buttons += `<button class="btn btn-sm btn-outline-success fw-bold me-1" onclick="forceAudit('${type}', ${id}, 'approved')">核准</button>`;
                buttons += `<button class="btn btn-sm btn-outline-danger fw-bold" onclick="forceAudit('${type}', ${id}, 'rejected')">退件</button>`;
            } else if (currentStatus === 'approved') {
                buttons += `<button class="btn btn-sm btn-outline-danger fw-bold" onclick="forceAudit('${type}', ${id}, 'rejected')">強制退件</button>`;
            } else if (currentStatus === 'missing_punch') {
                buttons = '<span class="text-danger small fw-bold">等待員工補登</span>';
            } else if (currentStatus === 'rejected') {
                // 💡 修正：退件後，若為打卡紀錄則給予手動補卡按鈕
                if (type === 'clockin' && r && r.clock_time) {
                    const dStr = r.clock_time.substring(0, 10);
                    buttons = `<button class="btn btn-sm btn-outline-info fw-bold" onclick="adminManualClockin('${r.user_name}', '${dStr}', '${r.mode}')">手動補卡</button>`;
                } else {
                    buttons = '<span class="text-muted small">無操作</span>';
                }
            } else {
                buttons = '<span class="text-muted small">無操作</span>';
            }
            return buttons;
        }

        async function forceAudit(type, id, newStatus) {
            const actionName = newStatus === 'approved' ? '核准' : '退件';
            if (!confirm(`系統確認：確定要執行「${actionName}」操作嗎？\n(系統將自動回收/發放時數，並通知員工)`)) return;
            try {
                const res = await fetch('admin_api.php?action=force_audit', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ type: type, id: id, status: newStatus }) });
                const json = await res.json();
                if (json.status === 'success') { 
                    alert("系統提示：" + json.message); 
                    loadRecords(); 
                    loadDashboard(); 
                    
                    // 🔥 新增：如果當前在行事曆模式，同步更新背景日曆與開啟中的明細視窗
                    if (!document.getElementById('recordCalendarView').classList.contains('d-none')) {
                        await renderAdminCalendar();
                        if (currentDayDetailDate) openDayDetail(currentDayDetailDate);
                    }
                } 
                else alert("操作失敗：" + json.message);
            } catch (err) { alert("系統錯誤，無法連線。"); }
        }

        // 👇 替換整個 loadDashboard 函式：
        async function loadDashboard() {
            try {
                // 取得今天日期字串 (YYYY-MM-DD)
                const now = new Date();
                const todayStr = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

                // 同時請求：大盤統計 API + 單日明細 API
                const [resDash, resRec] = await Promise.all([
                    fetch('admin_api.php?action=dashboard'),
                    fetch(`admin_api.php?action=all_records&start=${todayStr}&end=${todayStr}`)
                ]);
                
                const jsonDash = await resDash.json();
                const jsonRec = await resRec.json();

                if (jsonDash.status === 'success' && jsonRec.status === 'success') {
                    
                    // --- 1. 處理應到名單 (排除免打卡) ---
                    const activeUsers = allUsers.filter(u => u.is_archived != 1);
                    const expectedUsers = activeUsers.filter(u => u.is_exempt != 1 && u.is_exempt !== '1');
                    
                    document.getElementById('dashTotalEmp').innerText = expectedUsers.length + " 人";
                    let expectedHtml = expectedUsers.map(u => `<div class="badge border border-secondary text-light mb-2 d-block text-start p-2" style="font-size:0.85rem;">${u.name}</div>`).join('');
                    document.getElementById('dashExpectedList').innerHTML = expectedHtml || '<div class="text-muted small">無名單</div>';

                    // --- 2. 處理打卡名單 (已打卡 vs 未打卡) ---
                    const todaysClockins = jsonRec.data.clockins;
                    // 找出今天有成功打卡的姓名
                    const clockedInNames = new Set(todaysClockins.filter(c => c.status === 'success' || c.approval_status === 'approved').map(c => c.user_name));
                    // 應到名單 扣除 有打卡的 = 未打卡名單
                    const missingUsers = expectedUsers.filter(u => !clockedInNames.has(u.name));

                    document.getElementById('dashClockin').innerText = clockedInNames.size + " 人";
                    
                    let clockinHtml = '';
                    if (clockedInNames.size > 0) {
                        clockinHtml += `<div class="text-success small fw-bold mb-2">已打卡：</div>`;
                        clockinHtml += Array.from(clockedInNames).map(name => `<div class="badge border border-success text-success mb-2 d-block text-start p-2" style="font-size:0.85rem;">${name}</div>`).join('');
                    }
                    if (missingUsers.length > 0) {
                        clockinHtml += `<div class="text-danger small fw-bold mb-2 mt-3">未打卡：</div>`;
                        clockinHtml += missingUsers.map(u => `<div class="badge border border-danger text-danger mb-2 d-block text-start p-2" style="font-size:0.85rem;">${u.name}</div>`).join('');
                    }
                    document.getElementById('dashClockinList').innerHTML = clockinHtml || '<div class="text-muted small">無資料</div>';

                    // --- 3. 處理請假名單 ---
                    const leavesToday = jsonDash.data.leaves_today; 
                    document.getElementById('dashLeaveCount').innerText = leavesToday.length + " 人";
                    
                    let leaveHtml = '';
                    if (leavesToday.length > 0) {
                        leaveHtml = leavesToday.map(l => `<div class="badge border border-warning text-warning mb-2 d-block text-start p-2" style="font-size:0.85rem;">${l.name} - ${l.leave_type}</div>`).join('');
                    }
                    document.getElementById('dashLeaveList').innerHTML = leaveHtml || '<div class="text-muted small">無人請假</div>';

                    // --- 4. 處理「今日相關」的待審核單據 ---
                    const pendingCk = todaysClockins.filter(c => c.approval_status === 'pending');
                    const pendingLv = jsonRec.data.leaves.filter(l => l.status === 'pending');
                    const pendingOt = jsonRec.data.overtimes.filter(o => o.status === 'pending');
                    const todayPendingTotal = pendingCk.length + pendingLv.length + pendingOt.length;

                    document.getElementById('dashPending').innerText = todayPendingTotal + " 筆";
                    
                    let pendingHtml = '';
                    if(pendingCk.length > 0) {
                        pendingHtml += `<div class="text-info small fw-bold mb-2">打卡異常：</div>`;
                        pendingHtml += pendingCk.map(c => `<div class="badge border border-info text-info mb-2 d-block text-start p-2" style="font-size:0.85rem;">${c.user_name}</div>`).join('');
                    }
                    if(pendingLv.length > 0) {
                        pendingHtml += `<div class="text-warning small fw-bold mb-2 mt-3">請假待審：</div>`;
                        pendingHtml += pendingLv.map(l => `<div class="badge border border-warning text-warning mb-2 d-block text-start p-2" style="font-size:0.85rem;">${l.user_name}</div>`).join('');
                    }
                    if(pendingOt.length > 0) {
                        pendingHtml += `<div class="text-primary small fw-bold mb-2 mt-3">加班待審：</div>`;
                        pendingHtml += pendingOt.map(o => `<div class="badge border border-primary text-primary mb-2 d-block text-start p-2" style="font-size:0.85rem;">${o.user_name}</div>`).join('');
                    }
                    document.getElementById('dashPendingList').innerHTML = pendingHtml || '<div class="text-muted small">今日無待辦異常</div>';

                }
            } catch (err) { console.error("Dashboard Error:", err); }
        }

        // 👇 替換 loadUsers 函式：
        async function loadUsers() {
            try {
                const res = await fetch('admin_api.php?action=list'); 
                const json = await res.json();
                
                if (json.status === 'success') {
                    allUsers = json.data;
                    document.getElementById('userTableBody').innerHTML = json.data.map(u => {
                        // 防呆判定
                        const isArchived = (u.is_archived == 1 || u.is_archived === '1');
                        const isExempt = (u.is_exempt == 1 || u.is_exempt === '1');
                        
                        // 🔥 組合最左側的狀態標籤
                        let statusHtml = isArchived ? '<span class="badge bg-secondary">已離職</span>' : '<span class="badge bg-success">在職</span>';
                        if (!isArchived && isExempt) {
                            statusHtml += ' <span class="badge bg-info text-dark ms-1">免打卡</span>';
                        }
                        
                        const opacityClass = isArchived ? 'opacity-50' : '';
                        const resignText = isArchived && u.resign_date ? `<br><small class="text-danger">離職日: ${u.resign_date}</small>` : '';

                        let buttons = '';
                        if (isArchived) {
                            buttons = `<button class="btn btn-sm btn-outline-danger fw-bold" onclick="deleteUser(${u.id}, '${u.name}')">刪除</button>`;
                        } else {
                            buttons = `
                                <button class="btn btn-sm btn-outline-primary fw-bold" onclick='openUserModal("edit", ${JSON.stringify(u)})'>編輯</button>
                                <button class="btn btn-sm btn-outline-secondary fw-bold mx-1" onclick="archiveUser(${u.id}, '${u.name}')">封存</button>
                                <button class="btn btn-sm btn-outline-danger fw-bold" onclick="deleteUser(${u.id}, '${u.name}')">刪除</button>
                            `;
                        }

                        return `<tr class="${opacityClass}">
                            <td>${statusHtml} <span class="text-muted small ms-1">#${u.id}</span></td>
                            <td><span class="fw-bold text-white">${u.name}</span> ${resignText}</td>
                            <td class="font-monospace text-secondary">${u.user_id}</td>
                            <td>${u.start_date || '-'}</td>
                            <td>${buttons}</td>
                        </tr>`;
                    }).join('') || '<tr><td colspan="5" align="center" class="text-muted py-4">無資料</td></tr>';
                    populateDropdowns();
                }
            } catch (err) { console.error("Error loading users:", err); }
        }

        function resetFilter() { 
            document.getElementById('filterStart').value = ''; 
            document.getElementById('filterEnd').value = ''; 
            document.getElementById('filterStatus').value = 'all'; // 重置下拉選單
            loadRecords(); 
        }

        async function loadRecords() {
            try {
                const startInput = document.getElementById('filterStart');
                const endInput = document.getElementById('filterEnd');
                let start = startInput.value; 
                let end = endInput.value;
                const statusFilter = document.getElementById('filterStatus').value;
                const empFilter = document.getElementById('filterEmp').value;
                
                const now = new Date();
                const todayStr = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

                if (start || end) {
                    if (!start && end) { start = end; startInput.value = start; } 
                    else if (start && !end) { end = (start <= todayStr) ? todayStr : start; endInput.value = end; }
                    if (start > end) return alert("系統提示：起始日期不能晚於結束日期。");
                }
                
                let url = 'admin_api.php?action=all_records';
                if (start && end) { url += `&start=${start}&end=${end}`; }
                
                const res = await fetch(url); 
                const json = await res.json();
                
                if (json.status === 'success') {
                    // 前端即時狀態篩選器
                    const filterByStatus = (record, type) => {
                        if (statusFilter === 'all') return true;
                        const recStatus = (type === 'clockin') ? record.approval_status : record.status;
                        const secondaryStatus = record.status;
                        if (statusFilter === 'pending') return recStatus === 'pending' || secondaryStatus === 'pending' || recStatus === 'missing_punch';
                        if (statusFilter === 'approved') return recStatus === 'approved' || secondaryStatus === 'success' || secondaryStatus === 'normal';
                        if (statusFilter === 'rejected') return recStatus === 'rejected' || secondaryStatus === 'fail' || secondaryStatus === 'cancelled';
                        return true;
                    };

                    const holidays = json.data.holidays || [];
                    const filteredLeaves = json.data.leaves.filter(r => filterByStatus(r, 'leave') && (empFilter === 'all' || r.user_name === empFilter));
                    const filteredOvertimes = json.data.overtimes.filter(r => filterByStatus(r, 'overtime') && (empFilter === 'all' || r.user_name === empFilter));

                    let clockinHtml = '';
                    const emptyRow = '<tr><td colspan="6" align="center" class="text-muted py-4">查無符合條件的紀錄</td></tr>';

                    if (empFilter !== 'all' && start && end) {
                        // 🟢 【個人專屬模式】: 逐日展開 1 號到 30 號 (強制要求上下班兩次)
                        let curDate = new Date(start + 'T00:00:00');
                        let endDateObj = new Date(end + 'T00:00:00');

                        while (curDate <= endDateObj) {
                            const y = curDate.getFullYear();
                            const m = String(curDate.getMonth() + 1).padStart(2, '0');
                            const d = String(curDate.getDate()).padStart(2, '0');
                            const dStr = `${y}-${m}-${d}`;

                            const isHoliday = holidays.find(h => h.date === dStr);
                            const dayRecords = json.data.clockins.filter(c => c.user_name === empFilter && c.clock_time.startsWith(dStr));

                            // 1. 渲染當天已有的打卡紀錄
                            dayRecords.forEach(r => {
                                if(filterByStatus(r, 'clockin')) {
                                    const displayStatus = (r.approval_status === 'rejected') ? 'fail' : r.status;
                                    clockinHtml += `<tr><td><span class="fw-bold text-white">${r.user_name}</span></td><td>${r.mode}</td><td class="font-monospace text-secondary">${r.clock_time}</td><td>${getStatusBadge(displayStatus)}</td><td>${getStatusBadge(r.approval_status)}</td><td><span class="text-muted small">${r.reason || '-'}</span></td><td>${getActionButtons('clockin', r.id, r.approval_status, r)}</td></tr>`;
                                }
                            });

                            // 2. 判斷是否為休假日
                            const isWeekend = (curDate.getDay() === 0 || curDate.getDay() === 6);
                            const isWorkday = isHoliday && isHoliday.type === 'workday';
                            const isOffDay = (isWeekend && !isWorkday) || (isHoliday && isHoliday.type === 'holiday');

                            // 3. 判斷缺卡次數與補卡按鈕
                            if (!isOffDay) {
                                const missingCount = Math.max(0, 2 - dayRecords.length);
                                for (let i = 0; i < missingCount; i++) {
                                    let punchLabel = (dayRecords.length === 0 && i === 0) ? '上班' : '下班';
                                    // 修正：補回缺卡時的中文提示，並補上一個空的 <td>-</td> 給原因欄位
                                    clockinHtml += `<tr><td><span class="fw-bold text-white">${empFilter}</span></td><td>系統偵測</td><td class="font-monospace text-secondary">${dStr} 尚未打卡 <span class="text-warning">(${punchLabel}缺卡)</span></td><td><span class="badge bg-danger px-2 py-1">異常</span></td><td><span class="badge bg-danger px-2 py-1">缺卡</span></td><td>-</td><td><button class="btn btn-sm btn-outline-info fw-bold" onclick="adminManualClockin('${empFilter}', '${dStr}', '${punchLabel}')">手動補卡</button></td></tr>`;
                                }
                            } else {
                                // 假日且完全無任何紀錄時，顯示為休假
                                if (dayRecords.length === 0) {
                                    const reasonStr = isHoliday ? isHoliday.name : '週末';
                                    // 修正：多加一個 <td>-</td> 確保有 7 個欄位
                                    clockinHtml += `<tr><td><span class="fw-bold text-white">${empFilter}</span></td><td>-</td><td class="font-monospace text-danger">${dStr} (休假: ${reasonStr})</td><td>-</td><td>-</td><td>-</td><td>-</td></tr>`;
                                }
                            }
                            curDate.setDate(curDate.getDate() + 1);
                        }
                    } else {
                        // 🔵 【全體員工/預設平鋪模式】
                        const filteredClockins = json.data.clockins.filter(r => filterByStatus(r, 'clockin') && (empFilter === 'all' || r.user_name === empFilter));
                        clockinHtml = filteredClockins.map(r => {
                            const displayStatus = (r.approval_status === 'rejected') ? 'fail' : r.status;
                            return `<tr><td><span class="fw-bold text-white">${r.user_name}</span></td><td>${r.mode}</td><td class="font-monospace text-secondary">${r.clock_time}</td><td>${getStatusBadge(displayStatus)}</td><td>${getStatusBadge(r.approval_status)}</td><td><span class="text-muted small">${r.reason || '-'}</span></td><td>${getActionButtons('clockin', r.id, r.approval_status, r)}</td></tr>`;
                        }).join('');
                    }

                    document.getElementById('recClockinBody').innerHTML = clockinHtml || emptyRow;
                    document.getElementById('recLeaveBody').innerHTML = filteredLeaves.map(r => `<tr><td><span class="fw-bold text-white">${r.user_name}</span></td><td>${r.leave_type}</td><td class="font-monospace text-secondary">${r.start_at}</td><td class="font-monospace text-secondary">${r.end_at}</td><td>${getStatusBadge(r.status)}</td><td><span class="text-muted small" style="max-width: 150px; display: inline-block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${r.reason || ''}">${r.reason || '-'}</span></td><td>${getActionButtons('leave', r.id, r.status)}</td></tr>`).join('') || emptyRow;
                    document.getElementById('recOvertimeBody').innerHTML = filteredOvertimes.map(r => `<tr><td><span class="fw-bold text-white">${r.user_name}</span></td><td class="font-monospace text-secondary">${r.start_at}</td><td class="font-monospace text-secondary">${r.end_at}</td><td class="fw-bold text-info">${r.hours}h</td><td>${getStatusBadge(r.status)}</td><td><span class="text-muted small" style="max-width: 150px; display: inline-block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${r.reason || ''}">${r.reason || '-'}</span></td><td>${getActionButtons('overtime', r.id, r.status)}</td></tr>`).join('') || emptyRow;
                }
            } catch (err) { console.error(err); }
        }

        // 管理員強制手動補卡 (加入類型選擇)
        async function adminManualClockin(userName, dateStr, defaultMode) {
            // 第一步：輸入時間
            const timeStr = prompt(`請輸入 ${userName} 於 ${dateStr} 的補卡時間\n(格式 HH:MM，例如 09:00 或 18:30)：`);
            if (!timeStr) return;
            
            if (!/^([01]\d|2[0-3]):([0-5]\d)$/.test(timeStr)) {
                return alert("時間格式錯誤，請輸入正確的 HH:MM 格式！");
            }

            // 第二步：選擇類型 (防呆)
            let modeInput = prompt(`請確認補卡類型 (輸入 1 或 2)：\n1. 上班\n2. 下班`, defaultMode === '下班' ? '2' : '1');
            if (!modeInput) return;
            
            const finalMode = (modeInput === '2' || modeInput === '下班') ? '下班' : '上班';
            const fullTime = `${dateStr} ${timeStr}:00`;
            
            if (!confirm(`確定要為 ${userName} 寫入打卡紀錄：${fullTime} (${finalMode}) 嗎？`)) return;

            try {
                const res = await fetch('admin_api.php?action=manual_clockin', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_name: userName, clock_time: fullTime, mode: finalMode })
                });
                const json = await res.json();
                if (json.status === 'success') {
                    alert('手動補卡成功！');
                    loadRecords(); 
                } else {
                    alert('補卡失敗：' + json.message);
                }
            } catch (err) {
                alert('連線失敗，請稍後再試。');
            }
        }

        async function loadUsers() {
            try {
                const res = await fetch('admin_api.php?action=list'); const json = await res.json();
                if (json.status === 'success') {
                    allUsers = json.data;
                    document.getElementById('userTableBody').innerHTML = json.data.map(u => {
                        const isArchived = u.is_archived == 1;
                        const isExempt = u.is_exempt == 1;
                        
                        // 🔥 組合最左側的狀態標籤
                        let statusHtml = isArchived ? '<span class="badge bg-secondary">已離職</span>' : '<span class="badge bg-success">在職</span>';
                        if (!isArchived && isExempt) {
                            statusHtml += ' <span class="badge bg-info text-dark ms-1">免打卡</span>';
                        }
                        
                        const opacityClass = isArchived ? 'opacity-50' : '';
                        const resignText = isArchived && u.resign_date ? `<br><small class="text-danger">離職日: ${u.resign_date}</small>` : '';
                        const exemptBadge = (u.is_exempt == 1) ? '<span class="badge bg-info text-dark ms-1">免打卡</span>' : '';


                        let buttons = '';
                        if (isArchived) {
                            buttons = `<button class="btn btn-sm btn-outline-danger fw-bold" onclick="deleteUser(${u.id}, '${u.name}')">刪除</button>`;
                        } else {
                            buttons = `
                                <button class="btn btn-sm btn-outline-primary fw-bold" onclick='openUserModal("edit", ${JSON.stringify(u)})'>編輯</button>
                                <button class="btn btn-sm btn-outline-secondary fw-bold mx-1" onclick="archiveUser(${u.id}, '${u.name}')">封存</button>
                                <button class="btn btn-sm btn-outline-danger fw-bold" onclick="deleteUser(${u.id}, '${u.name}')">刪除</button>
                            `;
                        }

                        return `<tr class="${opacityClass}">
                            <td>${statusHtml} <span class="text-muted small ms-1">#${u.id}</span></td>
                            <td><span class="fw-bold text-white">${u.name}</span> ${resignText}</td>
                            <td class="font-monospace text-secondary">${u.user_id}</td>
                            <td>${u.start_date || '-'}</td>
                            <td>${buttons}</td>
                        </tr>`;
                    }).join('') || '<tr><td colspan="5" align="center" class="text-muted py-4">無資料</td></tr>';
                    populateDropdowns();
                }
            } catch (err) { console.error("Error loading users:", err); }
        }

        async function archiveUser(id, name) {
            const now = new Date();
            const defaultDate = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
            const resignDate = prompt(`系統提示：\n請輸入「${name}」的最後在職日 (格式：YYYY-MM-DD)：`, defaultDate);
            
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
                document.getElementById('balanceTableBody').innerHTML = json.data.map(u => `<tr><td><span class="fw-bold text-white">${u.name}</span></td><td class="font-monospace text-info fw-bold">${u.annual_leave_hours || 0}</td><td class="font-monospace" style="color: #b19cd9; font-weight: bold;">${u.comp_leave_hours || 0}</td><td class="font-monospace text-secondary">${u.used_personal_hours || 0}</td><td class="font-monospace text-secondary">${u.used_sick_hours || 0}</td><td><button class="btn btn-sm btn-outline-light fw-bold" onclick='openHoursForm(${JSON.stringify(u)})'>手動校正</button></td></tr>`).join('') || '<tr><td colspan="6" align="center" class="text-muted py-4">無資料</td></tr>';
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
            document.getElementById('userIsExempt').checked = mode === 'edit' ? (userData.is_exempt == 1) : false;
            bsUserModal.show();
        }

        async function saveUser() { 
            const payload = { 
                id: document.getElementById('editId').value, 
                name: document.getElementById('userName').value, 
                user_id: document.getElementById('userLineId').value, 
                start_date: document.getElementById('userStartDate').value,
                is_exempt: document.getElementById('userIsExempt').checked ? 1 : 0
            };
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
            let filterOps = '<option value="all">全體員工</option>';
            [...allUsers].filter(u => u.is_archived != 1).sort((a,b)=>a.name.localeCompare(b.name)).forEach(u => {
                ops += `<option value="${u.user_id}">${u.name}</option>`;
                filterOps += `<option value="${u.name}">${u.name}</option>`;
            }); 
            document.getElementById('selEmployee').innerHTML = ops; 
            document.getElementById('selSupervisor').innerHTML = ops; 
            if (document.getElementById('filterEmp')) document.getElementById('filterEmp').innerHTML = filterOps;
        }

        async function loadSupervisors() { 
            const res = await fetch('admin_api.php?action=supervisor_list'); const json = await res.json(); 
            if (json.status === 'success') document.getElementById('supTableBody').innerHTML = json.data.map(rel => `<tr><td class="fw-bold text-white">${rel.sup_name || '查無此人'}</td><td class="text-white">${rel.emp_name || '查無此人'}</td><td><button class="btn btn-sm btn-outline-danger fw-bold" onclick="removeSupervisor('${rel.user_id}', '${rel.supervisor_id}')">解除權限</button></td></tr>`).join('') || '<tr><td colspan="3" align="center" class="text-muted py-4">無資料</td></tr>'; 
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

        // ==========================================
        // 行事曆視圖與 Popover 邏輯
        // ==========================================
        function switchRecordView(mode) {
            const btnList = document.getElementById('btnViewList');
            const btnCal = document.getElementById('btnViewCalendar');
            const viewList = document.getElementById('recordListView');
            const viewCal = document.getElementById('recordCalendarView');

            if (mode === 'list') {
                btnList.className = 'btn btn-primary fw-bold';
                btnCal.className = 'btn btn-outline-primary fw-bold';
                viewList.classList.remove('d-none');
                viewCal.classList.add('d-none');
            } else {
                btnList.className = 'btn btn-outline-primary fw-bold';
                btnCal.className = 'btn btn-primary fw-bold';
                viewList.classList.add('d-none');
                viewCal.classList.remove('d-none');
                renderAdminCalendar(); // 切換時自動載入
            }
        }

        function changeAdminCalMonth(offset) {
            adminCalMonth += offset;
            if (adminCalMonth > 12) { adminCalMonth = 1; adminCalYear++; }
            if (adminCalMonth < 1) { adminCalMonth = 12; adminCalYear--; }
            renderAdminCalendar();
        }

        // 👇 替換整個 renderAdminCalendar 函式：
        async function renderAdminCalendar() {
            popoverList.forEach(p => p.dispose());
            popoverList = [];

            const grid = document.getElementById('adminCalGrid');
            grid.innerHTML = '<div style="grid-column: span 7; text-align: center; padding: 40px; color: #888;">讀取資料中...</div>';
            document.getElementById('adminCalTitle').innerText = `${adminCalYear}年 ${adminCalMonth}月`;

            const startDate = `${adminCalYear}-${String(adminCalMonth).padStart(2,'0')}-01`;
            const endObj = new Date(adminCalYear, adminCalMonth, 0);
            const endDate = `${adminCalYear}-${String(adminCalMonth).padStart(2,'0')}-${String(endObj.getDate()).padStart(2,'0')}`;

            try {
                const res = await fetch(`admin_api.php?action=all_records&start=${startDate}&end=${endDate}`);
                const json = await res.json();
                
                adminCalData = {};
                
                json.data.clockins.forEach(c => {
                    const d = c.clock_time.substring(0, 10);
                    if(!adminCalData[d]) adminCalData[d] = { clockins:[], leaves:[], overtimes:[], holiday: null };
                    adminCalData[d].clockins.push(c);
                });
                
                json.data.overtimes.forEach(o => {
                    // 🔥 防呆：過濾掉「已註銷」與「已退件」的加班單
                    if (o.status === 'cancelled' || o.status === 'rejected') return;
                    const d = o.start_at.substring(0, 10);
                    if(!adminCalData[d]) adminCalData[d] = { clockins:[], leaves:[], overtimes:[], holiday: null };
                    adminCalData[d].overtimes.push(o);
                });
                
                json.data.leaves.forEach(l => {
                    // 🔥 防呆：過濾掉「已註銷」與「已退件」的假單，不再算入日曆
                    if (l.status === 'cancelled' || l.status === 'rejected') return;
                    let cur = new Date(l.start_at.substring(0, 10));
                    let end = new Date(l.end_at.substring(0, 10));
                    while (cur <= end) {
                        let d = cur.toISOString().substring(0, 10);
                        if(!adminCalData[d]) adminCalData[d] = { clockins:[], leaves:[], overtimes:[], holiday: null };
                        adminCalData[d].leaves.push(l);
                        cur.setDate(cur.getDate() + 1);
                    }
                });

                // 🔥 處理國定假日與補班日
                if (json.data.holidays) {
                    json.data.holidays.forEach(h => {
                        const d = h.date;
                        if(!adminCalData[d]) adminCalData[d] = { clockins:[], leaves:[], overtimes:[], holiday: null };
                        adminCalData[d].holiday = h;
                    });
                }

                grid.innerHTML = '';
                const firstDay = new Date(adminCalYear, adminCalMonth - 1, 1).getDay();
                const totalDays = endObj.getDate();
                const todayStr = new Date().toLocaleDateString('en-CA'); 

                for (let i = 0; i < firstDay; i++) {
                    grid.innerHTML += `<div class="cal-day empty"></div>`;
                }

                for (let i = 1; i <= totalDays; i++) {
                    const dStr = `${adminCalYear}-${String(adminCalMonth).padStart(2,'0')}-${String(i).padStart(2,'0')}`;
                    const isToday = (dStr === todayStr) ? 'today' : '';
                    const data = adminCalData[dStr] || { clockins:[], leaves:[], overtimes:[], holiday: null };
                    
                    let indicators = '';
                    let hasPending = false;
                    let holidayLabel = '';
                    let isHolidayClass = '';

                    // 🔥 渲染假日標籤與變更背景色
                    if (data.holiday) {
                        if (data.holiday.type === 'holiday') {
                            holidayLabel = `<span class="text-danger ms-1" style="font-size:0.75rem;">${data.holiday.name}</span>`;
                            isHolidayClass = 'holiday-bg';
                        } else if (data.holiday.type === 'workday') {
                            holidayLabel = `<span class="text-warning ms-1" style="font-size:0.75rem;">${data.holiday.name}</span>`;
                        }
                    }

                    if (data.clockins.some(c => c.approval_status === 'pending') || 
                        data.leaves.some(l => l.status === 'pending') || 
                        data.overtimes.some(o => o.status === 'pending')) {
                        hasPending = true;
                    }

                    if (data.clockins.length > 0) indicators += `<div class="cal-dot dot-clock">打卡 ${data.clockins.length}</div>`;
                    if (data.leaves.length > 0) indicators += `<div class="cal-dot dot-leave">休 ${data.leaves.length}</div>`;
                    if (data.overtimes.length > 0) indicators += `<div class="cal-dot dot-ot">加 ${data.overtimes.length}</div>`;
                    if (hasPending) indicators += `<div class="cal-dot dot-warning">待處理</div>`;

                    const popoverHtml = generatePopoverHtml(dStr, data);

                    const cell = document.createElement('div');
                    cell.className = `cal-day ${isToday} ${isHolidayClass}`; // 加上假日 class
                    cell.setAttribute('data-bs-toggle', 'popover');
                    cell.setAttribute('data-bs-placement', 'auto');
                    cell.setAttribute('data-bs-html', 'true');
                    cell.setAttribute('data-bs-trigger', 'hover focus');
                    cell.setAttribute('data-bs-content', popoverHtml);

                    cell.onclick = () => openDayDetail(dStr);

                    // 🔥 顯示日期與假日名稱
                    cell.innerHTML = `
                        <div class="cal-date-num d-flex justify-content-between align-items-start">
                            <span>${i}</span> ${holidayLabel}
                        </div>
                        <div class="cal-indicator">${indicators}</div>
                    `;
                    grid.appendChild(cell);
                }

                const popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"]');
                popoverList = [...popoverTriggerList].map(el => new bootstrap.Popover(el));

            } catch (e) {
                console.error(e);
                grid.innerHTML = '<div style="grid-column: span 7; text-align: center; padding: 40px; color: #dc3545;">載入失敗</div>';
            }
        }

        // 🔥 關鍵修正：準確撈出未打卡名單
        function generatePopoverHtml(dateStr, data) {
            const activeUsers = allUsers.filter(u => u.is_archived != 1 && u.is_exempt != 1);

            const normalCk = data.clockins.filter(c => c.status === 'success' || c.approval_status === 'approved');
            const pendingCk = data.clockins.filter(c => c.approval_status === 'pending');
            
            const clockedInNames = new Set(data.clockins.map(c => c.user_name));
            // 系統比對出：在職員工中，今天沒有打卡紀錄的人
            const unclockedUsers = activeUsers.filter(u => !clockedInNames.has(u.name));

            const approvedLv = data.leaves.filter(l => l.status === 'approved');
            const pendingLv = data.leaves.filter(l => l.status === 'pending');

            const approvedOt = data.overtimes.filter(o => o.status === 'approved');
            const pendingOt = data.overtimes.filter(o => o.status === 'pending');

            let html = `<div style="font-size:0.9rem; color:#e0e0e0;">`;

            // 打卡區塊
            html += `<h6 class="border-bottom border-info text-info pb-1 mb-2 fw-bold">考勤打卡</h6>`;
            html += `<div class="d-flex justify-content-between text-success"><span>準時或正常:</span> <span>${normalCk.length} 人</span></div>`;
            if (normalCk.length > 0) html += `<div class="text-muted small mb-1 ms-3">${normalCk.map(c=>c.user_name).join('、')}</div>`;
            
            html += `<div class="d-flex justify-content-between text-secondary mt-1"><span>尚未打卡:</span> <span>${unclockedUsers.length} 人</span></div>`;
            if (unclockedUsers.length > 0) html += `<div class="text-muted small mb-1 ms-3">${unclockedUsers.map(u=>u.name).join('、')}</div>`;
            
            html += `<div class="d-flex justify-content-between text-danger mt-1"><span>待審核(異常):</span> <span>${pendingCk.length} 人</span></div>`;
            if (pendingCk.length > 0) html += `<div class="text-muted small mb-1 ms-3">${pendingCk.map(c=>c.user_name).join('、')}</div>`;

            // 請假區塊
            html += `<h6 class="border-bottom border-success text-success pb-1 mt-3 mb-2 fw-bold">請假名單</h6>`;
            html += `<div class="d-flex justify-content-between text-success"><span>已核准:</span> <span>${approvedLv.length} 人</span></div>`;
            if (approvedLv.length > 0) html += `<div class="text-muted small mb-1 ms-3">${approvedLv.map(l=>l.user_name).join('、')}</div>`;
            
            html += `<div class="d-flex justify-content-between text-danger mt-1"><span>待審核:</span> <span>${pendingLv.length} 人</span></div>`;
            if (pendingLv.length > 0) html += `<div class="text-muted small mb-1 ms-3">${pendingLv.map(l=>l.user_name).join('、')}</div>`;

            // 加班區塊
            html += `<h6 class="border-bottom pb-1 mt-3 mb-2 fw-bold" style="color:#b19cd9; border-color:#b19cd9;">加班名單</h6>`;
            html += `<div class="d-flex justify-content-between text-success"><span>已核准:</span> <span>${approvedOt.length} 人</span></div>`;
            if (approvedOt.length > 0) html += `<div class="text-muted small mb-1 ms-3">${approvedOt.map(o=>o.user_name).join('、')}</div>`;
            
            html += `<div class="d-flex justify-content-between text-danger mt-1"><span>待審核:</span> <span>${pendingOt.length} 人</span></div>`;
            if (pendingOt.length > 0) html += `<div class="text-muted small mb-1 ms-3">${pendingOt.map(o=>o.user_name).join('、')}</div>`;

            html += `</div>`;
            return html;
        }

        // ==========================================
        // 🔥 點擊日曆格子：開啟單日明細與簽核視窗 (加入容錯保護)
        // ==========================================
        function openDayDetail(dateStr) {
            try {
                // 1. 強制清除畫面上殘留的 popover 元素，避免動畫卡死阻擋點擊
                document.querySelectorAll('.popover').forEach(el => el.remove());

                currentDayDetailDate = dateStr;
                const data = adminCalData[dateStr] || { clockins:[], leaves:[], overtimes:[] };
                document.getElementById('dayDetailTitle').innerText = `${dateStr} 出勤與簽核明細`;

                let html = '';

                // --- 📍 打卡紀錄 ---
                if (data.clockins.length > 0) {
                    html += `<h6 class="text-info fw-bold border-bottom border-info pb-1 mb-2 mt-2">打卡紀錄</h6>`;
                    html += `<table class="table table-sm table-dark table-hover mb-4">
                        <thead class="table-dark-header"><tr><th>員工</th><th>類型</th><th>時間</th><th>狀態</th><th>原因</th><th>操作</th></tr></thead><tbody>`;
                    data.clockins.forEach(c => {
                        const t = c.clock_time ? c.clock_time.substring(11, 16) : '--:--';
                        html += `<tr>
                            <td>${c.user_name}</td>
                            <td>${c.mode}</td>
                            <td class="font-monospace text-secondary">${t}</td>
                            <td>${getStatusBadge(c.approval_status === 'pending' ? 'pending' : c.status)}</td>
                            <td><span class="text-muted small" style="max-width: 100px; display: inline-block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${c.reason || ''}">${c.reason || '-'}</span></td>
                            <td>${getActionButtons('clockin', c.id, c.approval_status, c)}</td>
                        </tr>`;
                    });
                    html += `</tbody></table>`;
                }

                // --- 🌴 請假紀錄 ---
                if (data.leaves.length > 0) {
                    html += `<h6 class="text-success fw-bold border-bottom border-success pb-1 mb-2">請假紀錄</h6>`;
                    html += `<table class="table table-sm table-dark table-hover mb-4">
                        <thead class="table-dark-header"><tr><th>員工</th><th>假別</th><th>時間區間</th><th>狀態</th><th>原因</th><th>操作</th></tr></thead><tbody>`;
                    data.leaves.forEach(l => {
                        const st = l.start_at ? l.start_at.substring(11,16) : '--:--';
                        const et = l.end_at ? l.end_at.substring(11,16) : '--:--';
                        html += `<tr>
                            <td>${l.user_name}</td>
                            <td>${l.leave_type}</td>
                            <td class="font-monospace text-secondary">${st} ~ ${et}</td>
                            <td>${getStatusBadge(l.status)}</td>
                            <td><span class="text-muted small" style="max-width: 100px; display: inline-block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${l.reason || ''}">${l.reason || '-'}</span></td>
                            <td>${getActionButtons('leave', l.id, l.status)}</td>
                        </tr>`;
                    });
                    html += `</tbody></table>`;
                }

                // --- ⏳ 加班紀錄 ---
                if (data.overtimes.length > 0) {
                    html += `<h6 class="fw-bold border-bottom pb-1 mb-2" style="color:#b19cd9; border-color:#b19cd9;">加班紀錄</h6>`;
                    html += `<table class="table table-sm table-dark table-hover mb-4">
                        <thead class="table-dark-header"><tr><th>員工</th><th>時間區間</th><th>時數</th><th>狀態</th><th>原因</th><th>操作</th></tr></thead><tbody>`;
                    data.overtimes.forEach(o => {
                        const st = o.start_at ? o.start_at.substring(11,16) : '--:--';
                        const et = o.end_at ? o.end_at.substring(11,16) : '--:--';
                        html += `<tr>
                            <td>${o.user_name}</td>
                            <td class="font-monospace text-secondary">${st} ~ ${et}</td>
                            <td class="fw-bold text-info">${o.hours}h</td>
                            <td>${getStatusBadge(o.status)}</td>
                            <td><span class="text-muted small" style="max-width: 100px; display: inline-block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${o.reason || ''}">${o.reason || '-'}</span></td>
                            <td>${getActionButtons('overtime', o.id, o.status)}</td>
                        </tr>`;
                    });
                    html += `</tbody></table>`;
                }

                if (html === '') {
                    html = '<div class="text-muted text-center py-5">本日無任何打卡、請假或加班紀錄。</div>';
                }

                // 2. 塞入 HTML 並呼叫 Bootstrap Modal 顯示
                document.getElementById('dayDetailBody').innerHTML = html;
                bsDayDetailModal.show();

            } catch (error) {
                // 如果發生預期外的錯誤，攔截並在網頁上跳出警告，避免毫無反應
                console.error("開啟明細視窗時發生錯誤：", error);
                alert("系統錯誤：無法開啟單日明細，請按 F12 查看 Console 錯誤訊息。");
            }
        }

    </script>
</body>
</html>