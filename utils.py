# utils.py
from datetime import datetime
from firebase_db import get_db
from linebot.models import TextSendMessage, FlexSendMessage
from firebase_admin import firestore

# ✅ 支援整天請假與單日區間請假
import re
import os
import urllib.parse

from pytz import timezone
from collections import defaultdict

def format_tw_time(timestamp):
    if not timestamp:
        return "未知時間"
    return timestamp.astimezone(timezone('Asia/Taipei')).strftime("%Y/%m/%d %H:%M")

def normalize_date(date_str):
    try:
        return datetime.strptime(date_str, "%Y/%m/%d").strftime("%Y-%m-%d")
    except:
        return None

def normalize_time(time_str):
    try:
        return datetime.strptime(time_str, "%H:%M").strftime("%H:%M")
    except:
        return None

def parse_leave_command(text):
    text = text.strip().replace("/請假", "").strip()
    text = text.replace("～", "-").replace("到", "-")

    # 預設時間
    default_start = "08:30"
    default_end = "17:30"

    # ✅ 解析標題 + 分行格式
    if "日期：" in text and "事由：" in text:
        lines = text.splitlines()
        date_line = next((l for l in lines if l.startswith("日期：")), "")
        time_line = next((l for l in lines if l.startswith("時間：")), "")
        reason_line = next((l for l in lines if l.startswith("事由：")), "")

        # 日期欄位
        date_range = date_line.replace("日期：", "").strip()
        if "-" in date_range:
            start_date_str, end_date_str = [d.strip() for d in date_range.split("-")]
        else:
            start_date_str = end_date_str = date_range.strip()

        # 時間欄位
        time_range = time_line.replace("時間：", "").strip() if time_line else "整天"
        if time_range == "整天":
            start_time = default_start
            end_time = default_end
        elif "-" in time_range:
            start_time, end_time = [t.strip() for t in time_range.split("-")]
        else:
            try:
                t = datetime.strptime(time_range, "%H:%M")
                start_time = time_range
                end_time = (t + timedelta(hours=1)).strftime("%H:%M")
            except:
                return None

        # 事由欄位
        reason = reason_line.replace("事由：", "").strip()

        return {
            "start_date": normalize_date(start_date_str),
            "end_date": normalize_date(end_date_str),
            "start_time": normalize_time(start_time),
            "end_time": normalize_time(end_time),
            "reason": reason
        }

    # 1️⃣ 單日整天
    match = re.match(r"(\d{4}/\d+/\d+)\s*整天\s+(.+)", text)
    if match:
        date = normalize_date(match.group(1))
        reason = match.group(2)
        if date:
            return {
                "start_date": date,
                "end_date": date,
                "start_time": default_start,
                "end_time": default_end,
                "reason": reason
            }

    # 2️⃣ 區間請假
    match = re.match(r"(\d{4}/\d+/\d+)\s+(\d{1,2}:\d{2})-(\d{1,2}:\d{2})\s+(.+)", text)
    if match:
        date = normalize_date(match.group(1))
        start_time = normalize_time(match.group(2))
        end_time = normalize_time(match.group(3))
        reason = match.group(4)
        if date and start_time and end_time:
            return {
                "start_date": date,
                "end_date": date,
                "start_time": start_time,
                "end_time": end_time,
                "reason": reason
            }

    # 3️⃣ 多日整天（沒時間 → 補上預設時間）
    match = re.match(r"(\d{4}/\d+/\d+)-(\d{4}/\d+/\d+)\s+(.+)", text)
    if match:
        start_date = normalize_date(match.group(1))
        end_date = normalize_date(match.group(2))
        reason = match.group(3)
        if start_date and end_date:
            return {
                "start_date": start_date,
                "end_date": end_date,
                "start_time": default_start,
                "end_time": default_end,
                "reason": reason
            }

    # 4️⃣ 多日單一時間（如 11:30）
    match = re.match(r"(\d{4}/\d+/\d+)-(\d{4}/\d+/\d+)\s+(\d{1,2}:\d{2})\s+(.+)", text)
    if match:
        start_date = normalize_date(match.group(1))
        end_date = normalize_date(match.group(2))
        time_str = match.group(3)
        reason = match.group(4)

        try:
            start_dt = datetime.strptime(time_str, "%H:%M")
            end_dt = (start_dt + timedelta(hours=1)).time()
            start_time = start_dt.strftime("%H:%M")
            end_time = end_dt.strftime("%H:%M")
        except:
            return None

        if start_date and end_date:
            return {
                "start_date": start_date,
                "end_date": end_date,
                "start_time": start_time,
                "end_time": end_time,
                "reason": reason
            }

    return None


# ✅ 將請假資料轉為可供轉發的 LINE 訊息
def build_forward_message(data, request_id):
    """
    根據請假資料產生要轉傳給主管的訊息。
    參數 data 必須包含：
        - name：請假人姓名
        - reason：請假事由
        - start_date, start_time, end_date, end_time：請假時間
        - supervisor_ids：主管 LINE ID list（可選）

    傳回 tuple：(forward_msg, user_hint_msg)
    """
    user_name = data["name"]
    supervisor_ids = data.get("supervisor_ids", [])
    bot_id = os.getenv("LINE_BOT_ID")  # 例如 @123xyz

    approval_command = f"/同意請假 {request_id} {user_name}"
    encoded_query = urllib.parse.quote(approval_command)
    approval_link = f"line://oaMessage/@{bot_id}/?{encoded_query}"

    forward_msg = (
        "弟兄您好，\n\n"
        f"因為 {data['reason']}（{data.get('leave_type', '假別未填')}），從 {data['start_date']} {data['start_time']} "
        f"到 {data['end_date']} {data['end_time']} 需要請假，煩請批准。\n\n"
        f"👉 點擊以下連結，系統將自動填入「/同意請假 （假單編號） {user_name}」，"
        "請直接送出即可完成簽核：\n"
        f"{approval_link}"
        "\n\n若不同意，請口頭告知請假者即可，無需操作此連結。"
    )

    if supervisor_ids:
        from firebase_db import get_supervisor_names  # 避免循環 import 可放在這行
        supervisor_names = get_supervisor_names(supervisor_ids)
        user_hint_msg = (
            "📌 請記得轉傳上方訊息給以下主管簽核：\n" +
            "\n".join(f"- {name}" for name in supervisor_names)
        )
    else:
        user_hint_msg = None

    return forward_msg, user_hint_msg

# ✅ 取得使用者顯示名稱（可進階改為查表）
def get_user_display_name(user_id):
    # 可改為查 Firebase 對照表或手動對應
    return f"使用者 {user_id[-4:]}"  # 例如：使用者 3f8a

def calculate_effective_hours(start: datetime, end: datetime) -> float:
    """計算排除午休後的一段請假時數（轉換為台灣時區後計算）"""
    tz = timezone("Asia/Taipei")
    start = start.astimezone(tz)
    end = end.astimezone(tz)

    if start.date() != end.date():
        raise ValueError("請假時段需為同一日內")

    total_seconds = (end - start).total_seconds()
    
    # 午休時間（固定 12:00–13:00）
    rest_start = start.replace(hour=12, minute=0, second=0, microsecond=0)
    rest_end = start.replace(hour=13, minute=0, second=0, microsecond=0)

    # 計算與午休重疊的秒數
    rest_overlap = max(0, (min(end, rest_end) - max(start, rest_start)).total_seconds())
    effective_seconds = total_seconds - rest_overlap
    return round(effective_seconds / 3600, 2)

def build_leave_flex_card(doc_id, name, reason, start, end, is_own=False, can_delete=False):
    body_contents = [
        {"type": "text", "text": f"🧾 {name}", "weight": "bold", "size": "md"},
        {"type": "text", "text": f"🗓️ {start} ~ {end}", "size": "sm", "wrap": True},
        {"type": "text", "text": f"📝 {reason}", "size": "sm", "wrap": True}
    ]

    footer_contents = []

    # 🔘 加入刪除按鈕
    if is_own and can_delete:
        footer_contents.append({
            "type": "button",
            "style": "primary",
            "color": "#FF5555",
            "action": {
                "type": "message",
                "label": "🗑️ 刪除請假",
                "text": f"/刪除請假 {doc_id}"
            }
        })

    return {
        "type": "bubble",
        "body": {"type": "box", "layout": "vertical", "contents": body_contents},
        "footer": {"type": "box", "layout": "vertical", "contents": footer_contents}
    }


def summarize_leave_days_and_hours(request_docs) -> str:
    """
    回傳各類請假類型的統計：「🌴 特休：X 小時（Y 天）」格式
    - 類型依 reason 分類
    - 時數使用 calculate_effective_hours()
    - 天數依據 start_at.date() ~ end_at.date()
    """
    hours_by_reason = defaultdict(float)
    days_by_reason = defaultdict(set)

    for doc in request_docs:
        data = doc.to_dict()
        reason = data.get("reason", "未分類")
        start = data.get("start_at")
        end = data.get("end_at")

        if not start or not end:
            continue
        hours = calculate_effective_hours(start, end)
        hours_by_reason[reason] += hours
        days_by_reason[reason].add(start.date())

    if not hours_by_reason:
        return "查無請假統計資料。"

    # 🎨 emoji 對應表（可自行擴充）
    emoji_map = {
        "特休": "🌴", "事假": "📌", "病假": "🤒", "婚假": "💒",
        "喪假": "🖤", "產假": "🤰", "公假": "🏛️", "未分類": "📁"
    }

    lines = []
    for reason, hours in sorted(hours_by_reason.items(), key=lambda x: -x[1]):
        emoji = emoji_map.get(reason, "📁")
        lines.append(f"{emoji} {reason}：{round(hours)} 小時")

    return "\n".join(lines)

def handle_delete_request(event, line_bot_api, request_id):
    user_id = event.source.user_id
    db = get_db()
    doc_ref = db.collection("requests").document(request_id)
    doc = doc_ref.get()

    if not doc.exists:
        line_bot_api.reply_message(event.reply_token, TextSendMessage(text="❌ 找不到該筆請假紀錄。"))
        return

    data = doc.to_dict()

    # 僅允許刪除自己的請假
    if data.get("user_id") != user_id:
        line_bot_api.reply_message(event.reply_token, TextSendMessage(text="⚠️ 你無權刪除此請假紀錄。"))
        return

    # 僅允許刪除尚未簽核完成的
    if not any(v == "pending" for v in data.get("approvals", {}).values()):
        line_bot_api.reply_message(event.reply_token, TextSendMessage(text="❌ 此請假已簽核完成，無法刪除。"))
        return

    # ✅ 執行刪除
    doc_ref.delete()

    # ✅ 呼叫原本查詢請假紀錄函式，但改為回傳訊息物件，而不是直接 reply
    messages = build_pending_leave_messages(user_id)  # ⚠️ 你需要將原本 handle_query_pending_leaves 拆成此函式
    messages.insert(0, TextSendMessage(text="🗑️ 請假紀錄已成功刪除。以下為最新紀錄："))

    # ✅ 一次性回覆
    line_bot_api.reply_message(event.reply_token, messages)

def build_pending_leave_messages(user_id, start_date=None, end_date=None):
    from linebot.models import FlexSendMessage, TextSendMessage
    db = get_db()
    tz = timezone("Asia/Taipei")
    flex_bubbles = []

    # ✅ 查詢自己的請假紀錄，加上區間條件
    user_query = db.collection("requests").where("user_id", "==", user_id)

    if start_date:
        start_ts = tz.localize(datetime.combine(start_date, datetime.min.time()))
        user_query = user_query.where("start_at", ">=", start_ts)

    if end_date:
        end_ts = tz.localize(datetime.combine(end_date, datetime.max.time()))
        user_query = user_query.where("start_at", "<=", end_ts)

    user_requests = list(user_query.order_by("start_at", direction=firestore.Query.DESCENDING).stream())

    for doc in user_requests:
        data = doc.to_dict()
        start = data.get("start_at")
        end = data.get("end_at")
        if not start or not end:
            continue
        status_map = data.get("approvals", {})
        is_pending = any(v == "pending" for v in status_map.values())
        bubble = build_leave_flex_card(
            doc.id,
            name="你自己",
            reason=data.get("reason", ""),
            start=format_tw_time(start),
            end=format_tw_time(end),
            is_own=True,
            can_delete=is_pending
        )
        flex_bubbles.append(bubble)

    # ✅ 查詢需要我審核的請假（不篩選日期）
    approve_docs = list(db.collection("requests")
        .order_by("created_at", direction=firestore.Query.DESCENDING)
        .stream())

    for doc in approve_docs:
        data = doc.to_dict()
        approvals = data.get("approvals", {})
        if approvals.get(user_id) != "pending":
            continue
        bubble = build_leave_flex_card(
            doc.id,
            name=data.get("user_name", "未知"),
            reason=data.get("reason", ""),
            start=format_tw_time(data.get("start_at")),
            end=format_tw_time(data.get("end_at"))
        )
        flex_bubbles.append(bubble)

    # ✅ 統計指定區間的請假時數
    summary_msg = None
    if start_date or end_date:
        summary_query = db.collection("requests").where("user_id", "==", user_id)

        if start_date:
            start_ts = tz.localize(datetime.combine(start_date, datetime.min.time()))
            summary_query = summary_query.where("start_at", ">=", start_ts)

        if end_date:
            end_ts = tz.localize(datetime.combine(end_date, datetime.max.time()))
            summary_query = summary_query.where("start_at", "<=", end_ts)

        filtered_requests = summary_query.stream()
        summary = summarize_leave_days_and_hours(filtered_requests)

        summary_range_str = f"{start_date.strftime('%Y/%m/%d')} ~ {end_date.strftime('%Y/%m/%d')}" if start_date and end_date else "查詢區間"
        summary_msg = TextSendMessage(text=f"📊 {summary_range_str} 請假統計：\n{summary}")

    # ✅ 組合回應訊息
    if flex_bubbles:
        reply_msgs = [
            FlexSendMessage(
                alt_text="📋 請假紀錄與簽核項目",
                contents={
                    "type": "carousel",
                    "contents": flex_bubbles[:10]
                }
            )
        ]
        if summary_msg:
            reply_msgs.append(summary_msg)
        return reply_msgs
    else:
        return [TextSendMessage(text="📌 你尚未提出任何請假，也沒有待簽核項目。")]


def handle_query_pending_leaves(event, line_bot_api, start_date=None, end_date=None):
    user_id = event.source.user_id
    try:
        messages = build_pending_leave_messages(user_id, start_date, end_date)
        line_bot_api.reply_message(event.reply_token, messages)
    except Exception as e:
        print("[查詢請假] 錯誤：", e)
        line_bot_api.reply_message(event.reply_token, TextSendMessage(text="❌ 查詢失敗，請稍後再試。"))



def handle_approve_by_group(event, line_bot_api, group_id, user_name):
    supervisor_id = event.source.user_id
    db = get_db()

    docs = db.collection("requests")\
             .where("request_group_id", "==", group_id)\
             .where("user_name", "==", user_name)\
             .stream()

    approved_count = 0
    for doc in docs:
        data = doc.to_dict()
        approvals = data.get("approvals", {})
        if approvals.get(supervisor_id) == "pending":
            approve(doc, data, supervisor_id, line_bot_api)
            approved_count += 1

    if approved_count > 0:
        # ✅ 重新查詢整組資料來統計總請假時間
        group_docs = db.collection("requests")\
            .where("request_group_id", "==", group_id)\
            .stream()
        
        summary_text = summarize_leave_days_and_hours(group_docs)

        line_bot_api.reply_message(
            event.reply_token,
            TextSendMessage(text=f"✅ 已完成對 {user_name} 的請假簽核（{summary_text}）")
        )
    else:
        line_bot_api.reply_message(
            event.reply_token,
            TextSendMessage(text=f"❌ 找不到「{user_name}」的待簽核請假單（編號 {group_id}）。")
        )


def approve(doc, data, supervisor_id, line_bot_api=None):
    db = get_db()
    doc_ref = db.collection("requests").document(doc.id)

    # ✅ 更新 memory 中的 approvals（避免重查）
    approvals = data["approvals"]
    approvals[supervisor_id] = "approved"

    # ✅ 更新 Firestore：單一主管簽核狀態
    doc_ref.update({
        f"approvals.{supervisor_id}": "approved"
    })

    # ✅ 檢查是否本筆請假單的所有主管皆已簽核
    if all(status == "approved" for status in approvals.values()):
        doc_ref.update({"status": "approved"})
    else:
        return  # 尚未完成，直接 return

    # ✅ 再檢查整個 group 是否每一筆請假都 status = approved
    group_id = data.get("request_group_id")
    if not group_id:
        return

    group_docs = db.collection("requests")\
        .where("request_group_id", "==", group_id)\
        .stream()

    all_approved = True
    all_items = []
    for gdoc in group_docs:
        gdata = gdoc.to_dict()
        all_items.append(gdata)
        if gdata.get("status") != "approved":
            all_approved = False
            break

    if not all_approved:
        return

    # ✅ 若整組皆核准，推播一次給請假者
    user_id = data.get("user_id")
    reason = data.get("reason", "")

    if user_id and line_bot_api:
        try:
            # 彙整所有請假時間段
            time_ranges = []
            for item in sorted(all_items, key=lambda x: x.get("start_at")):
                start_str = format_tw_time(item.get("start_at"))
                end_str = format_tw_time(item.get("end_at"))
                time_ranges.append(f"📅 {start_str} ~ {end_str}")

            message = (
                "✅ 你的請假申請已通過所有主管簽核！\n" +
                "\n".join(time_ranges) + "\n" +
                f"📝 原因：{reason}"
            )

            line_bot_api.push_message(user_id, TextSendMessage(text=message))
        except Exception as e:
            print(f"❗ 通知請假者失敗：{e}")