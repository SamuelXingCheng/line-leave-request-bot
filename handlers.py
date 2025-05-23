# handlers.py
import logging
from linebot.models import TextSendMessage
from firebase_db import save_request, get_pending_requests, update_request_status
from utils import parse_leave_command, build_forward_message, get_user_display_name


def handle_message(event, line_bot_api):
    user_id = event.source.user_id
    text = event.message.text.strip()
    logging.info(f"✅ 處理訊息：{text}")

    if text.startswith("/請假"):
        data = parse_leave_command(text)
        if not data:
            line_bot_api.reply_message(
                event.reply_token,
                TextSendMessage(text="❗格式錯誤，請使用：\n1️⃣ /請假 2025-05-24 2025-05-25 家中急事\n2️⃣ /請假 2025-05-24 09:00 12:00 看診")
            )
            return

        request_id = save_request(user_id=user_id, **data)
        msg = build_forward_message(data, request_id)
        line_bot_api.reply_message(event.reply_token, TextSendMessage(text=msg))
        return

    elif text.startswith("/查詢請假"):
        requests = get_pending_requests()
        if not requests:
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text="目前沒有待簽核請假單。"))
            return

        msg = "目前待簽核請假申請：\n"
        for r in requests:
            name = get_user_display_name(r["user_id"])
            if r.get("start_time") and r.get("end_time"):
                date_range = f"{r['start_date']} {r['start_time']}～{r['end_time']}"
            else:
                date_range = f"{r['start_date']} 至 {r['end_date']}"
            msg += f"{name} - {date_range} - {r['reason']}\n👉 /簽核 {r['request_id']} ｜ /拒絕 {r['request_id']}\n\n"

        line_bot_api.reply_message(event.reply_token, TextSendMessage(text=msg.strip()))
        return

    elif text.startswith("/簽核") or text.startswith("/拒絕"):
        parts = text.split()
        if len(parts) != 2:
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text="請使用：/簽核 request_id 或 /拒絕 request_id"))
            return

        request_id = parts[1]
        status = "approved" if text.startswith("/簽核") else "rejected"
        success = update_request_status(request_id, status)

        if success:
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text=f"✅ 請假單已{status}"))
        else:
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text="❗查無此請假單"))
        return

    else:
        line_bot_api.reply_message(event.reply_token, TextSendMessage(text="請輸入正確指令，例如 /請假 或 /查詢請假"))
