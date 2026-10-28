FROM python:3.11-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    curl \
    libffi-dev \
    libssl-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt \
    && apt-get purge -y --auto-remove build-essential \
    && rm -rf /var/lib/apt/lists/*

# Run container as non-root user with writable home directory for caches (MED-07)
RUN useradd -m -u 1000 -s /bin/bash appuser \
    && mkdir -p /home/appuser/.cache \
    && chown -R appuser:appuser /home/appuser /app

COPY --chown=appuser:appuser . .

USER appuser
ENV HOME=/home/appuser

CMD ["python", "agent.py", "start"]
