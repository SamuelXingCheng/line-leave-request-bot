# main.py
import os
import logging
from flask import Flask, request, abort
from dotenv import load_dotenv
load_dotenv()
from linebot import LineBotApi, WebhookParser
from linebot.exceptions import InvalidSignatureError
from linebot.models import MessageEvent, TextMessage
from handlers import handle_message  # 你的原始 handler 可重用

# 載入 .env


app = Flask(__name__)
logging.basicConfig(level=logging.INFO)

line_bot_api = LineBotApi(os.getenv("LINE_CHANNEL_ACCESS_TOKEN"))
parser = WebhookParser(os.getenv("LINE_CHANNEL_SECRET"))

@app.route("/")
def home():
    return "✅ LINE 請假 Bot 運行中！"

@app.route("/callback", methods=["POST"])
def callback():
    signature = request.headers.get("X-Line-Signature", "")
    body = request.get_data(as_text=True)
    logging.info(f"📥 收到 LINE webhook 請求：{body}")

    try:
        events = parser.parse(body, signature)
    except InvalidSignatureError:
        logging.error("❌ LINE 簽名驗證失敗")
        abort(400)

    for event in events:
        if isinstance(event, MessageEvent) and isinstance(event.message, TextMessage):
            handle_message(event, line_bot_api)

    return "OK"

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8000, debug=True)
