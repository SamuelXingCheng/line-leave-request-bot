# handlers.py
import logging
from linebot.models import MessageEvent, TextMessage, TextSendMessage, FlexSendMessage
from utils import handle_approve_by_group, build_forward_message, parse_leave_command, handle_query_pending_leaves, handle_delete_request
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
    if user_text.startswith("/刪除請假"):
        parts = user_text.split()
        if len(parts) == 2:
            request_id = parts[1]
            handle_delete_request(event, line_bot_api, request_id)
        else:
            line_bot_api.reply_message(
                event.reply_token,
                TextSendMessage(text="❗請使用格式：/刪除請假 [請假ID]")
            )
        return


    # ✅ 任何階段輸入 /取消請假 都可中止流程
    if user_text == "/取消請假":
        user_sessions.pop(user_id, None)  # 清除暫存請假資料
        line_bot_api.reply_message(
            event.reply_token,
            TextSendMessage(text="❌ 已取消請假流程。如需重新申請，請點選「我要請假」。")
        )
        return

    # 📝 Flex + Quick Reply 請假互動流程
    session = user_sessions.get(user_id, {})

    if user_text == "/請假":
        user_sessions[user_id] = {"step": "date"}

        today = datetime.now().strftime("%Y/%m/%d")
        tomorrow = (datetime.now() + timedelta(days=1)).strftime("%Y/%m/%d")
        day_after = (datetime.now() + timedelta(days=2)).strftime("%Y/%m/%d")

        reply_quick_reply(
            line_bot_api,
            event.reply_token,
            "📅 請選擇請假日期或自訂輸入：",
            [
                (f"📆 今天 ({today})", today),
                (f"📆 明天 ({tomorrow})", tomorrow),
                (f"📆 後天 ({day_after})", day_after),
                ("✏️ 自訂日期", "自訂日期"),
                ("❌ 取消請假", "/取消請假")
            ]
        )
        return

    if session.get("step") == "date":
        if user_text == "自訂日期":
            # 進入自訂日期輸入
            line_bot_api.reply_message(
                event.reply_token,
                TextSendMessage(text="請輸入請假日期（例如：2025/06/17 或 2025/06/17-2025/06/18）：")
            )
            return
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
            ("自定", "自訂時段"),
            ("❌ 取消請假", "/取消請假")
        ])
        return
    
    if session.get("step") == "time":
        if user_text in ["整天", "上午", "下午"]:
            session["time"] = map_time_label(user_text)
            session["step"] = "reason"
            user_sessions[user_id] = session
            reply_quick_reply(
                line_bot_api,
                event.reply_token,
                "📝 請選擇請假類型或輸入自訂事由：",
                [
                    ("🤒 病假", "病假"),
                    ("📌 事假", "事假"),
                    ("🏛️ 公假", "公假"),
                    ("🖤 喪假", "喪假"),
                    ("✏️ 自訂", "自訂事由"),
                    ("❌ 取消請假", "/取消請假")
                ]
            )
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
            reply_quick_reply(
                line_bot_api,
                event.reply_token,
                "📝 請選擇請假類型或輸入自訂事由：",
                [
                    ("🤒 病假", "病假"),
                    ("📌 事假", "事假"),
                    ("🏛️ 公假", "公假"),
                    ("🖤 喪假", "喪假"),
                    ("✏️ 自訂", "自訂事由"),
                    ("❌ 取消請假", "/取消請假")
                ]
            )
        else:
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text="❌ 時間格式錯誤，請重新輸入：09:00-12:00"))
        return

    if session.get("step") == "reason":
        # 若點選「✏️ 自訂」後輸入內容
        if user_text == "自訂事由":
            line_bot_api.reply_message(
                event.reply_token,
                TextSendMessage(text="✏️ 請輸入請假事由（例如：家中有事等）：")
            )
            return

        # ✅ 記錄事由
        session["reason"] = user_text.strip()
        user_info = get_user_info_by_line_id(user_id)
        if not user_info:
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text="❌ 無法取得使用者資訊。"))
            return

        # ⬇️ 以下原有儲存與通知邏輯
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
                messages.append(TextSendMessage(
                    text="⚠️ 你尚未設定主管，請聯絡管理員設定 supervisor_ids。請假資料已儲存，但無法簽核。"))

        line_bot_api.reply_message(event.reply_token, messages)
        del user_sessions[user_id]
        return


    if user_text.startswith("/查詢請假"):
        user_sessions[user_id] = {"step": "query_range"}
        reply_quick_reply(
            line_bot_api,
            event.reply_token,
            "請選擇要查詢的範圍：",
            [
                ("📅 今天", "查今天"),
                ("📆 本週", "查本週"),
                ("📊 本月", "查本月"),
                ("📋 查今年", "查今年"),
                ("✏️ 自訂區間", "自訂查詢"),
                ("❌ 取消", "/取消查詢")
            ]
        )
        return

    if session.get("step") == "query_range":
        today = datetime.now().date()

        if user_text == "查今天":
            start_date = end_date = today
        elif user_text == "查本週":
            start_date = today - timedelta(days=today.weekday())
            end_date = start_date + timedelta(days=6)
        elif user_text == "查本月":
            start_date = today.replace(day=1)
            next_month = start_date.replace(day=28) + timedelta(days=4)
            end_date = next_month.replace(day=1) - timedelta(days=1)
        elif user_text == "查今年":
            start_date = today.replace(month=1, day=1)
            end_date = today.replace(month=12, day=31)
        elif user_text == "自訂查詢":
            session["step"] = "custom_query"
            user_sessions[user_id] = session
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text="請輸入起訖日期（格式：2025/07/01-2025/07/15）"))
            return
        elif user_text == "/取消查詢":
            del user_sessions[user_id]
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text="✅ 已取消查詢"))
            return
        else:
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text="❌ 指令無效，請重新選擇。"))
            return

        handle_query_pending_leaves(event, line_bot_api, start_date, end_date)
        del user_sessions[user_id]
        return

    if session.get("step") == "custom_query":
        try:
            start_str, end_str = user_text.split("-")
            start_date = datetime.strptime(start_str.strip(), "%Y/%m/%d").date()
            end_date = datetime.strptime(end_str.strip(), "%Y/%m/%d").date()
        except Exception:
            line_bot_api.reply_message(event.reply_token, TextSendMessage(text="❌ 日期格式錯誤，請用 2025/07/01-2025/07/15"))
            return

        handle_query_pending_leaves(event, line_bot_api, start_date, end_date)
        del user_sessions[user_id]
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
