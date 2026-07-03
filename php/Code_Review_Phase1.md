# 請假出勤系統 - 功能性程式碼審查報告

**審查日期：** 2025 年 7 月
**審查範圍：** `leave_form_api.php`、`LeaveFlowHandler.php`、`ApprovalHandler.php`、`DeleteHandler.php`、`utils.php`
**審查人員：** 系統架構師 / 品質保證工程師

---

## 系統功能邏輯現況評估

本系統以 PHP 實作一套 LINE Bot 驅動的請假出勤流程，涵蓋假單申請、主管簽核、假單撤回及時數管理。整體架構採用 Handler 責任分離的設計，具備基礎的 PDO 交易機制與會話狀態管理，顯示開發者對系統複雜度有一定的認識。

然而，在深入審查業務邏輯後，發現以下幾個結構性問題：

**一、雙軌並行架構導致功能語義分裂。** 系統同時存在兩套請假處理流程：`leave_form_api.php`（API 端）與 `LeaveFlowHandler.php`（Bot 對話端）。兩者對假別名稱的定義、時數計算邏輯、以及資料庫寫入欄位均存在差異，形成維護上的高風險地帶。

**二、核心時數計算函式重複定義且行為不一致。** `calculateHours()` 同時存在於 `utils.php`（全域函式）與 `LeaveFlowHandler.php`（私有方法），兩者在午休扣除邏輯上的邊界條件處理有所不同，可能導致 API 端與 Bot 端計算出的時數結果不同。

**三、資料一致性保護不完整。** 部分高風險操作（如主管簽核時的狀態更新）缺乏完整的 Transaction 包覆，在高併發情境下有產生競爭條件（Race Condition）的風險。

**四、邊界條件驗證過於鬆散。** 多處輸入驗證不足，包含日期格式、時數為零、以及跨年特休計算等場景均缺乏明確的防護機制。

整體穩定度評估：**中等偏低**，在功能正常使用路徑下可運作，但在邊界輸入或高頻操作下具有明顯的資料損毀風險。

---

## 嚴重邏輯缺陷與邊界條件遺漏 (Critical Priority)

### 1. [leave_form_api.php & LeaveFlowHandler.php] — 假別名稱不一致導致抵扣邏輯失效

**問題描述：**

`leave_form_api.php` 中判斷假別使用的是繁體中文全名（`"特休假"`、`"補休假"`），而 `LeaveFlowHandler.php` 中的 `getLeaveTypes()` 回傳的快捷回覆按鈕文字是縮寫（`"特休"`、`"補休"`），且 `finishLeaveRequest()` 中的 `if ($leaveType === "特休")` 也使用縮寫。

若使用者透過 Bot 流程申請「特休」，傳入 `leave_form_api.php` 的值將為 `"特休"`，而非 `"特休假"`，導致 `leave_form_api.php` 第 43 行的 `if ($leaveType === '特休假')` 條件永遠不成立，**補休優先抵扣邏輯完全失效，時數也不會被扣除，但假單卻會被正常建立**，造成餘額虛增。

**具體程式碼對照：**

```php
// leave_form_api.php (第43行) — 期望 "特休假"
if ($leaveType === '特休假') { ... }

// LeaveFlowHandler.php getLeaveTypes() — 實際回傳 "特休"
["特休假", "特休"],
["補休假", "補休"],

// LeaveFlowHandler.php finishLeaveRequest() — 也是用縮寫
if ($leaveType === "特休") { ... }
```

**修復建議：**

統一假別名稱為一個常數來源，建議在 `utils.php` 定義一組常數陣列，並在所有 Handler 中共用，消滅魔術字串。

---

### 2. [leave_form_api.php] — 補休抵扣邏輯在「扣額不足」時未完整驗證補休餘額

**問題描述：**

在第二個分支（`$currentComp > 0` 但不足以全額支付）中，程式正確設定了 `$deductComp` 與 `$deductAnnual`，並驗證了特休是否足夠，但**沒有驗證特休加補休合計是否等於 `$leaveHours`**。若出現資料異常（例如 `$currentComp` 因浮點數誤差變為負數），將導致扣款金額與請假時數不一致。

此外，當 `$leaveType` 不是 `"特休假"` 也不是 `"補休假"` 時（例如「病假」、「事假」），`$deductComp` 與 `$deductAnnual` 均為 `0`，**不會對任何餘額進行扣除**，這對「病假」等不需扣特休的假別是正確的；但如果未來加入「有薪事假」等需扣額的假別，此處將無法正確擴充，且**目前沒有任何明確的文件或防護說明哪些假別不需扣額**。

---

### 3. [leave_form_api.php & LeaveFlowHandler.php] — 時數為零或負數的假單可被正常寫入

**問題描述：**

`calculateHours()` 在傳入不合法的時間組合時（如開始時間等於結束時間、或選擇了假日/週末作為唯一的一天）會回傳 `0`。然而，**在 `leave_form_api.php` 中，沒有任何地方驗證 `$leaveHours > 0`**，程式會繼續向下執行，建立一筆 `leave_hours = 0` 的請假紀錄，並完成完整的流程（通知主管、產生簽核連結）。

同樣地，`LeaveFlowHandler.php` 的 `finishLeaveRequest()` 中對 `$requestHours` 有一個 `$requestHours > 0` 的判斷，但這個判斷僅用於是否觸發補休自動切換，**並非阻止零時數假單被寫入的守門邏輯**。

**修復建議：**

在任何 INSERT 操作執行前，加入明確的前置驗證：

```php
if ($leaveHours <= 0) {
    throw new Exception("請假時數計算結果為零，請確認所選日期與時間是否為有效的工作日及時段。");
}
```

---

### 4. [LeaveFlowHandler.php] — 兩套計算函式的午休扣除邏輯邊界不同

**問題描述：**

`utils.php` 的 `calculateHours()` 使用字串比較來判斷是否需要扣除午休：

```php
// utils.php
if ($s < $lunchStart && $e > $lunchEnd) { $dayHours -= 1; }
// 條件：開始時間 < 12:00 且 結束時間 > 13:00
```

`LeaveFlowHandler.php` 的私有方法使用完全相同的條件，但若使用者請假時間為 `12:00 ~ 13:30`，條件為 `"12:00" < "12:00"` 結果為 `false`，正確地**不扣除**午休（此時段並未跨越午休）。然而若請假時間為 `11:30 ~ 13:00`，條件為 `"11:30" < "12:00"` 為 `true`，但 `"13:00" > "13:00"` 為 `false`，因此**不扣除午休**，但實際上請假時段完整包含了午休 12:00 到 13:00 的一個小時，邏輯上應當扣除。

**邊界條件缺陷：** 結束時間恰好等於 `13:00` 時，應扣除午休但實際未扣除，導致時數計算多出一小時。

**修復建議：** 將條件修正為 `>=`：

```php
if ($s < $lunchStart && $e >= $lunchEnd) { $dayHours -= 1; }
```

---

### 5. [ApprovalHandler.php] — 簽核流程缺乏 Transaction 保護，存在高併發競爭條件

**問題描述：**

`handleApproveLeave()` 方法中，對每一筆 `leave_request` 的處理包含三個步驟：

1. `UPDATE leave_approvals SET status = 'approved'`
2. `SELECT COUNT(*) FROM leave_approvals WHERE status != 'approved'`（查詢是否全部簽核）
3. `UPDATE leave_requests SET status = 'approved'`

**這三個操作沒有被 Transaction 包覆**，也沒有使用 `SELECT ... FOR UPDATE` 進行悲觀鎖定。若兩位主管在極短時間內同時點擊簽核連結，可能發生以下競爭條件：

- 主管 A 與主管 B 同時執行步驟 2，兩者都查到剩餘未核准數為 `1`（對方尚未更新）
- 兩者都進入步驟 3，導致 `leave_requests.status` 被更新兩次
- 可能觸發兩次 `sendApprovalNotificationToApplicant()`，員工收到重複通知
- 在更複雜的系統中（若步驟 3 會觸發扣額），可能造成重複扣款

---

### 6. [DeleteHandler.php] — 刪除時僅驗證第一筆的狀態，群組假單存在部分狀態不一致的風險

**問題描述：**

`handleDeleteRequest()` 中使用 `foreach` 迴圈驗證每一筆 `$row` 的 `status !== "pending"`，並且在驗證不通過時立即 `return`。但在迴圈進入交易之前，如果存在一個 `request_group_id` 對應多筆假單（例如一個多日請假被拆成多筆 `leave_requests` 記錄），且其中某幾筆已 `approved`、某幾筆仍 `pending`，則：

**系統會拒絕整個刪除操作，但錯誤訊息為「此請假已簽核完成，無法撤回」**，這對使用者而言並不準確（實際上是部分簽核）。更嚴重的是，這個驗證迴圈在 `beginTransaction()` 之前執行，若中途有另一個請求插入，驗證通過後的資料狀態可能已改變（TOCTOU，Time-of-Check to Time-of-Use 漏洞）。

**修復建議：**

將狀態驗證與刪除操作放在同一個 Transaction 內，並使用 `SELECT ... FOR UPDATE` 鎖定要刪除的紀錄：

```sql
SELECT * FROM leave_requests WHERE request_group_id = ? FOR UPDATE
```

---

### 7. [utils.php] — `calculateAnnualLeaveDays()` 跨年計算存在邏輯謬誤

**問題描述：**

函式中計算 `$ratioBefore` 時，`$daysInAnniversaryMonth` 使用的是**目標年度**（`$targetYear`）的到職月份天數：

```php
$daysInAnniversaryMonth = (int)date('t', strtotime("$targetYear-$hireMonth-01"));
```

然而，計算的是「週年紀念日前」的比例，邏輯上應使用**到職年份**的月份天數，或以工作年度的實際月份為基準。對於 2 月份（28 天或 29 天，閏年影響），以不同年份計算會產生不同的比例結果，影響特休天數計算精確度，尤其在員工 2 月到職時更為顯著。

---

## 狀態與資料一致性風險 (Medium Priority)

### 1. [LeaveFlowHandler.php] — `finishLeaveRequest()` 未包含時數扣除邏輯

**問題描述：**

`LeaveFlowHandler.php` 的 `finishLeaveRequest()` 在執行 `INSERT INTO leave_requests` 時，**完全沒有對 `users` 表執行任何時數扣除的 UPDATE 操作**，也沒有寫入 `deduct_annual` 與 `deduct_comp` 欄位（這兩個欄位是 `leave_form_api.php` 中定義的）。

這意味著透過 Bot 對話申請的假單，不會即時扣除員工的特休或補休餘額，而 `leave_form_api.php` 的 API 流程則會即時扣除。兩套流程的財務語義完全不同，若兩者同時存在且皆有使用者使用，餘額將持續不準確。

**修復建議：**

統一由一個函式或 API 處理時數扣除，`LeaveFlowHandler.php` 應改為呼叫 `leave_form_api.php` 的 API 端點，而非直接寫入資料庫，以確保業務邏輯僅有一個信任來源（Single Source of Truth）。

---

### 2. [ApprovalHandler.php] — 主管簽核後無法驗證假單是否已被申請人撤回

**問題描述：**

`handleApproveLeave()` 查詢條件為 `la.status = 'pending'`，這個條件針對的是 `leave_approvals` 表的狀態，而非 `leave_requests` 表的狀態。若申請人在主管點擊簽核前已透過 `/刪除請假` 將假單撤回（此時 `leave_requests` 記錄已被刪除），`LEFT JOIN` 的結果會因為外鍵關聯的 `leave_requests` 記錄不存在而返回空集合，系統會回覆「找不到此請假單」。

雖然這個行為在結果上是正確的（已刪除的假單不應被簽核），但若 `leave_approvals` 記錄因 `DeleteHandler.php` 中刪除順序的問題而仍然存在，則可能出現「簽核了一筆已被撤回的假單」的孤兒資料問題。

**修復建議：**

在查詢條件中明確加入 `lr.status = 'pending'` 的限制，確保只有狀態為待審核的假單才能被簽核：

```sql
WHERE lr.request_group_id = ? AND la.supervisor_id = ? 
  AND la.status = 'pending' AND lr.status = 'pending'
```

---

### 3. [DeleteHandler.php] — `reloadList()` 依賴 Session 但 Session 建構子缺少 `$db` 參數

**問題描述：**

`reloadList()` 中建立 `UserSession` 物件時僅傳入 `$this->userId`：

```php
$session = new UserSession($this->userId);
```

但根據 `LeaveFlowHandler.php` 的建構子 `new UserSession($lineId, $db)`，`UserSession` 類別應需要兩個參數（`$lineId` 和 `$db`）。缺少 `$db` 參數會導致 `reloadList()` 在執行時拋出 PHP 錯誤，使刪除成功後的列表重新整理功能完全失效。

---

### 4. [utils.php] — `replyQuickReply()` 的 Label 長度未驗證，可能導致 LINE API 拒絕請求

**問題描述：**

LINE Messaging API 規定 Quick Reply 按鈕的 `label` 欄位最多 **20 個字元**。`LeaveFlowHandler.php` 中部分按鈕標籤（如 `"整天 (08:30-17:30)"` 共 16 字、`"選擇結束日期"` 共 7 字）目前在限制內，但如果未來有開發者加入更長的標籤，`replyQuickReply()` 不會有任何攔截，導致 LINE API 回傳 `400 Bad Request`，且錯誤只會被記錄在 `error_log` 中，使用者端不會收到任何提示。

---

### 5. [leave_form_api.php] — 浮點數比較可能導致補休抵扣邊界條件失準

**問題描述：**

`$currentComp >= $leaveHours` 使用直接的浮點數比較。在 PHP 中，浮點數運算存在精度問題，例如 `4.1 - 0.1` 的結果可能為 `3.9999999...` 而非 `4.0`。若員工的補休餘額因多次加減運算後存在微小的浮點誤差，可能出現「補休理論上夠用，但比較結果判定為不足」的情況，錯誤地進入混合扣除邏輯，影響員工的補休與特休餘額。

**修復建議：**

使用整數化（將時數乘以 100 存為整數分）或設定一個允許的誤差範圍：

```php
const FLOAT_EPSILON = 0.001;
if (($currentComp - $leaveHours) >= -self::FLOAT_EPSILON) {
    // 視為 currentComp >= leaveHours
}
```

---

### 6. [utils.php / 全系統] — 缺乏時區明確設定，`NOW()` 與 PHP `date()` 可能產生時差

**問題描述：**

資料庫 SQL 中大量使用 `NOW()` 記錄時間戳，而 PHP 程式碼中使用 `date()` 與 `strtotime()` 進行計算。若 MySQL 伺服器的時區設定（`time_zone`）與 PHP 的 `date.timezone`（`php.ini`）不一致（例如一個為 UTC，另一個為 Asia/Taipei），`created_at` 的記錄時間將與 PHP 計算出的請假時段產生 **8 小時**的系統性偏差，導致跨日假單的時數計算錯誤。

**修復建議：**

在 `Db.php` 建立連線後立即設定時區，並確保 `php.ini` 中也有明確設定：

```php
// Db.php
$pdo->exec("SET time_zone = '+08:00'");
```

```ini
; php.ini
date.timezone = "Asia/Taipei"
```

---

## 具體邏輯修復範例

### 範例一：修正 `ApprovalHandler.php` — 加入 Transaction 保護與防重複簽核

以下為針對「高併發競爭條件」問題的修正版本，核心修改包含：Transaction 包覆、`FOR UPDATE` 悲觀鎖定、以及明確的假單存活狀態驗證。

```php
// ApprovalHandler.php — handleApproveLeave() 修正版

private function handleApproveLeave($groupId) {

    try {
        // 1. 開啟 Transaction，並使用 FOR UPDATE 鎖定相關紀錄，防止競爭條件
        $this->db->beginTransaction();

        $stmt = $this->db->prepare("
            SELECT lr.id, lr.user_id, lr.user_name, lr.reason, lr.leave_type,
                   lr.start_at, lr.end_at, la.status AS approval_status
            FROM leave_requests lr
            JOIN leave_approvals la ON lr.id = la.request_id
            WHERE lr.request_group_id = ?
              AND la.supervisor_id = ?
              AND la.status = 'pending'
              AND lr.status = 'pending'
            FOR UPDATE
        ");
        $stmt->execute([$groupId, $this->lineId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            // Transaction 內查不到資料，表示已被其他人簽核或假單已撤回
            $this->db->rollBack();
            replyTextMessage(
                $this->replyToken,
                "【系統提示】找不到此請假單（編號 {$groupId}），" .
                "可能已完成簽核、遭申請人撤回，或您無此單的簽核權限。"
            );
            return;
        }

        $updateStmt = $this->db->prepare("
            UPDATE leave_approvals
            SET status = 'approved', updated_at = NOW()
            WHERE supervisor_id = ? AND request_id = ? AND status = 'pending'
        ");

        $approvedCount = 0;
        $lastRow       = null;
        $fullyApprovedIds = []; // 記錄本次已全員簽核完成的 request_id

        foreach ($rows as $row) {
            // 使用帶有 status = 'pending' 條件的 UPDATE，作為樂觀鎖二次確認
            $updateStmt->execute([$this->lineId, $row['id']]);

            // 若 rowCount() 為 0，表示已被其他 session 搶先更新
            if ($updateStmt->rowCount() === 0) {
                continue;
            }

            $approvedCount++;
            $lastRow = $row;

            // 2. 檢查此 request_id 的所有 approval 是否全部核准
            $checkStmt = $this->db->prepare("
                SELECT COUNT(*)
                FROM leave_approvals
                WHERE request_id = ? AND status != 'approved'
            ");
            $checkStmt->execute([$row['id']]);
            $remaining = (int)$checkStmt->fetchColumn();

            if ($remaining === 0) {
                $fullyApprovedIds[] = $row['id'];
                // 3. 將假單狀態正式更新為 approved
                $this->db->prepare(
                    "UPDATE leave_requests SET status = 'approved' WHERE id = ? AND status = 'pending'"
                )->execute([$row['id']]);
            }
        }

        // 4. 提交 Transaction — 所有操作原子性完成
        $this->db->commit();

        // 5. Transaction 外才執行推播（推播失敗不應影響資料庫狀態）
        foreach ($fullyApprovedIds as $rid) {
            $targetRow = array_values(array_filter($rows, fn($r) => $r['id'] === $rid))[0] ?? null;
            if ($targetRow) {
                $this->sendApprovalNotificationToApplicant($targetRow);
            }
        }

        // 6. 回覆主管
        if ($lastRow && $approvedCount > 0) {
            $startStr = date('Y-m-d H:i', strtotime($lastRow['start_at']));
            $endStr   = date('Y-m-d H:i', strtotime($lastRow['end_at']));

            $managerFlex = createBusinessFlex(
                "APPROVED",
                "請假簽核成功",
                [
                    "申請員工" => $lastRow['user_name'],
                    "假別"     => $lastRow['leave_type'],
                    "開始時間" => $startStr,
                    "結束時間" => $endStr,
                    "簽核筆數" => "共 {$approvedCount} 筆",
                    "簽核狀態" => "已核准 (Approved)"
                ],
                "#06C755"
            );
            replyFlexMessage($this->replyToken, $managerFlex);
        } else {
            replyTextMessage($this->replyToken, "【系統提示】此假單已由其他主管完成簽核，無需重複操作。");
        }

    } catch (Exception $e) {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        error_log("ApprovalHandler Error: " . $e->getMessage());
        replyTextMessage($this->replyToken, "【系統錯誤】簽核作業失敗，請稍後再試。");
    }
}
```

---

### 範例二：修正 `leave_form_api.php` — 加入時數零值防護與浮點數安全比較

以下為針對「零時數假單寫入」與「浮點數比較失準」問題的修正版本，在補休抵扣邏輯執行前加入完整的前置驗證守門。

```php
// leave_form_api.php — 核心驗證段落修正版

// 3. 計算請假時數
$startAt = $input['startDate'] . ' ' . $input['startTime'];
$endAt   = $input['endDate']   . ' ' . $input['endTime'];

// [修正] 驗證時間格式，防止 strtotime() 靜默失敗
if (!strtotime($startAt) || !strtotime($endAt)) {
    throw new Exception("時間格式無效，請確認日期與時間的輸入格式是否正確。");
}

if (strtotime($endAt) <= strtotime($startAt)) {
    throw new Exception("結束時間必須晚於開始時間。");
}

$leaveHours = calculateHours($startAt, $endAt);

// [新增] 零時數守門：防止假日、週末、或無效時段產生零時數假單
if ($leaveHours <= 0) {
    throw new Exception(
        "請假時數計算結果為零，所選時段可能為假日、週末或非工作時間，" .
        "請確認日期設定後重新申請。"
    );
}

// [修正] 以整數化方式儲存時數（乘以 100 避免浮點精度問題）
// 注意：資料庫欄位需對應調整，或在比較前使用以下 EPSILON 方法
const FLOAT_EPSILON = 0.001;

$leaveType    = $input['leaveType'];
$reason       = trim($input['reason'] ?? '');
$finalLeaveType = $leaveType;
$deductComp   = 0;
$deductAnnual = 0;
$systemNote   = "";

// [修正] 假別名稱標準化：接受縮寫與全名兩種格式
$leaveTypeNormalized = str_replace(['特休', '補休'], ['特休假', '補休假'], $leaveType);

if ($leaveTypeNormalized === '特休假') {
    // [修正] 使用 EPSILON 安全比較取代直接浮點數比較
    if (($currentComp - $leaveHours) >= -FLOAT_EPSILON) {
        // 補休夠扣：全扣補休
        $deductComp     = $leaveHours;
        $finalLeaveType = "補休假 (特休轉用)";
        $systemNote     = "(系統：優先抵扣補休 {$leaveHours} 小時)";

    } elseif ($currentComp > FLOAT_EPSILON) {
        // 補休不夠：先扣光補休，剩下扣特休
        $deductComp     = $currentComp;
        $deductAnnual   = round($leaveHours - $currentComp, 4); // 防止浮點殘差
        $finalLeaveType = "特休 / 補休混用";
        $systemNote     = "(系統：抵扣補休 {$deductComp} 小時，特休 {$deductAnnual} 小時)";

    } else {
        // 無補休：全扣特休
        $deductAnnual = $leaveHours;
    }

    // [修正] 加入合計驗證，確保