# utils.py
from datetime import datetime

# ✅ 支援整天請假與單日區間請假
def parse_leave_command(text):
    parts = text.strip().split()

    if len(parts) < 4:
        return None

    # 整天請假格式：/請假 2025-05-24 2025-05-25 理由
    if len(parts) >= 5 and ":" not in parts[2]:
        try:
            start_date = parts[1]
            end_date = parts[2]
            reason = " ".join(parts[3:])
            datetime.strptime(start_date, "%Y-%m-%d")
            datetime.strptime(end_date, "%Y-%m-%d")
            return {
                "start_date": start_date,
                "end_date": end_date,
                "start_time": None,
                "end_time": None,
                "reason": reason
            }
        except:
            return None

    # 區間請假格式：/請假 2025-05-24 09:00 12:00 理由
    elif len(parts) >= 5 and ":" in parts[2]:
        try:
            start_date = parts[1]
            start_time = parts[2]
            end_time = parts[3]
            reason = " ".join(parts[4:])
            datetime.strptime(start_date, "%Y-%m-%d")
            datetime.strptime(start_time, "%H:%M")
            datetime.strptime(end_time, "%H:%M")
            return {
                "start_date": start_date,
                "end_date": start_date,
                "start_time": start_time,
                "end_time": end_time,
                "reason": reason
            }
        except:
            return None

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
