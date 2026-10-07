"""Публикация на YouTube: обмен кода, загрузка с возобновлением, панель."""
import http.server
import json
import os
import socketserver
import sys
import threading

sys.path.insert(0, "/home/user/grandauto")
from pathlib import Path

from app import bootstrap; bootstrap.run()
from app import main as app_main, musicvideo as mv, storage, youtube as yt
from app import settings_store as st
from app.db import session_scope
from app.models import Job, MusicVideo
from fastapi.testclient import TestClient

OUT = Path(os.environ["OUT"]); OUT.mkdir(parents=True, exist_ok=True)

# Мелкий кусок, чтобы файл ушёл в несколько приёмов и протокол возобновления
# действительно проверился, а не проскочил одним запросом.
yt.CHUNK = 64 * 1024

STATE = {"chunks": [], "fail_once": True, "quota": False, "thumb": 0,
         "meta": None, "resume_probes": 0}


class Fake(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def _read(self):
        length = int(self.headers.get("content-length") or 0)
        return self.rfile.read(length) if length else b""

    def _send(self, code, body=None, headers=None):
        raw = json.dumps(body or {}).encode() if body is not None else b""
        self.send_response(code)
        for key, value in (headers or {}).items():
            self.send_header(key, value)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        if raw:
            self.wfile.write(raw)

    def do_POST(self):
        body = self._read()
        if self.path.startswith("/token"):
            text = body.decode()
            if "grant_type=authorization_code" in text:
                if "code=good" in text:
                    return self._send(200, {"refresh_token": "refresh-123",
                                            "access_token": "access-1"})
                if "code=noref" in text:
                    return self._send(200, {"access_token": "access-1"})
                return self._send(400, {"error": "invalid_grant",
                                        "error_description": "Bad Request"})
            return self._send(200, {"access_token": "access-1", "expires_in": 3599})
        if self.path.startswith("/upload"):
            if STATE["quota"]:
                return self._send(403, {"error": {
                    "message": "The request cannot be completed because you have "
                               "exceeded your quota.",
                    "errors": [{"reason": "quotaExceeded"}]}})
            STATE["meta"] = json.loads(body.decode())
            host = self.headers.get("host")
            return self._send(200, {}, {"Location": f"http://{host}/session"})
        if self.path.startswith("/thumb"):
            STATE["thumb"] += 1
            return self._send(200, {"kind": "youtube#thumbnailSetResponse"})
        return self._send(404, {})

    def do_PUT(self):
        body = self._read()
        rng = self.headers.get("content-range") or ""
        total = int(rng.rsplit("/", 1)[1])
        if rng.startswith("bytes */"):
            # Запрос «сколько принято» после обрыва.
            STATE["resume_probes"] += 1
            got = sum(len(c) for c in STATE["chunks"])
            return self._send(308, {}, {"Range": f"bytes=0-{got - 1}"} if got else {})

        start = int(rng.split(" ")[1].split("-")[0])
        if STATE["fail_once"] and start > 0:
            # Один раз роняем соединение посередине — ровно то, что бывает с
            # гигабайтным файлом на живой сети.
            STATE["fail_once"] = False
            return self._send(503, {"error": {"message": "Backend Error"}})
        STATE["chunks"].append(body)
        got = sum(len(c) for c in STATE["chunks"])
        if got >= total:
            return self._send(200, {"id": "VID123",
                                    "status": {"privacyStatus": "unlisted"}})
        return self._send(308, {}, {"Range": f"bytes=0-{got - 1}"})


server = socketserver.ThreadingTCPServer(("127.0.0.1", 0), Fake)
server.daemon_threads = True
threading.Thread(target=server.serve_forever, daemon=True).start()
BASE = f"http://127.0.0.1:{server.server_address[1]}"
yt.TOKEN_URL = BASE + "/token"
yt.UPLOAD_URL = BASE + "/upload"
yt.THUMB_URL = BASE + "/thumb"
print(f"подставной Google поднят на {BASE}")

# --- 1. обмен кода на токен обновления ---------------------------------------------
token = yt.exchange_code("cid", "secret", "good")
assert token == "refresh-123", token
print("1. код обменян на токен обновления")

try:
    yt.exchange_code("cid", "secret", "noref")
    raise AssertionError("ответ без токена обновления принят молча")
except yt.YouTubeError as exc:
    assert "токен обновления" in str(exc) and "заново" in str(exc), str(exc)
print("2. ответ без токена обновления объясняет, что делать, а не падает кодом")

try:
    yt.exchange_code("cid", "secret", "stale")
    raise AssertionError("просроченный код принят")
except yt.YouTubeError as exc:
    assert "Google" in str(exc), str(exc)
print("3. просроченный код отвергнут с причиной от Google")

# --- 2. загрузка с возобновлением ---------------------------------------------------
video = OUT / "mix.mp4"
video.write_bytes(bytes(range(256)) * 1400)  # ~350 КБ: пять-шесть кусков
poster = OUT / "poster.jpg"
poster.write_bytes(b"\xff\xd8\xff\xd9")

creds = {"client_id": "cid", "client_secret": "secret", "refresh_token": "refresh-123"}
seen = []
result = yt.upload(creds, video, title="T" * 150,
                   description="описание\n\nТайм-код:\n0:00 раз",
                   tags=["jazz", "soul"] + [f"t{i}" for i in range(40)],
                   privacy="unlisted", thumbnail=poster,
                   on_progress=lambda sent, size: seen.append(sent))

assert result["id"] == "VID123"
assert result["url"] == "https://www.youtube.com/watch?v=VID123"
assert result["privacy"] == "unlisted"
got = b"".join(STATE["chunks"])
assert got == video.read_bytes(), f"файл дошёл искажённым: {len(got)} из {video.stat().st_size}"
assert len(STATE["chunks"]) > 3, f"кусков всего {len(STATE['chunks'])} — протокол не проверен"
assert STATE["resume_probes"] >= 1, "после обрыва не спросили, сколько принято"
assert STATE["thumb"] == 1, "обложка не отправлена"
print(f"4. файл дошёл целиком: {len(STATE['chunks'])} кусков, один обрыв 503 "
      f"пережит с возобновлением ({STATE['resume_probes']} запрос о принятом)")

meta = STATE["meta"]
assert len(meta["snippet"]["title"]) == 100, "заголовок не обрезан до предела YouTube"
assert meta["snippet"]["categoryId"] == "10", meta["snippet"]["categoryId"]
assert len(meta["snippet"]["tags"]) == 30, len(meta["snippet"]["tags"])
assert meta["status"]["privacyStatus"] == "unlisted"
assert meta["status"]["selfDeclaredMadeForKids"] is False, \
    "без ответа про детскую аудиторию ролик висит с предупреждением"
assert "Тайм-код:" in meta["snippet"]["description"]
print("5. метаданные в порядке: заголовок до 100 знаков, 30 тегов, категория «Музыка», "
      "ответ про детскую аудиторию, тайм-код в описании")

# --- 3. исчерпанная квота объясняется по-человечески ---------------------------------
STATE["quota"] = True
reason = ""
try:
    yt.upload(creds, video, title="T", description="d", tags=[])
    raise AssertionError("отказ по квоте проглочен")
except yt.YouTubeError as exc:
    reason = str(exc)
assert "квота" in reason and "завтра" in reason, reason
print(f"6. квота: «{reason}»")
STATE["quota"] = False

# --- 4. панель ----------------------------------------------------------------------
client = TestClient(app_main.app)
client.post("/login", data={"username": "admin", "password": "test1234"},
            follow_redirects=False)

page = client.get("/youtube")
assert page.status_code == 200 and "Приложение в Google Cloud" in page.text
assert "localhost:8765" in page.text, "не сказано, какой адрес возврата регистрировать"
assert "youtube.upload" in page.text, "не сказано, какие права запрашиваются"
assert "Личными" in page.text, "не предупреждено про непроверенный канал"
print("7. страница подключения: шаги, адрес возврата, права и ограничения площадки")

res = client.post("/youtube/app", data={"client_id": "cid", "client_secret": "secret"},
                  follow_redirects=False)
assert res.status_code == 303
page = client.get("/youtube")
assert "accounts.google.com" in page.text and "prompt=consent" in page.text
print("8. ключи сохранены, ссылка согласия появилась")

# Человек вставляет весь адрес из строки браузера — код достаётся сам.
res = client.post("/youtube/code",
                  data={"code": "http://localhost:8765/?code=good&scope=youtube.upload"},
                  follow_redirects=False)
assert res.status_code == 303 and "connected=1" in res.headers["location"], \
    res.headers["location"]
with session_scope() as session:
    assert st.get(session, "youtube_refresh_token", "") == "refresh-123"
    assert yt.configured(session)
print("9. вставлен весь адрес — код извлечён, токен сохранён")

# --- 5. кнопка публикации -----------------------------------------------------------
with session_scope() as session:
    row = mv.create(session, style="soul", minutes=25)
    row.video_path = storage.rel(video)
    row.poster_path = storage.rel(poster)
    row.yt_title = "Velvet Afterglow — 25 Minutes"
    row.description = "описание\n\nTracklist:\n0:00 one"
    row.tags = "neo soul, soul music"
    row.status = "done"
    session.commit()
    mix_id = row.id
    before = len(session.execute(Job.__table__.select()).all())

res = client.post(f"/music/{mix_id}/publish", data={"privacy": "unlisted"},
                  follow_redirects=False)
assert res.status_code == 303 and f"publishing={mix_id}" in res.headers["location"]
with session_scope() as session:
    jobs = session.execute(Job.__table__.select()).all()
    assert len(jobs) == before + 1 and jobs[-1].kind == "youtube_upload", jobs[-1].kind
    assert session.get(MusicVideo, mix_id).youtube_state == "queued"
print("10. кнопка ставит задачу «youtube_upload», состояние микса — «в очереди»")

STATE["chunks"].clear()
STATE["fail_once"] = False
mv.publish(mix_id, privacy="unlisted")
with session_scope() as session:
    row = session.get(MusicVideo, mix_id)
    assert row.youtube_id == "VID123", row.youtube_id
    assert row.youtube_url.endswith("VID123")
    assert row.youtube_state == "done" and not row.youtube_error
    assert row.youtube_privacy == "unlisted"
print(f"11. публикация записана в карточку: {row.youtube_url} ({row.youtube_privacy})")

lib = client.get("/music?tab=library")
assert "Выложено:" in lib.text and "VID123" in lib.text
assert "Выложить ещё раз" in lib.text
print("12. в библиотеке видна ссылка и кнопка «Выложить ещё раз»")

# Неудача должна быть видна человеку, а не только в журнале.
STATE["quota"] = True
try:
    mv.publish(mix_id, privacy="unlisted")
except Exception:
    pass
with session_scope() as session:
    row = session.get(MusicVideo, mix_id)
    assert row.youtube_state == "failed" and "квота" in row.youtube_error
    # Прежняя ссылка не потеряна: ролик-то выложен.
    assert row.youtube_id == "VID123"
print("13. отказ записан в карточку с причиной, прежняя ссылка не потеряна")
STATE["quota"] = False

# --- 6. без настроек кнопки нет ------------------------------------------------------
with session_scope() as session:
    st.set_value(session, "youtube_refresh_token", "")
    session.commit()
lib = client.get("/music?tab=library")
assert "Публикация не настроена" in lib.text
res = client.post(f"/music/{mix_id}/publish", data={"privacy": "public"},
                  follow_redirects=False)
assert res.status_code == 303 and "error=no-youtube" in res.headers["location"]
print("14. без токена кнопка заменена ссылкой на настройку, запрос отвергнут")

server.shutdown()
print("\nВСЁ ПРОШЛО")
