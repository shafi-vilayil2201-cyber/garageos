# GarageOS — Project Documentation

*A workshop & garage management system built on RetailOS's local-first architecture: install it on the shop's own PC, run the business offline, sync to the cloud automatically, and hand every client a landing page that looks like nobody else's.*

`v0.1 — draft` · `2026-09-10` · `Built on the RetailOS core` · Author: shafivilayil2201@gmail.com

---

## 1. Vision & positioning

Platforms like `autorox.ai` prove garages will pay for software — but they ship enterprise-grade complexity (multi-module configuration, long onboarding, dedicated training) at a small workshop that just wants to open a job card, order a part, and text the customer when the car's ready. GarageOS competes on a different axis: **time-to-value**, not feature count.

- **Fast to learn** — a service advisor should be creating job cards within 10 minutes of first login, no manual required.
- **Fast to deploy** — because it reuses RetailOS's proven core (auth, roles, org/branch model, sync), a new client is mostly configuration, not code.
- **Reliable by construction** — local-first means the shop floor keeps working through a power cut or a dead internet line; the cloud catches up when it can.
- **Looks bespoke, runs standard** — every client gets a custom-designed landing page (§10), but the software behind the login screen is identical from client to client.

> **Why this matters commercially:** The sales pitch and the engineering plan are the same document. "Custom landing page + proven core" is simultaneously the thing that closes the deal (it doesn't feel generic) and the thing that keeps margins healthy (the core never changes per client).

---

## 2. Design principles

1. **Reuse over rebuild** — auth, sessions, RBAC, organizations/branches, and the sync scaffold are ported from RetailOS, not rewritten.
2. **Local-first, cloud-synced** — the shop's own PC is the source of truth for its own data. The cloud is a mirror + remote window, never a single point of failure.
3. **Config over code** — branding, service catalog, tax rules, and working hours are rows in a database, never a per-client code branch.
4. **One screen, one job** — every role gets a short, task-shaped screen list. No nested settings mazes, no Autorox-style module sprawl.

---

## 3. Domain glossary

| Term | Meaning |
|---|---|
| **Organization** | The workshop business — a single garage or a small chain. |
| **Branch** | One physical service center belonging to an organization. |
| **Customer** | A vehicle owner. |
| **Vehicle** | A customer's car/bike — make, model, year, plate, VIN. |
| **Job Card** | The central document: one service visit, from intake to delivery. |
| **Service** | A catalog item of billable labor (e.g. "Oil change") with a standard price and duration. |
| **Part** | An inventory item consumed on a job card. |
| **Technician** | Staff member who performs the work on a job card. |
| **Estimate** | A quote sent to the customer before work is approved. |
| **Invoice** | The final bill generated when a job card is closed. |
| **Payment** | Money received against an invoice. |
| **Appointment** | A booked future visit, walk-in or from the landing page. |
| **Reminder** | An automated nudge — service due, insurance/PUC expiry, follow-up. |
| **Inspection** | A checklist + photos capturing vehicle condition at intake and handover. |
| **Sync Outbox** | The queue of local events waiting to reach the cloud. |

---

## 4. Core business flows

**Service flow — the job card lifecycle**

```
Customer + Vehicle → Job Card (received)
→ Inspection (checklist + photos)
→ Estimate → Customer approval
→ Work in progress (technician assigned)
→ Parts consumed → Stock Movement (−)
→ Quality check → Invoice → Payment
→ Delivered / Closed → Sync Outbox
```

**Reminder flow**

```
Job Card closed → next-service date computed
→ Reminder scheduled → SMS/WhatsApp sent
→ (optional) Appointment booked
```

**Purchase flow — reused from RetailOS unchanged**

```
Supplier → Purchase → Purchase Items
→ Stock Movement (+) → Payment → Sync Outbox
```

---

## 5. Reused vs. new

**Reused as-is**
- organizations, branches
- users, roles, permissions, role_permissions, user_roles
- sessions (cookie-token auth)
- suppliers, purchases, purchase_items
- inventory_movements, stock_transfers
- payments (shape reused for invoices)
- sync outbox pattern

**New for GarageOS**
- vehicles, vehicle history
- job_cards, job_card_items, job_card_parts
- services, service_categories
- technician_profiles
- estimates, estimate_items, invoices, invoice_items
- appointments, reminders
- inspections, inspection_items, attachments
- notifications_log

> **Free reuse:** A "part" is a product. RetailOS's `products`, `categories`, and `inventory` tables can be reused with zero schema changes — a spark plug and a bag of rice are the same row shape. Same for `customers`: a vehicle owner is a customer with vehicles attached.

---

## 6. Database schema — new tables

All tables carry `organization_id`, `branch_id`, `created_at`/`updated_at` the same way every RetailOS table does — omitted below for brevity.

**vehicles** — customer's vehicles
| Column | Type | Notes |
|---|---|---|
| customer_id | bigint | FK → customers |
| registration_no | varchar(20) | unique per organization |
| make / model / variant | varchar | |
| year | int | |
| vin | varchar(32) | nullable |
| color | varchar(30) | |
| odometer_km | int | last known reading |
| fuel_type | varchar(20) | petrol / diesel / ev / hybrid |
| insurance_expiry, puc_expiry | date | drive reminders |

**job_cards** — the work order
| Column | Type | Notes |
|---|---|---|
| job_no | varchar(20) | human-readable, unique per branch |
| vehicle_id / customer_id | bigint | FK |
| status | varchar(20) | received / in_progress / quality_check / ready / delivered / on_hold / cancelled |
| odometer_in / odometer_out | int | |
| promised_at | timestamp | ETA shown to customer |
| advisor_id / primary_technician_id | bigint | FK → users |
| customer_complaint | text | reported by customer at intake |
| closed_at | timestamp | nullable |

**job_card_items** — labor lines
| Column | Type | Notes |
|---|---|---|
| job_card_id / service_id | bigint | FK |
| technician_id | bigint | who performed this line |
| price / discount | numeric(12,2) | copied from catalog, editable |
| status | varchar(20) | pending / done |

**job_card_parts** — parts consumed
| Column | Type | Notes |
|---|---|---|
| job_card_id / product_id | bigint | product_id → reused products table |
| quantity | numeric(10,2) | |
| unit_price | numeric(12,2) | snapshot at time of use |

**services / service_categories** — the labor catalog
| Column | Type | Notes |
|---|---|---|
| service_category_id | bigint | e.g. "Periodic maintenance", "Electrical" |
| name | varchar(150) | e.g. "Oil & filter change" |
| standard_price | numeric(12,2) | |
| estimated_minutes | int | drives scheduling & promised_at |
| applies_to_vehicle_type | varchar(20) | car / bike / commercial / all |

**estimates & invoices** — money documents
| Column | Type | Notes |
|---|---|---|
| job_card_id | bigint | FK |
| status | varchar(20) | draft/sent/approved/rejected (estimate) · unpaid/partial/paid (invoice) |
| subtotal, tax_amount, total | numeric(12,2) | |
| approved_via | varchar(20) | otp / signature / in_person — estimate only |
| invoice_no | varchar(20) | sequential, unique per branch — invoice only |

**appointments & reminders** — the calendar
| Column | Type | Notes |
|---|---|---|
| vehicle_id / customer_id | bigint | FK |
| scheduled_at | timestamp | appointment only |
| source | varchar(20) | landing_page / phone / walk_in |
| due_type | varchar(30) | service_due / insurance_expiry / puc_expiry / follow_up — reminder only |
| due_date, sent_at, channel | date/timestamp/varchar | sms / whatsapp / email — reminder only |

**inspections, inspection_items, attachments** — condition & proof
| Column | Type | Notes |
|---|---|---|
| job_card_id | bigint | FK |
| stage | varchar(10) | intake / handover |
| checklist_item, result | varchar | e.g. "Tyre tread" → ok / worn / replace |
| file_path, file_type | varchar | attachments: photo/video, synced to cloud storage |

---

## 7. Roles & permissions

Same RBAC engine as RetailOS (`roles` → `role_permissions` → `permissions`, users can hold more than one role). Only the seeded roles and permission codes are garage-specific.

| Role | Job cards | Estimates / invoices | Parts & purchases | Reports & settings |
|---|---|---|---|---|
| **Owner** | Full | Full | Full | Full |
| **Manager** | Full | Full | Full | View + edit (no org/license) |
| **Service Advisor** | Create / edit | Create / send | View only | None |
| **Technician** | Own assigned only, status + notes + photos | None (no pricing visibility) | Request only | None |
| **Accountant** | View only | Full | View only | Financial reports |
| **Parts Manager** | View only | None | Full | Stock reports |

---

## 8. Modules & screens

| Module | Screens | Phase |
|---|---|---|
| Auth & RBAC | Login, session, role-gated navigation | MVP |
| Dashboard | Today's job cards, revenue, technician load, low-stock parts, due appointments | MVP |
| Customers & Vehicles | List/search, profile, vehicle history timeline | MVP |
| Job Cards | Kanban by status, intake form, inspection + photos, technician assignment | MVP |
| Estimates & Invoices | Build from job card, PDF/print, WhatsApp/email share, payment capture | MVP |
| Parts & Inventory | Stock levels, reorder point, purchases, stock movement log | Phase 2 |
| Appointments & Reminders | Booking calendar, automated service/expiry reminders | Phase 2 |
| Reports | Revenue, technician productivity, parts usage, retention | Phase 2 |
| Technician mobile view | Assigned jobs, status update, notes/photos — phone-first layout | Phase 3 |
| Customer portal | Live job status, approve estimate, view invoice, book next visit | Phase 3 |
| Settings | Branding, service catalog, tax, working hours, notification templates | MVP |

---

## 9. Local + cloud sync architecture

Identical shape to RetailOS: the branch's own PC is authoritative for its own writes. A background worker drains the sync outbox to the cloud whenever a connection exists. Nothing on the shop floor ever blocks on the internet.

```
┌─────────────────────────────┐          ┌─────────────────────────────┐
│  CLIENT PC (on-premise)      │  syncs   │  CLOUD                       │
│                               │  when    │                              │
│  Local App ──writes──► Sync   │  online  │   Cloud API ──► Cloud DB     │
│  (PHP+Postgres)   Outbox ─────┼─────────►│      ▲                       │
│       ▲                       │◄─────────┼──────┘ pulls updates back    │
│       │                       │          │      │                       │
│  Technician tablets (LAN,     │          │  Mobile / remote device      │
│  no internet needed)          │          │  (owner checking in          │
│                               │          │   from anywhere)             │
└─────────────────────────────┘          └─────────────────────────────┘
```

LAN devices (technician tablets) never depend on internet; mobile/remote access goes through the cloud API.

---

## 10. Two-layer delivery model

This is the commercial heart of the plan. Every client gets a landing page nobody else has — that's what wins the deal against a generic-feeling competitor. Behind its "Book a service" / "Client login" button sits the exact same dashboard every other client runs — that's what keeps delivery fast and support sane.

```
┌───────────────────────┐   "Client Login"   ┌─────────────────────────────┐
│   LANDING PAGE          │ ──────────────►   │   CORE APPLICATION           │
│   unique per client     │                    │   identical for every client │
│                         │                    │                              │
│   Branding · services   │                    │   Dashboard · Job cards      │
│   Booking widget        │                    │   Inventory · Invoicing      │
│   Testimonials          │                    │   RBAC · Sync                │
│                         │                    │                              │
│   CUSTOM                │                    │   REUSED                     │
│   — closes the deal     │                    │   — keeps delivery fast      │
└───────────────────────┘                    └─────────────────────────────┘
```

> **Rule of thumb:** If a client asks for something that only affects the landing page (colors, copy, photos, a booking form field), say yes freely — it's isolated. If they ask for something that changes a core screen's behavior, push it into `Settings` as a configuration option available to *every* client, or decline it. The moment one client gets a forked core screen, delivery speed for every future client drops.

---

## 11. One codebase, many clients

Per-client difference should live entirely in data and a small config file, never in a code path:

| Varies per client | Where it lives |
|---|---|
| Business name, logo, colors, contact details | `organizations` row + asset files |
| Service catalog & pricing | `services`/`service_categories` rows, seeded per org at onboarding |
| Tax rate, currency, working hours | `organizations` config columns |
| Notification templates (SMS/WhatsApp wording) | `notification_templates` table |
| Landing page design & copy | A separate, per-client static site/repo — not part of the core app |
| DB credentials, license key, org slug | One local `.env` file per install |

Onboarding a new client is: run one script that inserts an organization + branch + admin user + seeded service catalog, install the (unmodified) app binary/package on their PC, and point their landing page's login button at their subdomain. No branch of the core repo is ever created per client.

---

## 12. ID & sync strategy

> **Architecture note — worth deciding before this scales:** RetailOS's tables use `BIGSERIAL` primary keys, which is fine as long as each organization's data lives in exactly one local database that never merges with another. The moment a record created offline needs a globally-unique ID before it reaches the cloud (multi-branch orgs, or any future peer-to-peer sync), auto-incrementing integers collide.
>
> **Recommendation:** generate primary keys client-side as **ULIDs** (sortable, 26-char, no coordination needed) for GarageOS's new tables instead of relying on the database to assign them. Keep `BIGSERIAL` only for tables that will never leave a single node (e.g. local-only cache/log tables). This is a one-time decision — retrofitting IDs after data exists is painful, so make the call at schema-design time, not after the first client goes live.

Conflict handling can stay simple for v1: each branch is authoritative for its own rows (scoped by `organization_id` + `branch_id`), so two branches never write the same record. Within a branch, last-write-wins on `updated_at` is sufficient — true concurrent-edit resolution isn't a real risk with one local server per branch.

---

## 13. API surface

Following the existing `public/api/*.php` convention already in RetailOS. Session-cookie auth for the local LAN app; a separate signed API key per branch for the cloud sync worker and the customer portal.

| Endpoint | Used by | Notes |
|---|---|---|
| `GET /api/job-cards` | Dashboard, technician view | filter by status, technician, date |
| `POST /api/job-cards` | Intake screen | creates job card + inspection shell |
| `PATCH /api/job-cards/{id}/status` | Technician mobile view | status transition, RBAC-checked |
| `GET /api/vehicles/search` | Intake screen | by plate number or customer phone |
| `POST /api/estimates/{id}/send` | Advisor screen | dispatches WhatsApp/SMS/email |
| `POST /api/sync/push` | Local sync worker | drains the sync outbox to the cloud |
| `GET /api/sync/pull` | Local sync worker | pulls cloud-side changes (e.g. remote-created appointments) |
| `GET /api/portal/job-cards/{token}` | Customer portal | token-scoped, read-only, no login required |

---

## 14. Client-PC deployment

- **Packaging** — bundle PHP + PostgreSQL + the app into a single installer (Windows/macOS) so the client never sees a terminal. A local service auto-starts the app on boot and serves it at `http://garage.local` on the shop's LAN.
- **Updates** — the app checks a cloud version manifest on a schedule; new versions download in the background and apply on a one-click restart from `Settings → Updates`. No client ever touches a file manually.
- **Backups** — nightly local DB dump, encrypted, uploaded to cloud storage. Restore is a single "Restore from cloud" action during install, used for both disaster recovery and moving to new hardware.
- **Licensing** — the `organizations` row carries a license key validated against the cloud on first run and periodically after. Soft-lock only: a lapsed check doesn't stop the shop floor mid-shift, it just stops working after a grace period, so a bad internet day never blocks a live customer handover.

---

## 15. Reliability checklist

- [ ] Every screen the service advisor and technician touch must work with zero internet.
- [ ] Sync is idempotent — replaying an outbox event twice must never double-charge a payment or double-deduct stock.
- [ ] Financial fields (prices, totals, payments) are validated server-side, never trusted from the client alone.
- [ ] The sync worker logs every failed push with enough context to retry or manually reconcile — silent sync failures are the #1 trust-killer for this kind of product.
- [ ] Daily automated backup, tested restore path documented and rehearsed before the first client goes live.

---

## 16. Security checklist

- [ ] Passwords hashed with `password_hash()` (bcrypt) — already the RetailOS pattern, keep it.
- [ ] Session cookie: `httponly`, `samesite=Lax`, and `secure=true` once the local app is served over HTTPS (self-signed cert for LAN is enough).
- [ ] RBAC enforced at the route/handler level, not just hidden in the UI — a technician's API calls must be rejected server-side, not just hidden buttons.
- [ ] Audit log for financial edits (invoice changes, discounts, refunds) and role/permission changes.
- [ ] Per-branch data isolation enforced by `organization_id`/`branch_id` scoping on every query, never trusted from client input.
- [ ] Login rate-limiting to blunt credential-stuffing against the local app's LAN-exposed login page.

---

## 17. Client onboarding playbook

The whole point of the shared-core strategy is that this list gets shorter every quarter, not longer.

| Step | Owner | Typical time |
|---|---|---|
| Demo with seeded sample data (no client data needed) | Sales | 30 min |
| Collect branding, service list & pricing, logo, working hours | Sales | 1 form, 1 call |
| Run onboarding script → org, branch, admin user, seeded catalog | Ops | < 10 min |
| Install app on client PC (or provision a cloud-only trial) | Ops | < 1 hour |
| Build the client's custom landing page from the brand kit | Design | 2–4 days |
| Staff training walkthrough (job card → invoice → payment) | Ops | 1 hour |
| **Go live** | — | **~1 week total** |

---

## 18. Build roadmap

| Phase | Scope | Estimate |
|---|---|---|
| 0 — Fork the core | Port auth, RBAC, org/branch, sessions, sync scaffold from RetailOS | 2–3 days |
| 1 — MVP | Customers, vehicles, job cards, service catalog, invoices/payments, dashboard | 2–3 weeks |
| 2 — Operations | Parts/inventory (mostly reused), purchases, appointments, reminders | 1–2 weeks |
| 3 — Sync & mobile | Cloud API, sync worker, technician mobile view | 1–2 weeks |
| 4 — Delivery tooling | Landing page template system, onboarding script, license/update mechanism | 1–2 weeks |
| 5 — Growth features | Customer portal, WhatsApp/SMS notifications, reports | ongoing |

---

## 19. Suggested repo structure

```
garageos/
├── app/                  domain classes (Auth, JobCards, Invoicing, Sync…)
├── config/               database.php, per-install .env
├── database/
│   ├── migrations/       numbered raw SQL, same convention as RetailOS
│   └── seeders/          onboarding seed scripts (per-client catalog)
├── public/               document root — one file per page
│   ├── dashboard.php
│   ├── job-cards.php
│   ├── api/
│   │   ├── job-cards/
│   │   └── sync/
│   └── css/ js/
├── sync/                 cloud sync worker (cron or long-running process)
├── docs/                 this document + business-model.md, database-design.md
└── clients/              one folder per client's landing page (separate, static)
    ├── acme-motors/
    └── city-garage/
```

---

## 20. Open decisions

- **Product name** — "GarageOS" used throughout this document as a placeholder, parallel to RetailOS.
- **Cloud hosting** — where the Cloud API + Cloud DB live (matters for the sync worker's design and cost per client).
- **Notification provider** — WhatsApp Business API vs. a cheaper SMS gateway for reminders/estimates.
- **Installer tooling** — how PHP + PostgreSQL get bundled for a one-click Windows/macOS install.
- **Shared core vs. separate repo** — whether GarageOS and RetailOS should literally share an `app/` layer (auth, RBAC, sync) via a common package, or stay two repos that are kept in sync by convention.

---

*Draft documentation — reflects the RetailOS codebase as of commit `68284e5`. Numbers in the roadmap and onboarding sections are estimates, not commitments.*
