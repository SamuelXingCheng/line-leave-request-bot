# attendance_handler.py
from linebot.models import TextSendMessage, LocationAction, QuickReply, QuickReplyButton
from datetime import datetime
import math
import logging

COMPANY_LOCATION = {"lat": 24.13384, "lng": 120.68162}
ALLOWED_RADIUS = 200  # 公尺

class AttendanceHandler:
    def __init__(self, user_id, line_bot_api, event, user_sessions):
        self.user_id = user_id
        self.line_bot_api = line_bot_api
        self.event = event
        self.user_sessions = user_sessions

    def handle(self):
        logging.info(f"[AttendanceHandler] 收到訊息事件: {self.event}")

        # --- dump message ---
        try:
            logging.info(f"[AttendanceHandler] event.message dump: {vars(self.event.message)}")
        except Exception as e:
            logging.warning(f"[AttendanceHandler] 無法轉換 event.message: {e}, raw={self.event.message}")

        user_text = ""
        if hasattr(self.event.message, "text"):
            user_text = self.event.message.text.strip()
            logging.info(f"[AttendanceHandler] 使用者文字輸入: {user_text}")

        # ✅ 上班打卡
        if user_text == "/上班打卡":
            self._reply_safe(
                TextSendMessage(
                    text="請傳送您的定位資訊以完成【上班打卡】",
                    quick_reply=QuickReply(
                        items=[QuickReplyButton(action=LocationAction(label="傳送現在位置"))]
                    )
                )
            )
            self.user_sessions[self.user_id] = {"mode": "上班"}
            logging.info(f"[AttendanceHandler] user_sessions: {self.user_sessions}")
            return True

        # ✅ 下班打卡
        if user_text == "/下班打卡":
            self._reply_safe(
                TextSendMessage(
                    text="請傳送您的定位資訊以完成【下班打卡】",
                    quick_reply=QuickReply(
                        items=[QuickReplyButton(action=LocationAction(label="📍傳送現在位置"))]
                    )
                )
            )
            self.user_sessions[self.user_id] = {"mode": "下班"}
            logging.info(f"[AttendanceHandler] user_sessions: {self.user_sessions}")
            return True

        # ✅ 使用者傳送定位
        msg_type = getattr(self.event.message, "type", None)
        logging.info(f"[AttendanceHandler] event.message.type = {msg_type}")

        if msg_type and msg_type.lower() == "location":
            logging.info(f"[AttendanceHandler] 收到定位訊息, user_id={self.user_id}")
            return self._handle_location()

        return False

    def _handle_location(self):
        mode = self.user_sessions.get(self.user_id, {}).get("mode")
        logging.info(f"[AttendanceHandler] 進入 _handle_location, mode={mode}")

        if not mode:
            logging.warning(f"[AttendanceHandler] 找不到 user_sessions[{self.user_id}]")
            return False

        # --- 抓取座標 ---
        lat = getattr(self.event.message, "latitude", None)
        lng = getattr(self.event.message, "longitude", None)
        logging.info(f"[AttendanceHandler] 嘗試解析座標 lat={lat}, lng={lng}")

        if lat is None or lng is None:
            logging.error(f"[AttendanceHandler] event.message 沒有 latitude/longitude，內容={vars(self.event.message)}")
            return False

        # --- 計算距離 ---
        distance = self._calculate_distance(lat, lng)
        logging.info(f"[AttendanceHandler] 計算距離 = {distance:.2f} 公尺")

        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

        if distance <= ALLOWED_RADIUS:
            msg = f"✅ {mode}打卡成功！時間：{now}"
        else:
            msg = f"⚠️ 您不在公司範圍內（距離 {int(distance)} 公尺），請確認位置或事後補充說明。"

        logging.info(f"[AttendanceHandler] 回覆訊息: {msg}")
        self._reply_safe(TextSendMessage(text=msg))

        if self.user_id in self.user_sessions:
            del self.user_sessions[self.user_id]
            logging.info(f"[AttendanceHandler] 清除 user_sessions[{self.user_id}]")

        return True

    def _reply_safe(self, message):
        """安全回覆，避免 reply_token 錯誤沒被發現"""
        try:
            self.line_bot_api.reply_message(self.event.reply_token, message)
            logging.info("[AttendanceHandler] 成功回覆訊息")
        except Exception as e:
            logging.error(f"[AttendanceHandler] 回覆訊息失敗: {e}")

    def _calculate_distance(self, lat, lng):
        """計算 GPS 距離 (Haversine formula)"""
        R = 6371000  # 地球半徑（公尺）
        phi1 = math.radians(COMPANY_LOCATION["lat"])
        phi2 = math.radians(lat)
        d_phi = math.radians(lat - COMPANY_LOCATION["lat"])
        d_lambda = math.radians(lng - COMPANY_LOCATION["lng"])

        a = math.sin(d_phi/2)**2 + math.cos(phi1) * math.cos(phi2) * math.sin(d_lambda/2)**2
        c = 2 * math.atan2(math.sqrt(a), math.sqrt(1-a))
        return R * c
