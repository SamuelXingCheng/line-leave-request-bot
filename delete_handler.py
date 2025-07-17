# delete_handler.py
from linebot.models import TextSendMessage
from utils import handle_delete_request  # 從 utils 匯入現有邏輯

class DeleteHandler:
    def __init__(self, user_id, line_bot_api, event):
        self.user_id = user_id
        self.line_bot_api = line_bot_api
        self.event = event
        self.user_text = event.message.text

    def handle(self):
        if self.user_text.startswith("/刪除請假"):
            parts = self.user_text.split()
            if len(parts) == 2:
                request_id = parts[1]
                handle_delete_request(self.event, self.line_bot_api, request_id)
            else:
                self.line_bot_api.reply_message(
                    self.event.reply_token,
                    TextSendMessage(text="❗請使用格式：/刪除請假 [請假ID]")
                )
            return True
        return False
