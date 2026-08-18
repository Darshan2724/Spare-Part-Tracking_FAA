# Docker Container Deployment Architecture

This document describes the production Docker Compose setup powering **FAITH AUTOMATION** on Windows 11.

---

## 1. Container Topology & Services

| Service Container | Image / Dockerfile | Internal Port | Host Port | Purpose |
| :--- | :--- | :--- | :--- | :--- |
| **`sparetrack-nginx`** | `nginx:alpine` | 80 | **`:8080`** | Primary reverse proxy handling HTTP API, SPA, and WebSockets. |
| **`sparetrack-app`** | `docker/app/Dockerfile` (PHP 8.2-FPM) | 9000 | Internal | Core Laravel 11 application logic and REST API. |
| **`sparetrack-postgres`** | `postgres:16-alpine` | 5432 | Internal | Production PostgreSQL 16 database. Port 5432 is not exposed publicly. |
| **`sparetrack-redis`** | `redis:7-alpine` | 6379 | Internal | High-speed cache, session store, and queue broker. |
| **`sparetrack-reverb`** | `docker/app/Dockerfile` (Reverb) | 8080 | **`:8085`** | Realtime WebSockets for cross-device queue updates. |
| **`sparetrack-worker`** | `docker/app/Dockerfile` (Queue) | — | Internal | Background job queue worker daemon. |
| **`sparetrack-adminer`** | `adminer:latest` | 8080 | **`:8088`** | Lightweight web-based database administration console. |

---

## 2. Docker Storage Volumes

Data persistence is managed via named Docker volumes:
- `postgres_data`: Stores PostgreSQL database files persistently on the host.
- `redis_data`: Stores Redis persistent data.

---

## 3. Useful Docker Commands

From PowerShell inside `C:\SpareTrack`:

```powershell
# Start all containers in background
docker compose up -d

# Check live container health & status
docker compose ps

# View live color-coded logs
docker compose logs -f

# Rebuild containers after Dockerfile or composer.json change
docker compose up -d --build

# Stop all containers
docker compose down
```
