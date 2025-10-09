# 📘 Laravel + Vite — Docker Development & Production Guide

This document describes how to build, run, and manage **development** and **production** environments using Docker and Docker Compose.

---

## 🧪 Development Environment (`docker-compose.yaml`)

### 🐳 Start Dev Environment

```bash
docker compose up -d
```

This will start:

* `app` — runs Vite dev server (`npm run dev`) and Laravel tasks
* `php` — runs PHP-FPM
* `nginx` — serves requests on [http://localhost:8080](http://localhost:8080)
* `mysql` — database (exposed on port 3306)
* `phpmyadmin` — available on [http://localhost:8081](http://localhost:8081)

First run will:

* Copy `.env.example` → `.env` (via `entrypoint.sh`)
* Install PHP & Node dependencies
* Run migrations, generate key, link storage

### 📂 Live Code Mount

The `app` and `php` containers mount your local project folder:

```yaml
volumes:
  - ./:/var/www/html
```

This means:

* Laravel and Vite automatically reflect changes.
* No rebuild needed for PHP or JS changes.

### 🧰 Useful Commands

```bash
docker compose up -d --build
docker compose build
docker compose up -d
```

### 🧼 Stop Dev

```bash
docker compose down
```

To remove DB data as well:

```bash
docker compose down -v
```

---

## 🚀 Production Environment (`docker-compose.prod.yaml`)

The production setup is a **multi-stage build** that:

* Builds frontend assets once (`assets` stage)
* Produces a lean PHP image with compiled assets
* Runs separate PHP-FPM and Nginx containers
* Uses named volumes (`app_code` and `storage`) for runtime data

### 🧱 Build Images + Start Stack

```bash
docker compose -f docker-compose.prod.yaml up -d --build
```

Services:

* `php` — runs Laravel under PHP-FPM (prod stage)
* `nginx` — serves built assets & forwards PHP to `php-fpm`
* `mysql` / `phpmyadmin` — as in dev

---

### ⚙️ Laravel Initialization

```bash
docker compose -f docker-compose.prod.yaml exec php sh -lc '[ -f .env ] || cp .env.example .env'
docker compose -f docker-compose.prod.yaml exec php php artisan key:generate --force
docker compose -f docker-compose.prod.yaml exec php php artisan migrate --force
docker compose -f docker-compose.prod.yaml exec php php artisan storage:link || true
docker compose -f docker-compose.prod.yaml exec php php artisan config:cache
docker compose -f docker-compose.prod.yaml exec php php artisan route:cache || true
docker compose -f docker-compose.prod.yaml exec php php artisan view:cache || true
```

---

### 🌐 Access the App

Visit [http://localhost](http://localhost) — you’re now running a **fully built production stack** locally.

---

### 🧪 Local Production Testing

You can fully test production locally by simply running:

```bash
docker compose -f docker-compose.prod.yaml up -d --build
```

Then visiting [http://localhost](http://localhost).
You’ll see exactly what your server would serve — no dev server (`5173`) involved, because `public/hot` is removed during the build.

---
