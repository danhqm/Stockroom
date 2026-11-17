# Stockroom backend design

Status: approved by user. Backend first; visual design follows working flows.

## Purpose and stack

Help a small retailer reconcile deliveries against purchase orders and maintain a traceable stock balance. React frontend, Laravel backend, MySQL, OpenAI document extraction, Docker Compose. No AWS. Pin supported versions during setup after checking official documentation.

## First-version scope

One shop, one stock location, integer unit quantities and two roles: manager and receiving staff. No public signup. Managers manage products, suppliers and orders; staff review and confirm deliveries. Managers also have all receiving permissions. Both can view inventory. Products have unique SKUs and can be archived without deleting history. Accounts are seeded for local demonstration with configurable private passwords.

## Approaches considered

1. Recommended: explicit Laravel JSON API, MySQL transactions and a stock movement ledger. React consumes the API later. This makes backend behaviour independently testable.
2. Laravel with Inertia and React: fewer API boundaries, but couples the initial backend work to page flows.
3. Full warehouse management: multiple locations, transfers, reservations and valuation. Adds substantial scope before the delivery workflow is proven.

## Happy path

1. Manager creates products and a supplier.
2. Manager creates a draft purchase order with product quantities, then issues it. Issued lines are immutable.
3. Staff creates a draft receipt against that order, manually or by uploading a delivery note.
4. Extraction proposes supplier, reference, product text and quantities. Staff explicitly maps each row to a product line on the chosen order and reviews it against the order and original document.
5. Staff records physically delivered and damaged quantities. Usable quantity equals delivered minus damaged. Zero through delivered is allowed for damaged quantity.
6. Confirmation writes the receipt and positive stock movements in one database transaction. The order becomes partially received or fulfilled based on cumulative usable quantities.
7. Inventory shows balances and links every movement back to the receipt and actor.

Example: order 10 mugs; first delivery contains 6, including 1 damaged. Confirming adds 5 to stock and leaves 5 usable units outstanding. A later usable delivery of 5 completes the order.

## Rules that protect the records

- Drafts, uploads and AI output never change inventory.
- Unknown products require explicit mapping. AI cannot create products or approve a receipt.
- An order contains each product at most once. A receipt contains each order line at most once.
- Delivered quantity is positive; damaged quantity cannot exceed it. At least one receipt line is required.
- The first version rejects usable receipt quantities above the outstanding usable quantity; staff resolves excess with the supplier outside the app. This conservative rule must be explained by validation messages.
- Outstanding quantities and balances are checked server-side while locking the order and affected records, so concurrent receipts cannot overreceive.
- Confirmation locks the receipt and checks its state. A repeated confirmation returns the existing result without new movements. A unique constraint on (receipt_line_id, movement_type) permits one receiving movement and one reversal movement per line.
- Confirmed receipts are immutable. Managers can reverse a receipt once, with a reason and linked negative movements. Reversal rejects a negative resulting balance and recalculates the order's outstanding quantities. Staff then creates a corrected receipt if needed.
- Managers can close an incomplete order with a reason, preserving the unreceived balance. Closed orders cannot receive further deliveries. Reversal against a closed order preserves its closed state.
- Warn about a repeated supplier delivery-note reference; do not silently merge it. A retry of draft creation uses an idempotency key to avoid duplicate drafts. Human review is still needed for genuinely duplicate documents.
- Store and display actor, timestamps and reasons for confirmation, reversal and closure.

## Data model

Users; suppliers; products; purchase_orders; purchase_order_lines; receipts; receipt_lines; stock_movements; document_extractions.

Receipt states: draft, confirmed, reversed. Order states: draft, issued, partially_received, fulfilled, closed. Extraction states: queued, processing, completed, failed.

Stock movements are append-only. Start balances at zero and derive current stock from movement sums. Add cached balances only if measured query performance requires it. Money, tax, valuation and payment processing are outside this version.

## API boundaries

Session login/logout/current user; product and supplier management; order draft creation/editing/issue/close; receipt draft creation/editing/confirmation/reversal; inventory and movement history; private document upload/extraction status.

Use Laravel session authentication with CSRF protection for the first-party React app, server-side policies for every action, validation errors tied to fields, and paginated lists. State changes have explicit endpoints rather than accepting arbitrary status values. Tests use separate MySQL data, never the developer's application database.

## OpenAI integration

First prove the full receipt flow with manual entry and fake extraction responses. Then add private JPEG/PNG upload, bounded by byte and decoded-image limits. Keep documents behind authenticated access. Do not fetch user-supplied remote URLs.

Use a Laravel database-backed queue for extraction. Send only the selected document to OpenAI, request structured fields, validate all output, and preserve extracted source text separately from reviewed receipt data. Treat document text as data, not instructions. Never invent unreadable quantities or rely on an AI confidence score to approve a field.

Keep API keys on the backend. Set request/time limits and a configurable usage allowance before any live calls. Use fake extraction in tests. Extraction failures leave the draft available for manual completion. A repeated extraction job must not create additional receipts or mutate inventory. Provider retries must be bounded and counted.

## Implementation order and acceptance checks

1. Docker Laravel/MySQL setup, health endpoint, authentication and role policies.
2. Products, suppliers and purchase orders; prove issue/edit/close rules.
3. Manual receipts and inventory ledger; demonstrate the 10-mug partial/damaged example.
4. Confirmation and reversal tests, including concurrent confirmation, retries, insufficient stock, forbidden actions and transaction rollback.
5. Queued document extraction with fake provider; test unknown products, malformed output, failed uploads, denied document access and repeated jobs.
6. One explicitly budgeted live extraction smoke check; API walkthrough and frontend integration contract.
7. React workflow implementation, then visual design.

## Deferred decisions and limits

Hosting remains undecided. Multi-shop tenancy, multiple warehouses, fractional units, barcode devices, sales deductions, returns to suppliers, PDFs, accounting and procurement payments are deferred. This version demonstrates receiving and reconciliation, not a complete retail stock system. Live AI spend and the deployment provider will be agreed before use.

## Review refinements

All-damaged receipts may be confirmed with zero usable quantity; they create no stock movements. An order with zero cumulative usable receipts remains issued. Reversing a zero-usable receipt also creates no movements. Unmapped or off-order products block confirmation. Every confirmation and reversal locks the order, receipt, and affected product rows in a consistent order; products are locked by ascending ID across all orders before reading stock balances or inserting movements.
