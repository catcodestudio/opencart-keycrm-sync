# CatCode KeyCRM Sync (OpenCart 4)

Order synchronization from OpenCart 4.x to **KeyCRM**.

## Features
- Pushes orders to KeyCRM on placement or on a configurable status change.
- Idempotent — `UNIQUE(order_id, target)` guarantees no duplicate orders even if the event re-fires.
- Retry queue via cron (`extension/cc_crm/cron.retry`) for failed pushes, with an attempt cap.
- Order mapper: skips zero-price lines, normalizes phone to `+380…`, maps shipping/payment.
- Auto-creates the KeyCRM source on the first order (sends `source_name` when no `source_id` is set).
- Encrypted API key at rest (sodium / HMAC fallback).
- Sync journal with per-order status and request/response excerpts.

## Install
Admin → Extensions → Installer → upload `cc_keycrm_sync.ocmod.zip`, then Extensions → Modules → install **CatCode KeyCRM Sync**. Enter the KeyCRM API key (Settings → General → API key in KeyCRM) and enable the target.

## Structure
Repository root is the extension payload (`admin/`, `catalog/`, `system/`, `install.json`). Zip these into `cc_keycrm_sync.ocmod.zip` for the OpenCart installer.

---
© CatCode — https://catcode.com.ua
