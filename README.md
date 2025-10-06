# 🚀 Laravel + Vite + Docker

This project runs a Laravel application with PHP-FPM, Vite, and Nginx using Docker.

## 📦 Run

```bash
docker compose up --build
````

The containers will install dependencies, generate the `.env` file if missing, run migrations, and start the development servers automatically.

* Vite: [http://localhost:5173](http://localhost:5173)
* Nginx: [http://localhost:8080](http://localhost:8080)
