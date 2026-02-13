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
            replyTextMessage($this->replyToken, $resultMsg);
        }
        return true;
    }

    private function approveModification($uuid) {
        $stmt = $this->db->prepare("SELECT * FROM leave_modifications WHERE modification_uuid = ?");
        $stmt->execute([$uuid]);
        $mod = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$mod || $mod['status'] !== 'pending') return "⚠️ 此申請不存在或已處理過。";

        $stmt = $this->db->prepare("SELECT * FROM leave_requests WHERE id = ?");
        $stmt->execute([$mod['leave_request_id']]);
        $original = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$original) return "⚠️ 原始假單已找不到。";

        try {
            $this->db->beginTransaction();

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

                // 🔥【修改重點】發送 Flex Message 給員工 (銷假成功)
                $flexMsg = [
                    "type" => "flex",
                    "altText" => "【系統通知】假單已註銷",
                    "contents" => [
                        "type" => "bubble",
                        "size" => "kilo",
                        "header" => [
                            "type" => "box",
                            "layout" => "vertical",
                            "backgroundColor" => "#DC3545", // 紅色 (代表刪除/註銷)
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
                if (strlen($newStart) == 10) $newStart .= " 09:00:00"; 
            } elseif ($mod['type'] === 'early_return') {
                $newEnd = str_replace('T', ' ', $mod['target_date']);
                if (strlen($newEnd) == 10) $newEnd .= " 18:00:00"; 
            }

            $newHours = calculateHours($newStart, $newEnd);

            // 退還舊扣抵
            $oldDeductAnnual = floatval($original['deduct_annual'] ?? 0);
            $oldDeductComp   = floatval($original['deduct_comp'] ?? 0);
            $backToUser = $this->db->prepare("UPDATE users SET annual_leave_hours = annual_leave_hours + ?, comp_leave_hours = comp_leave_hours + ? WHERE user_id = ?");
            $backToUser->execute([$oldDeductAnnual, $oldDeductComp, $original['user_id']]);

            // 重算扣抵
            $stmtUser = $this->db->prepare("SELECT annual_leave_hours, comp_leave_hours FROM users WHERE user_id = ?");
            $stmtUser->execute([$original['user_id']]);
            $userBalance = $stmtUser->fetch(PDO::FETCH_ASSOC);
            $currentComp = floatval($userBalance['comp_leave_hours']);
            
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

            // 新時數扣款
            if ($newDeductComp > 0 || $newDeductAnnual > 0) {
                $reDeduct = $this->db->prepare("UPDATE users SET annual_leave_hours = annual_leave_hours - ?, comp_leave_hours = comp_leave_hours - ? WHERE user_id = ?");
                $reDeduct->execute([$newDeductAnnual, $newDeductComp, $original['user_id']]);
            }

            // 更新假單
            $update = $this->db->prepare("UPDATE leave_requests SET start_at = ?, end_at = ?, leave_hours = ?, deduct_annual = ?, deduct_comp = ? WHERE id = ?");
            $update->execute([$newStart, $newEnd, $newHours, $newDeductAnnual, $newDeductComp, $original['id']]);

            $this->db->prepare("UPDATE leave_modifications SET status = 'approved' WHERE id = ?")->execute([$mod['id']]);
            
            $this->db->commit();
            
            // 🔥【修改重點】發送 Flex Message 給員工 (變更成功)
            $displayStart = substr($newStart, 0, 16); // 去除秒數
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
                        "backgroundColor" => "#06C755", // LINE 綠色 (代表更新成功)
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

    // 輔助函式：快速產生 Flex Row
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