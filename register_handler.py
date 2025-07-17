# register_handler.py

from linebot.models import TextSendMessage
from firebase_db import ensure_user_registered
from user_session import UserSession

class RegisterHandler:
    def __init__(self, user_id, user_text, event, line_bot_api, user_sessions):
        self.user_id = user_id
        self.user_text = user_text
        self.event = event
        self.line_bot_api = line_bot_api
        self.session = UserSession(user_id, user_sessions)

    def handle(self):
        # Step 1: 如果是 /註冊 指令
        if self.user_text == "/註冊":
            self.session.set("state", "awaiting_name")
            self.reply("請輸入你的姓名，例如：王小明")
            return True

        # Step 2: 若 session 狀態為等待姓名
        if self.session.get("state") == "awaiting_name":
            name = self.user_text.strip()
            if not name:
                self.reply("⚠️ 請輸入有效的姓名，例如：王小明")
                return True

            success = ensure_user_registered(self.user_id, name)
            if success:
                self.reply(f"✅ {name} 已註冊成功！")
            else:
                self.reply("⚠️ 你已經註冊過了！")

            self.session.clear()
            return True

        return False

    def reply(self, text):
        self.line_bot_api.reply_message(
            self.event.reply_token,
            TextSendMessage(text=text)
        )
