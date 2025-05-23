# utils.py
from datetime import datetime

# ✅ 支援整天請假與單日區間請假
import re
import os

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

    # 1️⃣ 單日整天（例：2025/5/23 整天 家裡有事）
    match = re.match(r"(\d{4}/\d+/\d+)\s*整天\s+(.+)", text)
    if match:
        date = normalize_date(match.group(1))
        reason = match.group(2)
        if date:
            return {
                "start_date": date,
                "end_date": date,
                "start_time": None,
                "end_time": None,
                "reason": reason
            }

    # 2️⃣ 區間請假（例：2025/5/23 09:00-10:00 外出辦事）
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

    # 3️⃣ 多日整天（例：2025/5/23-2025/5/24 家裡有事）
    match = re.match(r"(\d{4}/\d+/\d+)-(\d{4}/\d+/\d+)\s+(.+)", text)
    if match:
        start_date = normalize_date(match.group(1))
        end_date = normalize_date(match.group(2))
        reason = match.group(3)
        if start_date and end_date:
            return {
                "start_date": start_date,
                "end_date": end_date,
                "start_time": None,
                "end_time": None,
                "reason": reason
            }

    # 4️⃣ 多日單一時間（例：2025/5/23-2025/5/24 11:30 家裡有事）
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
