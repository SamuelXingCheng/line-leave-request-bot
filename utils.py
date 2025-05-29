# utils.py
from datetime import datetime
from firebase_db import get_db
from linebot.models import TextSendMessage
from firebase_admin import firestore

# ✅ 支援整天請假與單日區間請假
import re
import os

from pytz import timezone

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
    if data.get("start_time") and data.get("end_time"):
        date_part = f'{data["start_date"]} {data["start_time"]}～{data["end_time"]}'
    else:
        date_part = f'{data["start_date"]} 至 {data["end_date"]}'

    line_bot_id = os.getenv("LINE_BOT_ID", "yourbotid")  # ❗需設定在 .env
    link = f"line://oaMessage/@{line_bot_id}/?/查詢請假"

    return (
        f"請將以下訊息手動轉發給您的主管：\n\n"
        f"因為 {data['reason']}，從 {date_part} 需要請假，煩請批准。\n\n"
        f"👉 點擊以下連結查詢待簽核請假單：\n{link}"
    )

# ✅ 取得使用者顯示名稱（可進階改為查表）
def get_user_display_name(user_id):
    # 可改為查 Firebase 對照表或手動對應
    return f"使用者 {user_id[-4:]}"  # 例如：使用者 3f8a

def handle_query_pending_leaves(event, line_bot_api):
    supervisor_id = event.source.user_id
    db = get_db()

    docs = db.collection("requests")\
             .order_by("created_at", direction=firestore.Query.DESCENDING)\
             .stream()

    messages = []

    for doc in docs:
        data = doc.to_dict()
        approvals = data.get("approvals", {})

        if approvals.get(supervisor_id) == "pending":
            name = data.get("user_name", "未知")
            reason = data.get("reason", "")
            start = format_tw_time(data.get("start_at"))
            end = format_tw_time(data.get("end_at"))
            messages.append(f"👤 {name}\n🗓️ {start} ~ {end}\n📝 {reason}")

    reply = "\n\n".join(messages) if messages else "✅ 目前沒有待您簽核的請假申請。"

    line_bot_api.reply_message(
        event.reply_token,
        TextSendMessage(text=reply)
    )

def handle_approve_by_name(event, line_bot_api, user_name):
    supervisor_id = event.source.user_id
    db = get_db()

    docs = db.collection("requests")\
             .order_by("created_at", direction=firestore.Query.DESCENDING)\
             .stream()

    for doc in docs:
        data = doc.to_dict()
        approvals = data.get("approvals", {})
        if approvals.get(supervisor_id) == "pending" and user_name in data.get("user_name", ""):
            return approve(doc, data, supervisor_id, line_bot_api, event.reply_token)

    line_bot_api.reply_message(
        event.reply_token,
        TextSendMessage(text=f"❌ 找不到名為「{user_name}」的待簽核請假單。")
    )

def approve(doc, data, supervisor_id, line_bot_api, reply_token):
    db = get_db()
    doc_ref = db.collection("requests").document(doc.id)
    doc_ref.update({f"approvals.{supervisor_id}": "approved"})

    approvals = data["approvals"]
    approvals[supervisor_id] = "approved"

    user_name = data.get("user_name")
    user_id = data.get("user_id")  # 必須有 user_id 才能推播通知
    start = data.get("start_at")
    end = data.get("end_at")
    reason = data.get("reason", "")
    start_str = format_tw_time(start)
    end_str = format_tw_time(end)

    if all(status == "approved" for status in approvals.values()):
        doc_ref.update({"status": "approved"})
        message = f"✅ 您已簽核完成，「{user_name}」的請假單已全部核准！"

        # ✅ 自動通知請假人
        if user_id:
            try:
                line_bot_api.push_message(
                    user_id,
                    TextSendMessage(text=(
                        "✅ 你的請假申請已通過所有主管簽核！\n"
                        f"📅 時間：{start_str} ~ {end_str}\n"
                        f"📝 原因：{reason}"
                    ))
                )
            except Exception as e:
                print(f"❗ 通知請假者失敗：{e}")

    else:
        message = f"☑️ 您已簽核「{user_name}」，等待其他主管審核中。"

    line_bot_api.reply_message(
        reply_token,
        TextSendMessage(text=message)
    )