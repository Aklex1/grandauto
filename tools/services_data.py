# -*- coding: utf-8 -*-
"""Микросервисы genius-bot.ru — по списку их же API (genius/v1/services),
с каноническими URL, группировкой для меню и ключом SVG-иконки.

Сервисы без отдельной посадочной страницы ведут в бота: там они работают.
"""

BOT = "https://t.me/Neuro_HubAI_bot?start=web"

# группа: 'foto' | 'video' | 'zvuk'
SERVICES = [
    # --- Фото ---
    ("image",       "Картинка по описанию",   "Изображение из текста — для карточек товара, обложек и иллюстраций.",
     "foto", "image", "/kartinka-neyrosetyu/", BOT),
    ("image-edit",  "Изменить фото",          "Замена фона и одежды, удаление объектов, реставрация — словами, без редактора.",
     "foto", "edit", "/izmenit-foto-neyrosetyu/", BOT),
    ("upscale",     "Увеличить качество",     "Апскейл вдвое с восстановлением деталей: для старых и мелких снимков.",
     "foto", "upscale", "/uluchshit-kachestvo-foto/", BOT),
    ("photo-video", "Оживить фото",           "Из фотографии — короткое видео: движение головы, мимика, лёгкая камера.",
     "foto", "revive", "/ozhivit-foto/", "/ozhivit-foto/"),
    # --- Видео ---
    ("video",       "Видео по описанию",      "Ролик из одного текста: сцена, движение и камера — без исходной картинки.",
     "video", "video", "/video-neyrosetyu/", BOT),
    ("avatar",      "Говорящий аватар",       "Фото плюс запись голоса — видео, где человек со снимка говорит.",
     "video", "avatar", "/govoryashchiy-avatar/", "/govoryashchiy-avatar/"),
    ("clip-music",  "Музыка для видео",       "Фоновый трек без авторских прав под хронометраж ролика.",
     "video", "clipmusic", "/muzyka-dlya-video/", "/muzyka-dlya-video/"),
    # --- Звук ---
    ("tts",         "Озвучка текста",         "Речь из текста живым голосом, больше тридцати языков.",
     "zvuk", "tts", "/tts-pricing/", "/tts-pricing/"),
    ("stt",         "Расшифровка записи",     "Текст из записи с таймкодами и разделением по говорящим.",
     "zvuk", "stt", "/audio-v-tekst/", "/audio-v-tekst/"),
    ("music",       "Создать музыку",         "Два готовых трека по описанию: инструментал или песня с вокалом.",
     "zvuk", "music", "/sozdat-muzyku/", "/sozdat-muzyku/"),
    ("lyrics",      "Текст песни",            "Два варианта слов к треку по описанию, с куплетами и припевом.",
     "zvuk", "lyrics", "/neyroset-napishet-pesnyu/", "/neyroset-napishet-pesnyu/"),
    ("vocal",       "Убрать вокал",           "Две дорожки из песни: минусовка и отдельно голос.",
     "zvuk", "vocal", "/ubrat-vokal/", "/ubrat-vokal/"),
    ("denoise",     "Убрать шум",             "Чистый голос без фонового гула, эха и шума улицы.",
     "zvuk", "denoise", "/ubrat-shum/", "/ubrat-shum/"),
    ("sfx",         "Звук по описанию",       "Звуковой эффект или фон из текстового описания, MP3.",
     "zvuk", "sfx", "/sound-generator/", "/sound-generator/"),
    ("ytaudio",     "Звук из видео",          "Звуковая дорожка по ссылке на ролик — MP3, WAV или M4A.",
     "zvuk", "ytaudio", "/izvlech-zvuk-iz-video-onlayn/", "/izvlech-zvuk-iz-video-onlayn/"),
]

GROUPS = [
    ("foto",  "Фото",  "Картинки, фоторедактор и оживление снимков"),
    ("video", "Видео", "Ролики из текста, говорящие аватары, музыка под видео"),
    ("zvuk",  "Звук",  "Озвучка, музыка, расшифровка и работа со звуком"),
]

# Отдельные пункты меню, которые не входят в Фото/Видео/Звук
EXTRA = {
    "bg_music": ("Фоновая музыка", "/muzyka-dlya-video/"),
    "catalog":  ("Каталог звуков", "/sounds-catalog/"),
    "slides":   ("Презентации",    "/sozdat-prezentaciyu/"),
    "blog":     ("Блог",           "/blog/"),
    "assistants": ("ИИ-помощники", "/ai-pomoshnik/"),
}


def by_group(g: str) -> list:
    return [s for s in SERVICES if s[3] == g]
