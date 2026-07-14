<?php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/utils.php';

class ModificationApprovalHandler {
    private $lineId;
    private $text;
    private $db;
    private $replyToken;

    public function __construct($lineId, $text, $replyToken = null) {
        $this->lineId = $lineId;
        $this->text = trim($text);
        $this->replyToken = $replyToken;
        $this->db = Database::getConnection();
    }

    public function handle() {
        if (strpos($this->text, '/同意銷假') !== 0) return false;

        $parts = explode(' ', $this->text);
        if (!isset($parts[1])) return false;
        $uuid = trim($parts[1]);

        $resultMsg = $this->approveModification($uuid);
        
        if ($this->replyToken) {
            // LINE 機器人模式：直接回覆訊息
            replyTextMessage($this->replyToken, $resultMsg);
        } else {
            // 🔥 網頁 API 模式：遇到錯誤字串必須拋出例外，讓 API 知道這筆失敗了
            if (strpos($resultMsg, '⚠️') !== false) {
                throw new Exception($resultMsg);
            }
        }
        return true;
    }

    private function generateUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function approveModification($uuid) {
        $stmtEarly = $this->db->prepare("SELECT * FROM leave_modifications WHERE modification_uuid = ?");
        $stmtEarly->execute([$uuid]);
        $modEarly = $stmtEarly->fetch(PDO::FETCH_ASSOC);
        if (!$modEarly || $modEarly['status'] !== 'pending') return "⚠️ 此申請不存在或已處理過。";

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT * FROM leave_modifications WHERE modification_uuid = ? FOR UPDATE");
            $stmt->execute([$uuid]);
            $mod = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$mod || $mod['status'] !== 'pending') {
                $this->db->rollBack();
                return "⚠️ 此申請不存在或已處理過。";
            }

            $stmt = $this->db->prepare("SELECT * FROM leave_requests WHERE id = ? FOR UPDATE");
            $stmt->execute([$mod['leave_request_id']]);
            $original = $stmt->fetch(PDO::FETCH_ASSOC);

            // 🔥 新增檢查狀態是否為 approved
            if (!$original || $original['status'] !== 'approved') {
                $this->db->rollBack();
                return "⚠️ 原始假單不存在或狀態不可修改（可能已註銷或仍在審核中）。";
            }

            // 🔥 新增：老闆特權判斷
            $bossStmt = $this->db->prepare("SELECT role FROM users WHERE user_id = ?");
            $bossStmt->execute([$this->lineId]);
            $isBoss = ($bossStmt->fetchColumn() === 'boss');

            $authStmt = $this->db->prepare("
                SELECT COUNT(*) FROM user_supervisors
                WHERE user_id = ? AND supervisor_id = ?
            ");
            $authStmt->execute([$original['user_id'], $this->lineId]);
            $isSupervisor = ((int)$authStmt->fetchColumn() > 0);

            // 必須兩者皆非，才阻擋權限
            if (!$isSupervisor && !$isBoss) {
                $this->db->rollBack();
                return "⚠️ 您沒有核准此申請的權限。";
            }

            // ==========================================
            // 情境 A: 整筆銷假 (Full Revoke)
            // ==========================================
            if ($mod['type'] === 'full_revoke') {
                $refundAnnual = floatval($original['deduct_annual'] ?? 0);
                $refundComp   = floatval($original['deduct_comp'] ?? 0);

                if ($refundAnnual > 0 || $refundComp > 0) {
                    $refundStmt = $this->db->prepare("UPDATE users SET annual_leave_hours = annual_leave_hours + ?, comp_leave_hours = comp_leave_hours + ? WHERE user_id = ?");
                    $refundStmt->execute([$refundAnnual, $refundComp, $original['user_id']]);
                }

                $updateReq = $this->db->prepare("UPDATE leave_requests SET leave_hours = 0, deduct_annual = 0, deduct_comp = 0, status = 'cancelled', reason = CONCAT(reason, ' (已註銷)') WHERE id = ?");
                $updateReq->execute([$original['id']]);
                $this->db->prepare("UPDATE leave_modifications SET status = 'approved' WHERE id = ?")->execute([$mod['id']]);
                
                $this->db->commit();
                
                $flexMsg = [
                    "type" => "flex",
                        "altText" => "【系統通知】假單已註銷",
                    "contents" => [
                        "type" => "bubble",
                        "size" => "kilo",
                        "header" => [
                            "type" => "box",
                            "layout" => "vertical",
                                "backgroundColor" => "#DC3545",
                            "paddingAll" => "lg",
                            "contents" => [
                                    ["type" => "text", "text" => "假單註銷核准", "weight" => "bold", "size" => "lg", "color" => "#ffffff"]
                            ]
                        ],
                        "body" => [
                            "type" => "box",
                            "layout" => "vertical",
                            "contents" => [
                                    ["type" => "text", "text" => "您的銷假申請主管已核准。", "size" => "sm", "color" => "#666666", "wrap" => true],
                                ["type" => "separator", "margin" => "md"],
                                ["type" => "box", "layout" => "vertical", "margin" => "md", "spacing" => "sm", "contents" => [
                                        $this->buildRow("假單狀態", "已註銷 (作廢)"),
                                        $this->buildRow("退還時數", "全額退還"),
                                ]]
                            ]
                        ],
                        "footer" => [
                            "type" => "box", "layout" => "vertical", "contents" => [
                                    ["type" => "text", "text" => "系統已自動更新您的休假餘額。", "size" => "xs", "color" => "#aaaaaa", "align" => "center"]
                            ]
                        ]
                    ]
                ];
                pushMessage($original['user_id'], $flexMsg);

                    return "已核准：假單已註銷，時數已退還。";
            }

            // ==========================================
            // 情境 C: 中途銷假拆單 (Split)
            // ==========================================
            if ($mod['type'] === 'split') {
                $returnDate = $mod['target_date'];

                $part1Start = $original['start_at'];
                $part1End   = date('Y-m-d', strtotime($returnDate . ' -1 day')) . ' 17:30:00';

                $part2Start = $returnDate . ' 08:30:00';
                $part2End   = $original['end_at'];

                if (strtotime($returnDate) <= strtotime(substr($original['start_at'], 0, 10))) {
                    $this->db->rollBack();
                    return "⚠️ 回來日期不得早於或等於原始假單的開始日期，請重新申請。";
                }
                if ($returnDate === substr($original['end_at'], 0, 10)) {
                    $this->db->rollBack();
                    return "⚠️ 回來日期與假單結束日期相同，請改用整筆銷假功能。";
                }

                if (strtotime($returnDate) > strtotime(substr($original['end_at'], 0, 10))) {
                    $this->db->rollBack();
                    return "⚠️ 回來日期已超過原始假單的結束日期，若要全額銷假請改用整筆銷假功能。";
                }

                $part1Hours = calculateHours($part1Start, $part1End);

                if ($part1Hours <= 0) {
                    $this->db->rollBack();
                    return "⚠️ 拆單後保留段時數為零或負值，請確認回來日期是否合理。";
                }

                $part2Hours = calculateHours($part2Start, $part2End);

                if ($part2Hours <= 0) {
                    $this->db->rollBack();
                    return "⚠️ 拆單後銷假段時數為零或負值，請確認回來日期是否合理。";
                }

                $oldDeductAnnual = floatval($original['deduct_annual'] ?? 0);
                $oldDeductComp   = floatval($original['deduct_comp'] ?? 0);
                    $this->db->prepare("UPDATE users SET annual_leave_hours = annual_leave_hours + ?, comp_leave_hours = comp_leave_hours + ? WHERE user_id = ?")
                        ->execute([$oldDeductAnnual, $oldDeductComp, $original['user_id']]);

                $stmtUser = $this->db->prepare("SELECT annual_leave_hours, comp_leave_hours FROM users WHERE user_id = ? FOR UPDATE");
                $stmtUser->execute([$original['user_id']]);
                    $userBalance    = $stmtUser->fetch(PDO::FETCH_ASSOC);
                    $currentComp    = floatval($userBalance['comp_leave_hours']);
                    $part1DeductAnnual = 0;
                    $part1DeductComp   = 0;

                    if (strpos($original['leave_type'], '特休') !== false) {
                        if ($currentComp >= $part1Hours) {
                            $part1DeductComp = $part1Hours;
                        } else {
                            $part1DeductComp   = $currentComp;
                            $part1DeductAnnual = $part1Hours - $currentComp;
                        }
                    } elseif (strpos($original['leave_type'], '補休') !== false) {
                        $part1DeductComp = $part1Hours;
                    }

                    if ($part1DeductComp > 0 || $part1DeductAnnual > 0) {
                        $this->db->prepare("UPDATE users SET annual_leave_hours = annual_leave_hours - ?, comp_leave_hours = comp_leave_hours - ? WHERE user_id = ?")
                            ->execute([$part1DeductAnnual, $part1DeductComp, $original['user_id']]);
                    }

                    $this->db->prepare("UPDATE leave_requests SET start_at = ?, end_at = ?, leave_hours = ?, deduct_annual = ?, deduct_comp = ?, reason = CONCAT(reason, ' (拆單-保留段)') WHERE id = ?")
                        ->execute([$part1Start, $part1End, $part1Hours, $part1DeductAnnual, $part1DeductComp, $original['id']]);

                    $part2GroupId = $this->generateUuid();

                    $this->db->prepare("
                        INSERT INTO leave_requests
                            (request_group_id, user_id, user_name, leave_type, reason,
                            start_at, end_at, leave_hours, deduct_annual, deduct_comp, status, created_at)
                        VALUES
                            (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 'cancelled', NOW())
                    ")->execute([
                        $part2GroupId,
                        $original['user_id'],
                        $original['user_name'],
                        $original['leave_type'],
                        '中途銷假拆單（已註銷）',
                        $part2Start,
                        $part2End,
                        $part2Hours,
                    ]);
                    $this->db->prepare("UPDATE leave_modifications SET status = 'approved' WHERE id = ?")
                        ->execute([$mod['id']]);

                $this->db->commit();
                
                    $displayP1Start = substr($part1Start, 0, 16);
                    $displayP1End   = substr($part1End,   0, 16);
                    $displayP2Start = substr($part2Start, 0, 16);
                    $displayP2End   = substr($part2End,   0, 16);
                
                $flexMsg = [
                    "type" => "flex",
                        "altText" => "【系統通知】中途銷假拆單核准",
                    "contents" => [
                        "type" => "bubble",
                        "size" => "kilo",
                        "header" => [
                            "type" => "box",
                            "layout" => "vertical",
                                "backgroundColor" => "#FF8C00",
                            "paddingAll" => "lg",
                            "contents" => [
                                    ["type" => "text", "text" => "中途銷假拆單核准", "weight" => "bold", "size" => "lg", "color" => "#ffffff"]
                            ]
                        ],
                        "body" => [
                            "type" => "box",
                            "layout" => "vertical",
                            "contents" => [
                                    ["type" => "text", "text" => "您的中途銷假申請主管已核准，假單已拆分如下。", "size" => "sm", "color" => "#666666", "wrap" => true],
                                    ["type" => "separator", "margin" => "md"],
                                    ["type" => "text", "text" => "✅ 保留段（請假中）", "size" => "sm", "weight" => "bold", "margin" => "md", "color" => "#06C755"],
                                    ["type" => "box", "layout" => "vertical", "margin" => "sm", "spacing" => "sm", "contents" => [
                                        $this->buildRow("開始", $displayP1Start),
                                        $this->buildRow("結束", $displayP1End),
                                        $this->buildRow("時數", $part1Hours . " 小時"),
                                    ]],
                                    ["type" => "separator", "margin" => "md"],
                                    ["type" => "text", "text" => "❌ 銷假段（已註銷）", "size" => "sm", "weight" => "bold", "margin" => "md", "color" => "#DC3545"],
                                    ["type" => "box", "layout" => "vertical", "margin" => "sm", "spacing" => "sm", "contents" => [
                                        $this->buildRow("開始", $displayP2Start),
                                        $this->buildRow("結束", $displayP2End),
                                        $this->buildRow("退還時數", $part2Hours . " 小時"),
                                    ]]
                                ]
                            ],
                            "footer" => [
                                "type" => "box", "layout" => "vertical", "contents" => [
                                    ["type" => "text", "text" => "系統已自動校正您的休假餘額。", "size" => "xs", "color" => "#aaaaaa", "align" => "center"]
                        ]
                    ]
                        ]
                ];
                pushMessage($original['user_id'], $flexMsg);

                return "已核准：假單已拆單，銷假段時數已退還，保留段餘額已重新計算。";
            }

            // ==========================================
            // 情境 B: 修改時段 (Modify Range / Delay / Early)
            // ==========================================
            $newStart = $original['start_at'];
            $newEnd = $original['end_at'];

            if ($mod['type'] === 'modify_range') {
                $dates = explode('~', $mod['target_date']);
                if (count($dates) === 2) {
                    $newStart = trim($dates[0]);
                    $newEnd = trim($dates[1]);
                    if (strlen($newStart) == 16) $newStart .= ":00";
                    if (strlen($newEnd) == 16) $newEnd .= ":00";
                }
            } elseif ($mod['type'] === 'delay_start') {
                $newStart = str_replace('T', ' ', $mod['target_date']);
                if (strlen($newStart) == 10) $newStart .= " 08:30:00";
            } elseif ($mod['type'] === 'early_return') {
                $newEnd = str_replace('T', ' ', $mod['target_date']);
                if (strlen($newEnd) == 10) $newEnd .= " 17:30:00";
            }

            if (strtotime($newStart) >= strtotime($newEnd)) {
                $this->db->rollBack();
                return "⚠️ 修改後的開始時間不得晚於或等於結束時間，請確認申請內容是否正確。";
            }

            $newHours = calculateHours($newStart, $newEnd);

            if ($newHours <= 0) {
                $this->db->rollBack();
                return "⚠️ 修改後的請假時數為零或負值，無法核准此申請。請確認新的時間範圍是否合理。";
            }

            $oldDeductAnnual = floatval($original['deduct_annual'] ?? 0);
            $oldDeductComp   = floatval($original['deduct_comp'] ?? 0);
            $backToUser = $this->db->prepare("UPDATE users SET annual_leave_hours = annual_leave_hours + ?, comp_leave_hours = comp_leave_hours + ? WHERE user_id = ?");
            $backToUser->execute([$oldDeductAnnual, $oldDeductComp, $original['user_id']]);

            $stmtUser = $this->db->prepare("SELECT annual_leave_hours, comp_leave_hours FROM users WHERE user_id = ? FOR UPDATE");
            $stmtUser->execute([$original['user_id']]);
            $userBalance = $stmtUser->fetch(PDO::FETCH_ASSOC);
            $currentComp = floatval($userBalance['comp_leave_hours']);
            // 🔥 新增：把目前的特休餘額也抓出來
            $currentAnnual = floatval($userBalance['annual_leave_hours']); 
            
            $newDeductAnnual = 0;
            $newDeductComp = 0;

            if (strpos($original['leave_type'], '特休') !== false) {
                if ($currentComp >= $newHours) {
                    $newDeductComp = $newHours;
                } else {
                    $newDeductComp = $currentComp;
                    $newDeductAnnual = $newHours - $currentComp;
                }
            } else if (strpos($original['leave_type'], '補休') !== false) {
                $newDeductComp = $newHours;
            }

            // 🔥 新增：阻擋透支防護！檢查餘額是否足夠支付延長的假期
            if ($newDeductAnnual > $currentAnnual) {
                $this->db->rollBack();
                return "⚠️ 核准失敗：員工特休餘額不足以支付延長的請假時數。";
            }
            if ($newDeductComp > $currentComp && strpos($original['leave_type'], '補休') !== false) {
                $this->db->rollBack();
                return "⚠️ 核准失敗：員工補休餘額不足以支付延長的請假時數。";
            }

            if ($newDeductComp > 0 || $newDeductAnnual > 0) {
                $reDeduct = $this->db->prepare("UPDATE users SET annual_leave_hours = annual_leave_hours - ?, comp_leave_hours = comp_leave_hours - ? WHERE user_id = ?");
                $reDeduct->execute([$newDeductAnnual, $newDeductComp, $original['user_id']]);
            }

            $update = $this->db->prepare("UPDATE leave_requests SET start_at = ?, end_at = ?, leave_hours = ?, deduct_annual = ?, deduct_comp = ? WHERE id = ?");
            $update->execute([$newStart, $newEnd, $newHours, $newDeductAnnual, $newDeductComp, $original['id']]);

            $this->db->prepare("UPDATE leave_modifications SET status = 'approved' WHERE id = ?")->execute([$mod['id']]);
            
            $this->db->commit();
            
            $displayStart = substr($newStart, 0, 16);
            $displayEnd = substr($newEnd, 0, 16);
            
            $flexMsg = [
                "type" => "flex",
                "altText" => "【系統通知】假單變更核准",
                "contents" => [
                    "type" => "bubble",
                    "size" => "kilo",
                    "header" => [
                        "type" => "box",
                        "layout" => "vertical",
                        "backgroundColor" => "#06C755",
                        "paddingAll" => "lg",
                        "contents" => [
                            ["type" => "text", "text" => "假單變更核准", "weight" => "bold", "size" => "lg", "color" => "#ffffff"]
                        ]
                    ],
                    "body" => [
                        "type" => "box",
                        "layout" => "vertical",
                        "contents" => [
                            ["type" => "text", "text" => "您的請假時段變更申請已核准。", "size" => "sm", "color" => "#666666", "wrap" => true],
                            ["type" => "separator", "margin" => "md"],
                            ["type" => "box", "layout" => "vertical", "margin" => "md", "spacing" => "sm", "contents" => [
                                $this->buildRow("變更後開始", $displayStart),
                                $this->buildRow("變更後結束", $displayEnd),
                                $this->buildRow("修正時數", $newHours . " 小時"),
                            ]]
                        ]
                    ],
                    "footer" => [
                        "type" => "box", "layout" => "vertical", "contents" => [
                            ["type" => "text", "text" => "系統已自動校正您的休假餘額。", "size" => "xs", "color" => "#aaaaaa", "align" => "center"]
                        ]
                    ]
                ]
            ];
            pushMessage($original['user_id'], $flexMsg);

            return "已核准：假單時段已更新，餘額已重新計算。";

        } catch (Exception $e) {
            $this->db->rollBack();
            return "系統錯誤：" . $e->getMessage();
        }
    }

    private function buildRow($label, $value) {
        return [
            "type" => "box",
            "layout" => "baseline",
            "contents" => [
                ["type" => "text", "text" => $label, "color" => "#aaaaaa", "size" => "sm", "flex" => 2],
                ["type" => "text", "text" => $value, "wrap" => true, "color" => "#333333", "size" => "sm", "flex" => 4]
            ]
        ];
    }
}
