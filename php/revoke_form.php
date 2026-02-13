<?php
require_once __DIR__ . '/config.php';
$liffId = getenv('REVOKE_LIFF_ID');
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>請假變更申請</title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <style>
        :root {
            --primary: #06C755;
            --danger: #dc3545;
            --text-main: #111111;
            --text-sub: #888888;
            --bg: #F5F6F8;
            --border: #E0E0E0;
            --radius: 8px;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            margin: 0; padding: 16px;
            -webkit-font-smoothing: antialiased;
        }
        
        /* 記得 Header 也要設為左右排版 */
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-start;
            margin-bottom: 20px; 
            padding: 0 4px; 
        }
        .header-text h1 { 
            font-size: 1.4rem; font-weight: 700; margin: 0; 
            color: var(--text-main); border-left: 4px solid var(--primary); 
            padding-left: 12px; line-height: 1.2; 
        }
        .header-text p { 
            margin: 6px 0 0 16px; font-size: 0.85rem; color: var(--text-sub); 
        }

        /* 🔥 請將這段 CSS 加進去，讓按鈕變成綠色文字 + 白色圓鈕 */
        .btn-back {
            text-decoration: none;
            font-size: 0.9rem;
            color: var(--primary); /* 這就是綠色文字的關鍵 */
            font-weight: 600;
            background: #fff;
            padding: 6px 12px;
            border-radius: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            white-space: nowrap;
            display: inline-block; /* 確保按鈕形狀正常 */
        }
        
        .btn-back:active {
            background-color: #f0fdf4; /* 點擊時有淡淡的綠色背景 */
            transform: translateY(1px);
        }

        .card { 
            background: #fff; border-radius: 12px; padding: 20px; margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04); border: 1px solid transparent;
        }
        .card.pending { border-left: 4px solid #ff9800; }
        .card.approved { border-left: 4px solid var(--primary); }

        .card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
        .leave-type { font-size: 1.1rem; font-weight: 700; color: var(--text-main); }
        .hours { font-family: monospace; font-size: 0.9rem; color: var(--text-sub); background: #f0f0f0; padding: 2px 6px; border-radius: 4px; }
        
        .status-tag { font-size: 0.75rem; padding: 3px 8px; border-radius: 4px; font-weight: 600; margin-left: 8px; vertical-align: middle; }
        .tag-pending { background-color: #fff3cd; color: #856404; }
        .tag-approved { background-color: #d4edda; color: #155724; }

        .date-range { font-size: 0.9rem; color: #555; margin-bottom: 16px; display: block; }
        .label { font-size: 0.8rem; color: #999; margin-right: 4px; }

        .btn-group { display: flex; gap: 10px; }
        .btn { 
            flex: 1; padding: 10px 0; border: 1px solid var(--border); background: #fff; 
            color: var(--text-main); border-radius: var(--radius); font-size: 0.9rem; 
            font-weight: 600; cursor: pointer; text-align: center;
        }
        .btn-outline { color: #555; border-color: #ccc; }
        .btn-danger-outline { color: var(--danger); border-color: #f5c6cb; }
        .btn-primary { background-color: var(--primary); color: white; border: none; box-shadow: 0 2px 6px rgba(6, 199, 85, 0.2); }

        /* Modal Styles */
        .modal-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 1000;
        }
        .modal-content {
            background: #fff; width: 90%; max-width: 360px; padding: 24px;
            border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            animation: slideUp 0.3s ease;
        }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        
        .modal-title { font-size: 1.2rem; font-weight: 700; margin-bottom: 20px; text-align: center; }
        
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 6px; font-size: 0.9rem; color: #333; font-weight: 500; }
        input[type="datetime-local"] { 
            width: 100%; padding: 12px; font-size: 1rem; border: 1px solid var(--border); 
            border-radius: var(--radius); box-sizing: border-box; -webkit-appearance: none; 
        }
        #loading { text-align: center; color: var(--text-sub); margin-top: 40px; }
    </style>
</head>
<body>

    <div class="header">
        <div class="header-text">
            <h1>請假變更與銷假</h1>
            <p>Leave Modification & Cancellation</p>
        </div>
        <a href="menu.php" class="btn-back">回選單</a>
    </div>

    <div id="loading">資料讀取中...</div>
    <div id="leaveList" style="display: none;"></div>

    <div id="modifyModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-title">調整請假時段</div>
            
            <input type="hidden" id="modLeaveId">
            <input type="hidden" id="modOriginalStart">
            <input type="hidden" id="modOriginalEnd">

            <div class="form-group">
                <label>新的起始時間</label>
                <input type="datetime-local" id="modNewStart">
            </div>

            <div class="form-group">
                <label>新的結束時間</label>
                <input type="datetime-local" id="modNewEnd">
            </div>

            <div class="btn-group" style="margin-top: 24px;">
                <button class="btn btn-outline" onclick="closeModal()">取消</button>
                <button class="btn btn-primary" onclick="submitModification()">確認變更</button>
            </div>
        </div>
    </div>

    <script>
        const LIFF_ID = "<?php echo $liffId; ?>";

        async function init() {
            try {
                await liff.init({ liffId: LIFF_ID });
                if (!liff.isLoggedIn()) { liff.login(); return; }
                loadLeaves();
            } catch (err) {
                alert("系統初始化失敗：" + err.message);
            }
        }

        async function loadLeaves() {
            try {
                const profile = await liff.getProfile();
                const res = await fetch(`revoke_api.php?action=list&userId=${profile.userId}`);
                const data = await res.json();
                
                const listDiv = document.getElementById('leaveList');
                document.getElementById('loading').style.display = 'none';
                listDiv.style.display = 'block';
                listDiv.innerHTML = "";

                if (!data.leaves || data.leaves.length === 0) {
                    listDiv.innerHTML = '<p style="text-align:center; color:#888; padding: 40px;">目前無可變更之紀錄</p>';
                    return;
                }

                data.leaves.forEach(leaf => {
                    const isPending = leaf.status === 'pending';
                    const startStr = leaf.start_at.substring(0, 16);
                    const endStr = leaf.end_at.substring(0, 16);
                    const statusTag = isPending ? `<span class="status-tag tag-pending">審核中</span>` : `<span class="status-tag tag-approved">已核准</span>`;

                    const buttons = isPending
                        ? `<button onclick="handleDelete('${leaf.request_group_id}')" class="btn btn-outline">撤回申請</button>`
                        : `<button onclick="handleFullRevoke('${leaf.id}')" class="btn btn-danger-outline">註銷假單</button>
                           <button onclick="openModifyModal('${leaf.id}', '${leaf.start_at}', '${leaf.end_at}')" class="btn btn-primary">變更時段</button>`;

                    listDiv.innerHTML += `
                        <div class="card ${isPending ? 'pending' : 'approved'}">
                            <div class="card-header">
                                <div><span class="leave-type">${leaf.leave_type}</span>${statusTag}</div>
                                <span class="hours">${leaf.leave_hours}h</span>
                            </div>
                            <span class="date-range"><span class="label">期間</span> ${startStr} ~ ${endStr}</span>
                            <div class="btn-group">${buttons}</div>
                        </div>
                    `;
                });
            } catch (e) {
                alert("讀取失敗：" + e.message);
            }
        }

        // --- 新版彈窗邏輯 ---
        function openModifyModal(id, start, end) {
            document.getElementById('modLeaveId').value = id;
            document.getElementById('modOriginalStart').value = start;
            document.getElementById('modOriginalEnd').value = end;

            // 預設填入原本的時間
            document.getElementById('modNewStart').value = start.replace(' ', 'T').substring(0, 16);
            document.getElementById('modNewEnd').value = end.replace(' ', 'T').substring(0, 16);
            
            document.getElementById('modifyModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('modifyModal').style.display = 'none';
        }

        async function submitModification() {
            const leaveId = document.getElementById('modLeaveId').value;
            const newStart = document.getElementById('modNewStart').value;
            const newEnd = document.getElementById('modNewEnd').value;

            if (!newStart || !newEnd) { alert("請完整填寫起始與結束時間"); return; }
            if (newStart >= newEnd) { alert("錯誤：結束時間必須晚於起始時間"); return; }

            if (!confirm("確定變更時段？")) return;

            const combinedDate = newStart.replace('T', ' ') + '~' + newEnd.replace('T', ' ');

            try {
                const profile = await liff.getProfile();
                const res = await fetch(`revoke_api.php?action=request_modification`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        userId: profile.userId,
                        leaveId: leaveId,
                        modType: 'modify_range',
                        targetDate: combinedDate
                    })
                });
                
                const result = await res.json();
                if (result.status === 'success') {
                    // 🔥【新增】: 使用 liff.sendMessages 發送訊息
                    if (result.forward_message) {
                        await liff.sendMessages(result.forward_message)
                            .then(() => {
                                alert("申請已送出！\n訊息已發送至聊天室，請轉傳給主管。");
                            })
                            .catch((err) => {
                                console.error(err);
                                alert("申請成功，但訊息發送失敗 (請確認是否從 LINE 開啟)。");
                            });
                    } else {
                        alert(result.message);
                    }
                    
                    closeModal();
                    loadLeaves();
                } else {
                    throw new Error(result.message);
                }
            } catch (e) {
                alert("申請失敗：" + e.message);
            }
        }

        async function handleDelete(groupId) { /* 同上 */
             if(!confirm("確定要撤回此申請？")) return;
             try {
                const res = await fetch(`revoke_api.php?action=delete&groupId=${groupId}`);
                const result = await res.json();
                alert(result.message);
                loadLeaves();
            } catch(e) { alert("失敗：" + e.message); }
        }
        async function handleFullRevoke(id) {
            if(!confirm("確定申請註銷？\n(需主管核准後退還時數)")) return;
            try {
                const profile = await liff.getProfile();
                const res = await fetch(`revoke_api.php?action=request_modification`, {
                    method: 'POST',
                    body: JSON.stringify({ 
                        userId: profile.userId, 
                        leaveId: id, 
                        modType: 'full_revoke', 
                        targetDate: '' 
                    })
                });
                const result = await res.json();
                
                if (result.status === 'success') {
                    // 🔥【新增】: 使用 liff.sendMessages 發送訊息
                    if (result.forward_message) {
                        await liff.sendMessages(result.forward_message)
                            .then(() => {
                                alert("註銷申請已送出！\n請將聊天室中的訊息轉傳給主管。");
                            })
                            .catch((err) => {
                                alert("申請成功，但訊息發送失敗。");
                            });
                    }
                    loadLeaves();
                } else {
                    alert(result.message);
                }
            } catch(e) { 
                alert("失敗：" + e.message); 
            }
        }

        init();
    </script>
</body>
</html>