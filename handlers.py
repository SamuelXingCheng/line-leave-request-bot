# handlers.py
import logging
from linebot.models import MessageEvent, TextMessage, TextSendMessage
from utils import parse_leave_command
from firebase_db import save_request, get_user_info_by_line_id

def handle_message(event, line_bot_api):
    if not isinstance(event.message, TextMessage):
        return

    user_id = event.source.user_id
    user_text = event.message.text.strip()

    logging.info(f"✅ 收到使用者訊息：{user_text}")

    # 解析指令
    if user_text.startswith("/請假"):
        parsed = parse_leave_command(user_text)
        if not parsed:
            line_bot_api.reply_message(
                event.reply_token,
                TextSendMessage(text="❌ 請假格式錯誤，請確認格式。")
            )
            return

        # 取得使用者資訊（姓名、主管）
        user_info = get_user_info_by_line_id(user_id)
        if not user_info:
            line_bot_api.reply_message(
                event.reply_token,
                TextSendMessage(text="❌ 無法取得使用者資訊，請聯絡管理員。")
            )
            return

        # 儲存請假資料
        save_request(
            user_id=user_id,
            user_name=user_info["name"],
            start_date=parsed["start_date"],
            start_time=parsed["start_time"],
            end_date=parsed["end_date"],
            end_time=parsed["end_time"],
            reason=parsed["reason"],
            supervisor_ids=user_info.get("supervisor_ids", [])
        )

        line_bot_api.reply_message(
            event.reply_token,
            TextSendMessage(text="✅ 請假申請已送出，等待主管簽核")
        )

def get_user_info_by_line_id(line_user_id):
    try:
        user_doc = db.collection("users").document(line_user_id).get()
        if user_doc.exists:
            return user_doc.to_dict()
        return None
    except Exception as e:
        logging.error(f"❌ 取得使用者資訊失敗：{e}")
        return None
