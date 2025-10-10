# 📘 Laravel + Vite — Docker Guide

Run Laravel + Vite locally with **Docker** for both dev and production.

---

## 🧪 Development

### ▶ Start

```bash
docker compose up -d        # or: docker compose up -d --build
```

Services:

* `app` — Node + Vite dev server
* `php` — PHP-FPM
* `nginx` — [http://localhost:8080](http://localhost:8080)
* `mysql` — port 3306
* `phpmyadmin` — [http://localhost:8081](http://localhost:8081)

First run: copies `.env`, installs deps, runs migrations, sets key, links storage.

### 📝 Code Mount

```yaml
volumes:
  - ./:/var/www/html
```

Edits are live — no rebuild needed.

### 🔧 Common Commands

```bash
docker compose exec php php artisan migrate
docker compose exec php composer require <pkg>
docker compose exec app npm i <pkg> -D
docker compose down -v    # stop + clear DB
```

---

## 🚀 Production (Local)

Builds frontend once → serves via Nginx & PHP-FPM (no Vite server).

### ▶ Start

```bash
docker compose -f docker-compose.prod.yaml up -d --build
```

### ⚙ First Init

```bash
docker compose -f docker-compose.prod.yaml exec php sh -lc '[ -f .env ] || cp .env.example .env'
docker compose -f docker-compose.prod.yaml exec php php artisan key:generate --force
docker compose -f docker-compose.prod.yaml exec php php artisan migrate --force
docker compose -f docker-compose.prod.yaml exec php php artisan storage:link || true
docker compose -f docker-compose.prod.yaml exec php php artisan config:cache
```

Visit:

* App → [http://localhost](http://localhost)
* phpMyAdmin → [http://localhost:8081](http://localhost:8081)

---
