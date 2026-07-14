# KeyCRM Sync (OpenCart 4)

Order synchronization between OpenCart 4.x and **KeyCRM** — pushes orders to the CRM and, optionally, pulls status / tracking / stock updates back.

## Features
- Pushes orders to KeyCRM on placement or on a configurable status change.
- Idempotent — `UNIQUE(order_id, target)` guarantees no duplicate orders even if the event re-fires.
- Retry queue via cron (`extension/cc_crm/cron.retry`) for failed pushes, with an attempt cap.
- Order mapper: skips zero-price lines, normalizes phone to `+380…`, maps shipping/payment.
- Auto-creates the KeyCRM source on the first order (sends `source_name` when no `source_id` is set).
- Encrypted API key at rest (sodium / HMAC fallback).
- Sync journal with per-order status and request/response excerpts.
- **Reverse sync** (statuses, tracking codes, stock) — see below.

## Reverse sync (KeyCRM → OpenCart)
Optional, off by default; configured on the *Reverse sync* tab of the module. A second cron task (`extension/cc_crm/cron.reverse`, registered in the OpenCart cron registry as `cc_crm_reverse`) pulls from KeyCRM:

- **Order statuses.** Fetches orders updated since the previous run (`GET /order?include=shipping&filter[updated_between]=FROM,TO&sort=-updated_at`, paginated), matches them to OpenCart orders by `source_uuid` = `oc-{order_id}` (written by the forward sync) and applies the status through the admin-configured mapping *KeyCRM status → OpenCart status* (order history entry; customer notification optional, off by default). KeyCRM statuses for the mapping are loaded right in the admin via `GET /order/status`.
- **Tracking codes.** When `shipping.tracking_code` appears on a KeyCRM order, a one-time history comment `ТТН: {code}` is added (deduplicated via the `tracking_code` column of the sync journal).
- **Stock levels** (separate checkbox, off by default). `GET /offers/stocks` (paginated) → offers matched by SKU → `oc_product.quantity` updated; the run summary with the number of updated products goes to the OpenCart error log.

Details:
- The last-run timestamp is stored in the module settings; each run re-scans a 10-minute overlap so boundary updates are never missed. Timestamps are handled in UTC (KeyCRM API time).
- Anti-loop: reverse sync is read-only towards KeyCRM and mutes the forward-sync event while writing order history, so nothing is ever pushed back.
- Only reverse-capable adapters participate (currently KeyCRM); for any other target the feature is hidden and a no-op.

## Install
Admin → Extensions → Installer → upload `cc_keycrm_sync.ocmod.zip`, then Extensions → Modules → install **KeyCRM Sync**. Enter the KeyCRM API key (Settings → General → API key in KeyCRM) and enable the target.

## Structure
Repository root is the extension payload (`admin/`, `catalog/`, `system/`, `install.json`). Zip these into `cc_keycrm_sync.ocmod.zip` for the OpenCart installer.

---
© CatCode — https://catcode.com.ua


## 🔗 CatCode

Модуль розробляє і підтримує студія **[CatCode](https://catcode.com.ua)**.

- 📄 Сторінка модуля з документацією та ліцензією: **https://catcode.com.ua/modules/opencart-keycrm-sync/**
- 🧩 Усі наші модулі для OpenCart та WooCommerce: https://catcode.com.ua/modules/
- ✉️ Підтримка: catcode.info@gmail.com
