# Junie Project Guidelines

This document tells Junie how to work with this repository efficiently during tasks. It includes a short project overview, structure, and exact commands for run, build, lint, and tests.

## Project overview
- Backend: Laravel 12 (PHP >= 8.2)
- Frontend: Vue 3 + Inertia.js, Vite, TypeScript
- Styling: Tailwind CSS v4
- Lint/format:
  - PHP: Laravel Pint
  - JS/TS/Vue: ESLint + Prettier

## Repository structure (top-level)
- app/ — Laravel application code (PHP)
- resources/ — Frontend (Vue 3 + TS) and assets
- routes/ — Web/API routes
- tests/ — PHPUnit tests
- public/ — Public web root
- config/, database/, bootstrap/, storage/ — Standard Laravel dirs
- package.json, vite.config.ts — Frontend toolchain
- composer.json — PHP dependencies and scripts
- GUIDELINES.md — Human-readable full project guidelines
- .junie/guidelines.md — This file (short, actionable guide for Junie)

## How to run locally
- Install PHP deps: composer install
- Install Node deps: npm install
- Dev mode (backend + queue + logs + Vite): composer run dev
- Build frontend (production): npm run build

Environment/DB setup follows standard Laravel flow: copy .env, configure DB, run php artisan key:generate and php artisan migrate when needed.

## Tests
- Run backend tests: php artisan test
- Or via composer script: composer run test

Junie should run tests if code behavior is affected (controllers, models, services, policies, etc.). For documentation-only changes, tests are not required.

## Lint and format
- PHP (Pint): ./vendor/bin/pint
- Frontend lint: npm run lint
- Frontend format check: npm run format:check
- Frontend auto-format: npm run format

Junie should ensure linters/formatters pass before submitting when PHP/JS/TS/Vue files are changed.

## Build policy before submit
- If frontend code changed: ensure npm run build succeeds locally.
- If only backend or docs changed: build is not required.

## Commit/branching (short)
- Use Conventional Commits (feat, fix, docs, refactor, test, chore, etc.).
- Prefer small, focused changes. One PR/patch — one logical change.

## Special notes for Junie tool usage
- Prefer special tools provided by the environment (search_project, open, get_file_structure, run_test, build, etc.).
- Don’t use terminal to create/open files; use the designated tools.
- For project-wide search use search_project with short keywords.
- Keep changes minimal and well-scoped to satisfy the issue description.


## SOLID & Clean Code (для Junie)
- Дотримуйся SOLID:
  - S: Тримай контролери тонкими; валідацію винось у FormRequest; бізнес-логіку — у Services/Actions.
  - O: Розширюй через нові класи/стратегії, не змінюй існуюче без потреби.
  - L: Не ломи контракт батьківського типу; зберігай очікувані інваріанти.
  - I: Використовуй вузькі інтерфейси; не змушуй клієнтів залежати від зайвого.
  - D: Залеж від інтерфейсів; інжектуй через конструктор/контейнер.
- Clean Code правила:
  - Маленькі методи, одна відповідальність, ранні повернення замість глибокої вкладеності.
  - Зрозумілі імена англійською. Видаляй мертвий код і зайві коментарі.
  - Використовуй DTO/Value Objects для складних запитів/відповідей; форматування — у Resources/Presenters.
  - Політики/гейти для доступу; репозиторії/Query-класи для складних запитів.
- Чекліст перед змінами:
  - Контролер не містить бізнес-логіки? Валідація через FormRequest?  
  - Залежності вводяться через інтерфейси/DI?  
  - Відсутні довгі методи/багатовідповідальні класи?  
  - Імена зрозумілі; немає мертвого коду/коментарів.  
  - Вся бізнес логіка виконується в сервісах/акціях.  
  - Чисті контроллери, використовуйте виключно сервіси/акції для бізнес-логіки.
