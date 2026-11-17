# Stockroom

Stockroom helps shop staff check deliveries against purchase orders, record shortages and damage, and see what actually went into inventory. Every stock movement links back to the receipt and the person who confirmed it.

[Try the live demo](https://stockroom-khaki.vercel.app/)

## Demo video

https://github.com/user-attachments/assets/30f6263c-4daf-4793-9fce-64676144efaa

## Try the demo

1. Open the [workspace](https://stockroom-khaki.vercel.app/workspace) and choose **Open staff demo**.
2. Open **Receipts** and select the sample receipt. The detail page shows Harbour Ceramics as the supplier. Expand **Sample delivery note** to compare the original image with the saved extraction.
3. If the receipt is still a draft, enter **6 delivered** and **1 damaged**, save the review, then confirm it. **Inventory** shows the movement of **5 usable mugs**. If someone has already confirmed it, open the receipt or ledger to see the result.

The public demo uses shared fictional data and a saved extraction, with live uploads and OpenAI calls disabled.

## Stack and deployment

- **Frontend** — React and Vite, served by Laravel on the same origin as the API.
- **Backend** — Laravel 13 on PHP 8.4, with database-backed sessions and a queue for optional delivery-note extraction.
- **Local** — Docker Compose with MySQL 8.4 and a separate temporary MySQL test database.
- **Hosted demo** — Vercel runs the production Docker image with FrankenPHP. The demo stores its data in TiDB Cloud Starter, a MySQL-compatible service. Production credentials are configured through Vercel environment variables.

## Run locally

With Docker Desktop running, open PowerShell in the repository root. On a fresh checkout, copy the root and backend `.env.example` files to `.env`:

```powershell
Copy-Item .env.example .env
Copy-Item backend/.env.example backend/.env
```

Set random database passwords in the root `.env`, use the same `DB_PASSWORD` in `backend/.env`, and set `DEMO_MANAGER_PASSWORD` and `DEMO_STAFF_PASSWORD` in `backend/.env` to separate passwords of at least 12 characters. Then run:

```powershell
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps app php artisan key:generate
npm --prefix backend ci
npm --prefix backend run build
docker compose up -d --wait
docker compose exec -T app php artisan migrate --seed
```

Open the [local workspace](http://localhost:8081/workspace). The database health endpoint is at `/api/health`. Run `docker compose --profile test down` to stop the stack and keep the local MySQL data.

The demo emails are `manager@stockroom.test` and `staff@stockroom.test`. Their passwords are in the ignored `backend/.env` under `DEMO_MANAGER_PASSWORD` and `DEMO_STAFF_PASSWORD`. Seeded passwords are set when those accounts are first created.

Run `npm --prefix backend run build` again after changing the frontend.

## Verify

```powershell
docker compose --profile test up -d --wait mysql-test
docker compose run --rm --no-deps app php artisan test
```

The suite uses the separate `mysql-test` service with temporary storage. It covers sessions and roles, stock calculations and reversals, concurrent confirmations, and simulated OpenAI responses.

## Deliveries

A manager issues an order. Staff record delivered and damaged quantities on a draft and confirm it after review. Receiving 6 of 10 mugs with 1 damaged adds 5 usable mugs to stock and leaves 5 outstanding. Inventory movements link to the receipt and actor. Only a manager can reverse a confirmed receipt.

The optional OpenAI extraction reads one JPEG or PNG delivery note per receipt and suggests fields for review. It is disabled by default. Manual receiving works without it. See the [API walkthrough](docs/api.md) and [approved backend design](docs/backend-design.md).
