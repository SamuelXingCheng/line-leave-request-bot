# cancel_handler.py
from linebot.models import TextSendMessage
from session_manager import UserSession

class CancelHandler:
    def __init__(self, user_id, line_bot_api, event, session_store):
        self.user_id = user_id
        self.line_bot_api = line_bot_api
        self.event = event
        self.session = UserSession(user_id, session_store)
        self.user_text = event.message.text

    def handle(self):
        if self.user_text in ["/取消請假", "/取消查詢", "/取消補打卡"]:
            action = self._get_action_name()
            self.session.clear()
            self.line_bot_api.reply_message(
                self.event.reply_token,
                TextSendMessage(text=f"✅ 已取消{action}")
            )
            return True
        return False

    def _get_action_name(self):
        if self.user_text == "/取消請假":
            return "請假"
        elif self.user_text == "/取消查詢":
            return "查詢"
        elif self.user_text == "/取消補打卡":
            return "補打卡"
        return "操作"
