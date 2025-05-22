# handlers.py
from linebot.models import TextSendMessage
from firebase_db import save_request, get_pending_requests, update_request_status
from utils import parse_leave_command, build_leave_message, get_user_name
import os


async def handle_message(event, line_bot_api):
    user_id = event.source.user_id
    text = event.message.text.strip()

    if text.startswith("/請假"):
        data = parse_leave_command(text)
        if not data:
            await line_bot_api.reply_message(
                event.reply_token,
                TextSendMessage(text="格式錯誤，請使用：\n1️⃣ /請假 2025-05-20 2025-05-21 請假原因\n2️⃣ /請假 2025-05-20 09:00 12:00 請假原因")
            )
            return

        request_id = await save_request(
            user_id=user_id,
            start_date=data["start_date"],
            end_date=data["end_date"],
            start_time=data["start_time"],
            end_time=data["end_time"],
            reason=data["reason"]
        )

        msg = build_leave_message(data, request_id)
        await line_bot_api.reply_message(event.reply_token, TextSendMessage(text=msg))

    elif text.startswith("/查詢請假"):
        requests = await get_pending_requests()
        if not requests:
            await line_bot_api.reply_message(event.reply_token, TextSendMessage(text="目前沒有待簽核請假單。"))
            return

        msg = "目前待簽核請假申請：\n"
        for r in requests:
            name = await get_user_name(r["user_id"])
            
            # 顯示請假時間段
            if r.get("start_time") and r.get("end_time"):
                date_range = f"{r['start_date']} {r['start_time']}～{r['end_time']}"
            else:
                date_range = f"{r['start_date']} 至 {r['end_date']}"

            msg += f"{name} - {date_range} - {r['reason']}\n"
            msg += f"👉 /簽核 {r['request_id']} ｜ /拒絕 {r['request_id']}\n\n"

        await line_bot_api.reply_message(event.reply_token, TextSendMessage(text=msg.strip()))

    elif text.startswith("/簽核") or text.startswith("/拒絕"):
        parts = text.split()
        if len(parts) != 2:
            await line_bot_api.reply_message(event.reply_token, TextSendMessage(text="請使用：/簽核 request_id"))
            return

        request_id = parts[1]
        status = "approved" if text.startswith("/簽核") else "rejected"
        success = await update_request_status(request_id, status)

        if success:
            await line_bot_api.reply_message(event.reply_token, TextSendMessage(text=f"請假單已{status}。"))
        else:
            await line_bot_api.reply_message(event.reply_token, TextSendMessage(text="查無此請假單。"))
