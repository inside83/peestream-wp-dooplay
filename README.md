<p align="center">
  <a href="https://peestream.com"><img src="assets/images/peestream-logo.png" alt="PeeStream logo" width="96"></a>
</p>
<h1 align="center">PeeStream for WordPress & DooPlay</h1>
<p align="center"><strong>Video player integration, remote import, and controlled video-link migration for WordPress publishers.</strong></p>
<p align="center">
  <a href="https://peestream.com">PeeStream</a> ·
  <a href="https://github.com/PeeStream/peestream-wp-dooplay/releases">Releases</a> ·
  <a href="https://github.com/PeeStream/peestream-wp-dooplay/issues">Report an issue</a>
</p>
<p align="center"><strong>v1.0.4-beta.1 planned</strong> · <strong>Staging only</strong> · <strong>Live CMS E2E: NOT RUN</strong></p>

This official PeeStream integration is designed for the DooPlay 2.5.x theme and also provides a universal shortcode for other WordPress themes. It connects your existing PeeStream account to WordPress without modifying DooPlay's core templates.

> [!WARNING]
> **BETA RELEASE (v1.0.4-beta.1) — NOT PRODUCTION TESTED**
> Compatibility across WordPress and DooPlay 2.5.x is expected based on architecture review and automated harness simulations (174 tests passed). Live CMS End-to-End runtime testing on an active WordPress/DooPlay installation has **NOT BEEN RUN**. Installation is recommended exclusively on staging/testing sites. Always make a full database backup before running migrations. Please report any issues along with your CMS, theme, PHP, and database versions.

---

## Compatibility

- **WordPress:** 5.8 to 6.7+ (Live CMS E2E: NOT RUN)
- **PHP:** 7.4 through 8.3 (syntax lint verified on lsphp72 through lsphp83)
- **Theme:** DooPlay 2.5.x (native `repeatable_fields` support) and all standard WordPress themes (via Universal Shortcode)

---

## Key Features

1. **Native DooPlay Repeatable Fields Formatter:**
   - Seamlessly integrates into DooPlay's `repeatable_fields` postmeta architecture without editing core theme templates.
   - Preserves player server name, audio language flag (`English`, `Spanish`, `Subbed`, `Latino`, etc.), and quality badge (`1080p`, `720p`, `4K`).
   - Supports selecting position: **Primary (Server 1 / Top Tab)** or **Append to list**.
   - Strict deduplication ensures identical PeeStream players are never attached twice.

2. **DooPlay Safe Competitor Auto-Migrator:**
   - Deep scans your `wp_postmeta` database table with paginated chunking to prevent memory exhaustion.
   - Whitelist of hosters functionally confirmed working on PeeStream remote upload:
     - **Streamtape** (`streamtape.com`, `streamtape.net`, `streamtape.to`, etc.)
     - **MixDrop** (`mixdrop.co`, `mixdrop.to`, `mixdrop.sx`, etc.)
     - **VOE** (`voe.sx`, `reputationsheriffgrey.com`)
     - **StreamWish** (`streamwish.to`, `streamwish.com`, etc.)
     - **EarnVids / VidHide** (`earnvids.com`, `vidhide.com`, `vidhidepro.com`, etc.)
     - **PixelDrain** (`pixeldrain.com`)
   - **Persistent Migration Queue & Safe Polling:** Competitor links are queued to PeeStream first; `repeatable_fields` are only modified after the video is verified `ready` (`canplay=1`). If remote upload fails, the original link remains 100% intact.
   - **Dry Run Mode:** Preview matched candidate links and posts without making any API calls or modifying the database.
   - **Automatic Backups & 1-Click Rollback:** Snapshots of original server arrays are stored in `wp_peestream_migration_backups` for instant rollback.

3. **Interactive Post Editor Metabox:**
   - Integrated into `movies`, `episodes`, and standard `post` editor screens.
   - **Quick Embed:** Paste a PeeStream code or embed URL to attach immediately.
   - **Remote URL Upload:** Paste external direct MP4 or hoster links to download and transcode asynchronously.
   - **IMDb Importer:** Reserve and import videos by IMDb ID (`tt...`) with season and episode numbers.
   - Real-time DooPlay server list preview with quick delete action.

4. **Hardened REST API Webhook Receiver:**
   - Dedicated endpoint: `POST /wp-json/peestream/v1/webhook?secret=YOUR_SECRET`.
   - **Security:** POST-only, fail-closed token validation (minimum 16 characters required), IP rate-limiting (60 requests/minute), replay attack prevention via DB audit table (`wp_peestream_webhooks`).
   - **Strict Disambiguation:** Resolves parent TV shows and episodes strictly via IMDb (`tt...`) or TMDb IDs. Eliminates ambiguous title-only guessing.
   - **Safe Opt-In Post Creation:** `auto_create_posts` defaults to `OFF` (`0`). Dynamic administrator author assignment prevents unauthenticated post publications.

5. **Universal Shortcode for Generic Themes:**
   - Embed fluid 16:9 HLS players on any WordPress theme:
     ```html
     [peestream id="eK7g1xY9" responsive="true" autoplay="false"]
     ```
   - Supports custom width and height attributes for fixed dimensions:
     ```html
     [peestream id="eK7g1xY9" responsive="false" width="100%" height="520px"]
     ```

---

## Installation

1. When published, download the **beta** package `peestream-wp-dooplay_v1.0.4-beta.1.zip` from the [GitHub Releases page](https://github.com/PeeStream/peestream-wp-dooplay/releases). Do not use this beta on a production site.
2. Go to **WordPress Admin -> Plugins -> Add New -> Upload Plugin** and select the ZIP file.
3. Click **Install Now** and activate the plugin.
4. Go to **PeeStream -> Settings** in your WordPress admin menu.
5. Enter your **PeeStream API Key** (found in your PeeStream dashboard under *My Account*).
6. Click **Verify API Connection** to confirm connectivity, account tier, and USD balance.
7. Configure default player server name, language, and quality preferences, then click **Save All Changes**.

---

## DooPlay Data Structure Reference

The plugin natively manages DooPlay's serialized `repeatable_fields` format:
```php
array(
    array(
        'name'    => 'PeeStream HD',
        'select'  => 'iframe',
        'idioma'  => 'English',
        'quality' => '1080p',
        'url'     => 'https://peeplayer.com/e/{file_code}'
    )
)
```

---

## Changelog

### Version 1.0.4 (beta; not tested on a live WordPress/DooPlay installation)
- **Binary-Safe Case-Sensitive URL Deduplication:** Enforced SHA-256 identity (`url_hash`) and `BINARY original_url` comparison in database migration deduplication and queue reservation, preventing case-sensitive video URLs from colliding on case-insensitive collations.
- **Fail-Safe Deduplication Archiving:** Upgrades safely archive duplicate queue items to `wp_peestream_migration_queue_archive` before adding unique index `uq_post_urlhash`; any SQL error halts migration with zero data loss.
- **Lock Lease Hardening:** Loss of global processing lock immediately stops post metadata modifications.
- **Webhook Transaction Protection:** Wrapped post insertion and metadata attachment in explicit SQL transactions with automatic rollback on failure, eliminating orphan draft posts.
- **Truthful Admin Batch Progress:** Distinct counters for completed, pending, failed, and skipped items; pending items show "Queued / Pending encoding".

### Version 1.0.3
- **Unified Manual Migration Locking:** `ajax_process_batch()` is now fully integrated with `acquire_lock(120)`, periodic heartbeat lease extension, and safe `finally` unlock, ensuring AJAX Start Migration, AJAX Check Queue, and WP-Cron run mutually exclusively without racing or overwriting post metadata.
- **Multi-Server Post Atomicity:** Posts containing multiple competitor links (e.g. 3 servers) now process each server link individually and atomically. Fresh post metadata is reloaded before each modification, distinct backup snapshots are stored in `wp_peestream_migration_backups`, and each server can be rolled back individually without corrupting other links.
- **Idempotent Queue Pre-Reservation & UNIQUE Constraint:** Added `UNIQUE KEY uq_post_orig (post_id, original_url(191))` to the migration queue table. Tasks are atomically pre-reserved in the database before calling PeeStream's `/api/upload/url`, eliminating duplicate remote upload calls on concurrent requests.
- **Strict DB Schema Compliance Verification:** `create_tables()` strictly verifies the actual presence of all mandatory columns across all three tables and validates both UNIQUE indexes (`uq_fc_event` and `uq_post_orig`). Schema version is bumped to `104` and is only recorded after all verifications succeed.
- **Consistent UTC Timestamps & Timezone Safety:** Switched all timestamp writes (`backup_time`, `created_at`, `updated_at`, `received_at`) and comparison cutoffs (`recover_partial_failures()`, `process_queue_batch()`) to consistent UTC (`current_time('mysql', true)` and `gmdate()`), eliminating bugs caused by WordPress local timezone offsets (`gmt_offset`).
- **Eliminated Webhook Draft Crash Window:** Passed metadata directly in `wp_insert_post( 'meta_input' => ... )` and added title-based draft querying, preventing duplicate draft creation even if a crash occurs immediately after post insertion.
- **Verified Server Attachment in Webhook:** `attach_server_to_post()` verifies that `repeatable_fields` actually persisted to the database; failures return HTTP 500 and set audit status to `'failed'` instead of `'processed'`.

### Version 1.0.2
> **Note:** v1.0.4-beta.1 has not undergone end-to-end testing on a running WordPress/DooPlay installation. Consult the release notes and validate any version in a staging environment before deploying it.

- **Queue Lock Lease Extension & Time Budgeting:** Replaced fixed 120s queue lock timeout with per-task heartbeat lease extension (`extend_lock`) and execution time budgeting (`max_execution_time`). Slow API responses can no longer cause an active worker's lock to expire and be usurped by a concurrent worker.
- **Atomic Lock Release by Owner Token:** Implemented atomic SQL conditional deletion (`DELETE FROM wp_options WHERE option_name = %s AND option_value LIKE %s`), ensuring a worker can only release its own lock token and cannot clear locks acquired by another process.
- **Verified Post Meta Persistence:** In both manual AJAX and background WP-Cron queue processing, DooPlay `repeatable_fields` updates are strictly verified (`fresh_meta === target_meta`) before marking queue items as `completed`.
- **Partial Failure Recovery & Crash Protection:** Backups are written with status `in_progress` prior to post modification and promoted to `completed` only after post meta is confirmed saved. Added `recover_partial_failures()` to automatically identify and flag interrupted jobs as `apply_failed` without corrupting post data.
- **Legacy Backup Mode Migration & Duplicate Prevention:** Enforced `DEFAULT 'unknown'` on schema version 103 for the backup `mode` column; migrated historical empty backup modes to `'unknown'`. If an old backup recorded mode as `replace` but the original URL is already present in the post, `ajax_rollback()` safely overrides to `primary` to prevent duplicating original servers.
- **Webhook Crash Recovery & Draft Deduplication:** Added immediate post link persistence (`_peestream_webhook_filecode` and `post_id` audit column) right after `wp_insert_post()`. Subsequent webhook retries re-use existing draft posts, preventing duplicate post creation.
- **Decoupled DB Schema Versioning:** Upgraded to `DB_SCHEMA_VERSION = 103`, saving the schema option strictly after all tables and column modifications return verified success.

### Version 1.0.1
- **Server Integrity & Strict Matching:** `replace` migration strictly matches identical original server URLs, eliminating loose hostname matching to protect posts containing multiple servers from the same hoster.
- **Editorial Conflict Detection:** Automatic conflict detection flags queue items if the target server was modified or deleted by an editor during encoding, preventing accidental server overwrites or unwarranted appends.
- **Differentiated Surgical Rollback:** Rollback engine distinguishes `primary` (removes only the prepended PeeStream server without duplicating original server) from `replace` (restores complete original server entry including name, player type, audio language, quality, and URL), fully preserving newer editorial modifications.
- **Atomic Webhook Idempotency:** Webhook receiver atomically pre-reserves incoming `(file_code, event)` records with status `processing` prior to modifying posts or inserting drafts, neutralizing concurrent race conditions.
- **Strict Disambiguation & DISTINCT Queries:** TV show, episode, and movie matching queries utilize `DISTINCT` post IDs, eliminating false ambiguity caused by multiple postmeta entries for the same ID.
- **Strict API Parsing:** `/api/file/info` parser enforces strict `filecode` matching without loose fallbacks.
- **Unified Concurrency Locking:** Manual AJAX checks and automated WP-Cron share a unified lock (`acquire_lock` / `release_lock`) and row-level atomic state claiming (`status = 'checking'`) to prevent parallel duplicate queue runs.
- **Resilient Scheduling:** Automated WP-Cron registration verified on both plugin activation and runtime `init` / upgrade hooks.
- **Security & Webhook Disambiguation:** Hardened REST API webhook with POST-only restriction, fail-closed secret authentication (>= 16 chars), IP rate-limiting, and atomic replay protection with `UNIQUE KEY uq_fc_event (file_code, event)`.
- **Security:** Strict ambiguity detection for duplicate IMDb/TMDb IDs and duplicate season/episode entries across different shows, rejecting with 409 Conflict for manual review to prevent wrong overwrites.
- **Security:** Default `auto_create_posts` set to `0` (opt-in only). Unmatched incoming webhook items logged for manual review.
- **Security:** XSS prevention across admin, metabox, and migrator scripts using safe DOM node construction and `.text()`.
- **Reliability & Async Queue:** Persistent migration queue (`wp_peestream_migration_queue`) with status polling (`/api/file/info`). Correctly handles PeeStream array result payload `[ { "filecode": "...", "canplay": 1 } ]`. Competitor links are modified only when `canplay=1`.
- **Automated Processing:** Automated background queue processing via WP-Cron (`peestream_cron_process_queue`) with transient concurrency locking and bounded retry protection (max 20 retries / 24h timeout).
- **Strategy Preservation:** Migration queue preserves selected mode (`replace` vs `primary`) along with user-selected server name, audio language, and quality badges.
- **Reliability:** Dry-run simulation mode for migrator.
- **Compatibility:** Strict host validation against confirmed PeeStream hosters (Streamtape, MixDrop, VOE, StreamWish, EarnVids/VidHide, PixelDrain). Non-functional hosters removed.
- **Network:** Replaced all HTTP requests with `wp_safe_remote_get()` and enforced HTTPS API endpoints.
- **License:** Full GNU General Public License v2 (GPLv2).

### Version 1.0.0
- Initial release with DooPlay metabox, shortcode, competitor migrator, and webhook receiver.

---

## License & Support

- **License:** GNU General Public License v2 (GPLv2).
- **Official Support:** Visit `https://peestream.com` or contact our WJunction support thread.
