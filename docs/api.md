# Stockroom API

Use JSON and the same origin as Laravel. Fetch `GET /api/csrf`, keep the returned session cookie, and send the JSON token in `X-CSRF-TOKEN` on writes. Log in with `POST /api/login` using `email` and `password`, then refresh the token because login rotates the session. `GET /api/me` gives the current user and role. `POST /api/logout` ends the session.

Successful detail and write responses wrap records in `data`. Lists paginate 25 records per page with `?page=2`. Common errors are 401 (sign in), 403 (role), 404 (unknown record), 409 (state conflict), 419 (refresh the CSRF token), and 422 (field validation). Send `Accept: application/json`.

| Resource | Routes and payloads |
| --- | --- |
| Products | `GET/POST /api/products`, `PUT /api/products/{id}`. Send `sku`, `name`, and optionally `archived`. |
| Suppliers | `GET/POST /api/suppliers`, `PUT /api/suppliers/{id}`. Send `name`. |
| Purchase orders | `GET/POST /api/purchase-orders`, `GET/PUT /api/purchase-orders/{id}`. Send `supplier_id` and `lines` of `product_id` and `quantity`. |
| Order actions | `POST /api/purchase-orders/{id}/issue` or `/close`. Close takes a `reason`. |
| Receipts | `GET/POST /api/receipts`, `GET/PUT /api/receipts/{id}`. The list accepts `purchase_order_id`. See example below. |
| Receipt actions | `POST /api/receipts/{id}/confirm` or `/reverse`. Reverse takes a `reason`. |
| Inventory | `GET /api/inventory` and `GET /api/inventory/{product}/movements`. |

Managers can write catalogue records, manage orders, and reverse receipts. Managers and staff can view records and draft, review, and confirm receipts.

## Manual walkthrough

1. As manager, create a product and a supplier. Create an order for 10 units of the product and issue it. Save the returned order ID and order-line ID.
2. As staff, create a draft with the following shape, substituting the IDs:

   ```json
   {
     "purchase_order_id": 1,
     "idempotency_key": "receiving-example-first",
     "reference": "DN-101",
     "lines": [{"purchase_order_line_id": 1, "delivered": 6, "damaged": 1}]
   }
   ```

3. Review the draft. An empty line list is valid for a draft, allowing document upload before review. Confirmation requires at least one line. Updates replace the draft's `lines` and `reference` and require its current `revision`. The detail response flags a repeated delivery-note reference for the same supplier.
4. Confirm with a JSON body containing the `revision` you reviewed. If the draft changed, reload it after the 409 response and review the new values. Order details show 5 received and 5 outstanding. Inventory shows 5 usable units. Repeating confirmation returns the confirmed receipt. Retries of draft creation use the same key and payload for that order and actor.
5. Confirm a second receipt for 5 usable units. The order becomes fulfilled. A manager can reverse that receipt with a reason, which records a negative movement and restores the outstanding quantity.

## Delivery notes

With extraction enabled, upload a JPEG or PNG (up to 2 MiB, 32–2048 px per side) as the multipart `document` field at `POST /api/receipts/{id}/document`. Each draft accepts one document, and the upload queues its image for OpenAI. `GET /api/receipts/{id}/document` retrieves the authenticated original; `GET /api/receipts/{id}/extraction` returns progress and suggested fields. Unreadable quantities can be null. The user maps extracted lines to order lines and saves reviewed quantities in the draft.

For an explicitly authorized live run, configure the backend's ignored environment file with `STOCKROOM_EXTRACTION_ENABLED=true`, `OPENAI_API_KEY`, and a positive `STOCKROOM_EXTRACTION_ATTEMPT_LIMIT`. The limit is the cumulative number of reserved provider attempts in this database, including failures. It is a request cap. Restart the app after editing configuration. Start the database queue worker in its own terminal:

```powershell
docker compose exec app php artisan queue:work database --timeout=45
```

Extraction uses OpenAI image input and Structured Outputs. The receipt stays editable if extraction fails or the allowance runs out. The test suite uses simulated provider responses. [OpenAI image input](https://developers.openai.com/api/docs/guides/images-vision) · [Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs)
