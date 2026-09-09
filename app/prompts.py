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
    заголовок накладывается своим шрифтом поверх. Зато просим оставить СВЕРХУ
    место под него и держать композицию в нижних двух третях кадра — заголовок
    на обложке идёт по верху.
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
        f"subject centered in the lower two thirds, clean uncluttered top third "
        f"left empty for a headline. "
        f"No text, no letters, no words, no captions, no watermark, no logo, no borders."
    )


def short_metadata(channel_name: str, topic: str, video_title: str, book_title: str,
                   heading: str, narration: str, script_excerpt: str) -> list[dict]:
    """Теги и описание для отдельного шортса.

    Модели даётся и сквозная тема ролика, и текст именно этого куска: теги должны
    цеплять обе стороны, иначе шортс либо теряется в общей массе канала, либо
    собирает нецелевые показы не по своей теме.
    """
    user = f"""Готовим публикацию вертикального шортса для YouTube.

Канал: «{channel_name}» (тематика: {topic})
Ролик целиком: «{video_title}» по книге «{book_title}»
Заголовок этого куска: {heading}

ТЕКСТ ЭТОГО ШОРТСА:
{narration}

КОНТЕКСТ ВСЕГО РОЛИКА (для связности тегов):
{script_excerpt}

Подбери теги так, чтобы они работали на двух уровнях:
- часть тегов — по сквозной теме ролика и канала, они связывают шортс с остальными;
- часть — по конкретной мысли именно этого куска, они приводят целевого зрителя.

Верни JSON:
{{"tags":["18-25 тегов на русском, по одному-три слова, без решёток, без повторов, от самых точных к общим"],
 "description":"описание шортса 200-400 символов: первая строка — крючок по мысли этого куска, затем одно предложение о ролике целиком, затем 3-5 хештегов через пробел"}}

Только JSON, ничего кроме него."""
    return [{"role": "system", "content": SYSTEM_EDITOR}, {"role": "user", "content": user}]


def bust_background(topic: str, heading: str, thumb_style: str = "") -> str:
    """Кадр для формата «бюст»: античная скульптура в дыму на тёмном фоне."""
    return still_background("bust", topic, heading, thumb_style)


# Сюжеты «живого кадра». Общее у всех одно: движение потом дорисует ffmpeg, а от
# генератора нужен кадр с запасом воздуха сверху под заголовок и с фактурой,
# которую есть смысл шевелить — дым, вода, облака, огонь, капли.
STILL_SCENES: dict[str, str] = {
    "bust": (
        "Marble bust of an ancient philosopher in profile, weathered stone texture, "
        "draped toga, looking upward. Very dark charcoal background, low drifting smoke "
        "and clouds around the base and top. Monochrome, desaturated, high contrast"
    ),
    "sea": (
        "Vast open ocean at dusk, long slow swells catching the last light, dark teal water, "
        "distant horizon line low in the frame, heavy moody sky above, spray and haze over "
        "the waves. No boats, no people, no land"
    ),
    "sky": (
        "Towering cloudscape seen from above, endless layers of cumulus lit from the side, "
        "deep blue upper sky, golden light raking across the cloud tops, vast empty space. "
        "No aircraft, no people, no ground"
    ),
    "flight": (
        "Eagle in flight seen from directly behind and slightly above, wings spread wide, "
        "flying away from the camera over a vast mountain valley far below, clouds and haze "
        "in the distance, strong sense of forward motion and depth. No people"
    ),
    "fire": (
        "Close view of glowing embers and low flames in deep darkness, orange and red coals, "
        "sparks rising, smoke drifting above, everything else black. No people, no fireplace "
        "details, no logs in focus"
    ),
    "rain": (
        "Rain running down a dark window at night, large out-of-focus water droplets and "
        "streaks on the glass, cold blue city bokeh far behind, almost black overall. "
        "No people, no text on signs"
    ),
}


def still_background(motion: str, topic: str, heading: str, thumb_style: str = "") -> str:
    """Кадр для форматов «живого кадра» — движение к нему добавит ffmpeg."""
    scene = STILL_SCENES.get(motion) or STILL_SCENES["bust"]
    style = thumb_style or "cinematic, dramatic side light, deep shadows, film grain"
    return (
        f"Vertical 9:16 cinematic still. {scene}. "
        f"Composition kept in the lower two thirds, calm empty space in the upper third "
        f"for a headline. Theme: {heading} ({topic}). {style}. "
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
