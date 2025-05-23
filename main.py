import os
import logging
from dotenv import load_dotenv
# 載入 .env 環境變數
load_dotenv()

from flask import Flask, request, abort
from linebot import LineBotApi, WebhookParser
from linebot.exceptions import InvalidSignatureError
from linebot.models import MessageEvent, TextMessage
from handlers import handle_message

# 初始化 Flask 應用
app = Flask(__name__)
logging.basicConfig(level=logging.INFO)

# 初始化 LINE SDK
line_bot_api = LineBotApi(os.getenv("LINE_CHANNEL_ACCESS_TOKEN"))
parser = WebhookParser(os.getenv("LINE_CHANNEL_SECRET"))


@app.route("/")
def health_check():
    return "✅ LINE 請假簽核系統正在運行"


@app.route("/callback", methods=['POST'])
def callback():
    signature = request.headers.get('X-Line-Signature')
    body = request.get_data(as_text=True)

    if not signature:
        logging.error("❌ 缺少 X-Line-Signature 標頭")
        abort(400)

    try:
        events = parser.parse(body, signature)
    except InvalidSignatureError:
        logging.error("❌ LINE 簽名驗證失敗")
        abort(400)

    for event in events:
        if isinstance(event, MessageEvent) and isinstance(event.message, TextMessage):
            logging.info(f"✅ 收到使用者訊息：{event.message.text}")
            handle_message(event, line_bot_api)

    return 'OK'


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8000, debug=True)