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

## Imports and namespaces (PHP)
- Always import classes/interfaces with `use` at the top of the file. Avoid inline fully qualified names (FQCN) like `\App\Repositories\PdpSkillRepository` in property/parameter/return types and code.
- Example (preferred):
  - use App\Repositories\PdpSkillRepository;
  - private PdpSkillRepository $skillRepo;
- Use FQCN only when absolutely necessary (e.g., string references in config) or to avoid rare name collisions.
- Keep import lists organized and remove unused imports.


## SOLID & Clean Code (for Junie)
- Follow SOLID:
  - S: Keep controllers thin; move validation to FormRequest; business logic goes to Services/Actions.
  - O: Extend via new classes/strategies; avoid modifying existing code without necessity.
  - L: Do not break parent contracts; maintain expected invariants.
  - I: Use narrow interfaces; do not force clients to depend on unnecessary things.
  - D: Depend on abstractions; inject via constructor/container.
- Clean Code rules:
  - Small methods with single responsibility; prefer early returns over deep nesting.
  - Clear names in English. Remove dead code and redundant comments.
  - Use DTOs/Value Objects for complex requests/responses; handle presentation/formatting in Resources/Presenters.
  - Use Policies/Gates for access control; use Repositories/Query classes for complex queries.
- Pre-change checklist:
  - Does the controller contain no business logic? Is validation done via a FormRequest?
  - Are dependencies injected via interfaces/DI?
  - Are there no long methods or multi-responsibility classes?
  - Are names clear; is there no dead code/comments?
  - Is all business logic executed in services/actions?
  - Are controllers clean, using only services/actions for business logic?
