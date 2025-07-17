# handlers.py
import logging
from linebot.models import TextMessage, TextSendMessage, FlexSendMessage
import urllib
from menu_handler import MenuHandler
from register_handler import RegisterHandler
from delete_handler import DeleteHandler
from cancel_handler import CancelHandler
from leave_flow_handler import LeaveFlowHandler
from leave_query_handler import LeaveQueryHandler
from approval_handler import ApprovalHandler
from correction_handler import CorrectionHandler

from datetime import datetime, timedelta
user_sessions = {}  # user_id ➝ {"start_date":..., "end_date":..., "time":..., "reason":...}

def handle_message(event, line_bot_api):
    if not isinstance(event.message, TextMessage):
        return

    user_id = event.source.user_id
    user_text = event.message.text.strip()

    logging.info(f"✅ 收到使用者訊息：{user_text}")
    
    # ✅ 靜態訊息處理
    if MenuHandler(user_text, event, line_bot_api).handle():
        return

    # ✅ 註冊指令：/註冊 王小明
    if RegisterHandler(user_id, user_text, event, line_bot_api, user_sessions).handle():
        return

    # --- 刪除未簽劾請假 ---
    delete_handler = DeleteHandler(user_id, line_bot_api, event)
    if delete_handler.handle():
        return

    # --- 中止流程 ---
    if CancelHandler(user_id, line_bot_api, event, user_sessions).handle():
        return

    # --- 請假處理 ---
    if LeaveFlowHandler(user_id, line_bot_api, event, user_sessions).handle():
        return

    # --- 查詢請假 ---
    if LeaveQueryHandler(user_id, line_bot_api, event, user_sessions).handle():
        return

    # --- 同意請假/補打卡 ---
    if ApprovalHandler(user_id, line_bot_api, event).handle():
        return

    # --- 補打卡 ---
    correction = CorrectionHandler(user_id, line_bot_api, event, user_sessions)
    if correction.handle():
        return
        