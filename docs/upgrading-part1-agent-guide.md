# Agent Guide for Upgrading Customized Zen Cart Stores

This document is written primarily for AI agents, and optionally developers, who are helping a
store owner upgrade an existing Zen Cart store. It is not a replacement for `zc_install` which is used for both initial installs and for database-upgrades between versions.

The goal is to upgrade safely by understanding the old store, preserving
intentional customizations, replacing obsolete code where appropriate, and
validating the result as a working ecommerce application.

For staging, sensitive-data handling, production cutover, and owner sign-off,
see `upgrading-part2-staging-and-deploy.md`. This Part 1 guide focuses on
analyzing and migrating customized Zen Cart code and database-upgrade workflow.

## Official References

Consult the official Zen Cart documentation while planning and performing an
upgrade:

- [Server Requirements](https://docs.zen-cart.com/user/first_steps/server_requirements/)
- [Upgrade Reference Guide](https://docs.zen-cart.com/user/upgrading/upgrading/)
- [Standard Upgrade - Recommended Procedure](https://docs.zen-cart.com/user/upgrading/detailed_upgrading/)
- [Release-Specific Upgrade Considerations](https://docs.zen-cart.com/user/upgrading/release_specific_upgrade_considerations/)
- [Updating your PHP Version](https://docs.zen-cart.com/user/upgrading/php_version/)
- [Date Standardization](https://docs.zen-cart.com/user/upgrading/date_standardization/)

Use the live documentation for version-specific server requirements. Do not
freeze PHP, MySQL, MariaDB, or extension requirements into this file unless the
current target branch requires a specific note.

Agents working from the Zen Cart GitHub repository may also consult the
branch-specific
[AGENTS.md](https://github.com/zencart/zencart/blob/2.3/AGENTS.md) file for
development workflow and architecture notes. `AGENTS.md` is not included in
release distributions, so this upgrade guide must remain usable without it.

## Core Principles

- Never upgrade a live production store in-place.
- Work from an offline copy or a staging environment.
- Preserve rollback backups before changing files or data.
- Prefer incremental upgrades across major Zen Cart release lines.
- Build the upgraded store from a clean target Zen Cart release, then migrate
  customizations into it deliberately.
- Do not blindly copy old customized core files over new core files.
- Do not treat static analysis, syntax checks, or a test suite as proof that a
  customized merchant site is safe.
- Validate the upgraded store by exercising real storefront, admin, and checkout
  workflows.
- Keep detailed notes during the test upgrade so the same steps can be repeated
  during production cutover.

## Store Owner Prerequisites

Ask the store owner for the following before beginning work:

- A complete archive of the current live site's files.
- A database dump for rollback and analysis.
- The current Zen Cart version, if known.
- The current PHP version.
- The current MySQL or MariaDB version.
- The renamed admin directory name, if the admin directory has been renamed.
- A list of known installed plugins, payment modules, shipping modules,
  order-total modules, and third-party templates.
- Screenshots of important storefront pages and admin configuration screens,
  especially where custom template work is expected.

The file archive used for code analysis may omit the site's `/images` directory
if it is very large, but the store owner should still keep a full production
backup that includes images and downloadable assets.

If a database dump is not available, continue only with work that does not depend
on store-specific schema or configuration data. The clean Zen Cart schema in
`zc_install/sql/install/mysql_zencart.sql` can help with core comparisons, but it
does not include store-specific plugin tables, custom data, or configuration.

## Server Environment Planning

Check the official server requirements for every source, intermediate, and
target Zen Cart version in the upgrade path.

Pay special attention to:

- PHP version
- MySQL or MariaDB version
- required PHP extensions
- PHP memory limit
- cURL and OpenSSL
- HTTPS/SSL configuration
- Apache `.htaccess` support or equivalent Nginx directory protections

The lower PHP versions in a supported range should be treated as temporary
upgrade bridges, not long-term targets. The goal is to run the newest PHP version
supported by the upgraded Zen Cart version and the required plugins.

Plugin PHP compatibility may be narrower than core Zen Cart compatibility. Check
each plugin's documentation, release notes, and support thread when available.

If the staging store needs a different PHP version than the live store, plan that
environment difference before running `zc_install` or testing checkout.

## Version Detection

When the store owner does not know the current Zen Cart version, inspect:

- `includes/version.php`
- the `project_version` database table
- the `project_version_history` database table

In `includes/version.php`, concatenate `PROJECT_VERSION_MAJOR` and
`PROJECT_VERSION_MINOR` with a `.` between them to determine the application
version.

If database upgrades were previously run through `zc_install`, the
`project_version` table should indicate the current database version, and
`project_version_history` should show completed or attempted database upgrades.

Treat mismatches between file version and database version as a risk that must be
understood before proceeding.

## Upgrade Path Strategy

Prefer incremental upgrades rather than jumping across many old releases at
once.

Zen Cart's `1.x` versions should be treated as major release lines even though
they use minor-looking version numbers. Letter suffixes such as `a`, `b`, `c`,
and `d` are the minor releases in that series.

Semantic versioning begins with Zen Cart v2.0.0. Larger jumps within the v2
series may be safer than large jumps across the older `1.x` series, but the
actual risk depends on the store's custom code, PHP compatibility, template
changes, and plugins.

For file upgrades, the main challenge is not the database schema. The main
challenge is understanding how far the PHP, template, language, and plugin files
have diverged between:

- the clean release that the old store was based on
- the customized old store
- the clean target release

Use those three sources for comparison whenever possible.

Before selecting an upgrade path, read the "What's New" notes and
release-specific upgrade considerations for every release being crossed.

## Baseline Sources

For each upgrade step, collect:

- the store owner's customized old files
- a clean copy of the matching old Zen Cart release
- a clean copy of the target Zen Cart release
- current official versions of plugins and templates where available

Use official Zen Cart release tags or archives as the baseline for clean core
files. Do not infer customizations by comparing only the old store to the new
release. That hides whether a change came from the store owner, a plugin, an old
bugfix, or normal upstream Zen Cart evolution.

## Inventory the Old Store

Before changing files, build an inventory:

- Zen Cart file version from `includes/version.php`
- database version from `project_version`, if a database dump is available
- active template from the `template_select` table, if available
- renamed admin directory
- installed plugin directories under `zc_plugins/`
- old-style dropped-in plugin files outside `zc_plugins/`
- customized template directories under `includes/templates/`
- customized language files
- custom admin pages
- custom catalog pages
- payment, shipping, and order-total modules
- extra database tables and configuration keys, where detectable
- payment or webhook listener customizations
- upload, download, import, export, API, and feed scripts
- PHP and database compatibility requirements for known plugins

When a database dump is available, identify active modules from these
`configuration` keys:

- `MODULE_PAYMENT_INSTALLED`
- `MODULE_SHIPPING_INSTALLED`
- `MODULE_ORDER_TOTAL_INSTALLED`

Ask permission before using credentials from `includes/configure.php` to query a
database directly. In offline work, those credentials may not work at all, so a
database dump or schema-only dump is usually more useful.

## Classify Customizations

Classify old-store differences before migrating them:

- Core file edits
- Template overrides
- Language overrides
- Encapsulated plugins under `zc_plugins/`
- Old dropped-in plugins that replaced or added core files
- Admin customizations
- Payment, shipping, and order-total modules
- Database-only customizations
- Server configuration such as `.htaccess` rules
- Generated files, cache files, logs, and backups that should not be migrated

For every changed core file, compare:

- clean old release file
- customized old store file
- clean target release file

Decide whether the old-store diff represents:

- a store-owner customization
- a third-party plugin file replacement
- a local bugfix
- an old security workaround
- an obsolete change now handled by core
- generated or accidental damage

Do not carry a customization forward until its purpose is understood.

## Build the Target Store

Start from a clean copy of the target Zen Cart release.

Then migrate into it:

- current compatible plugin versions
- required plugin-specific customizations
- template customizations
- language customizations
- custom pages and business logic
- required server configuration
- required uploaded assets and downloads

Prefer established Zen Cart extension points:

- plugin structure under `zc_plugins/`
- template overrides
- language overrides
- `extra_configures`
- `extra_datafiles`
- init includes
- observers and notifiers

Use `CONVENTIONS.md` as the baseline for new or actively modified custom code.
Do not use it as a mandate to rewrite unrelated legacy code.

## Plugin Handling

Prefer the official current version of each plugin when one exists for the
target Zen Cart version.

If the old store customized a plugin, first upgrade to the current plugin
version, then decide whether those customizations still need to be re-applied.

Do not blindly copy old plugin files into the new store. Old plugins may:

- replace core files that no longer exist
- depend on old bootstrap behavior
- use PHP syntax incompatible with the target PHP version
- duplicate functionality now built into Zen Cart
- miss security fixes from newer plugin releases

If a plugin uses PHP syntax or runtime assumptions incompatible with the target
Zen Cart or PHP version, stop and either update, replace, or remove that plugin
with the store owner's approval.

Plugins that manage configuration records through payment, shipping, or
order-total module classes may still need module-level install or configuration
steps after files are migrated.

## Template Handling

Treat templates as a careful merge, not a file overwrite.

Preserve design intent while incorporating relevant changes from the newer
target template files. Pay special attention to:

- `template_default`
- `responsive_classic`, when the custom template was based on it
- checkout templates
- account and login templates
- forms and CSRF/security token behavior
- product listing and product info templates
- JavaScript and CSS loading changes
- output escaping
- mobile and responsive layout behavior

If a third-party template has a newer version compatible with the target Zen Cart
release, prefer that newer version and then migrate site-specific design changes
into it.

If no newer template version is available, merge relevant fixes from the target
release's `template_default` or `responsive_classic` files into the customized
template. This is especially important for security, checkout, account, and form
handling changes.

Screenshots supplied by the store owner are useful for identifying intended
layout and content behavior after the merge.

## Language File Handling

Language files changed significantly across some release lines. When upgrading
from Zen Cart 1.5.7 or older to 1.5.8 or newer, expect additional work converting
or reapplying define-based language customizations into the newer array-based
language-file format.

For simple language edits, migrate only the intended strings. Do not blindly copy
old language files over new ones, because new releases may include new constants,
renamed files, changed structure, and security or compatibility fixes.

If the store uses multiple languages or third-party language packs, include each
active language in the inventory and validation checklist.

## Database Upgrade Phase

Zen Cart's `zc_install` upgrader updates the database schema and database data
required by the target version. It does not merge PHP files, templates, plugins,
or custom code.

Treat database upgrading as a separate phase.

Until a supported CLI flow exists for `zc_install`, expect the store owner or
developer to run the web-based database upgrader manually in a staging
environment. The agent should prepare the upgraded files and document when the
database upgrader must be run.

For staging, restore a fresh copy of the live database into a new temporary
database. Configure the upgraded files to use that temporary database.

When creating or editing `includes/configure.php` and
`admin/includes/configure.php` for staging:

- use the temporary database name
- use the temporary database username and password
- preserve the old store's `DB_PREFIX` value exactly
- update filesystem paths so they point to the staging directory

When running `zc_install`, choose the database upgrade path. Do not choose a
clean install against a copy of the store database, because that will overwrite
store data.

Do not manually edit schema or data as a substitute for `zc_install` unless you
are diagnosing a specific failed upgrade step and have a clear rollback path.

After the database upgrader runs, inspect:

- the upgrader result messages
- `project_version`
- `project_version_history`
- Zen Cart logs
- web server and PHP error logs

Unresolved upgrader warnings or errors must be handled before production
deployment.

After database upgrade in staging:

- run plugin install, upgrade, or removal steps required by plugin documentation
- use Admin > Modules > Shipping, Admin > Modules > Payment, and Admin > Modules
  > Order Total so active modules can create or repair missing configuration keys
- use Admin > Modules > Plugin Manager for encapsulated-plugin upgrade actions
  when available

If custom database date fields exist, review the official date-standardization
upgrade guidance before migrating those customizations.

## Tooling Guidance

The agent may use developer tools to assist analysis, but the upgraded store
must be validated as an application.

Reliable baseline tools:

- file inventories
- diffs against official Zen Cart release tags
- three-way comparisons
- recursive PHP syntax checks with the target PHP version
- database restore checks
- staging web smoke tests
- log inspection

Useful but not decisive tools:

- Composer, only in the agent or developer environment
- PHPUnit, when the target branch and dependencies match
- PHPStan, as a before-and-after sanity check when practical
- Rector or other automated transforms, only for narrow reviewed changes
- browser automation for repeatable staging checks

Risky or misleading signals:

- running tests from a different Zen Cart branch
- treating passing tests as proof that checkout or payments work
- using static analysis as a replacement for business workflow testing
- running broad automated refactors across customized legacy code
- uploading old files over a new release without review

Composer is not a production dependency for core Zen Cart. Ordinary store owners
do not need to run Composer, PHPUnit, or PHPStan. The core `/vendor` directory
created by Composer for development and testing does not belong on the
production server.

Run `php -l` against migrated PHP files with the target PHP version as a minimum
syntax check.

## Security Review

Review migrated custom code for security-sensitive behavior before carrying it
forward.

High-risk areas include:

- catalog request handling
- raw `$_GET`, `$_POST`, or `$_REQUEST` usage
- unescaped HTML output
- SQL query construction
- file upload handlers
- file download handlers
- import and export scripts
- redirects
- payment callbacks and webhooks
- custom admin pages
- custom AJAX endpoints
- API endpoints and feeds
- path construction and filesystem access

Catalog code generally requires more explicit request and output review. Admin
code benefits from Zen Cart's admin input sanitization system, but custom admin
fields still need correct whitelist handling when accepting new inputs.

Follow `CONVENTIONS.md` for new or modified code:

- use table-name constants instead of raw table names
- protect user-supplied output with `zen_output_string_protected()`
- avoid spreading direct superglobal access when safer helpers exist
- preserve early request-sanitizing behavior in `application_top.php`
- prefer extension points instead of direct bootstrap and path-file edits

## Minimum Acceptance Checklist

Before handing the upgraded store back as ready for production review, verify as
many of these as the available staging environment allows:

- storefront home page loads
- category pages load
- product info pages load
- search works
- create-account flow works
- login and logout work
- password reset works
- cart add, update, and remove actions work
- checkout can progress through shipping, payment, confirmation, and success
  pages using non-production payment handling
- guest checkout works when enabled
- tax calculation matches expectations
- shipping module quotes appear as expected
- order-total modules apply correctly
- coupons and gift certificates work when used by the store
- downloadable products work when used by the store
- contact-us and email flows work
- admin login works
- admin dashboard loads
- orders can be viewed
- customers can be viewed
- products and categories can be viewed and edited
- payment, shipping, and order-total module configuration pages load
- plugin manager loads
- active custom admin pages load
- storefront template layout matches intended screenshots closely enough for
  owner review
- desktop and mobile layouts are both acceptable for owner review
- every active language loads key storefront and checkout pages
- no unresolved PHP errors appear in Zen Cart logs
- no unresolved PHP errors appear in web server logs
- `project_version` matches the target version after database upgrade

Actual payment capture or authorization should be verified by the store owner or
developer with appropriate sandbox or production procedures. Do not assume a
payment module is production-ready merely because checkout pages render.

## Stop and Ask

Stop and ask for owner or developer input when:

- the requested process would modify the live production store directly
- no rollback backup exists
- the file version and database version conflict
- the database dump is required but unavailable
- database credentials from `configure.php` would be used directly
- a plugin has no compatible target-version path
- a payment module's behavior cannot be verified or safely inferred
- custom code handles passwords, encryption, payment data, or customer PII
- PHP files are obfuscated
- old customizations appear to contain malware or unauthorized code
- a manual `zc_install` database upgrade reports unresolved warnings or errors
- a required business workflow cannot be validated in staging

Document the blocker and the safest next action instead of guessing.

## Deployment Handoff

The final production deployment should be performed only after:

- file migration is complete
- database upgrade has completed in staging
- validation checklist results are documented
- unresolved risks are accepted or fixed
- the store owner has a fresh production backup plan
- the store owner or developer understands the production cutover steps

Production cutover should generally include:

- place the live store in maintenance mode
- back up live files and database
- deploy upgraded files
- run `zc_install` database upgrade if production has not already been upgraded
- verify admin and storefront access
- test checkout using the merchant's accepted procedure
- inspect logs
- remove or protect installer files according to Zen Cart instructions
- remove or block public access to old store directories, temporary upgrade
  directories, database dumps, and backup archives
- take the store out of maintenance mode

Do not leave old live-store copies, database dumps, ZIP archives, or temporary
upgrade directories publicly reachable on the server. If any must be retained
temporarily for rollback, store them outside the web root where possible, rename
them unpredictably, restrict access, and schedule prompt removal.

## Final Upgrade Report Template

At the end of an upgrade engagement, provide a concise report:

```text
Zen Cart Upgrade Report

Source version:
Target version:
PHP version used for validation:
MySQL/MariaDB version used for validation:
Admin directory:

Inputs received:
- File archive:
- Database dump or schema dump:
- Plugin/template list:
- Screenshots:

Upgrade path used:

Plugins:
- Updated:
- Replaced:
- Removed:
- Carried forward with changes:
- Requiring owner/developer follow-up:

Templates:
- Active template:
- Base template assumed:
- Files merged:
- Known visual differences:

Custom code migrated:

Database upgrade:
- zc_install run by:
- Result:
- project_version:
- unresolved warnings/errors:

Validation performed:
- PHP syntax check:
- Storefront flows:
- Admin flows:
- Checkout/payment:
- Logs checked:

Security review notes:

Known risks and follow-up items:

Files or directories intentionally not migrated:

Production deployment notes:
```
