# utils.py
from datetime import datetime

def parse_leave_command(text):
    parts = text.strip().split()
    
    if len(parts) < 4:
        return None

    # 整天請假格式
    if len(parts) >= 5 and ":" not in parts[2]:  # /請假 2025-05-20 2025-05-21 家中急事
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

    # 區間請假格式（單日＋時段）
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


def build_leave_message(data, request_id):
    if data["start_time"] and data["end_time"]:
        date_part = f'{data["start_date"]} {data["start_time"]}～{data["end_time"]}'
    else:
        date_part = f'{data["start_date"]} 至 {data["end_date"]}'

    line_url = os.environ.get("LINE_BOT_ID", "請設定 LINE_BOT_ID")
    link = f"line://oaMessage/@{line_url}/?/查詢請假"

    return (
        f"請將以下訊息手動轉發給您的主管：\n\n"
        f"因為 {data['reason']}，從 {date_part} 需要請假，煩請批准。\n\n"
        f"👉 點擊以下連結查詢待簽核請假單：\n{link}"
    )


# TODO: 實作使用者暱稱（可選）
async def get_user_name(user_id):
    # 目前僅回傳 user_id 代稱，如需連接真實姓名需另外儲存
    return f"使用者 {user_id[-4:]}"  # 例如顯示為：使用者 3e4f
