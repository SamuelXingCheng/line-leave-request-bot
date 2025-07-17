# menu_handler.py

from linebot.models import FlexSendMessage

class MenuHandler:
    def __init__(self, user_text, event, line_bot_api):
        self.user_text = user_text
        self.event = event
        self.line_bot_api = line_bot_api

    def handle(self):
        if self.user_text == "/menu":
            self.send_main_menu()
            return True
        if self.user_text.startswith("/如何使用"):
            self.send_usage_guide()
            return True
        return False

    def send_main_menu(self):
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
                        {"type": "button", "action": {"type": "message", "label": "📅 查詢請假", "text": "/查詢請假"}, "style": "secondary"},
                        {"type": "button", "action": {"type": "message", "label": "🔄 補打卡", "text": "/補打卡"}, "style": "secondary"},
                        {"type": "button", "action": {"type": "message", "label": "❓ 使用教學", "text": "/如何使用"}, "style": "secondary"}
                    ]
                }
            }
        )
        self.line_bot_api.reply_message(self.event.reply_token, flex)

    def send_usage_guide(self):
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
                            "text": "📘 系統操作說明",
                            "weight": "bold",
                            "size": "lg",
                            "color": "#1DB446",
                            "wrap": True
                        },
                        {
                            "type": "text",
                            "text": "🟢 請假申請：\n點選「我要請假」，依畫面選擇請假日期、時段與事由，送出後可複製訊息轉給主管簽核。",
                            "wrap": True,
                            "size": "sm"
                        },
                        {
                            "type": "text",
                            "text": "🟡 補打卡申請：\n點選「補打卡」，選擇日期、補打卡原因、上/下班與時間，送出後產生訊息供主管簽核。",
                            "wrap": True,
                            "size": "sm"
                        },
                        {
                            "type": "text",
                            "text": "📋 查詢紀錄：\n點選「查詢請假」，可查看近期所有請假或補打卡紀錄及簽核狀態。",
                            "wrap": True,
                            "size": "sm"
                        },
                        {
                            "type": "text",
                            "text": "🗑️ 刪除請假：\n輸入「/刪除請假 請假ID」，可刪除尚未簽核完成的請假申請。",
                            "wrap": True,
                            "size": "sm"
                        },
                        {
                            "type": "text",
                            "text": "✅ 請假簽核：\n主管收到訊息後，點擊查詢按鈕並依指示同意簽核。",
                            "wrap": True,
                            "size": "sm"
                        },
                        {
                            "type": "text",
                            "text": "📘 操作教學：\n可隨時點選「使用教學」查看本說明內容。",
                            "wrap": True,
                            "size": "sm"
                        },
                        {
                            "type": "text",
                            "text": "📞 若無法操作，請聯絡管理員協助設定主管或處理問題。",
                            "wrap": True,
                            "size": "sm",
                            "color": "#888888"
                        }
                    ]
                }
            }
        )
        self.line_bot_api.reply_message(self.event.reply_token, messages=[usage_flex])
