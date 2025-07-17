#session_manager.py

class UserSession:
    def __init__(self, user_id, session_store):
        self.user_id = user_id
        self._store = session_store
        self._data = session_store.get(user_id, {})

    def get(self, key, default=None):
        return self._data.get(key, default)

    def set(self, key, value):
        self._data[key] = value
        self._store[self.user_id] = self._data

    def set_step(self, step: str):
        self.set("step", step)

    def get_step(self):
        return self.get("step")

    def clear(self):
        self._store.pop(self.user_id, None)

    def all(self):
        return self._data

    def reset(self):
        self._data = {}
        self._store[self.user_id] = self._data