# firebase_db.py
import os
import firebase_admin
from firebase_admin import credentials, firestore
import uuid
from datetime import datetime

# 初始化 Firebase
if not firebase_admin._apps:
    cred = credentials.Certificate(os.getenv("FIREBASE_CREDENTIAL_PATH"))
    firebase_admin.initialize_app(cred)

db = firestore.client()

# 儲存請假資料
async def save_request(user_id, start_date, end_date, reason, start_time=None, end_time=None):
    request_id = str(uuid.uuid4())
    doc_ref = db.collection("requests").document(request_id)
    doc_ref.set({
        "request_id": request_id,
        "user_id": user_id,
        "start_date": start_date,
        "end_date": end_date,
        "start_time": start_time,  # 新增
        "end_time": end_time,      # 新增
        "reason": reason,
        "status": "pending",
        "created_at": datetime.now().isoformat()
    })
    return request_id

# 查詢所有 pending 的請假單
async def get_pending_requests():
    docs = db.collection("requests").where("status", "==", "pending").stream()
    results = []
    async for doc in docs:
        results.append(doc.to_dict())
    return results

# 更新請假單狀態
async def update_request_status(request_id, new_status):
    doc_ref = db.collection("requests").document(request_id)
    doc = doc_ref.get()
    if not doc.exists:
        return False
    doc_ref.update({"status": new_status})
    return True
