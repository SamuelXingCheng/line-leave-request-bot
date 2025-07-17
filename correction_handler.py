# correction_handler.py

from datetime import datetime, timedelta
from pytz import timezone
import urllib.parse
import os

from linebot.models import TextSendMessage
from session_manager import UserSession
from utils import reply_quick_reply
from firebase_db import get_user_info_by_line_id, save_correction

class CorrectionHandler:
    def __init__(self, user_id, line_bot_api, event, session_store):
        self.user_id = user_id
        self.line_bot_api = line_bot_api
        self.event = event
        self.session = UserSession(user_id, session_store)
        self.user_text = event.message.text
        self.tz = timezone("Asia/Taipei")

    def handle(self):
        step = self.session.get_step()

        if self.user_text.startswith("/補打卡"):
            self.session.reset()
            self.session.set_step("correction_date")

            now = datetime.now(self.tz)
            today = now.strftime("%Y/%m/%d")
            yesterday = (now - timedelta(days=1)).strftime("%Y/%m/%d")

            reply_quick_reply(
                self.line_bot_api,
                self.event.reply_token,
                "📅 請選擇補打卡的日期：",
                [
                    (f"📆 今天 ({today})", today),
                    (f"📆 昨天 ({yesterday})", yesterday),
                    ("✏️ 自訂日期", "自訂日期"),
                    ("❌ 取消補打卡", "/取消補打卡")
                ]
            )
            return True

        if step == "correction_date":
            if self.user_text == "自訂日期":
                self.line_bot_api.reply_message(
                    self.event.reply_token,
                    TextSendMessage(text="請輸入補打卡日期（例如：2025/07/16）：")
                )
                return True

            try:
                correction_date = datetime.strptime(self.user_text.strip(), "%Y/%m/%d").date()
            except ValueError:
                self.line_bot_api.reply_message(self.event.reply_token, TextSendMessage(text="❌ 日期格式錯誤，請重新輸入。"))
                return True

            self.session.set("correction_date", correction_date)
            self.session.set_step("correction_type")
            reply_quick_reply(
                self.line_bot_api,
                self.event.reply_token,
                "🕒 請選擇補打卡的類型：",
                [
                    ("🟢 上班", "上班"),
                    ("🔴 下班", "下班"),
                    ("❌ 取消補打卡", "/取消補打卡")
                ]
            )
            return True

        if step == "correction_type":
            if self.user_text in ["上班", "下班"]:
                self.session.set("correction_type", self.user_text)
                self.session.set_step("correction_time")
                reply_quick_reply(
                    self.line_bot_api,
                    self.event.reply_token,
                    "請選擇補打卡時間或自訂輸入：",
                    [
                        ("🕗 08:30", "08:30"),
                        ("🕛 12:00", "12:00"),
                        ("🕐 13:00", "13:00"),
                        ("🕔 17:30", "17:30"),
                        ("✏️ 自訂時間", "自訂時間"),
                        ("❌ 取消補打卡", "/取消補打卡")
                    ]
                )
            else:
                self.line_bot_api.reply_message(self.event.reply_token, TextSendMessage(text="❌ 請選擇有效的補打卡類型（上班或下班）。"))
            return True

        if step == "correction_time":
            if self.user_text == "自訂時間":
                self.line_bot_api.reply_message(
                    self.event.reply_token,
                    TextSendMessage(text="請輸入補打卡時間（例如：08:30）：")
                )
                return True

            try:
                datetime.strptime(self.user_text.strip(), "%H:%M")
            except ValueError:
                self.line_bot_api.reply_message(self.event.reply_token, TextSendMessage(text="❌ 時間格式錯誤，請重新輸入（例如：08:30）"))
                return True

            self.session.set("correction_time", self.user_text.strip())
            self.session.set_step("correction_reason")
            reply_quick_reply(
                self.line_bot_api,
                self.event.reply_token,
                "📋 請選擇補打卡原因：",
                [
                    ("😅 忘記打卡", "忘記打卡"),
                    ("🚗 在外服事", "在外服事"),
                    ("✏️ 自訂", "自訂原因"),
                    ("❌ 取消補打卡", "/取消補打卡")
                ]
            )
            return True

        if step == "correction_reason":
            if self.user_text in ["忘記打卡", "在外服事"]:
                self.session.set("correction_reason", self.user_text)
                self.session.set_step("correction_complete")
            elif self.user_text == "自訂原因":
                self.session.set_step("custom_reason")
                self.line_bot_api.reply_message(self.event.reply_token, TextSendMessage(text="請輸入補打卡原因："))
                return True
            else:
                self.line_bot_api.reply_message(self.event.reply_token, TextSendMessage(text="❌ 請選擇有效的補打卡原因。"))
                return True

        if step == "custom_reason":
            self.session.set("correction_reason", self.user_text.strip())
            self.session.set_step("correction_complete")

        if self.session.get_step() == "correction_complete":
            correction_date = self.session.get("correction_date")
            correction_time = datetime.strptime(self.session.get("correction_time"), "%H:%M").time()
            correction_dt = self.tz.localize(datetime.combine(correction_date, correction_time))

            user_info = get_user_info_by_line_id(self.user_id)
            if not user_info:
                self.line_bot_api.reply_message(
                    self.event.reply_token,
                    TextSendMessage(text="❌ 無法取得使用者資訊")
                )
                return True

            correction_id = save_correction(
                user_id=self.user_id,
                user_name=user_info["name"],
                correction_type=self.session.get("correction_type"),
                correction_dt=correction_dt,
                reason=self.session.get("correction_reason"),
                supervisors=user_info.get("supervisor_ids", [])
            )

            approval_command = f"/同意補打卡 {correction_id}"
            
            bot_id = os.getenv("LINE_BOT_ID")  # 例如 @123xyz
            encoded_query = urllib.parse.quote(approval_command)
            approval_link = f"line://oaMessage/@{bot_id}/?{encoded_query}"
        
            approval_msg = (
                f"弟兄您好，\n\n"
                f"{user_info['name']} 因為「{self.session.get('correction_reason')}」，"
                f"於 {correction_dt.strftime('%Y/%m/%d %H:%M')} 補打卡（{self.session.get('correction_type')}）。\n\n"
                f"👉 點擊以下連結自動填入簽核指令：\n"
                f"{approval_link}\n\n"
                f"如不同意，請口頭告知即可，無需操作。"
            )
            messages = []
            messages.append(TextSendMessage(text=approval_msg))

            supervisor_ids = user_info.get("supervisor_ids", [])

            if supervisor_ids:
                from firebase_db import get_supervisor_names  # 避免循環 import 可放在這行
                supervisor_names = get_supervisor_names(supervisor_ids)
                user_hint_msg = (
                    "📌 請記得轉傳上方訊息給以下主管簽核：\n" +
                    "\n".join(f"- {name}" for name in supervisor_names)
                )
            else:
                user_hint_msg = None

            if user_hint_msg:
                    messages.append(TextSendMessage(text=user_hint_msg))

            self.line_bot_api.reply_message(self.event.reply_token, messages)
            self.session.clear()
            return True

        return False
