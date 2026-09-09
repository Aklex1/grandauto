"""Промпты для текстовых моделей: контент-план, сценарий, метаданные, шортсы."""
from __future__ import annotations

SYSTEM_EDITOR = (
    "Ты — главный редактор и сценарист YouTube-канала на русском языке. "
    "Ты пишешь живо, конкретно и без воды: короткие предложения, примеры, никакого канцелярита. "
    "Ты никогда не используешь англицизмы там, где есть русское слово. "
    "Ты всегда возвращаешь строго валидный JSON без markdown-обёрток и комментариев."
)


def content_plan(channel_name: str, topic: str, count: int, audience: str,
                 existing: list[str] | None = None) -> list[dict]:
    """Промпт для генерации контент-плана из саммари книг."""
    exclude = ""
    if existing:
        exclude = "\nНЕ повторяй уже запланированные книги:\n- " + "\n- ".join(existing[:200])
    user = f"""Составь контент-план для YouTube-канала «{channel_name}».

Тематика канала: {topic}
Аудитория: {audience}
Формат каждого ролика: саммари одной нон-фикшн книги — главная идея, 5–8 ключевых мыслей,
практические правила и упражнения. Длительность 7–10 минут.

Подбери {count} книг. Требования к подборке:
- только реально существующие книги с настоящими авторами;
- сильные, известные и работающие книги по теме канала, а не случайные;
- разнообразие подтем (отношения, деньги и карьера, характер и дисциплина, тело и энергия,
  психика и эмоции, коммуникация, смысл и цели);
- порядок: начинать с самых «цепляющих» и понятных широкой аудитории.{exclude}

Верни JSON-массив объектов:
[{{"book_title":"название книги на русском",
  "book_author":"автор",
  "video_title":"кликабельный заголовок ролика на русском, 45–70 символов, без кликбейта-вранья",
  "angle":"1–2 предложения: под каким углом подаём книгу и кому она нужна",
  "key_points":["5–7 тезисов, которые обязательно должны прозвучать в ролике"]}}]

Только JSON-массив, ничего кроме него."""
    return [{"role": "system", "content": SYSTEM_EDITOR}, {"role": "user", "content": user}]


def script(channel_name: str, topic: str, book_title: str, book_author: str, angle: str,
           key_points: str, scene_count: int, target_minutes: float, script_style: str,
           visual_style: str) -> list[dict]:
    """Промпт для сценария ролика, разбитого на сцены."""
    words = int(target_minutes * 145)  # ~145 слов в минуту для русской речи
    style_block = f"\nДополнительные требования к подаче: {script_style}" if script_style else ""
    visual_block = (f"\nВизуальный стиль канала (учитывай в описаниях кадров): {visual_style}"
                    if visual_style else "")
    user = f"""Напиши сценарий ролика для канала «{channel_name}» (тематика: {topic}).

Книга: «{book_title}» — {book_author}
Угол подачи: {angle}
Обязательные тезисы: {key_points}

Требования:
- Общий объём закадрового текста: примерно {words} слов (это ~{target_minutes:.0f} минут речи).
- Ровно {scene_count} сцен.
- Сцена 1 — крючок: 3–4 предложения, которые бьют в боль зрителя, без приветствий и «сегодня мы поговорим».
- Дальше — по одной ключевой мысли на сцену: тезис, объяснение, живой пример, что делать на практике.
- Предпоследняя сцена — конкретные упражнения или правила, которые можно применить сегодня.
- Последняя сцена — короткий вывод + призыв подписаться, естественно, без пафоса.
- Текст пиши так, как его произнесёт диктор: без заголовков, списков, звёздочек, эмодзи и скобок.
- Числа пиши словами (не «7 правил», а «семь правил»).{style_block}{visual_block}

Для каждой сцены дай описание видеоряда (visual_prompt) на АНГЛИЙСКОМ языке для видеогенератора:
кинематографичный кадр, живой человек или атмосферная сцена, свет, ракурс, движение камеры.
Без текста и надписей в кадре, без логотипов, без субтитров.

Верни JSON:
{{"title":"заголовок ролика для YouTube, 45–70 символов",
 "hook":"одно предложение — о чём ролик",
 "scenes":[{{"heading":"короткий заголовок сцены","narration":"закадровый текст сцены",
             "visual_prompt":"english cinematic shot description"}}]}}

Только JSON, ничего кроме него."""
    return [{"role": "system", "content": SYSTEM_EDITOR}, {"role": "user", "content": user}]


def metadata(channel_name: str, topic: str, book_title: str, book_author: str,
             script_text: str) -> list[dict]:
    """Промпт для YouTube-метаданных: заголовки, описание, теги."""
    excerpt = script_text[:6000]
    user = f"""Ниже сценарий ролика канала «{channel_name}» (тематика: {topic}) по книге
«{book_title}» — {book_author}.

СЦЕНАРИЙ:
{excerpt}

Подготовь метаданные для YouTube на русском языке.

Верни JSON:
{{"title":"лучший заголовок, 45–70 символов",
 "title_variants":["4 альтернативных заголовка для A/B-теста"],
 "description":"описание ролика 900–1500 символов: первые 2 строки — крючок, затем польза ролика, \
затем тайм-коды в формате 00:00 Название, затем призыв подписаться и дисклеймер, что это авторское саммари, \
а не замена книге",
 "tags":["25–30 тегов на русском и английском, по одному-три слова, без решёток"],
 "thumb_text":"2–4 слова крупным шрифтом на обложку: цепляющая суть ролика, без точки",
 "pinned_comment":"закреплённый комментарий с вопросом к аудитории"}}

Только JSON, ничего кроме него."""
    return [{"role": "system", "content": SYSTEM_EDITOR}, {"role": "user", "content": user}]


def thumbnail(channel_name: str, video_title: str, book_title: str, thumb_style: str) -> str:
    """Промпт для генератора обложек (nano banana)."""
    style = thumb_style or ("bold high-contrast YouTube thumbnail, cinematic lighting, "
                            "dramatic portrait, dark background with strong accent color")
    return (
        f"YouTube video thumbnail, 16:9. Topic: {video_title} (book summary: {book_title}, "
        f"channel: {channel_name}). {style}. Photorealistic, sharp focus, strong emotional face, "
        f"shallow depth of field, high contrast, vivid colors, room on the left third for a headline. "
        f"No text, no letters, no words, no watermark, no logo."
    )


def short_cover(channel_name: str, topic: str, heading: str, narration: str,
                thumb_style: str = "") -> str:
    """Промпт обложки вертикального шортса.

    Текст модели не заказываем: кириллицу генераторы изображений рисуют плохо,
    заголовок накладывается своим шрифтом поверх. Зато просим оставить внизу
    место под него и держать композицию в верхних двух третях кадра.
    """
    style = thumb_style or ("bold high-contrast mobile thumbnail, cinematic lighting, "
                            "dramatic close-up portrait, vivid saturated accent colors")
    hook = (narration or "").strip().replace("\n", " ")[:180]
    return (
        f"Vertical 9:16 cover image for a short video. "
        f"Topic: {heading} (channel: {channel_name}, subject: {topic}). "
        f"Scene mood: {hook}. {style}. "
        f"Bright, eye-catching, punchy colors, strong contrast, expressive human face, "
        f"sharp focus, shallow depth of field, dramatic rim light, "
        f"subject centered in the upper two thirds, clean uncluttered bottom third "
        f"left empty for a headline. "
        f"No text, no letters, no words, no captions, no watermark, no logo, no borders."
    )


def bust_background(topic: str, heading: str, thumb_style: str = "") -> str:
    """Кадр для формата «бюст»: античная скульптура в дыму на тёмном фоне."""
    style = thumb_style or "cinematic, dramatic side light, deep shadows, film grain"
    return (
        f"Vertical 9:16 cinematic still. Marble bust of an ancient philosopher in profile, "
        f"weathered stone texture, draped toga, looking upward. Very dark charcoal background, "
        f"low drifting smoke and clouds around the base and top. Monochrome, desaturated, "
        f"high contrast. Subject centered in the lower two thirds, empty dark sky in the upper "
        f"third for a headline. Theme: {heading} ({topic}). {style}. "
        f"No text, no letters, no words, no watermark, no logo."
    )


def paragraph_background(topic: str, heading: str) -> str:
    """Фон для формата «абзац»: почти чёрный кадр с еле различимой фактурой."""
    return (
        f"Vertical 9:16 background image, almost entirely deep black. A very faint, subtle "
        f"texture in the corners — soft smoke or dust, barely visible, no bright areas. "
        f"Extremely dark, minimalist, cinematic. Mood: {heading} ({topic}). "
        f"The center must stay plain black so white text stays readable. "
        f"No text, no letters, no words, no subject, no watermark."
    )


def bridge(channel_name: str, topic: str, prev_heading: str, prev_tail: str,
           next_heading: str, next_head: str) -> list[dict]:
    """Промпт для короткой связки между двумя несмежными сценами."""
    user = f"""Ты монтируешь ролик канала «{channel_name}» (тематика: {topic}).
Зритель только что услышал конец одного блока, а дальше идёт другой блок,
между ними вырезан кусок. Нужна короткая связка, чтобы переход не был рваным.

КОНЕЦ ПРЕДЫДУЩЕГО БЛОКА («{prev_heading}»):
{prev_tail}

НАЧАЛО СЛЕДУЮЩЕГО БЛОКА («{next_heading}»):
{next_head}

Напиши связку: 1–2 предложения закадрового текста, которые логично соединяют эти два блока.
Требования:
- пиши так, как это произнесёт диктор: без заголовков, списков и скобок;
- не пересказывай оба блока, а именно перекидывай мостик;
- никаких «итак», «давайте перейдём», «в этой части видео»;
- числа словами.

Также дай описание кадра для видеогенератора на АНГЛИЙСКОМ языке: кинематографичный
план без текста и надписей.

Верни JSON:
{{"narration":"текст связки","visual_prompt":"english cinematic shot description"}}

Только JSON, ничего кроме него."""
    return [{"role": "system", "content": SYSTEM_EDITOR}, {"role": "user", "content": user}]


def shorts(video_title: str, count: int, transcript: str, max_sec: int = 58) -> list[dict]:
    """Промпт для выбора фрагментов под шортсы из транскрипта с таймкодами."""
    user = f"""Ниже транскрипт ролика «{video_title}» с таймкодами (секунды).

{transcript[:14000]}

Выбери {count} самых сильных самостоятельных фрагментов для вертикальных шортсов.

Правила отбора:
- фрагмент должен быть понятен без контекста ролика;
- начинается с сильной фразы-крючка, а не с середины мысли;
- длительность от 25 до {max_sec} секунд;
- фрагменты не пересекаются между собой;
- start и end — секунды из транскрипта (числа с одним знаком после запятой);
- начинай и заканчивай по границам предложений.

Верни JSON-массив:
[{{"title":"заголовок шортса до 60 символов",
   "start":12.4,"end":58.0,
   "caption":"подпись для публикации, 1–2 предложения",
   "hashtags":["5–8 хештегов без решётки"]}}]

Только JSON-массив, ничего кроме него."""
    return [{"role": "system", "content": SYSTEM_EDITOR}, {"role": "user", "content": user}]
