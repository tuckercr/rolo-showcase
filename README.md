# Rolo

[![CI](https://github.com/tuckercr/rolo-showcase/actions/workflows/ci.yml/badge.svg)](https://github.com/tuckercr/rolo-showcase/actions/workflows/ci.yml)

Rolo is a small CRM with one job: never let a relationship go cold. Contacts
take 30 seconds to capture, every interaction gets logged, and a cadence
engine decides who you owe a follow-up. It's also an **MCP server** (Model
Context Protocol): AI assistants like Claude and Superhuman plug straight
into it, and every change they propose waits for human approval.

Plain PHP 8.4, MariaDB, and server-rendered HTML. No build step, no SPA,
nothing to babysit. Deploys are a file copy to shared hosting. It replaced a
Coda spreadsheet at a real two-person consultancy and has run their pipeline
in production since July 2026. Lighthouse (desktop) scores 96-100 for
performance, 100 for accessibility, and 100 for best practices across the
login page, dashboard, and contact pages.

> **Note:** this is a public snapshot of a private production repo. Internal
> docs, deploy scripts, and history are stripped, and the seed data is
> genericized. The real thing runs in production every day.
>
> If Rolo looks useful to you, take it: it's Apache-2.0 and you're welcome
> to the code. And if you'd like help adapting it, or want something like
> it built for you, email me at [colin@fangjet.com](mailto:colin@fangjet.com)
> and I'd be happy to chat.

## Screenshots

**Approvals.** AI agents propose changes over MCP. A human sees a live
before/after diff and exactly what the change does to the follow-up schedule,
then approves or rejects. Agents never touch the CRM directly.

![Approvals](screenshots/approvals.png)

**Dashboard.** The Monday review: overdue, due this week, and a going-cold
section for anyone 30+ days past their follow-up or with nothing scheduled
at all. Each contact carries a note saying what actually needs doing, and
every row can log a touch or snooze inline without leaving the page.

![Dashboard](screenshots/dashboard.png)

**Daily email.** Every morning at 9 AM: due today first, then overdue, with
contact photos embedded as tiny inline attachments and initials for everyone
else.

![Daily email](screenshots/daily-email.png)

**Contact detail.** Profile photo (click, drop, or paste an image straight
onto the avatar), full history, cadence snooze, and a one-line "human
detail" so you remember the person, not just the pipeline stage.

![Contact detail](screenshots/contact-detail.png)

**Contacts.** Sortable, searchable, overdue flagged in red.

![Contacts](screenshots/contacts.png)

**Organizations.** Grouped view with per-org contact counts.

![Organizations](screenshots/organizations.png)

**Settings.** The cadence engine is data, not code: per-stage reminder rules
with one-click reset, and a checkbox per stage to keep it off the dashboard
and the daily email (Leads and Closed / Not a Fit by default).

![Settings](screenshots/settings.png)

## The MCP integration

This is the part worth reading the code for. The CRM doubles as a tool server
for AI agents, with one rule enforced in the API layer instead of in agent
instructions: **reads are live, writes never land directly.**

- **REST API** (`/api/v1/*`). Separate read-only and read-write bearer tokens
  compared in constant time, cursor pagination, machine-readable errors, a
  120 req/min rate limit per token, and an audit row for every request.
- **MCP endpoint** (`/mcp`). The same tools over streamable HTTP: 13 tools
  with JSON schemas, read tools marked `readOnlyHint`, and a protocol core
  that's a pure, unit-tested class. Clients that can't send an Authorization
  header (claude.ai custom connectors) pass the token as a URL secret, which
  the audit trail redacts.
- **Every agent write is a proposal.** Bad payloads bounce immediately with a
  plain-English reason. Retries dedupe on idempotency keys. The review page
  shows each change as a live before/after diff against current data, plus a
  consequence line ("reschedules the next follow-up to Oct 23") computed by
  the same rules engine that will apply it. Approvals are attributed to the
  human; the proposing token stays on the audit trail.
- **Agents can't wreck the schedule.** Agent-logged activities don't count as
  a personal touch unless explicitly flagged, so bulk campaign logging can't
  clear the follow-up queue. A backdated touch approved out of order goes
  into history without dragging the schedule backwards.

## What it does

- **Contacts and organizations.** Only a name is required to capture. A
  configurable pipeline (New/Captured through Active client), search,
  sortable columns, and a "human detail" field for the one memorable thing
  about each person.
- **Profile photos.** Click the avatar, drop a file on it, or paste an image
  from the clipboard. Server-side GD processing EXIF-rotates, center-crops,
  resizes to 400px and re-encodes, which also strips EXIF/GPS metadata;
  files live outside the web root and are served through a session-gated
  route. Contacts without a photo get a deterministic initials avatar, same
  color for the same name everywhere, including the email.
- **A follow-up rules engine.** Every logged interaction or stage change
  schedules the next touch from per-stage cadence rules stored in the
  database: fixed days, cadence from last touch, or business days. Rules are
  editable in a settings UI with one-click reset. An explicit "follow up by
  Saturday" always beats the cadence, and per-contact overrides beat the
  stage default. Each stage also has a visibility checkbox: hidden stages
  keep scheduling normally but stay off the dashboard and the daily email,
  so a bulk-imported Leads list never drowns out real follow-ups.
- **A dashboard that matches how the user actually works.** Overdue plus due
  this week, the real Monday review, and a going-cold section that catches
  what date-sorted lists miss: contacts 30+ days past their follow-up or
  with no follow-up scheduled at all. Logging and snoozing happen inline on
  the row, so clearing the morning list never means leaving the page.
  Overdue is always derived (`next_touch_date < today`), never stored, so it
  can't drift.
- **A daily summary email.** Every morning at 9 AM Eastern: due today first
  (hidden entirely when empty), then overdue, then what was logged
  yesterday, with tiny inline photo thumbnails (CID attachments, since the
  photo route is auth-gated and Gmail strips data URIs) and initials
  fallbacks. A Cloudflare Worker cron hits a token-guarded endpoint, with a
  GitHub Actions schedule as backup; the app enforces local send time and
  once-per-day semantics, so DST is handled, retries are safe, and the
  redundant trigger is free.
- **An interaction history built for real use.** Edit in place with "edited
  by" attribution, editable dates, delete, file attachments (allowlisted by
  extension AND sniffed content type, stored outside the web root under
  random names, download-only), snooze that leaves an audit entry, and
  trash-can soft deletion with restore.
- **Follow-up notes.** Check "Follow-up needed" and a "What needs doing?"
  field appears. The note follows the contact onto the dashboard and into the
  daily email until the follow-up is logged, then clears itself.
- **Tags.** Freeform, comma-separated, case-insensitively deduped, filterable
  everywhere, auto-pruned when orphaned.
- **Two-person collaboration.** `created_by` / `updated_by` trails are stored
  and shown ("added by Jessica, last updated by Colin"), so both users always
  know who touched what.

There's also a built-in Help page covering connector setup for
non-technical users: what the AI integration is, how to connect Claude and
Superhuman, example prompts, and troubleshooting, written so the private
connector link is treated like a password.

## Architecture

Hand-rolled MVC, one front controller. Small enough to read in an afternoon:

```
public/index.php     front controller: session, auth gate, FastRoute dispatch
config/              bootstrap (.env via phpdotenv, UTC discipline), route table
src/Controllers/     request handling, no SQL, no HTML; Api/ holds the
                     stateless bearer-token REST + MCP controllers
src/Models/          PDO data access, one class per entity
src/Services/        ReminderService (the rules engine), ProposalService
                     (validate/queue/apply agent writes), McpProtocol,
                     GoogleAuthService, DailySummaryService, AttachmentService
src/Views/           PHP templates only; escaping helper on all dynamic output
src/Support/         Config, Database, Session, Csrf, Auth, Mailer, Dates, Migrator
database/migrations/ ordered .sql files, forward-only, tracked in a table
tests/               PHPUnit, focused on the logic most likely to break
```

Engineering choices I'd defend:

- **Auth is Google Sign-In only**, with an email allowlist. Google proves
  identity, the allowlist decides authorization. No passwords exist anywhere
  in the system. ID-token claims (`iss`, `aud`, `exp`, `email_verified`) are
  validated in pure, unit-tested functions.
- **The cadence engine is data, not code.** Changing "follow up with
  proposals after 5 business days" is a settings-page edit, not a deploy. The
  engine itself is a pure class with rules injected as arrays, and the
  weekend math and precedence order are fully unit-tested.
- **UTC discipline.** Storage and `CURRENT_TIMESTAMP` are UTC (the PDO
  session forces `time_zone = '+00:00'`). "Today" for humans is always
  computed in the business's timezone, so an evening entry never lands on
  tomorrow's date.
- **Security basics done properly.** Prepared statements everywhere, zero SQL
  concatenation, CSRF tokens on every state-changing form, escaping on all
  dynamic output, httponly/secure/SameSite cookies, session ID regeneration
  at login, strict types in every file.
- **Soft deletes over hard deletes.** Trashing a contact hides it from every
  list, search, and reminder, but the full history stays restorable. There is
  deliberately no hard delete in the UI.
- **No-build deploys.** In the production repo `vendor/` is committed by
  design; deployment to shared hosting is a file mirror. A token-gated HTTP
  migration endpoint applies pending migrations on hosts where the database
  isn't reachable from outside.

## Stack

| Layer     | Choice                                              |
|-----------|-----------------------------------------------------|
| Backend   | PHP 8.4 (strict types), FastRoute, phpdotenv, PHPMailer |
| Database  | MariaDB 10.11 via PDO (native prepares)             |
| Frontend  | Server-rendered PHP views, Tailwind CSS via CDN     |
| Email     | SMTP (STARTTLS) or `mail()` fallback                |
| CI        | GitHub Actions: `php -l`, PHP_CodeSniffer (PSR-12), PHPUnit |
| Scheduler | Cloudflare Worker cron hitting a token-guarded endpoint (GitHub Actions as backup) |

## Running locally

```bash
git clone <repo> && cd rolo
composer install
cp .env.example .env                   # point DB_* at a local MariaDB
php bin/migrate.php                    # creates schema + seeds stages/cadence rules
php -S 127.0.0.1:8000 -t public public/index.php
```

With `APP_ENV=local` the login page offers one-click dev sign-in, no Google
credentials needed. Tests and linting:

```bash
vendor/bin/phpunit
vendor/bin/phpcs
```

## License

Apache-2.0. See [LICENSE](LICENSE).
