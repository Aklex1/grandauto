"""Ролики про ИИ-помощника юриста: вертикальный шортс и выпуск для канала.

Запуск на сервере (голос через KIE, ключ берётся из панели):

    cd /opt/contentfactory
    venv/bin/python deploy/make_explainer.py --kind short
    venv/bin/python deploy/make_explainer.py --kind long
    venv/bin/python deploy/make_explainer.py --kind both --voice-name James

Без ключа KIE озвучка берётся локальная (piper) — ролик можно посмотреть, не
потратив ни копейки, но голос заметно проще. Готовое кладётся в media и
открывается ссылкой прямо в панели.

Текст написан от лица помощника юриста, который сам пробует сервис. Обещаний
исхода дела в нём нет намеренно: это и правило площадки, и то, что сервис сам
про себя пишет — он не заменяет юриста.
"""
import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app import bootstrap  # создаёт базу и каталоги, если их ещё нет

bootstrap.run()

from app import config, explainer, storage
from app import settings_store as st
from app.db import session_scope
from app.explainer import Scene
from app.kie import KieClient

BRAND = "GENIUS"
SITE = "genius-bot.ru"

# --- шортс: 9:16, около минуты ------------------------------------------------------
SHORT = [
    Scene("intro", voice="Я помощник юриста. Расскажу, чем сам пользуюсь каждый день.",
          sub="ИИ-помощник юриста", hold=0.3),
    Scene("talk", badge="РЕАЛЬНЫЙ СЛУЧАЙ",
          title="Договор на четырнадцать страниц. К утру.",
          sub="Раньше это был вечер с маркером и кофе.",
          voice="Вчера мне дали договор на четырнадцать страниц и попросили "
                "посмотреть к утру."),
    Scene("card", glyph="lens", title="Разбор договора",
          sub="По пунктам: что это значит на практике.",
          bullets=("Что обязывает вас", "Что обязывает их", "Где сроки и штрафы"),
          voice="Я загрузил его в помощника. Через пару минут получил разбор по "
                "пунктам: что каждый из них значит на практике."),
    Scene("clause",
          bad="Исполнитель вправе в одностороннем порядке изменить сроки выполнения "
              "работ, уведомив заказчика.",
          good="Сроки изменяются только письменным соглашением сторон.",
          voice="А отдельно — формулировки против вас. Вот эта позволяет двигать "
                "сроки без вашего согласия. И сразу готовая замена."),
    Scene("offer", badge="5", title="документов бесплатно",
          sub="Хватит, чтобы проверить на своих бумагах.",
          voice="Первые пять документов можно разобрать бесплатно."),
    Scene("outro", title="Проверьте свой договор до подписи",
          sub="Ссылка на сервис — в описании канала.",
          badge="ССЫЛКА В ОПИСАНИИ",
          voice="Переходите в описание канала — там ссылка на сервис. Проверьте "
                "свой договор, пока он не подписан.", hold=0.9),
]

# --- выпуск для канала: 16:9, две с половиной минуты ---------------------------------
LONG = [
    Scene("intro", voice="ИИ-помощник юриста. Личный опыт.",
          sub="проверка договоров и подготовка документов", hold=0.4),
    Scene("talk", badge="КТО ГОВОРИТ",
          title="Я помощник юриста",
          sub="Читаю чужие договоры каждый день.",
          voice="Я работаю помощником юриста. Моя повседневная работа — читать "
                "чужие договоры и готовить документы. Это долго и однообразно, "
                "и именно поэтому я взялся проверить ИИ-помощника на своих задачах."),
    Scene("card", glyph="lens", title="Разбор договора",
          sub="По пунктам: что это значит на практике.",
          bullets=("Что обязывает вас", "Что обязывает другую сторону",
                   "Сроки, штрафы, порядок расторжения"),
          voice="Первое, ради чего я его открыл, — разбор договора. Он проходит по "
                "пунктам и объясняет каждый простыми словами: что обязывает вас, "
                "что другую сторону, где сроки и штрафы."),
    Scene("clause",
          bad="Исполнитель вправе в одностороннем порядке изменить сроки выполнения "
              "работ, уведомив заказчика.",
          good="Сроки изменяются только письменным соглашением сторон.",
          voice="Отдельным блоком идут формулировки против вас. В моём договоре он "
                "нашёл право подрядчика двигать сроки в одностороннем порядке — и "
                "сразу предложил замену. Эту правку я вставил в протокол разногласий "
                "почти без изменений."),
    Scene("card", glyph="doc", title="Не только договоры",
          sub="То, на что обычно уходит полдня.",
          bullets=("Претензия: адресат, основание, требования, срок",
                   "Заявление в организацию или госорган",
                   "Ответ на требование", "Расписка, акт, соглашение"),
          voice="Дальше я попробовал остальное. Претензия с адресатом, основанием и "
                "сроком. Заявление в госорган. Ответ на требование. Расписки и акты. "
                "Это та самая черновая работа, на которую обычно уходит полдня."),
    Scene("faq", badge="1", title="Это заменит юриста?",
          sub="Нет. Ассистент снимает черновую работу: прочитать договор, найти "
              "слабые места, собрать документ по форме. Перед подачей в суд или "
              "госорган результат должен посмотреть практикующий юрист.",
          voice="Теперь частые вопросы. Это заменит юриста? Нет. Он снимает черновую "
                "работу: прочитать, найти слабые места, собрать документ по форме. "
                "Перед подачей в суд или госорган результат должен посмотреть "
                "практикующий юрист."),
    Scene("faq", badge="2", title="Насколько ему можно верить?",
          sub="Проверяйте номера норм и сроки. Ассистент помечает, где не уверен, "
              "и не обещает исход дела.",
          voice="Насколько ему можно верить? Проверяйте номера норм и сроки. Он "
                "честно помечает, где не уверен, и не обещает исход дела. Если "
                "какой-то сервис обещает — это повод насторожиться."),
    Scene("faq", badge="3", title="Договор длинный, влезет?",
          sub="До шести тысяч знаков за раз. Длинный договор присылайте частями "
              "по разделам.",
          voice="Договор длинный, влезет? До шести тысяч знаков за раз. Длинный "
                "договор присылайте частями по разделам — разбор выйдет даже "
                "точнее, потому что контекст не размывается."),
    Scene("offer", badge="5", title="документов бесплатно",
          sub="Проверьте на своих бумагах, прежде чем платить.",
          voice="Первые пять документов можно разобрать бесплатно. Этого достаточно, "
                "чтобы проверить его на своих бумагах и решить, нужен ли он вам."),
    Scene("outro", title="Ссылка на сервис — в описании",
          sub=f"{SITE} — ИИ-помощник юриста",
          badge=SITE.upper(),
          voice="Ссылка на сервис — в описании под этим роликом. Проверьте свой "
                "договор до того, как подпишете его.", hold=1.0),
]


def pick_voice(args):
    """Голос: KIE, если ключ есть, иначе локальный piper."""
    with session_scope() as session:
        key = args.kie_key or st.get(session, "kie_api_key", "") or config.KIE_API_KEY
        model = args.voice_model or st.get(session, "default_tts_model",
                                           "elevenlabs/text-to-speech-multilingual-v2")
    if key and not args.local:
        voice_id = args.voice_id
        if not voice_id:
            for row in explainer_voices():
                if row.get("name", "").lower() == (args.voice_name or "").lower():
                    voice_id = row.get("voice_id")
                    break
        if not voice_id:
            voice_id = (explainer_voices() or [{}])[0].get("voice_id", "")
        if voice_id:
            print(f"голос: KIE {model}, voice_id {voice_id}")
            return explainer.kie_voice(KieClient(api_key=key), model=model,
                                       voice_id=voice_id, speed=args.speed)
    local = Path(args.piper or "")
    if not local.is_file():
        raise SystemExit("Ключа KIE нет, и локальная модель piper не указана: "
                         "передайте --piper путь/к/модели.onnx")
    print(f"голос: локальный piper {local.name}")
    return explainer.piper_voice(local)


def explainer_voices():
    from app import tts

    try:
        return tts.load_voice_catalog()
    except Exception:  # noqa: BLE001 — список голосов не обязателен
        return []


# Фоновые клипы: предметные кадры по теме, без людей и без текста — поверх них
# ложатся карточки и подписи, и лишние детали только мешают читать.
CLIP_PROMPTS = (
    "close-up of hands turning the pages of a printed contract on a dark wooden desk, "
    "warm desk lamp light, shallow depth of field, slow calm motion",
    "a laptop on a dark desk at night showing a document on screen, soft blue glow, "
    "papers and a pen beside it, slow push-in, no readable text",
    "soft abstract dark navy background with slowly drifting light and faint paper "
    "texture, cinematic, minimal, no objects",
)
CLIP_RULES = ("cinematic footage, dark navy and amber palette, calm slow movement, "
              "no text, no letters, no watermark, no logo, no people's faces")


def ensure_clips(args, size, out: Path) -> list:
    """Фоновые клипы под нужные пропорции. Генерируются один раз и переиспользуются.

    Это единственная платная часть картинки, поэтому повторный запуск берёт
    готовое с диска: пересобрать ролик с другим текстом ничего не стоит.
    """
    from app import kie, loops
    from app.kie import KieClient

    vertical = size[1] > size[0]
    folder = out / "clips" / ("v" if vertical else "h")
    folder.mkdir(parents=True, exist_ok=True)
    ready = sorted(folder.glob("clip_*.mp4"))
    if ready and not args.regen_clips:
        print(f"фон: беру готовые клипы ({len(ready)})")
        return ready

    with session_scope() as session:
        key = st.get(session, "kie_api_key", "") or config.KIE_API_KEY
        image_model = st.get(session, "default_image_model", "nano-banana-2")
        video_model = st.get(session, "default_video_model", "")
    if args.kie_key:
        key = args.kie_key
    if not key:
        raise SystemExit("Ключа KIE нет — фон из клипов сгенерировать нечем")
    video_model = loops.to_image_model(video_model or loops.DEFAULT_VIDEO_MODEL)
    client = KieClient(api_key=key)

    made: list = []
    spent = 0.0
    for index, prompt in enumerate(CLIP_PROMPTS[:args.clips], start=1):
        dst = folder / f"clip_{index:02d}.mp4"
        if dst.exists() and not args.regen_clips:
            made.append(dst)
            continue
        shot = client.run_task(image_model, kie.image_input_payload(
            image_model, prompt=f"{prompt}. {CLIP_RULES}",
            aspect_ratio="9:16" if vertical else "16:9",
            resolution="2K", output_format="png"), timeout=900, poll=5)
        urls = kie.extract_urls(shot)
        spent += float(shot.get("_credits") or 0)
        if not urls:
            raise SystemExit(f"{image_model}: нет ссылки на картинку фона")
        clip = client.run_task(video_model, kie.image_to_video_input(
            video_model, prompt=f"{prompt}. {CLIP_RULES}", image_urls=urls[:1],
            resolution="1080p", duration=5), timeout=1800, poll=8)
        vids = kie.extract_urls(clip)
        spent += float(clip.get("_credits") or 0)
        if not vids:
            raise SystemExit(f"{video_model}: нет ссылки на клип фона")
        storage.download(vids[0], dst)
        print(f"фон {index}: {dst.name}")
        made.append(dst)
    print(f"фон: потрачено {spent:.1f} кредитов")
    return made


def main() -> int:
    ap = argparse.ArgumentParser(description="Ролики про ИИ-помощника юриста")
    ap.add_argument("--kind", choices=("short", "long", "both"), default="both")
    ap.add_argument("--voice-name", default="James", help="имя голоса из каталога")
    ap.add_argument("--voice-id", default="", help="voice_id напрямую")
    ap.add_argument("--voice-model", default="", help="модель озвучки")
    ap.add_argument("--speed", type=float, default=1.0)
    ap.add_argument("--local", action="store_true", help="принудительно локальный голос")
    ap.add_argument("--piper", default="", help="путь к модели piper .onnx")
    ap.add_argument("--captions", choices=("ass", "draw"), default="ass",
                    help="ass — вжигать ffmpeg'ом, draw — рисовать в кадре")
    ap.add_argument("--music", default="", help="подложка (необязательно)")
    ap.add_argument("--bg", choices=("kie", "draw"), default="kie",
                    help="kie — сгенерированные клипы фоном, draw — рисованный фон")
    ap.add_argument("--clips", type=int, default=3, help="сколько клипов фона")
    ap.add_argument("--regen-clips", action="store_true", help="перегенерировать фон")
    ap.add_argument("--kie-key", default="", help="ключ KIE, если его нет в панели")
    args = ap.parse_args()

    speak = pick_voice(args)
    out = config.MEDIA_DIR / "_explainer"
    out.mkdir(parents=True, exist_ok=True)
    music = Path(args.music) if args.music else None

    jobs = []
    if args.kind in ("short", "both"):
        jobs.append(("short", SHORT, (1080, 1920)))
    if args.kind in ("long", "both"):
        jobs.append(("long", LONG, (1920, 1080)))

    for name, scenes, size in jobs:
        dst = out / f"lawyer_{name}.mp4"
        clips = ensure_clips(args, size, out) if args.bg == "kie" else None
        explainer.build([Scene(**{**scene.__dict__}) for scene in scenes], dst,
                        size=size, workdir=out / f"work_{name}", speak=speak,
                        brand=BRAND, music=music, captions=args.captions, clips=clips)
        print(f"{name}: {dst}")
        print(f"      в панели: /media/{storage.rel(dst)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
