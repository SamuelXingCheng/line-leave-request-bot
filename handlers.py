# handlers.py
import logging
from linebot.models import MessageEvent, TextMessage, TextSendMessage
from utils import parse_leave_command
from firebase_db import save_request, get_user_info_by_line_id, ensure_user_registered

def handle_message(event, line_bot_api):
    if not isinstance(event.message, TextMessage):
        return

    user_id = event.source.user_id
    user_text = event.message.text.strip()

    logging.info(f"✅ 收到使用者訊息：{user_text}")
    
    # ✅ 註冊指令：/註冊 王小明
    if user_text.startswith("/註冊"):
        parts = user_text.split()
        if len(parts) >= 2:
            name = " ".join(parts[1:])
            success = ensure_user_registered(user_id, name)
            reply = (
                f"✅ {name} 已註冊成功！" if success
                else "⚠️ 你已經註冊過了！"
            )
        else:
            reply = "❗ 請使用格式：\n/註冊 王小明"
        line_bot_api.reply_message(
            event.reply_token,
            TextSendMessage(text=reply)
        )
        return

    # ✅ 請假指令
    if user_text.startswith("/請假"):
        parsed = parse_leave_command(user_text)
        if not parsed:
            line_bot_api.reply_message(
                event.reply_token,
                TextSendMessage(text="❌ 請假格式錯誤，請確認格式。")
            )
            return

        # 取得使用者資訊
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

        # ✅ 生成轉發訊息給主管
        if user_info.get("supervisor_ids"):
            bot_id = os.getenv("LINE_BOT_ID")  # 例如 @123xyz
            encoded_query = urllib.parse.quote("/查詢請假")
            approval_link = f"line://oaMessage/{bot_id}/?{encoded_query}"

            forward_msg = (
                "📤 請將以下訊息轉傳給主管簽核：\n\n"
                f"因為 {parsed['reason']}，從 {parsed['start_date']} {parsed['start_time']} "
                f"到 {parsed['end_date']} {parsed['end_time']} 需要請假，煩請批准。\n\n"
                f"👉 點擊以下連結查詢待簽核請假單：\n{approval_link}"
            )
        else:
            forward_msg = (
                "⚠️ 你尚未設定主管，請聯絡管理員設定 supervisor_ids。\n"
                "請假資料已儲存，但無法簽核。"
            )

        line_bot_api.reply_message(
            event.reply_token,
            TextSendMessage(text=forward_msg)
        )
