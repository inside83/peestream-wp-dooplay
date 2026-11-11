# PeeStream for WordPress & DooPlay

The official high-performance video player, automation suite, and mass migrator for **PeeStream** (`peestream.com` / `peeplayer.com`). Tailored for high-traffic movie & TV show streaming portals running the **DooPlay** theme or generic WordPress themes.

---

## Key Features

1. **Native DooPlay Repeatable Fields Formatter:**
   - Seamlessly integrates into DooPlay's `repeatable_fields` postmeta architecture without altering theme templates.
   - Preserves player server name, audio language flag (`English`, `Spanish`, `Subbed`, `Latino`, etc.), and quality badge (`1080p`, `720p`, `4K`).
   - Supports selecting position: **Primary (Server 1 / Top Tab)** or **Append to list**.

2. **DooPlay Competitor Auto-Migrator:**
   - Deep scans your `wp_postmeta` database table for failing or discontinued competitor hosters:
     - **MixDrop** (`mixdrop.co`, `mixdrop.to`, `mixdrop.sx`, etc.)
     - **Doodstream** (`dood.wf`, `doodstream.com`, `ds2play.com`, etc.)
     - **Streamtape** (`streamtape.com`, `streamtape.net`, etc.)
     - **Filemoon**, **Streamwish**, **Vidguard**, **VOE**, **Upstream**, **Vidoza**.
   - Mass migrates links to PeeStream with a live progress bar (0% - 100%) and interactive console.
   - Choose between in-place replacement or keeping old servers as secondary backups.

3. **Interactive Post Editor Metabox:**
   - Injected into `movies`, `episodes`, and standard `post` editor screens.
   - **Quick Embed:** Paste a PeeStream code or embed URL to attach immediately.
   - **Remote URL Upload:** Paste any direct MP4 or external hoster link to download and transcode asynchronously.
   - **IMDb Importer:** Reserve and import videos by IMDb ID (`tt...`) with season and episode numbers.
   - Real-time DooPlay server list preview with quick delete action.

4. **REST API Webhook Auto-Publish Receiver:**
   - Dedicated endpoint: `POST /wp-json/peestream/v1/webhook?secret=YOUR_SECRET`.
   - Automatically synchronizes newly transcoded TV episodes with parent series (`tvshows`), attaching PeeStream as Server 1.
   - Optional automatic creation of new episode and movie posts when incoming titles are detected.

5. **Universal Shortcode for Non-DooPlay Themes:**
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

1. Upload the plugin folder or install [`peestream-wp-dooplay_v1.0.0.zip`](file:///root/gemini_stuff/peestream-wp-dooplay_v1.0.0.zip) via **WP Admin -> Plugins -> Add New -> Upload Plugin**.
2. Activate the plugin.
3. Go to **PeeStream -> Settings** in your WordPress admin menu.
4. Enter your **PeeStream API Key** (found in your PeeStream dashboard under *My Account*).
5. Click **Verify API Connection** to confirm connectivity, account tier, and USD balance.
6. Configure default player server name, language, and quality preferences, then click **Save All Changes**.

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

## License

GPLv2 or later. Developed and maintained by the PeeStream Engineering Team.
