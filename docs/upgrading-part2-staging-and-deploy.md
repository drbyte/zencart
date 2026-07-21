# Staging and Deployment Guide for Zen Cart Upgrades

This document is written for AI agents and developers assisting with a Zen Cart
upgrade. It covers staging, data handling, environment configuration, production
cutover, and owner sign-off.

Use this alongside `upgrading-part1-agent-guide.md`. The upgrade guide explains
how to analyze and migrate customized code. This guide explains how to handle the
operational parts without exposing customer data, losing orders, sending
accidental emails, or deploying an unsafe staging copy.

## Core Principles

- Never perform the first upgrade attempt on the live production store.
- Never expose staging stores, database dumps, backups, or old-store copies to
  the public web.
- Treat copied store files and database dumps as sensitive production data.
- Disable or isolate anything in staging that can contact customers, charge
  payments, notify third parties, or receive live callbacks.
- Rehearse the upgrade on staging, then repeat the documented steps against a
  fresh production backup during cutover.
- Do not deploy until the store owner or responsible developer has accepted the
  remaining risks and approved cutover.

## Sensitive Data Handling

A Zen Cart file archive or database dump may contain:

- customer names, addresses, emails, phone numbers, and order history
- password hashes
- admin usernames and password hashes
- payment, shipping, tax, and order-total configuration
- payment gateway API keys or tokens
- SMTP credentials
- database credentials in `includes/configure.php`
- plugin credentials and API keys
- webhooks, feeds, export files, and generated reports
- downloadable product files and uploaded customer files

Agents should:

- keep working copies local to the approved workspace or staging host
- avoid uploading archives or database dumps to unrelated third-party services
- avoid pasting credentials, API keys, password hashes, or customer data into
  reports unless explicitly required and approved
- redact secrets and customer data in notes, screenshots, and final reports
- delete temporary extracted archives, SQL dumps, and generated analysis files
  when they are no longer needed
- document where sensitive temporary files were created so the owner can remove
  them

If a database dump is too large for practical analysis, ask for a schema-only
dump plus targeted configuration tables before asking for a full data dump.

## Staging Site Isolation

The staging site must not behave like the live store.

Before opening staging to browser testing:

- use a non-production hostname or protected subdirectory
- require HTTP authentication, VPN access, IP allowlisting, or equivalent access
  control
- block search engine indexing with server rules and a `robots.txt` rule
- ensure staging does not share a database with production
- ensure staging does not write to production image, download, cache, log, or
  upload directories
- confirm staging does not use production cron jobs
- confirm staging does not receive live payment, shipping, marketplace, or ERP
  callbacks

Do not rely only on `robots.txt` for privacy. Use access control that prevents
unauthorized visitors from reaching copied customer and order data.

## Emails, Payments, and External Services

Before functional testing in staging, neutralize external side effects.

Review and adjust:

- email transport and SMTP settings
- order confirmation emails
- contact-us emails
- admin notification emails
- newsletter, back-in-stock, coupon, review, and status-update emails
- payment module credentials and transaction mode
- shipping carrier credentials
- tax provider credentials
- webhook URLs
- analytics, ads, tags, pixels, and conversion tracking
- marketplace, accounting, ERP, fulfillment, CRM, and inventory integrations
- scheduled jobs and server cron tasks

Preferred staging behavior:

- route email to a local catcher or test mailbox
- use payment sandbox credentials where available
- disable payment modules that cannot be safely tested
- disable live fulfillment, accounting, and inventory exports
- disable analytics and ad pixels or point them to non-production properties
- prevent staging webhooks from being registered with live providers

Actual payment authorization, capture, refund, and settlement behavior must be
verified by the store owner or responsible developer using the merchant's
accepted test procedure.

## Assets and Downloadable Files

Code analysis can often omit large runtime asset directories, but production
deployment cannot.

Inventory and plan migration for:

- `images/`
- `download/`
- `media/`
- `pub/`
- uploaded files used by custom plugins
- downloadable product files
- generated product images and image caches
- custom email logos and template images
- PDF, CSV, feed, import, and export directories
- any storage paths configured by plugins or custom code

When possible, keep large assets out of repeated code-diff work and sync them
separately near cutover. Verify file ownership and permissions after syncing.

Do not leave downloadable product files, customer uploads, database exports, or
backup archives in publicly browsable directories.

## Configure Files and Environment Settings

Do not blindly copy old `configure.php` files into the upgraded staging or
production store.

Create or edit these files deliberately for the environment:

- `includes/configure.php`
- `admin/includes/configure.php`, when required by the source or target version

Check:

- database host, database name, username, and password
- `DB_PREFIX`, preserving the old store's value exactly
- filesystem paths
- HTTP and HTTPS catalog URLs
- admin HTTPS settings
- session and cookie domain behavior
- cache, log, upload, download, and image paths
- renamed admin directory paths

Search custom code, templates, language files, and plugin configuration for:

- hardcoded production domains
- hardcoded staging domains
- old HTTP URLs that should be HTTPS
- absolute filesystem paths
- old hosting-account paths
- CDN URLs
- renamed admin paths
- webhook callback URLs
- cron command paths

After deployment, set appropriate permissions for configure files and writable
directories. `cache/`, `logs/`, image-upload paths, download paths, and plugin
storage paths may need write access, while configure files should not remain
world-writable.

## Staging Database Workflow

For staging:

1. Create a new temporary database.
2. Import a copy of the live production database.
3. Point staging `configure.php` files to the temporary database.
4. Preserve `DB_PREFIX` exactly.
5. Run the Zen Cart database upgrade with `zc_install`.
6. Inspect `project_version`, `project_version_history`, Zen Cart logs, and PHP
   error logs.
7. Run plugin and module upgrade/configuration steps.
8. Repeat the acceptance checklist from `upgrading-part1-agent-guide.md`.

Never choose a clean install against a copied production database.

Do not manually modify production data to "match staging" unless the change is
part of a reviewed, repeatable cutover step with rollback.

## Production Cutover

The staging database is only a rehearsal. Production data changes while staging
is being tested.

During production cutover:

1. Announce and schedule the maintenance window.
2. Put the live store into maintenance mode.
3. Stop or pause cron jobs and external integrations that can write orders,
   inventory, exports, or customer updates.
4. Take a fresh production file backup.
5. Take a fresh production database backup after maintenance mode is enabled.
6. Verify the backup can be accessed for rollback.
7. Deploy the upgraded files.
8. Sync required runtime assets.
9. Confirm production `configure.php` values, `DB_PREFIX`, paths, and HTTPS
   settings.
10. Run `zc_install` database upgrade if production has not already been
    upgraded.
11. Run required plugin and module upgrade steps.
12. Verify storefront, admin, logs, and checkout according to the acceptance
    checklist.
13. Remove or protect installer files according to Zen Cart instructions.
14. Remove or block public access to old-store copies, upgrade directories, SQL
    dumps, ZIP files, and backup archives.
15. Resume cron jobs and integrations only after the upgraded store is verified.
16. Take the store out of maintenance mode.

If cutover fails, restore both files and database from the matching fresh
production backups. Do not restore only one side unless the impact is fully
understood.

## Post-Deployment Monitoring

After the store is live, monitor:

- Zen Cart logs
- PHP and web server error logs
- order creation
- payment gateway records
- shipping quotes and labels
- tax calculations
- email delivery
- admin login and order-management workflows
- scheduled jobs
- third-party integration queues
- customer reports from the first live orders

Keep the maintenance window notes and staging notes until the store has completed
normal live order processing.

## Owner Sign-Off

Before production deployment, record approval for:

- visual/template acceptance
- desktop and mobile layout acceptance
- checkout flow acceptance
- payment testing or payment-testing deferral
- shipping quote behavior
- tax behavior
- email behavior
- active plugins and removed plugins
- known unresolved risks
- production cutover timing
- rollback plan

Suggested sign-off format:

```text
Upgrade Staging and Deployment Sign-Off

Store:
Source version:
Target version:
Staging URL:
Production cutover window:

Owner/developer approvals:
- Visual/template review:
- Desktop/mobile review:
- Storefront workflow review:
- Admin workflow review:
- Checkout review:
- Payment testing:
- Shipping/tax review:
- Email behavior:
- Plugin changes:
- Known risks:
- Rollback plan:

Approved for production cutover by:
Date:
```
