# handlers.py
import logging
from linebot.models import MessageEvent, TextMessage, TextSendMessage, FlexSendMessage
from utils import handle_approve_by_group, build_forward_message, parse_leave_command, handle_query_pending_leaves
from firebase_db import save_request, get_user_info_by_line_id, ensure_user_registered, get_supervisor_names
import os
import urllib

from datetime import datetime, timedelta
user_sessions = {}  # user_id ➝ {"start_date":..., "end_date":..., "time":..., "reason":...}

def parse_date_range(text):
    try:
        if "-" in text:
            start_str, end_str = text.split("-")
            start = datetime.strptime(start_str.strip(), "%Y/%m/%d")
            end = datetime.strptime(end_str.strip(), "%Y/%m/%d")
        else:
            start = end = datetime.strptime(text.strip(), "%Y/%m/%d")
        return start, end
    except ValueError:
        return None, None

def daterange(start_date, end_date):
    for n in range((end_date - start_date).days + 1):
        yield start_date + timedelta(n)

def map_time_label(label):
    mapping = {
        "整天": ("08:30", "17:30"),
        "上午": ("08:30", "12:00"),
        "下午": ("13:00", "17:30")
    }
    if "-" in label:
        parts = label.strip().split("-")
        return parts[0], parts[1]
    return mapping.get(label, (None, None))

def normalize_date(date_str):
    # 將 2025/06/13 轉為 2025-06-13
    return date_str.replace("/", "-")

def reply_quick_reply(line_bot_api, reply_token, text, options):
    items = [{
        "type": "action",
        "action": {
            "type": "message",
            "label": label,
            "text": value
        }
    } for label, value in options]

    line_bot_api.reply_message(
        reply_token,
        TextSendMessage(text=text, quick_reply={"items": items})
    )

def handle_message(event, line_bot_api):
    if not isinstance(event.message, TextMessage):
        return

    user_id = event.source.user_id
    user_text = event.message.text.strip()

    logging.info(f"✅ 收到使用者訊息：{user_text}")
    
    if user_text == "/menu":
        flex = FlexSendMessage(
            alt_text="主選單",
            contents={
                "type": "bubble",
                "body": {
                    "type": "box",
                    "layout": "vertical",
                    "spacing": "md",
                    "contents": [
                        {"type": "text", "text": "📋 請選擇操作功能", "weight": "bold", "size": "lg"},
                        {"type": "button", "action": {"type": "message", "label": "📝 我要請假", "text": "/請假"}, "style": "primary"},
                        {"type": "button", "action": {"type": "message", "label": "📅 查詢請假", "text": "/查詢請假"}, "style": "secondary"}
                    ]
                }
            }
        )
        line_bot_api.reply_message(event.reply_token, flex)
        return

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

    # 📝 Flex + Quick Reply 請假互動流程
    session = user_sessions.get(user_id, {})

    if user_text == "/請假":
        user_sessions[user_id] = {"step": "date"}
        line_bot_api.reply_message(
            event.reply_token,
            TextSendMessage(text="📅 請輸入請假日期（格式：2025/06/17 或 2025/06/17-2025/06/18）：")
        )
        return

    if session.get("step") == "date":
        start, end = parse_date_range(user_text)
        if not start:
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text="❌ 日期格式錯誤，請重新輸入。"))
            return
        session["start_date"] = start
        session["end_date"] = end
        session["step"] = "time"
        user_sessions[user_id] = session
        reply_quick_reply(line_bot_api, event.reply_token, "🕒 請選擇請假時段：", [
            ("整天", "整天"),
            ("上午", "上午"),
            ("下午", "下午"),
            ("自定", "自訂時段")
        ])
        return
    
    if session.get("step") == "time":
        if user_text in ["整天", "上午", "下午"]:
            session["time"] = map_time_label(user_text)
            session["step"] = "reason"
            user_sessions[user_id] = session
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text="📝 請輸入請假事由："))
            return
        elif user_text == "自訂時段":
            session["step"] = "custom_time"
            user_sessions[user_id] = session
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text="請輸入時間範圍（格式：09:00-12:00）："))
            return
        else:
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text="❌ 請選擇有效的時段或輸入自訂時段。"))
            return
    
    if session.get("step") == "custom_time":
        if "-" in user_text:
            start_time, end_time = user_text.split("-")
            session["time"] = (start_time.strip(), end_time.strip())
            session["step"] = "reason"
            user_sessions[user_id] = session
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text="📝 請輸入請假事由："))
        else:
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text="❌ 時間格式錯誤，請重新輸入：09:00-12:00"))
        return

    if session.get("step") == "reason":
        session["reason"] = user_text.strip()
        user_info = get_user_info_by_line_id(user_id)
        if not user_info:
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text="❌ 無法取得使用者資訊。"))
            return

        messages = []
        for date in daterange(session["start_date"], session["end_date"]):
            date_str = normalize_date(date.strftime("%Y/%m/%d"))
            request_group_id = save_request(
                user_id=user_id,
                user_name=user_info["name"],
                start_date=date_str,
                start_time=session["time"][0],
                end_date=date_str,
                end_time=session["time"][1],
                reason=session["reason"],
                supervisor_ids=user_info.get("supervisor_ids", [])
            )
            supervisor_ids = user_info.get("supervisor_ids", [])
            if supervisor_ids:
                forward_msg, user_hint_msg = build_forward_message({
                    "name": user_info["name"],
                    "reason": session["reason"],
                    "start_date": date_str,
                    "start_time": session["time"][0],
                    "end_date": date_str,
                    "end_time": session["time"][1],
                    "supervisor_ids": supervisor_ids
                }, request_id=request_group_id)
                messages.append(TextSendMessage(text=forward_msg))
                if user_hint_msg:
                    messages.append(TextSendMessage(text=user_hint_msg))
            else:
                messages.append(TextSendMessage(text="⚠️ 你尚未設定主管，請聯絡管理員設定 supervisor_ids。請假資料已儲存，但無法簽核。")
                )

        line_bot_api.reply_message(event.reply_token, messages)
        del user_sessions[user_id]
        return

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
                            "text": "📘 請假系統操作教學",
                            "weight": "bold",
                            "size": "lg",
                            "color": "#1DB446",
                            "wrap": True
                        },
                        {
                            "type": "text",
                            "text": "🟢 點選下方選單中的「我要請假」，依畫面指示選擇請假日期、時間與事由。",
                            "wrap": True,
                            "size": "sm"
                        },
                        {
                            "type": "text",
                            "text": "🟢 確認無誤後送出，系統會幫你儲存資料，並產生訊息供你轉傳給主管。",
                            "wrap": True,
                            "size": "sm"
                        },
                        {
                            "type": "text",
                            "text": "🟢 點選「查詢請假」可查看自己歷次請假紀錄。",
                            "wrap": True,
                            "size": "sm"
                        },
                        {
                            "type": "text",
                            "text": "🟢 點選「使用教學」可隨時再次查看本說明。",
                            "wrap": True,
                            "size": "sm"
                        },
                        {
                            "type": "text",
                            "text": "📞 若無法請假或查詢，請聯絡管理員協助設定主管。",
                            "wrap": True,
                            "size": "sm",
                            "color": "#888888"
                        }
                    ]
                }
            }
        )

        line_bot_api.reply_message(
            event.reply_token,
            messages=[usage_flex]
        )
        return
