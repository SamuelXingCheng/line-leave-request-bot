# firebase_db.py
import logging
import uuid
from datetime import datetime, timedelta
from firebase_admin import credentials, firestore, initialize_app
import os
from dotenv import load_dotenv
import pytz

load_dotenv()
cred_path = os.getenv("FIREBASE_CREDENTIAL_PATH")
cred = credentials.Certificate(cred_path)
initialize_app(cred)
db = firestore.client()

def get_db():
    return firestore.client()
    
def save_request(user_id, user_name, start_date, start_time, end_date, end_time, reason, supervisor_ids):
    tz = pytz.timezone("Asia/Taipei")
    start_dt = tz.localize(datetime.strptime(f"{start_date} {start_time}", "%Y-%m-%d %H:%M"))
    end_dt = tz.localize(datetime.strptime(f"{end_date} {end_time}", "%Y-%m-%d %H:%M"))

    created_ids = []

    current_date = start_dt
    while current_date.date() <= end_dt.date():
        request_start = max(current_date, start_dt)
        request_end = min(
            current_date.replace(hour=23, minute=59) if current_date.date() != end_dt.date() else end_dt,
            end_dt
        )

        result = db.collection("requests").add({
            "user_id": user_id,
            "user_name": user_name,
            "start_at": request_start,
            "end_at": request_end,
            "reason": reason,
            "status": "pending",
            "supervisors": supervisor_ids,
            "approvals": {sid: "pending" for sid in supervisor_ids},
            "created_at": datetime.now(tz)
        })
        created_ids.append(result[1].id)  # 正確取得 document_ref.id

        current_date += timedelta(days=1)

    return created_ids


def save_requests(request_list):
    results = []
    for req in request_list:
        # 確保 start_at / end_at 是 datetime 並帶有時區
        start_at = req["start_at"]
        end_at = req["end_at"]

        if isinstance(start_at, str):
            start_at = datetime.fromisoformat(start_at)
        if isinstance(end_at, str):
            end_at = datetime.fromisoformat(end_at)

        # 建立 approvals map
        supervisors = req.get("supervisors", [])
        approvals = {sid: "pending" for sid in supervisors}

        result = db.collection("requests").add({
            "user_id": req["user_id"],
            "user_name": req.get("user_name"),
            "start_at": start_at,
            "end_at": end_at,
            "reason": req["reason"],
            "status": "pending",
            "supervisors": supervisors,
            "approvals": approvals,
            "created_at": datetime.now(pytz.timezone("Asia/Taipei"))
        })

        results.append(result)
    return [{"id": ref[1].id} for ref in results]


def get_pending_requests_for_supervisor(supervisor_id):
    try:
        docs = db.collection("requests")\
            .where(f"approvals.{supervisor_id}", "==", "pending")\
            .stream()
        return [doc.to_dict() | {"id": doc.id} for doc in docs]
    except Exception as e:
        logging.error(f"❌ 讀取 pending 請假資料失敗：{e}")
        return []

def update_approval_status(request_id, supervisor_id, decision):
    doc_ref = db.collection("requests").document(request_id)
    try:
        doc = doc_ref.get()
        if not doc.exists:
            return False
        data = doc.to_dict()
        
        approvals = data.get("approvals", {})
        if supervisor_id not in approvals:
            logging.warning(f"⚠️ 此主管不在 approvals 清單中")
            return False
        
        approvals[supervisor_id] = decision
        update_data = {f"approvals.{supervisor_id}": decision}

        # 如果所有 approvals 都非 pending，才更新整體狀態
        if all(v != "pending" for v in approvals.values()):
            if all(v == "approved" for v in approvals.values()):
                update_data["status"] = "approved"
            else:
                update_data["status"] = "rejected"

        doc_ref.update(update_data)
        logging.info(f"✅ {supervisor_id} 已簽核為 {decision}")
        return True

    except Exception as e:
        logging.error(f"❌ 更新簽核狀態失敗：{e}")
        return False

def get_supervisor_names(supervisor_ids):
    names = []
    for sid in supervisor_ids:
        info = get_user_info_by_line_id(sid)
        if info:
            names.append(info.get("name", sid))
        else:
            names.append(f"(未知主管 {sid})")
    return names

def get_user_info_by_line_id(line_user_id):
    try:
        user_doc = db.collection("users").document(line_user_id).get()
        if user_doc.exists:
            return user_doc.to_dict()
        return None
    except Exception as e:
        logging.error(f"❌ 取得使用者資訊失敗：{e}")
        return None

def ensure_user_registered(user_id, name):
    try:
        doc_ref = db.collection("users").document(user_id)
        if not doc_ref.get().exists:
            doc_ref.set({
                "name": name,
                "role": "employee",  # 預設為 employee，如要支援 supervisor 請自行切換
                "supervisor_ids": [],  # 尚未設定主管
                "auth_uid": None
            })
            logging.info(f"✅ 已註冊使用者 {user_id}：{name}")
            return True
        else:
            logging.info(f"⚠️ 使用者 {user_id} 已存在，略過註冊")
            return False
    except Exception as e:
        logging.error(f"❌ 註冊使用者失敗：{e}")
        return False
