# asaas-notazz

**Automatic Brazilian invoice issuing (NF-e and NFS-e) from Asaas payments, through Notazz.** When a payment clears in [Asaas](https://www.asaas.com), this system queues the invoices and issues them in [Notazz](https://notazz.com) on its own — with retries, rate limiting, multiple companies and an admin panel where the finance team follows every invoice.

> 🇧🇷 Emissão automática de NF-e e NFS-e a partir dos pagamentos do Asaas, via Notazz: webhook → fila → emissão com novas tentativas, multiempresa, painel do financeiro e acompanhamento de inadimplência. PHP puro, feito para hospedagem compartilhada.

| | |
|---|---|
| **Status** | In production, maintained |
| **Usage** | 10–100 invoices a month, depending on the month, across multiple companies |
| **Why not the native integration** | Asaas' built-in Notazz integration couldn't keep a product catalog or split one sale into NF-e + NFS-e |
| **Build time** | ~1 week to production (traditional estimate: 6–8 weeks), with occasional fixes since |

## Why

Asaas has a native Notazz integration, but it couldn't do what the business needed: keep a product catalog, route products to different companies, or split one sale into an NF-e and an NFS-e. So every paid sale needed invoices typed by hand, cross-referencing the customer and the product between the two platforms. Mistakes and delays were common, and installment card payments made it worse (one invoice per installment, in the right month).

## How it works

```
Asaas ──webhook──▶ webhook-asaas.php ──▶ notazz_documents (pending)
                    log raw payload        │
                    check token            ▼ cron, every minute
                    reply 200 fast    EmissionQueue ──▶ Notazz API ──▶ NF-e / NFS-e
                                            │ retries with backoff
                                            ▼
                                     admin panel (sales, invoices, errors, review)
```

- **The webhook never calls Notazz.** It stores the raw payload, validates the `asaas-access-token` header, enqueues the documents and answers `200` immediately — so Asaas never times out and nothing is lost if Notazz is down.
- **Queue with backoff:** pending documents are processed in batches by cron, with retries spaced from 1 minute up to 6 hours, and requests paced to stay under Notazz's rate limit.
- **Product matching:** the payment description is matched against product keywords (longest keyword wins; ties are logged as ambiguous), and only active products count — so routing a product to another company is just activating/deactivating it.
- **Two invoices, exact split:** when a sale generates both an NF-e and an NFS-e, the value is split by configuration and the NFS-e is computed as the complement, so the sum is always exactly the amount paid.
- **Installments done right:** credit-card installments are confirmed by Asaas on day one, so each installment's invoice is **scheduled for its due date** — one invoice per installment, in its month.
- **Multi-company:** each product belongs to a company with its own Notazz key, CNPJ and tax setup (NFS-e tax overrides per company and per fiscal group).
- **Customer data validation** before issuing (person/company, address fallback), so Notazz rejections become visible review items instead of silent failures.

## Admin panel

Dashboard with monthly goal, sales, issued / pending / failed invoices with detail pages, a **review** queue for the finance team, companies, products and keywords, webhook logs, and an **overdue payments** board (synced from Asaas and Eduzz, with recovered/renegotiated tracking and contact notes). Login with sessions, CSRF protection and per-user access.

## Operations

| Script | What it does |
|---|---|
| `cron/process-queue.php` | Every minute: issues pending invoices |
| `cron/sync-inadimplentes.php` | A few times a day: syncs overdue payments per company |
| `cron/auditoria-mes.php [YYYY-MM] [--enviar]` | Monthly audit: cross-checks Asaas sales against the database, per company; optionally re-injects anything that never arrived |
| `cron/reprocess-discarded.php` | Replays discarded webhooks through the current endpoint logic (idempotent) |
| `cron/cancela-duplicadas.php` | Finds invoices issued twice and cancels/deletes them safely, checking each one's state in Notazz first |

## Setup

1. Create a MySQL database and import `database/schema.sql`, then the `migrate-*.sql` files in order.
2. Copy `config.example.php` to `config.php` and fill in the database, Asaas token, webhook token and Notazz key.
3. Create the first admin: `php database/create-admin.php "Name" "admin@example.com" "strong-password"`.
4. Point the Asaas webhook to `/webhook-asaas.php` and add the cron entries above.

`.htaccess` files keep `app/`, `cron/`, `storage/` and `config.php` out of reach of the web, so it runs on plain shared hosting.

## Stack

PHP 8 (no framework) · MySQL · Asaas API · Notazz API · Eduzz API · cron · Chart.js

## How it was built

Built with AI coding agents (Claude Code and OpenAI Codex) writing the code. My part was understanding the finance team's process and the fiscal rules, specifying the behaviour, reviewing the generated code, testing against real payments and running it in production. Traditional estimates are my own ballpark for one developer writing it by hand.

---

Built by [João Barbosa](https://joaobarbosa.pages.dev) at his employer and published here **with the employer's permission**. Companies, CNPJs, products, Notazz IDs, business figures, domains and all customer data were replaced with fictional ones; one-off diagnostic and data-fix scripts were left out.

**© João Barbosa. All rights reserved.** No open-source license is granted — you're welcome to read the code, but please don't reuse it without permission.
