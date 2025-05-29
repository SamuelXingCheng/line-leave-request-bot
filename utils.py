# utils.py
from datetime import datetime
from firebase_db import get_db
from linebot.models import TextSendMessage
from firebase_admin import firestore

# ✅ 支援整天請假與單日區間請假
import re
import os
import urllib.parse

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
        f"因為 {data['reason']}，從 {data['start_date']} {data['start_time']} "
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
            # ✅ 只更新資料，不用回覆訊息
            approve(doc, data, supervisor_id, line_bot_api)

            approved_count += 1

    if approved_count > 0:
        line_bot_api.reply_message(
            event.reply_token,
            TextSendMessage(text=f"✅ 已簽核 {user_name} 的請假申請（共 {approved_count} 筆）")
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