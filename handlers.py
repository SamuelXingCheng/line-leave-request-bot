# handlers.py
import logging
from linebot.models import TextMessage, TextSendMessage, FlexSendMessage, LocationMessage
import urllib
from menu_handler import MenuHandler
from register_handler import RegisterHandler
from delete_handler import DeleteHandler
from cancel_handler import CancelHandler
from leave_flow_handler import LeaveFlowHandler
from leave_query_handler import LeaveQueryHandler
from approval_handler import ApprovalHandler
from correction_handler import CorrectionHandler
from attendance_handler import AttendanceHandler
from debug_handler import DebugHandler

from datetime import datetime, timedelta
user_sessions = {}  # user_id ➝ {"start_date":..., "end_date":..., "time":..., "reason":...}

def handle_message(event, line_bot_api):
    # --- Debug 輔助，印出完整 event ---
    DebugHandler(event).handle()
    
    logging.info(f"[handle_message] 收到 event: {event}")
    logging.info(f"[handle_message] event.type={event.type}, message.type={getattr(event.message, 'type', None)}")

    # 僅處理文字 & 定位訊息
    if not isinstance(event.message, (TextMessage, LocationMessage)):
        logging.info(f"[handle_message] 忽略訊息類型: {type(event.message)}")
        return

    user_id = event.source.user_id

    if isinstance(event.message, TextMessage):
        user_text = event.message.text.strip()
    else:
        user_text = None

    logging.info(f"✅ 收到使用者訊息：{user_text or '[非文字訊息]'}")
    
    # ✅ 靜態訊息處理 (僅處理文字訊息)
    if user_text and MenuHandler(user_text, event, line_bot_api).handle():
        return

    # ✅ 註冊指令：/註冊 王小明
    if user_text and RegisterHandler(user_id, user_text, event, line_bot_api, user_sessions).handle():
        return

    # --- 刪除未簽劾請假 ---
    if user_text and DeleteHandler(user_id, line_bot_api, event).handle():
        return

    # --- 中止流程 ---
    if user_text and CancelHandler(user_id, line_bot_api, event, user_sessions).handle():
        return

    # --- 請假處理 ---
    if user_text and LeaveFlowHandler(user_id, line_bot_api, event, user_sessions).handle():
        return

    # --- 查詢請假 ---
    if user_text and LeaveQueryHandler(user_id, line_bot_api, event, user_sessions).handle():
        return

    # --- 同意請假/補打卡 ---
    if user_text and ApprovalHandler(user_id, line_bot_api, event).handle():
        return

    # --- 補打卡 ---
    if user_text and CorrectionHandler(user_id, line_bot_api, event, user_sessions).handle():
        return
    
    # --- 上下班打卡 ---
    if AttendanceHandler(user_id, line_bot_api, event, user_sessions).handle():
        return
