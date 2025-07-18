# leave_flow_handler.py
from datetime import datetime, timedelta
from linebot.models import TextSendMessage
from session_manager import UserSession
from firebase_db import get_user_info_by_line_id, save_request
from utils import reply_quick_reply, parse_date_range, map_time_label, daterange, normalize_date, build_forward_message
import uuid

class LeaveFlowHandler:
    def __init__(self, user_id, line_bot_api, event, session_store):
        self.user_id = user_id
        self.line_bot_api = line_bot_api
        self.event = event
        self.session = UserSession(user_id, session_store)
        self.user_text = event.message.text

    def handle(self):
        step = self.session.get_step()
        if self.user_text == "/請假":
            return self.start_leave_flow()
        elif step == "leave_date":
            return self.handle_leave_date()
        elif step == "leave_time":
            return self.handle_leave_time()
        elif step == "leave_custom_time":
            return self.handle_leave_custom_time()
        elif step == "leave_type":
            return self.handle_leave_type()
        elif step == "leave_reason_detail":
            return self.handle_leave_reason_detail()
        return False  # 非請假流程

    def start_leave_flow(self):
        self.session.reset()
        self.session.set_step("leave_date")

        today = datetime.now().strftime("%Y/%m/%d")
        tomorrow = (datetime.now() + timedelta(days=1)).strftime("%Y/%m/%d")
        day_after = (datetime.now() + timedelta(days=2)).strftime("%Y/%m/%d")

        reply_quick_reply(
            self.line_bot_api,
            self.event.reply_token,
            "📅 請選擇請假日期或自訂輸入：",
            [
                (f"📆 今天 ({today})", today),
                (f"📆 明天 ({tomorrow})", tomorrow),
                (f"📆 後天 ({day_after})", day_after),
                ("✏️ 自訂日期", "自訂日期"),
                ("❌ 取消請假", "/取消請假")
            ]
        )
        return True

    def handle_leave_date(self):
        if self.user_text == "自訂日期":
            self.line_bot_api.reply_message(
                self.event.reply_token,
                TextSendMessage(text="請輸入請假日期（例如：2025/06/17 或 2025/06/17-2025/06/18）：")
            )
            return True

        start, end = parse_date_range(self.user_text)
        if not start:
            self.line_bot_api.reply_message(self.event.reply_token, TextSendMessage(text="❌ 日期格式錯誤，請重新輸入。"))
            return True

        self.session.set("start_date", start)
        self.session.set("end_date", end)
        self.session.set_step("leave_time")

        reply_quick_reply(self.line_bot_api, self.event.reply_token, "🕒 請選擇請假時段：", [
            ("整天", "整天"),
            ("上午", "上午"),
            ("下午", "下午"),
            ("自定", "自訂時段"),
            ("❌ 取消請假", "/取消請假")
        ])
        return True

    def handle_leave_time(self):
        if self.user_text in ["整天", "上午", "下午"]:
            self.session.set("time", map_time_label(self.user_text))
            self.session.set_step("leave_type")
            reply_quick_reply(
                self.line_bot_api,
                self.event.reply_token,
                "📝 請選擇請假類型：",
                self.get_leave_types()
            )
            return True
        elif self.user_text == "自訂時段":
            self.session.set_step("leave_custom_time")
            self.line_bot_api.reply_message(self.event.reply_token, TextSendMessage(text="請輸入時間範圍（格式：09:00-12:00）："))
            return True
        else:
            self.line_bot_api.reply_message(self.event.reply_token, TextSendMessage(text="❌ 請選擇有效的時段或輸入自訂時段。"))
            return True

    def handle_leave_custom_time(self):
        if "-" in self.user_text:
            start_time, end_time = self.user_text.split("-")
            self.session.set("time", (start_time.strip(), end_time.strip()))
            self.session.set_step("leave_type")
            reply_quick_reply(
                self.line_bot_api,
                self.event.reply_token,
                "📝 請選擇請假類型：",
                self.get_leave_types()
            )
        else:
            self.line_bot_api.reply_message(self.event.reply_token, TextSendMessage(text="❌ 時間格式錯誤，請重新輸入：09:00-12:00"))
        return True

    def handle_leave_type(self):
        self.session.set("type", self.user_text.strip())
        self.session.set_step("leave_reason_detail")
        self.line_bot_api.reply_message(self.event.reply_token, TextSendMessage(text="✏️ 請輸入請假事由說明（例如：感冒不適、家中有事）："))
        return True

    def handle_leave_reason_detail(self):
        self.session.set("reason", self.user_text.strip())
        user_info = get_user_info_by_line_id(self.user_id)
        if not user_info:
            self.line_bot_api.reply_message(self.event.reply_token, TextSendMessage(text="❌ 無法取得使用者資訊。"))
            return True

        # ✅ 產生共用的 group_id
        request_group_id = str(uuid.uuid4())

        # ✅ 把 session 資料暫存起來（可讀性更好）
        start_date = self.session.get("start_date")
        end_date = self.session.get("end_date")
        start_time, end_time = self.session.get("time")
        reason = self.session.get("reason")
        leave_type = self.session.get("type")
        supervisor_ids = user_info.get("supervisor_ids", [])
        user_name = user_info["name"]

        messages = []
        requests_data = []
        
        for date in daterange(start_date, end_date):
            date_str = normalize_date(date.strftime("%Y/%m/%d"))

            save_request(
                user_id=self.user_id,
                user_name=user_name,
                start_date=date_str,
                start_time=start_time,
                end_date=date_str,
                end_time=end_time,
                reason=reason,
                leave_type=leave_type,
                supervisor_ids=supervisor_ids,
                group_id=request_group_id  # ✅ 傳入共用的 group_id
            )
            requests_data.append({
                "start_date": date_str,
                "start_time": start_time,
                "end_time": end_time,
                "leave_type": leave_type,
                "reason": reason,
                "name": user_name,
                "supervisor_ids": supervisor_ids
            })
        if supervisor_ids:
            forward_msg, user_hint_msg = build_forward_message(requests_data, request_group_id)
            messages.append(TextSendMessage(text=forward_msg))
            if user_hint_msg:
                messages.append(TextSendMessage(text=user_hint_msg))
        else:
            messages.append(TextSendMessage(
                text="⚠️ 你尚未設定主管，請聯絡管理員設定 supervisor_ids。請假資料已儲存，但無法簽核。"))

        self.line_bot_api.reply_message(self.event.reply_token, messages)
        self.session.clear()
        return True

    def get_leave_types(self):
        return [
            ("🌴 特休", "特休"),
            ("📌 事假", "事假"),
            ("🤒 病假", "病假"),
            ("🏛️ 公假", "公假"),
            ("💒 婚假", "婚假"),
            ("🤰 產假", "產假"),
            ("🖤 喪假", "喪假"),
            ("❌ 取消請假", "/取消請假")
        ]
