# PeeStream for WordPress & DooPlay

The official high-performance video player, automation suite, and safe competitor migrator for **PeeStream** (`peestream.com` / `peeplayer.com`). Tailored for high-traffic movie & TV show streaming portals running the **DooPlay** theme (2.5.x) or generic WordPress themes.

---

## Compatibility

- **WordPress:** 5.8 to 6.7+
- **PHP:** 7.4, 8.0, 8.1, 8.2, 8.3
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

1. Download the release package `peestream-wp-dooplay_v1.0.1.zip`.
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

### Version 1.0.1
- **Security & Webhook Disambiguation:** Hardened REST API webhook with POST-only restriction, fail-closed secret authentication (>= 16 chars), IP rate-limiting, and atomic replay protection with `UNIQUE KEY uq_fc_event (file_code, event)`.
- **Security:** Strict ambiguity detection for duplicate IMDb/TMDb IDs and duplicate season/episode entries across different shows, rejecting with 409 Conflict for manual review to prevent wrong overwrites.
- **Security:** Default `auto_create_posts` set to `0` (opt-in only). Unmatched incoming webhook items logged for manual review.
- **Security:** XSS prevention across admin, metabox, and migrator scripts using safe DOM node construction and `.text()`.
- **Reliability & Async Queue:** Persistent migration queue (`wp_peestream_migration_queue`) with status polling (`/api/file/info`). Correctly handles PeeStream array result payload `[ { "filecode": "...", "canplay": 1 } ]`. Competitor links are modified only when `canplay=1`.
- **Automated Processing:** Automated background queue processing via WP-Cron (`peestream_cron_process_queue`) with transient concurrency locking and bounded retry protection (max 20 retries / 24h timeout).
- **Strategy Preservation:** Migration queue preserves selected mode (`replace` vs `primary`) along with user-selected server name, audio language, and quality badges.
- **Surgical Rollback:** Selective and targeted rollback engine restores only the specific PeeStream server back to the original hoster URL, guaranteeing newer editorial modifications and additional servers are completely preserved.
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
