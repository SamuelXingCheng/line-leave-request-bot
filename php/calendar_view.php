<?php
require_once __DIR__ . '/config.php';
$liffId = getenv('CALENDAR_LIFF_ID');
// 加入版本號以強制清除快取
$ver = time(); 
if (!$liffId) { die("錯誤：請在 .env 檔案中設定 CALENDAR_LIFF_ID"); }
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>我的出勤行事曆</title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <style>
        :root {
            --primary-color: #06C755; 
            --bg-color: #F7F9FC;      
            --card-bg: #FFFFFF;
            --text-main: #2C3E50;
            --text-sub: #7F8C8D;
            --border-radius: 12px;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg-color);
            margin: 0; padding: 15px; color: var(--text-main);
        }

        #error-msg {
            background-color: #FFEBEE; color: #D32F2F; border: 1px solid #FFCDD2;
            padding: 10px; border-radius: 8px; font-size: 0.8rem; margin-bottom: 10px;
            display: none;
        }

        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .header h2 { margin: 0; font-size: 1.3rem; font-weight: 700; color: #34495E; }
        .nav-btn {
            background: #fff; border: 1px solid #eee; border-radius: 50%; width: 36px; height: 36px;
            font-size: 1rem; color: var(--text-sub); cursor: pointer;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: center;
        }

        .calendar-card {
            background: var(--card-bg); border-radius: var(--border-radius); 
            padding: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 20px;
        }
        .week-row, .days-grid { display: grid; grid-template-columns: repeat(7, 1fr); text-align: center; }
        .week-row { margin-bottom: 8px; }
        .week-day { font-size: 0.8rem; color: #B0B0B0; font-weight: 600; }
        
        .day-cell {
            min-height: 75px;
            border-radius: 8px; margin: 2px; 
            position: relative; 
            background-color: #fff;
            cursor: pointer;
        }

        /* 🔥 新增：週末背景色 */
        .day-cell.weekend { background-color: #F2F2F2; }
        
        .day-cell.today { background-color: #E3F2FD; font-weight: bold; border: 1px solid #90CAF9; }
        .day-cell.active { border: 2px solid var(--primary-color); }
        
        /* 🔥 修改：國定假日 cell 的數字層顏色 */
        .day-cell.holiday-cell .day-number-layer { color: #E74C3C; }

        .day-number-layer {
            position: absolute;
            top: 4px;
            right: 6px;
            font-size: 1.4rem;
            font-weight: 800;
            color: #333;
            line-height: 1;
            z-index: 10;
        }

        /* 🔥 新增：節日名稱小標籤 */
        .holiday-label {
            font-size: 0.6rem;
            color: #E74C3C;
            position: absolute;
            top: 22px;
            right: 6px;
            font-weight: bold;
            z-index: 11;
        }

        .day-content-layer {
            position: absolute;
            bottom: 6px;
            left: 0; right: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
        }
        
        .indicators-row { display: flex; gap: 4px; }

        .split-circle {
            width: 12px; height: 12px;
            display: flex; flex-direction: column;
            border-radius: 50%;
            overflow: hidden;
            background-color: #E0E0E0;
            flex-shrink: 0;
        }
        .split-circle.empty { background-color: transparent; }
        .half { width: 100%; height: 50%; }
        
        .bg-green { background-color: #2ECC71; } 
        .bg-orange { background-color: #F1C40F; } 
        .bg-blue { background-color: #3498DB; }   
        .bg-red { background-color: #E74C3C; }    
        .bg-none { background-color: transparent; }

        .ot-dot {
            width: 5px; height: 5px; border-radius: 50%;
            background-color: #9B59B6;
        }

        .legend { 
            display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; 
            margin-top: 15px; padding-top: 10px; border-top: 1px solid #f0f0f0;
            font-size: 0.7rem; color: var(--text-sub); 
        }
        .legend-item { display: flex; align-items: center; gap: 4px; }
        .legend-icon { width: 10px; height: 10px; border-radius: 50%; }
        
        .l-green { background-color: #2ECC71; }
        .l-blue { background-color: #3498DB; }
        .l-red { background-color: #E74C3C; }
        .l-orange { background-color: #F1C40F; }
        .l-purple { background-color: #9B59B6; width: 6px; height: 6px; }

        .details-section { display: none; animation: slideUp 0.2s ease-out; margin-top: 10px;}
        @keyframes slideUp { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        .details-title { font-size: 0.95rem; font-weight: bold; margin-bottom: 10px; color: var(--text-main); }
        
        .event-card {
            background: #fff; padding: 12px; border-radius: 10px;
            margin-bottom: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            display: flex; align-items: center; border-left: 4px solid #ccc;
        }
        .event-card.green { border-left-color: #2ECC71; }
        .event-card.red { border-left-color: #E74C3C; }
        .event-card.orange { border-left-color: #F1C40F; }
        .event-card.blue { border-left-color: #3498DB; }
        .event-card.purple { border-left-color: #9B59B6; }

        .event-info { font-size: 0.9rem; }
        .event-info span { font-size: 0.8rem; color: var(--text-sub); display: block; margin-top: 2px; }
        
        .event-time { 
            color: #333; font-weight: 600; margin-left: 5px; 
            font-family: monospace; background: #f0f0f0; 
            padding: 2px 6px; border-radius: 4px; font-size: 0.85rem;
        }

        #loading { text-align: center; color: var(--text-sub); padding: 20px; }
    </style>
</head>
<body>

<div id="error-msg"></div>

<div class="header">
    <button class="nav-btn" onclick="changeMonth(-1)">❮</button>
    <h2 id="currentMonth">Loading...</h2>
    <button class="nav-btn" onclick="changeMonth(1)">❯</button>
</div>

<div class="calendar-card">
    <div class="week-row">
        <div class="week-day">日</div><div class="week-day">一</div><div class="week-day">二</div>
        <div class="week-day">三</div><div class="week-day">四</div><div class="week-day">五</div><div class="week-day">六</div>
    </div>
    <div class="days-grid" id="calendarGrid"></div>
    
    <div class="legend">
        <div class="legend-item"><div class="legend-icon l-green"></div>正常</div>
        <div class="legend-item"><div class="legend-icon l-blue"></div>請假</div>
        <div class="legend-item"><div class="legend-icon l-orange"></div>補打卡</div>
        <div class="legend-item"><div class="legend-icon l-red"></div>待審核</div>
        <div class="legend-item"><div class="legend-icon l-purple"></div>加班</div>
    </div>
</div>

<div id="loading">讀取中...</div>

<div class="details-section" id="detailsSection">
    <div class="details-title" id="detailsDate"></div>
    <div id="detailsContent"></div>
</div>

<script>
    const LIFF_ID = "<?php echo $liffId; ?>"; 
    let currentYear = new Date().getFullYear();
    let currentMonth = new Date().getMonth() + 1;
    let userLineId = null;
    let currentEventData = {};

    window.onload = function() { initLiff(); };

    function initLiff() {
        if (!LIFF_ID) { showError("錯誤：無法讀取 LIFF ID"); return; }
        liff.init({ liffId: LIFF_ID }).then(() => {
            if (!liff.isLoggedIn()) { liff.login(); } 
            else { 
                liff.getProfile().then(profile => {
                    userLineId = profile.userId;
                    renderCalendar(currentYear, currentMonth);
                }).catch(err => showError(err));
            }
        }).catch(err => showError(err));
    }

    function showError(msg) {
        document.getElementById('error-msg').style.display = 'block';
        document.getElementById('error-msg').innerText = msg;
        document.getElementById('loading').style.display = 'none';
    }

    function changeMonth(offset) {
        currentMonth += offset;
        if (currentMonth > 12) { currentMonth = 1; currentYear++; }
        if (currentMonth < 1) { currentMonth = 12; currentYear--; }
        renderCalendar(currentYear, currentMonth);
    }

    async function renderCalendar(year, month) {
        document.getElementById('currentMonth').innerText = `${year}年 ${month}月`;
        document.getElementById('loading').style.display = 'block';
        document.getElementById('detailsSection').style.display = 'none';
        
        const grid = document.getElementById('calendarGrid');
        grid.innerHTML = '';

        try {
            if (!userLineId) throw new Error("等待使用者登入...");
            const apiUrl = `get_calendar_data.php?userId=${userLineId}&year=${year}&month=${month}&t=${Date.now()}`;
            const res = await fetch(apiUrl);
            let json;
            try { json = await res.json(); } catch (e) { throw new Error("API 格式錯誤"); }
            if (json.error) throw new Error(json.error);
            currentEventData = json;
        } catch (e) {
            console.error(e); showError(e.message); currentEventData = {};
        } finally {
            document.getElementById('loading').style.display = 'none';
        }

        const firstDay = new Date(year, month - 1, 1).getDay();
        const daysInMonth = new Date(year, month, 0).getDate();
        const today = new Date();

        for (let i = 0; i < firstDay; i++) grid.innerHTML += `<div></div>`;

        for (let day = 1; day <= daysInMonth; day++) {
            const dateObj = new Date(year, month - 1, day);
            const dayOfWeek = dateObj.getDay(); // 0(日) ~ 6(六)
            const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const events = currentEventData[dateStr] || [];
            
            // 🔥 判斷是否為國定假日或補班日
            const holidayInfo = events.find(e => e.type === 'holiday_info');
            const isHoliday = holidayInfo && holidayInfo.holiday_type === 'holiday';
            const isWorkday = holidayInfo && holidayInfo.holiday_type === 'workday';

            // 🔥 判斷週末樣式
            let weekendClass = (dayOfWeek === 0 || dayOfWeek === 6) ? 'weekend' : '';
            if (isWorkday) weekendClass = ''; // 補班日恢復一般樣式

            let workState = { up: 'bg-none', down: 'bg-none' };
            let leaveState = { up: 'bg-none', down: 'bg-none' };
            let hasOvertime = false;

            events.forEach(evt => {
                if (evt.type === 'attendance') {
                    let colorClass = 'bg-' + evt.color;
                    if (evt.desc === '上班') workState.up = colorClass;
                    else if (evt.desc === '下班') workState.down = colorClass;
                }
                if (evt.type === 'leave') {
                    if (evt.period === 'am') leaveState.up = 'bg-blue';
                    else if (evt.period === 'pm') leaveState.down = 'bg-blue';
                    else { leaveState.up = 'bg-blue'; leaveState.down = 'bg-blue'; }
                }
                if (evt.type === 'overtime') hasOvertime = true;
            });

            let leftCircleClass = '', leftCircleHtml = '';
            if (workState.up !== 'bg-none' || workState.down !== 'bg-none') {
                leftCircleHtml = `<div class="half ${workState.up}"></div><div class="half ${workState.down}"></div>`;
            } else { leftCircleClass = 'empty'; }

            let rightCircleClass = '', rightCircleHtml = '';
            if (leaveState.up !== 'bg-none' || leaveState.down !== 'bg-none') {
                rightCircleHtml = `<div class="half ${leaveState.up}"></div><div class="half ${leaveState.down}"></div>`;
            } else { rightCircleClass = 'empty'; }

            let otHtml = hasOvertime ? `<div class="ot-dot"></div>` : '';

            const isToday = (year === today.getFullYear() && (month - 1) === today.getMonth() && day === today.getDate());
            
            const holidayLabel = isHoliday ? `<div class="holiday-label">${holidayInfo.desc}</div>` : '';
            const holidayClass = isHoliday ? 'holiday-cell' : '';

            const cell = document.createElement('div');
            cell.className = `day-cell ${isToday ? 'today' : ''} ${weekendClass} ${holidayClass}`;
            
            cell.innerHTML = `
                <div class="day-number-layer">${day}</div>
                ${holidayLabel}
                <div class="day-content-layer">
                    <div class="indicators-row">
                        <div class="split-circle ${leftCircleClass}">${leftCircleHtml}</div>
                        <div class="split-circle ${rightCircleClass}">${rightCircleHtml}</div>
                    </div>
                    ${otHtml}
                </div>
            `;
            cell.onclick = () => showDetails(dateStr, events, cell);
            grid.appendChild(cell);
        }
    }

    function showDetails(dateStr, events, cellElement) {
        document.querySelectorAll('.day-cell').forEach(el => el.classList.remove('active'));
        cellElement.classList.add('active');

        const section = document.getElementById('detailsSection');
        const content = document.getElementById('detailsContent');
        document.getElementById('detailsDate').innerText = `${dateStr} 詳細紀錄`;
        content.innerHTML = '';

        // 排除掉僅用於前端顯示的 holiday_info
        const displayEvents = events.filter(e => e.type !== 'holiday_info');

        if (displayEvents.length === 0) {
            content.innerHTML = '<div style="color:#999; text-align:center; padding:20px;">本日無紀錄</div>';
        } else {
            const typeMap = { 'attendance': '打卡', 'leave': '請假', 'overtime': '加班' };
            const statusMap = {
                'green': '正常', 'red': '待審核', 
                'orange': '補打卡', 'blue': '請假', 'purple': '加班'
            };

            displayEvents.forEach(evt => {
                let desc = evt.desc ? ` (${evt.desc})` : '';
                let typeName = typeMap[evt.type] || evt.type;
                let statusText = statusMap[evt.color] || '正常';
                let timeDisplay = evt.time_info ? `<span class="event-time">${evt.time_info}</span>` : '';

                content.innerHTML += `
                    <div class="event-card ${evt.color}">
                        <div class="event-info">
                            <strong>${typeName}</strong>
                            <span>狀態：${statusText}${desc} ${timeDisplay}</span>
                        </div>
                    </div>
                `;
            });
        }
        section.style.display = 'block';
        section.scrollIntoView({ behavior: 'smooth' });
    }
</script>
</body>
</html>