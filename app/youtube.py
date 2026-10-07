"""Публикация на YouTube через Data API v3: авторизация и загрузка файла.

Зачем это в заводе. Готовый микс и так лежит с написанным заголовком, описанием,
тегами и тайм-кодом — руками остаётся только перетащить файл в Студию и вставить
текст. Отсюда и весь смысл: сервер умеет сделать это сам.

Что важно знать про доступ. Пароль от Google серверу не нужен и никуда не
попадает: человек один раз разрешает доступ у себя в браузере, Google выдаёт
код, и из кода получается токен обновления. Дальше сервер сам меняет его на
короткоживущий токен доступа перед каждой загрузкой. Токен обновления — такой же
секрет, как пароль: он лежит в настройках в базе и в журнал не пишется.

Ограничения площадки, про которые надо знать заранее:

* Квота. Одна загрузка стоит 1600 единиц из 10 000 в сутки — примерно шесть
  роликов в день. Исчерпали — API отвечает ошибкой квоты, и это не повод
  считать ролик испорченным: файл на месте, попробовать можно завтра.
* Проверка канала. У непроверенных аккаунтов видео, загруженные через API,
  принудительно становятся «Личными», что бы мы ни просили. Это ограничение
  YouTube, а не завода: проверка канала его снимает.
* Своя обложка тоже требует проверенного канала. Если не вышло — ролик уже
  загружен, и отказ от обложки не повод считать загрузку неудачной.
"""
from __future__ import annotations

import json
import logging
import time
from pathlib import Path
from typing import Optional

import httpx
from sqlalchemy.orm import Session

from . import settings_store as st

log = logging.getLogger("cf.youtube")

AUTH_URL = "https://accounts.google.com/o/oauth2/v2/auth"
TOKEN_URL = "https://oauth2.googleapis.com/token"
UPLOAD_URL = "https://www.googleapis.com/upload/youtube/v3/videos"
THUMB_URL = "https://www.googleapis.com/upload/youtube/v3/thumbnails/set"

# Только загрузка. Права на чтение канала, правку плейлистов и прочее заводу не
# нужны, а чем уже область доступа, тем меньше цена ошибки.
SCOPE = "https://www.googleapis.com/auth/youtube.upload"

# Возврат на localhost — единственный путь без своего домена и сертификата:
# Google разрешает http только для петлевого адреса. Страница не откроется, и это
# нормально — код виден в адресной строке и вставляется в панель.
#
# Адрес взят ровно такой, какой Google кладёт в файл клиента типа «приложение для
# настольного компьютера»: redirect_uris там — ["http://localhost"]. Для таких
# клиентов порт при проверке игнорируется, но совпадение один в один снимает
# лишний повод для отказа.
REDIRECT_URI = "http://localhost"

CATEGORY_MUSIC = "10"
PRIVACY = {"private": "Личное", "unlisted": "По ссылке", "public": "Открытое"}

# Размер куска при загрузке. Восемь мегабайт — компромисс: меньше кусков на
# гигабайтном файле, но при обрыве заново уходит немного.
CHUNK = 8 * 1024 * 1024


class YouTubeError(RuntimeError):
    """Ошибка публикации на YouTube."""


def credentials(session: Session) -> dict:
    """Ключи приложения и токен обновления из настроек.

    Читаем их один раз и дальше работаем без сессии базы: загрузка гигабайтного
    файла идёт минутами, и держать всё это время открытую транзакцию SQLite —
    значит мешать остальному заводу.
    """
    return {key: st.get(session, f"youtube_{key}", "").strip()
            for key in ("client_id", "client_secret", "refresh_token")}


def configured(session: Session) -> bool:
    """Есть ли всё, чтобы выложить ролик без участия человека."""
    return all(credentials(session).values())


def consent_url(client_id: str) -> str:
    """Ссылка на страницу разрешения доступа.

    access_type=offline — чтобы Google выдал токен обновления, иначе доступ
    кончится через час. prompt=consent — чтобы токен обновления пришёл и при
    повторном разрешении: без этого Google отдаёт его только в первый раз, и
    перевыпуск молча не работает.
    """
    from urllib.parse import urlencode

    return AUTH_URL + "?" + urlencode({
        "client_id": client_id.strip(),
        "redirect_uri": REDIRECT_URI,
        "response_type": "code",
        "scope": SCOPE,
        "access_type": "offline",
        "prompt": "consent",
    })


def exchange_code(client_id: str, client_secret: str, code: str) -> str:
    """Код с страницы разрешения → токен обновления."""
    body = {
        "client_id": client_id.strip(),
        "client_secret": client_secret.strip(),
        "code": code.strip(),
        "grant_type": "authorization_code",
        "redirect_uri": REDIRECT_URI,
    }
    with httpx.Client(timeout=60) as client:
        resp = client.post(TOKEN_URL, data=body)
    if resp.status_code >= 400:
        raise YouTubeError(_explain(resp))
    token = (resp.json() or {}).get("refresh_token", "")
    if not token:
        raise YouTubeError(
            "Google не выдал токен обновления. Обычно это значит, что доступ уже "
            "был разрешён раньше: откройте ссылку заново — в ней стоит повторный "
            "запрос согласия, и токен придёт")
    return token


def access_token(creds: dict) -> str:
    """Короткоживущий токен доступа из токена обновления."""
    body = {
        "client_id": creds.get("client_id", "").strip(),
        "client_secret": creds.get("client_secret", "").strip(),
        "refresh_token": creds.get("refresh_token", "").strip(),
        "grant_type": "refresh_token",
    }
    if not all(body.values()):
        raise YouTubeError("доступ к YouTube не настроен: нет ключей или токена")
    with httpx.Client(timeout=60) as client:
        resp = client.post(TOKEN_URL, data=body)
    if resp.status_code >= 400:
        raise YouTubeError(_explain(resp) + ". Возможно, доступ отозван — "
                                            "разрешите его заново в настройках")
    token = (resp.json() or {}).get("access_token", "")
    if not token:
        raise YouTubeError("Google не вернул токен доступа")
    return token


def _explain(resp: httpx.Response) -> str:
    """Человеческая причина вместо сырого ответа Google."""
    try:
        data = resp.json()
    except ValueError:
        return f"HTTP {resp.status_code}: {resp.text[:200]}"
    error = data.get("error")
    if isinstance(error, dict):
        reasons = {item.get("reason") for item in (error.get("errors") or [])}
        message = error.get("message") or ""
        if "quotaExceeded" in reasons or "quota" in message.lower():
            return ("суточная квота YouTube API исчерпана (одна загрузка стоит 1600 "
                    "из 10 000 единиц). Файл на месте — попробуйте завтра")
        if "youtubeSignupRequired" in reasons:
            return "у этого аккаунта Google нет канала YouTube"
        if "forbidden" in reasons or resp.status_code == 403:
            return f"YouTube отказал: {message[:200]}"
        return f"YouTube: {message[:200]}"
    detail = data.get("error_description") or str(error) or resp.text[:200]
    return f"Google: {detail}"


def upload(creds: dict, video: Path, *, title: str, description: str,
           tags: list[str], privacy: str = "private",
           thumbnail: Optional[Path] = None,
           on_progress=None) -> dict:
    """Выложить файл. Возвращает {'id': ..., 'url': ..., 'privacy': ...}.

    Загрузка идёт с возобновлением: гигабайтный файл по сети рвётся, и начинать
    его заново из-за одного обрыва незачем — Google сам сообщает, сколько байт
    уже принял, и мы продолжаем с этого места.
    """
    if not video.is_file() or video.stat().st_size == 0:
        raise YouTubeError(f"файла нет или он пуст: {video}")
    privacy = privacy if privacy in PRIVACY else "private"
    token = access_token(creds)
    size = video.stat().st_size

    meta = {
        "snippet": {
            "title": title[:100],
            "description": description[:5000],
            "tags": [tag[:60] for tag in tags][:30],
            "categoryId": CATEGORY_MUSIC,
        },
        "status": {
            "privacyStatus": privacy,
            # YouTube требует явного ответа про детскую аудиторию: без него
            # загрузка проходит, но ролик висит с предупреждением в Студии.
            "selfDeclaredMadeForKids": False,
        },
    }
    headers = {
        "Authorization": f"Bearer {token}",
        "Content-Type": "application/json; charset=UTF-8",
        "X-Upload-Content-Length": str(size),
        "X-Upload-Content-Type": "video/*",
    }
    with httpx.Client(timeout=120) as client:
        start = client.post(UPLOAD_URL, params={
            "uploadType": "resumable", "part": "snippet,status"},
            headers=headers, json=meta)
    if start.status_code >= 400:
        raise YouTubeError(_explain(start))
    session_url = start.headers.get("location") or start.headers.get("Location")
    if not session_url:
        raise YouTubeError("Google не вернул адрес для загрузки")

    sent = 0
    attempts = 0
    with open(video, "rb") as fh, httpx.Client(timeout=900) as client:
        while sent < size:
            fh.seek(sent)
            chunk = fh.read(CHUNK)
            if not chunk:
                break
            last = sent + len(chunk) - 1
            try:
                resp = client.put(session_url, content=chunk, headers={
                    "Content-Length": str(len(chunk)),
                    "Content-Range": f"bytes {sent}-{last}/{size}",
                })
            except httpx.HTTPError as exc:
                attempts += 1
                if attempts > 6:
                    raise YouTubeError(f"загрузка оборвалась: {exc}") from exc
                sent = _resume_from(client, session_url, size, sent)
                time.sleep(2 * attempts)
                continue

            if resp.status_code in (200, 201):
                data = resp.json() or {}
                video_id = data.get("id") or ""
                if not video_id:
                    raise YouTubeError("YouTube принял файл, но не вернул номер видео")
                done = {"id": video_id,
                        "url": f"https://www.youtube.com/watch?v={video_id}",
                        "privacy": ((data.get("status") or {}).get("privacyStatus")
                                    or privacy)}
                if thumbnail is not None and thumbnail.is_file():
                    _set_thumbnail(token, video_id, thumbnail)
                return done
            if resp.status_code == 308:
                sent = _range_end(resp.headers.get("range")) or (last + 1)
                attempts = 0
                if on_progress:
                    on_progress(sent, size)
                continue
            if resp.status_code in (500, 502, 503, 504):
                attempts += 1
                if attempts > 6:
                    raise YouTubeError(f"YouTube отвечает {resp.status_code} — "
                                       f"загрузка не удалась")
                sent = _resume_from(client, session_url, size, sent)
                time.sleep(2 * attempts)
                continue
            raise YouTubeError(_explain(resp))
    raise YouTubeError("файл отправлен целиком, но YouTube не подтвердил приём")


def _range_end(header: Optional[str]) -> int:
    """Сколько байт уже принято, по заголовку Range вида bytes=0-8388607."""
    if not header or "-" not in header:
        return 0
    try:
        return int(header.rsplit("-", 1)[1]) + 1
    except ValueError:
        return 0


def _resume_from(client: httpx.Client, session_url: str, size: int, fallback: int) -> int:
    """Спросить у Google, сколько байт он принял, чтобы продолжить с этого места."""
    try:
        probe = client.put(session_url, content=b"", headers={
            "Content-Length": "0", "Content-Range": f"bytes */{size}"})
    except httpx.HTTPError:
        return fallback
    if probe.status_code == 308:
        return _range_end(probe.headers.get("range")) or fallback
    return fallback


def _set_thumbnail(token: str, video_id: str, image: Path) -> None:
    """Своя обложка. Неудача не отменяет загруженный ролик."""
    try:
        with httpx.Client(timeout=300) as client:
            resp = client.post(THUMB_URL, params={"videoId": video_id},
                               headers={"Authorization": f"Bearer {token}",
                                        "Content-Type": "image/jpeg"},
                               content=image.read_bytes())
        if resp.status_code >= 400:
            log.warning("Обложка не поставлена: %s", _explain(resp))
    except Exception as exc:  # noqa: BLE001 — ролик уже выложен
        log.warning("Обложка не поставлена: %s", exc)
