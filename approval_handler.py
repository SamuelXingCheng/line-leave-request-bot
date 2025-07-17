# approval_handler.py
import os
from linebot.models import TextSendMessage
from firebase_db import get_db
from utils import approve, summarize_leave_days_and_hours, format_tw_time
from urllib.parse import quote_plus


class ApprovalHandler:
    def __init__(self, user_id, line_bot_api, event):
        self.user_id = user_id  # 主管 ID
        self.line_bot_api = line_bot_api
        self.event = event
        self.user_text = event.message.text

    def handle(self):
        if self.user_text.startswith("/同意請假"):
            parts = self.user_text.split()
            if len(parts) == 3:
                group_id = parts[1]
                user_name = parts[2]
                self.handle_approve_by_group(group_id, user_name)
            else:
                self.reply("❗請使用格式：/同意請假 請假編號 員工姓名")
            return True

        if self.user_text.startswith("/同意補打卡"):
            parts = self.user_text.split()
            if len(parts) == 2:
                correction_id = parts[1]
                self.handle_approve_correction(correction_id)
            else:
                self.reply("❗請使用格式：/同意補打卡 補打卡ID")
            return True

        return False  # 非簽核指令

    def handle_approve_by_group(self, group_id, user_name):
        db = get_db()
        docs = db.collection("requests") \
                 .where("request_group_id", "==", group_id) \
                 .where("user_name", "==", user_name) \
                 .stream()

        approved_count = 0
        for doc in docs:
            data = doc.to_dict()
            approvals = data.get("approvals", {})
            if approvals.get(self.user_id) == "pending":
                approve(doc, data, self.user_id, self.line_bot_api)
                approved_count += 1

        if approved_count > 0:
            group_docs = db.collection("requests") \
                .where("request_group_id", "==", group_id) \
                .stream()

            summary_text = summarize_leave_days_and_hours(group_docs)
            self.reply(f"✅ 已完成對 {user_name} 的請假簽核（{summary_text}）")
        else:
            self.reply(f"❌ 找不到「{user_name}」的待簽核請假單（編號 {group_id}）。")

    def handle_approve_correction(self, correction_id):
        db = get_db()
        doc_ref = db.collection("corrections").document(correction_id)
        doc = doc_ref.get()
        if not doc.exists:
            self.reply("❌ 找不到該筆補打卡資料。")
            return

        data = doc.to_dict()
        approvals = data.get("approvals", {})
        if approvals.get(self.user_id) != "pending":
            self.reply("⚠️ 你無需簽核此補打卡申請，或已簽核過。")
            return

        # ✅ 更新狀態
        approvals[self.user_id] = "approved"
        doc_ref.update({f"approvals.{self.user_id}": "approved"})

        # ✅ 若所有主管皆簽核完畢，標記為 approved 並通知員工
        if all(v == "approved" for v in approvals.values()):
            doc_ref.update({"status": "approved"})

            correction_type = data.get("correction_type", "")
            correction_dt = format_tw_time(data.get("correction_dt"))
            user_id = data.get("user_id")
            user_name = data.get("user_name", "")
            reason = data.get("reason", "")

            message = (
                f"✅ 你的補打卡申請已通過所有主管簽核！\n"
                f"👤 員工：{user_name}\n"
                f"🕒 類型：{correction_type}\n"
                f"📅 時間：{correction_dt}\n"
                f"📋 原因：{reason}"
            )
            try:
                self.line_bot_api.push_message(user_id, TextSendMessage(text=message))
            except Exception as e:
                print(f"❌ 推播補打卡通知失敗：{e}")

        self.reply("✅ 已完成補打卡簽核。")

    def reply(self, text):
        self.line_bot_api.reply_message(
            self.event.reply_token,
            TextSendMessage(text=text)
        )
