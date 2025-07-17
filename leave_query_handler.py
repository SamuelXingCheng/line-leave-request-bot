# leave_query_handler.py

from datetime import datetime, timedelta
from linebot.models import TextSendMessage
from session_manager import UserSession
from firebase_db import get_user_info_by_line_id
from utils import reply_quick_reply, handle_query_pending_leaves


class LeaveQueryHandler:
    def __init__(self, user_id, line_bot_api, event, session_store):
        self.user_id = user_id
        self.line_bot_api = line_bot_api
        self.event = event
        self.user_text = event.message.text.strip()
        self.session = UserSession(user_id, session_store)
        self.user_text = event.message.text

    def handle(self):
        if self.user_text.startswith("/查詢請假"):
            self.session.reset()
            self.session.set_step("query_range")
            reply_quick_reply(
                self.line_bot_api,
                self.event.reply_token,
                "請選擇要查詢的範圍：",
                [
                    ("📅 今天", "查今天"),
                    ("📆 本週", "查本週"),
                    ("📊 本月", "查本月"),
                    ("📋 今年", "查今年"),
                    ("✏️ 自訂區間", "自訂查詢"),
                    ("❌ 取消", "/取消查詢")
                ]
            )
            return True

        if self.session.get_step() == "query_range":
            today = datetime.now().date()

            if self.user_text == "查今天":
                start_date = end_date = today
            elif self.user_text == "查本週":
                start_date = today - timedelta(days=today.weekday())
                end_date = start_date + timedelta(days=6)
            elif self.user_text == "查本月":
                start_date = today.replace(day=1)
                next_month = start_date.replace(day=28) + timedelta(days=4)
                end_date = next_month.replace(day=1) - timedelta(days=1)
            elif self.user_text == "查今年":
                start_date = today.replace(month=1, day=1)
                end_date = today.replace(month=12, day=31)
            elif self.user_text == "自訂查詢":
                self.session.set_step("custom_query")
                self.line_bot_api.reply_message(
                    self.event.reply_token,
                    TextSendMessage(text="請輸入起訖日期（格式：2025/07/01-2025/07/15）")
                )
                return True
            elif self.user_text == "/取消查詢":
                self.session.clear()
                self.line_bot_api.reply_message(
                    self.event.reply_token,
                    TextSendMessage(text="✅ 已取消查詢")
                )
                return True
            else:
                self.line_bot_api.reply_message(
                    self.event.reply_token,
                    TextSendMessage(text="❌ 指令無效，請重新選擇。")
                )
                return True

            handle_query_pending_leaves(self.event, self.line_bot_api, start_date, end_date)
            self.session.clear()
            return True

        if self.session.get_step() == "custom_query":
            try:
                start_str, end_str = self.user_text.split("-")
                start_date = datetime.strptime(start_str.strip(), "%Y/%m/%d").date()
                end_date = datetime.strptime(end_str.strip(), "%Y/%m/%d").date()
            except Exception:
                self.line_bot_api.reply_message(
                    self.event.reply_token,
                    TextSendMessage(text="❌ 日期格式錯誤，請用 2025/07/01-2025/07/15")
                )
                return True

            handle_query_pending_leaves(self.event, self.line_bot_api, start_date, end_date)
            self.session.clear()
            return True

        return False
