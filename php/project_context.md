

---

```markdown
# 📖 LINE 請假出勤系統 - 專案架構說明書 (Project Context)

## 1. 系統概述與技術棧
* **專案名稱：** LINE 請假出勤機器人 (LINE Leave Request Bot)
* **核心技術：** 原生 PHP (無特定大型框架)、MySQL (使用 PDO)、LINE Messaging API、LINE Front-end Framework (LIFF)。
* **系統特色：** 具備「雙軌並行機制」，使用者可以透過 LIFF 網頁表單（Form）或是 LINE 聊天室文字指令（Text Command）來完成各項出勤申請。

---

## 2. 系統模組架構 (System Modules Breakdown)
專案依據業務邏輯與功能，拆解成以下 8 個主要模組：

### 🌴 1. 請假與餘額扣抵核心模組
處理員工發起請假、時數計算、防呆機制與特休/補休的扣抵。
* **LIFF / API 端：** `leave_form.php`, `leave_form_api.php` (含時間重疊防呆、時數計算、特休優先抵扣補休邏輯，並使用 `FOR UPDATE` 鎖定防止併發)
* **Bot 流程端：** `LeaveFlowHandler.php` (負責 LINE 聊天室內的文字請假引導)
* **簽核與撤回：** `ApprovalHandler.php` (主管簽核), `DeleteHandler.php` (撤回並退還時數)

### ✂️ 2. 銷假與變更時段模組
處理已核准假單的變更（包含延後開始、提早結束、中途銷假拆單及整筆註銷）。
* **LIFF / API 端：** `revoke_form.php`, `revoke_api.php` (含複雜拆單計算與防呆驗證)
* **Bot 流程端：** `RevokeHandler.php` 
* **簽核處理：** `ModificationApprovalHandler.php` (實作退還時數與重新計算的複雜邏輯)

### 💪 3. 加班與補休累計模組
處理加班申請、時數計算，並於核准後轉換為補休時數。
* **LIFF / API 端：** `overtime_form.php`, `overtime_api.php` (自動防堵重疊申請)
* **Bot 流程端：** `OvertimeFlowHandler.php`
* **簽核處理：** `OvertimeApprovalHandler.php` (核准後將時數加總至 `comp_leave_hours`)

### 📍 4. 出勤打卡與 GPS/QR 驗證模組
結合 GPS 定位與 QR Code 記錄員工上下班，及處理異常的補打卡。
* **打卡與驗證：** `gps_checkin.php`, `attendance_api.php` (計算與公司經緯度距離、範圍判定、QR Token 驗證)
* **補打卡機制：** `attendance_correction.php`, `attendance_correction_api.php`, `CorrectionHandler.php`
* **Bot 流程與簽核：** `AttendanceHandler.php`, `AttendanceApprovalHandler.php`

### 📅 5. 年度結轉與特休計算腳本模組
負責依據勞基法（曆年制/週年制）計算特休，及系統資料的批次維護。
* **排程與計算：** `cron_update_annual_leave.php` (內含 `calculateAnnualLeaveDaysStrict` 依到職日精算天數)
* **資料同步修復：** `sync_leave_balances.php`, `repair_leave_data.php` (確保 `users` 表的存摺數字正確)
* **報表與行事曆：** `export_year_report.php` (匯出 CSV 報表), `sync_holidays.php` (匯入人事行政總處行事曆)

### 👑 6. 簽核中心與 HR 後台管理模組
主管與管理員的專屬介面，用於批次審核與人事資料維護。
* **主管審核中心：** `supervisor_index.php`, `supervisor_api.php` (支援假單、變更單、加班單、打卡異常的批次處理)
* **HR 管理後台：** `admin_users.php`, `admin_api.php` (新增員工、設定 `user_supervisors` 組織架構、強制代簽核與離職封存)

### ⚙️ 7. 系統核心與共用基礎模組
驅動整個系統運作的底層架構。
* **入口與路由：** `index.php` (Webhook 進入點), `handlers.php` (路由分發器)
* **狀態與連線：** `Session.php` (基於 DB 的對話狀態機), `Db.php` (PDO 單例模式)
* **全域工具箱：** `utils.php`

### 🔍 8. 查詢與輔助功能模組
提供員工查看個人紀錄、圖表或同事狀態。
* **查詢紀錄：** `query_leave.php`, `LeaveQueryHandler.php`, `OvertimeQueryHandler.php`, `AttendanceQueryHandler.php`
* **查詢同事：** `query_colleagues.php`, `EmployeeQueryHandler.php`
* **個人行事曆：** `calendar_view.php`, `get_calendar_data.php` (統整打卡、請假、加班與國定假日於月曆顯示)

---

## 3. 雙軌並行機制 (Form 表單 vs Text 指令)
目前系統針對核心業務，同時保留了兩種操作介面，修改程式碼時需注意維持兩端邏輯的一致性（建議運算邏輯盡量收斂於 API 或 utils）：
* **請假申請：** `leave_form.php` / `leave_form_api.php` 🆚 `LeaveFlowHandler.php`
* **補打卡申請：** `attendance_correction.php` / `attendance_correction_api.php` 🆚 `CorrectionHandler.php`
* **加班申請：** `overtime_form.php` / `overtime_api.php` 🆚 `OvertimeFlowHandler.php`
* **假單變更與銷假：** `revoke_form.php` / `revoke_api.php` ➔ `RevokeHandler.php`

---

## 4. 給 AI 助手的開發與修改規範 (Coding Style & Guidelines)
當你需要新增或修改程式碼時，請嚴格遵守以下準則：
1. **資料庫操作：** 一律使用 PDO Prepared Statements (`?` 綁定參數) 來防止 SQL Injection。
2. **交易安全 (Transactions)：** 牽涉到「扣除休假餘額」或「更新假單狀態」等多表連動操作時，必須使用 `beginTransaction()` 與 `commit()`，並加入 `try...catch` 進行 `rollBack()`。高併發場景（如主管簽核）需使用 `FOR UPDATE` 進行悲觀鎖定。
3. **單一資料來源：** 時數計算邏輯（扣除午休、判斷假日）應收斂於 `utils.php`，避免重複定義。
4. **API 回傳格式：** 所有 `_api.php` 結尾的檔案，回傳格式必須為 JSON：`{"status": "success" | "error", "message": "...", "data": ...}`。

---

## 5. 核心依賴與檔案引入規範 (Core Dependencies)
AI 助手在建立或修改 PHP 檔案時，請嚴格遵守以下 `require_once` 與類別呼叫規範：

### 📌 必備的檔案引入清單
* **Webhook 處理器 (Handlers)：** 通常需要 `require_once __DIR__ . '/utils.php';` 與 `require_once __DIR__ . '/Session.php';`。
* **API 端點 (*_api.php)：** 必須在最上方引入 `require_once __DIR__ . '/config.php';` (用於載入環境變數) 與 `require_once __DIR__ . '/Db.php';`。

### ⚙️ 核心類別與物件實例化寫法
* **資料庫連線 (`Db.php`)**
  ```php
  $db = Database::getConnection(); // 取得 PDO 實例 (單例模式)

```

* **會話狀態管理 (`Session.php`)**
```php
$session = new UserSession($lineId);
$step =$session->getStep();      // 取得當前步驟
$session->setStep("next_step");   // 設定下一步
$session->clearStep();            // 清除對話步驟
$session->set("key", "value");    // 暫存資料
$session->get("key");             // 讀取暫存資料
$session->clear();                // 清空暫存資料 (保留 row)

```



---

## 6. 全域共用工具函式 (Global Utilities - utils.php)

AI 助手在撰寫或修改各個 Handler 時，嚴禁自行實作以下功能，必須直接呼叫對應的全域函式：

### 🟢 LINE 訊息發送相關

* `replyTextMessage($replyToken, $text)`: 快速回覆純文字訊息。
* `replyQuickReply($replyToken, $text, $items)`: 快速回覆帶有按鈕的訊息。
* `replyMessage($replyToken, $messageObjects)`: 傳送自訂格式的訊息。
* `pushMessage($to, $messageObjects)`: 主動推播訊息給特定 LINE ID。
* `createBusinessFlex($topStatus, $mainTitle, $dataPairs, $color = '#06C755')`: 產生系統統一的「商務版 Flex Message 卡片」陣列。

### 🕒 時數與休假計算

* `calculateHours($startStr, $endStr)`: 計算請假時數（回傳 Float）。內建跨日計算、自動排除假日與週末、並自動扣除午休 (12:00-13:00)。
* `calculateOvertimeHours($startStr, $endStr)`: 計算加班時數（回傳 Float）。若跨越 12:00-13:00 會自動扣除 1 小時。
* `getHolidayType($dateStr)`: 判斷特定日期狀態。回傳 `'holiday'`(放假), `'workday'`(補班), 或 `null`(無特殊)。
* `getLeaveSummary($userId)`: 取得員工休假存摺統計。回傳陣列包含 `entitledAnnual` (總特休), `remainingAnnual` (剩餘特休), `remainingComp` (剩餘補休) 等。

---

## 7. 資料庫結構 (Database Schema)

AI 助手在撰寫 SQL 查詢時，必須嚴格遵守以下欄位名稱，切勿自行發明欄位。

### 🧑‍💼 1. 員工與假別存摺 (`users`)

| 欄位名稱 | 型別 | 說明 |
| --- | --- | --- |
| `id` | INT (PK) | 內部流水號 |
| `user_id` | VARCHAR | **(重要)** 員工 LINE ID，主要關聯鍵 |
| `name` | VARCHAR | 員工姓名 |
| `start_date` | DATE | 到職日，用於計算特休年資 |
| `role` | VARCHAR | 角色權限 (`boss`, `employee`) |
| `is_archived` | TINYINT | 離職註記 (0: 在職, 1: 離職) |
| `entitled_annual_hours` | FLOAT | 本年度法定應得特休總時數 |
| `annual_leave_hours` | FLOAT | **剩餘特休時數** (動態扣抵) |
| `comp_leave_hours` | FLOAT | **剩餘補休時數** (動態扣抵) |
| `used_annual_hours` | FLOAT | 今年已用特休時數 |

### 📝 2. 請假申請主檔 (`leave_requests`)

| 欄位名稱 | 型別 | 說明 |
| --- | --- | --- |
| `id` | INT (PK) | 假單流水號 |
| `request_group_id` | VARCHAR | 假單群組 UUID，撤回時以此為準 |
| `user_id` | VARCHAR | 申請人的 LINE ID |
| `leave_type` | VARCHAR | 假別 (特休假, 補休假, 事假, 病假...) |
| `start_at` / `end_at` | DATETIME | 請假起訖時間 |
| `leave_hours` | FLOAT | 本次請假扣除的總時數 |
| `deduct_annual` | FLOAT | 本次實際扣抵的特休時數 |
| `deduct_comp` | FLOAT | 本次實際扣抵的補休時數 |
| `status` | VARCHAR | `pending`, `approved`, `rejected`, `cancelled` |

### ✍️ 3. 請假簽核明細 (`leave_approvals`)

| 欄位名稱 | 型別 | 說明 |
| --- | --- | --- |
| `request_id` | INT (FK) | 關聯至 `leave_requests.id` |
| `supervisor_id` | VARCHAR | 主管的 LINE ID |
| `status` | VARCHAR | `pending`, `approved`, `rejected` |

### ✂️ 4. 假單變更與銷假 (`leave_modifications`)

| 欄位名稱 | 型別 | 說明 |
| --- | --- | --- |
| `modification_uuid` | VARCHAR | 變更單 UUID |
| `leave_request_id` | INT (FK) | 關聯至原始假單 |
| `user_id` | VARCHAR | 申請人的 LINE ID |
| `type` | VARCHAR | `full_revoke`(全銷), `modify_range`(改時段), `split`(拆單) |
| `target_date` | VARCHAR | 變更目標時間字串 |

### 📍 5. 出勤打卡紀錄 (`attendance_logs`)

| 欄位名稱 | 型別 | 說明 |
| --- | --- | --- |
| `attendance_uuid` | VARCHAR | 打卡紀錄 UUID |
| `user_id` | VARCHAR | 打卡人的 LINE ID |
| `mode` | VARCHAR | `上班` 或 `下班` |
| `status` | VARCHAR | 系統判定: `success`, `fail`, `pending` |
| `approval_status` | VARCHAR | 主管審核: `normal`, `pending`, `approved`, `rejected` |

### 🕒 6. 加班申請單 (`overtime_requests`)

| 欄位名稱 | 型別 | 說明 |
| --- | --- | --- |
| `overtime_uuid` | VARCHAR | 加班單 UUID |
| `user_id` | VARCHAR | 申請人的 LINE ID |
| `start_at` / `end_at` | DATETIME | 加班起訖時間 |
| `hours` | FLOAT | 核准的加班時數 (將加至補休存摺) |
| `status` | VARCHAR | `pending`, `approved`, `rejected` |

### 🏢 7. 其他輔助資料表

* **`user_supervisors` (組織架構)：** `user_id`, `supervisor_id`
* **`holidays` (國定假日/補班)：** `date`, `name`, `type` (`holiday`, `workday`)
* **`sessions` (對話狀態機)：** `line_id`, `step`, `data`

```

```