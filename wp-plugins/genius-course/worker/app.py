# -*- coding: utf-8 -*-
"""Служба генерации на арендованной видеокарте.

Задача у неё узкая: принять описание кадра, посчитать картинку и отдать
её сайту. Всё остальное — учёт, лимиты, хранение — остаётся на сайте,
потому что машина живёт часами, а сайт годами.

Очередь одна и обрабатывается в один поток: видеокарта всё равно одна, а
две параллельные генерации на ней идут медленнее, чем две подряд, и
изредка падают по памяти.

Запуск:
    GC_TOKEN=... GC_SECRET=... python app.py
По умолчанию слушает 0.0.0.0:18080.
"""

import base64
import io
import os
import queue
import threading
import time
import traceback
import uuid

import requests
import torch
import uvicorn
from diffusers import StableDiffusionXLPipeline
from fastapi import Depends, FastAPI, Header, HTTPException
from pydantic import BaseModel

TOKEN = os.environ.get("GC_TOKEN", "")
MODEL = os.environ.get("GC_MODEL", "stabilityai/stable-diffusion-xl-base-1.0")
PORT = int(os.environ.get("GC_PORT", "18080"))

app = FastAPI(title="Genius Course GPU worker")

jobs = {}
jobs_lock = threading.Lock()
work_queue: "queue.Queue[str]" = queue.Queue()
pipe = None


class JobIn(BaseModel):
    prompt: str
    model: str = ""
    width: int = 1024
    height: int = 1024
    steps: int = 28
    job: int = 0
    callback: str = ""
    sign: str = ""


def check(authorization: str = Header(default="")):
    """Токен один на машину: публиковать её в интернет без него нельзя."""
    if not TOKEN:
        return
    if authorization != "Bearer " + TOKEN:
        raise HTTPException(status_code=401, detail="нет доступа")


def load_pipe():
    """Модель поднимаем один раз: загрузка весов занимает минуты."""
    global pipe
    if pipe is not None:
        return pipe
    pipe = StableDiffusionXLPipeline.from_pretrained(
        MODEL,
        torch_dtype=torch.float16,
        variant="fp16",
        use_safetensors=True,
    )
    pipe = pipe.to("cuda")
    pipe.set_progress_bar_config(disable=True)
    return pipe


def run_job(job_id: str):
    with jobs_lock:
        item = dict(jobs.get(job_id, {}))
    if not item:
        return
    try:
        p = load_pipe()
        # Кратные восьми размеры: модель округлит сама, но тогда кадр
        # перестанет совпадать с тем, что человек просил.
        width = max(512, min(1536, item["width"] // 8 * 8))
        height = max(512, min(1536, item["height"] // 8 * 8))
        image = p(
            prompt=item["prompt"],
            width=width,
            height=height,
            num_inference_steps=max(10, min(60, item["steps"])),
        ).images[0]

        buf = io.BytesIO()
        image.save(buf, format="PNG")
        encoded = base64.b64encode(buf.getvalue()).decode("ascii")

        with jobs_lock:
            jobs[job_id]["status"] = "done"
            jobs[job_id]["image"] = encoded
            jobs[job_id]["finished"] = time.time()
        notify(item, image=encoded)
    except Exception as exc:  # noqa: BLE001 — наверх отдаём текст, а не падаем
        reason = "".join(traceback.format_exception_only(type(exc), exc)).strip()
        with jobs_lock:
            jobs[job_id]["status"] = "failed"
            jobs[job_id]["error"] = reason
            jobs[job_id]["finished"] = time.time()
        notify(item, error=reason)


def notify(item, image: str = "", error: str = ""):
    """Обратный вызов на сайт.

    Если у машины нет белого адреса или сайт недоступен — не беда: сайт
    всё равно опрашивает задания по расписанию. Поэтому ошибку здесь
    только печатаем.
    """
    url = item.get("callback") or ""
    if not url:
        return
    body = {"job": item.get("job", 0), "sign": item.get("sign", "")}
    if error:
        body["error"] = error
    else:
        body["image"] = image
    try:
        requests.post(url, json=body, timeout=30)
    except Exception as exc:  # noqa: BLE001
        print("обратный вызов не прошёл:", exc, flush=True)


def worker():
    while True:
        job_id = work_queue.get()
        try:
            run_job(job_id)
        finally:
            work_queue.task_done()


threading.Thread(target=worker, daemon=True).start()


@app.get("/health")
def health(_=Depends(check)):
    name = "видеокарта не найдена"
    if torch.cuda.is_available():
        name = torch.cuda.get_device_name(0)
    with jobs_lock:
        busy = sum(1 for j in jobs.values() if j["status"] in ("queued", "running"))
    return {"ok": True, "gpu": name, "queue": busy, "model": MODEL}


@app.post("/jobs")
def create(job: JobIn, _=Depends(check)):
    if len(job.prompt.strip()) < 3:
        raise HTTPException(status_code=400, detail="пустое описание")
    job_id = uuid.uuid4().hex[:16]
    with jobs_lock:
        jobs[job_id] = {
            "status": "queued",
            "prompt": job.prompt,
            "width": job.width,
            "height": job.height,
            "steps": job.steps,
            "job": job.job,
            "callback": job.callback,
            "sign": job.sign,
            "created": time.time(),
        }
    work_queue.put(job_id)
    return {"id": job_id, "status": "queued"}


@app.get("/jobs/{job_id}")
def state(job_id: str, _=Depends(check)):
    with jobs_lock:
        item = jobs.get(job_id)
        if not item:
            raise HTTPException(status_code=404, detail="нет такого задания")
        out = {"status": item["status"]}
        if item["status"] == "done":
            out["image"] = item.get("image", "")
        if item["status"] == "failed":
            out["error"] = item.get("error", "")
    return out


if __name__ == "__main__":
    # Модель грузим до первого запроса: иначе первый ученик ждёт минуты
    # и думает, что всё сломалось.
    print("грузим модель…", flush=True)
    load_pipe()
    print("готово, слушаем порт", PORT, flush=True)
    uvicorn.run(app, host="0.0.0.0", port=PORT, log_level="info")
