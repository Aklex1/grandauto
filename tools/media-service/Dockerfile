FROM python:3.12-slim

# ffmpeg нужен и для перекодирования озвучки, и для извлечения дорожки
RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app.py .
ENV FILES_DIR=/data/files KEEP_HOURS=24
VOLUME ["/data"]
EXPOSE 8099

HEALTHCHECK --interval=60s --timeout=5s --start-period=20s \
    CMD python -c "import urllib.request;urllib.request.urlopen('http://127.0.0.1:8099/health',timeout=4)" || exit 1

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8099", "--workers", "2"]
