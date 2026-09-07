"""Аутентификация админ-панели: пароль, подписанные сессии."""
from __future__ import annotations

import hmac
import time
from typing import Optional

from itsdangerous import BadSignature, URLSafeTimedSerializer
from passlib.context import CryptContext

from . import config

_pwd = CryptContext(schemes=["bcrypt"], deprecated="auto")
_serializer = URLSafeTimedSerializer(config.SECRET_KEY, salt="cf-session")


def hash_password(password: str) -> str:
    return _pwd.hash(password)


def verify_password(password: str, hashed: str) -> bool:
    if not hashed:
        return False
    try:
        return _pwd.verify(password, hashed)
    except ValueError:
        return False


def make_session(username: str) -> str:
    return _serializer.dumps({"u": username, "t": int(time.time())})


def read_session(token: Optional[str]) -> Optional[str]:
    if not token:
        return None
    try:
        data = _serializer.loads(token, max_age=config.SESSION_MAX_AGE)
    except BadSignature:
        return None
    return data.get("u")


def constant_time_eq(a: str, b: str) -> bool:
    return hmac.compare_digest((a or "").encode(), (b or "").encode())
