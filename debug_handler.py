# debug_handler.py
import logging
import json

class DebugHandler:
    def __init__(self, event):
        self.event = event

    def handle(self):
        try:
            # 轉成 JSON string，方便看結構
            dump = json.dumps(self.event.__dict__, default=str, ensure_ascii=False, indent=2)
            logging.info(f"[DebugHandler] 收到完整 event:\n{dump}")

            # 如果有 message 就印更多細節
            if hasattr(self.event, "message"):
                try:
                    logging.info(f"[DebugHandler] event.message 內容: {vars(self.event.message)}")
                except Exception:
                    logging.info(f"[DebugHandler] event.message 原始: {self.event.message}")
        except Exception as e:
            logging.error(f"[DebugHandler] 無法 dump event: {e}")
        return False  # 永遠回 False，不會吃掉事件
