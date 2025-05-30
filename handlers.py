# handlers.py
import logging
from linebot.models import MessageEvent, TextMessage, TextSendMessage, FlexSendMessage
from utils import handle_approve_by_group, build_forward_message, parse_leave_command, handle_query_pending_leaves
from firebase_db import save_request, get_user_info_by_line_id, ensure_user_registered, get_supervisor_names
import os
import urllib

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
        request_group_id = save_request(
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
        supervisor_ids = user_info.get("supervisor_ids", [])
        if supervisor_ids:
            forward_msg, user_hint_msg = build_forward_message({
                "name": user_info["name"],
                "reason": parsed["reason"],
                "start_date": parsed["start_date"],
                "start_time": parsed["start_time"],
                "end_date": parsed["end_date"],
                "end_time": parsed["end_time"],
                "supervisor_ids": supervisor_ids,
            }, request_id=request_group_id)

            messages = [TextSendMessage(text=forward_msg)]
            if user_hint_msg:
                messages.append(TextSendMessage(text=user_hint_msg))

            line_bot_api.reply_message(event.reply_token, messages)
        else:
            line_bot_api.reply_message(
                event.reply_token,
                TextSendMessage(
                    text="⚠️ 你尚未設定主管，請聯絡管理員設定 supervisor_ids。\n"
                        "請假資料已儲存，但無法簽核。"
                )
            )

    if user_text.startswith("/查詢請假"):
        handle_query_pending_leaves(event, line_bot_api)
        return

    if user_text.startswith("/同意請假"):
        parts = user_text.split()
        if len(parts) == 3:
            group_id = parts[1]
            user_name = parts[2]
            handle_approve_by_group(event, line_bot_api, group_id, user_name)
        else:
            line_bot_api.reply_message(
                event.reply_token,
                TextSendMessage(text="❗請使用格式：/同意請假 請假編號 員工姓名")
            )
        return
    if user_text.startswith("/如何使用"):
        usage_flex = FlexSendMessage(
            alt_text="📘 使用說明",
            contents={
                "type": "bubble",
                "size": "mega",
                "body": {
                    "type": "box",
                    "layout": "vertical",
                    "spacing": "md",
                    "contents": [
                        {
                            "type": "text",
                            "text": "📘 請假機器人使用說明",
                            "weight": "bold",
                            "size": "lg",
                            "color": "#1DB446",
                            "wrap": True
                        },
                        {
                            "type": "text",
                            "text": "📌 請先完成註冊：\n/註冊 王得勝",
                            "wrap": True,
                            "size": "sm"
                        },
                        {
                            "type": "text",
                            "text": "📝 開始請假：\n/請假\n日期：2025/06/01\n時間：09:00-12:00\n事由：外出辦事",
                            "wrap": True,
                            "size": "sm"
                        },
                        {
                            "type": "text",
                            "text": "📅 多日請假也支援：\n（如有跨週末或國定假日請勿使用，請改用單日請假）\n/請假\n日期：2025/06/01 - 2025/06/03\n時間：整天\n事由：年假",
                            "wrap": True,
                            "size": "sm"
                        },
                        {
                            "type": "text",
                            "text": "🔎 查詢請假紀錄：\n/查詢請假",
                            "wrap": True,
                            "size": "sm"
                        },
                        {
                            "type": "text",
                            "text": "🧠 想看完整教學與範例？\n輸入：如何使用",
                            "wrap": True,
                            "size": "sm"
                        },
                        {
                            "type": "text",
                            "text": "📞 如有問題請聯絡管理員協助設定主管或查詢權限。",
                            "wrap": True,
                            "size": "sm",
                            "color": "#888888"
                        }
                    ]
                }
            }
        )
        hello_text = "以下為請假格式："
        example_text = "\n\n/請假\n日期：\n時間：\n事由："

        line_bot_api.reply_message(
            event.reply_token,
            messages=[
                usage_flex,
                TextSendMessage(text=hello_text.strip()),
                TextSendMessage(text=example_text.strip())  # 空白範例文字
            ]
        )
        return