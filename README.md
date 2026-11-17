# Stockroom

Stockroom reconciles shop deliveries with purchase orders. The Laravel 13 API runs on PHP 8.4 with MySQL 8.4 in Docker Compose. The React landing page and workspace use the same Laravel origin.

## Run locally

With Docker Desktop running, open PowerShell in the project folder:

```powershell
docker compose up -d --wait
docker compose exec -T app php artisan migrate --seed
```

On a fresh checkout, first copy the root and backend `.env.example` files to `.env`, choose random database passwords, set the backend `DB_PASSWORD` to match the root value, and set separate demo passwords in `backend/.env`. Run `docker compose run --rm app composer install` and `docker compose run --rm app php artisan key:generate` before starting the app.

Open [Stockroom](http://localhost:8081/) or its [workspace](http://localhost:8081/workspace). The database health endpoint is at /api/health. The API is bound to localhost. The database uses a persistent Docker volume. To stop the environment while keeping data, run `docker compose --profile test down`.

The demo emails are `manager@stockroom.test` and `staff@stockroom.test`. Their passwords are in the ignored `backend/.env` under `DEMO_MANAGER_PASSWORD` and `DEMO_STAFF_PASSWORD`. Seeded passwords are set when those accounts are first created.

## Build the React frontend

In the backend folder, run `npm ci` and `npm run build` before first opening Stockroom, and after frontend changes. Laravel serves the bundle from `backend/public/build` on the same origin as the API.

## Verify

```powershell
docker compose --profile test up -d --wait
docker compose exec -T app php artisan test
```

The suite uses the separate `mysql-test` service with temporary storage. It covers sessions and roles, stock calculations and reversals, simultaneous MySQL confirmations, and a simulated OpenAI service.

## Deliveries

A manager issues an order. Staff record delivered and damaged quantities on a draft and confirm it after review. Receiving 6 of 10 mugs with 1 damaged adds 5 usable mugs to stock and leaves 5 outstanding. Inventory movements link to the receipt and actor. Only a manager can reverse a confirmed receipt.

The optional OpenAI extraction reads one JPEG or PNG delivery note per receipt and suggests fields for review. It is disabled by default. Manual receiving works without it. See the [API walkthrough](docs/api.md) and [approved backend design](docs/backend-design.md).
