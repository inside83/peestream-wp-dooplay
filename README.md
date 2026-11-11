# PeeStream for WordPress & DooPlay

Official high-performance video player and automation plugin for **PeeStream** (`peestream.com` / `peeplayer.com`), designed specifically for the **DooPlay** theme and generic WordPress movie & TV streaming portals.

---

## Key Features

1. **Native DooPlay Repeatable Fields Integration:**
   - Automatically populates the `repeatable_fields` postmeta used by DooPlay movies and TV episodes.
   - Preserves player server name, quality badge (`1080p`, `720p`), and audio language tags.

2. **Real-time API Account Healthcheck:**
   - Direct connection to PeeStream Cloud via `/api/account/info`.
   - Live dashboard display of account balance (USD), storage quota, and connection status.

3. **Remote URL Upload & IMDb Importer:**
   - Easily queue direct video links (MP4 / external hosters) to PeeStream from your WordPress editor.
   - Import TV series and movies directly by IMDb ID (`tt...`).

4. **Webhook Auto-Publish Receiver:**
   - Built-in WordPress REST API endpoint (`/wp-json/peestream/v1/webhook`).
   - Automatically attaches new episodes to existing TV series upon encoding completion.

5. **Universal Fallback Shortcode:**
   - Use `[peestream id="FILE_CODE"]` on standard WordPress themes (Toroplay, Astra, custom themes).

---

## Installation

1. Download or clone this repository into your WordPress plugins directory:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/inside83/peestream-wp-dooplay.git
   ```
2. Activate the plugin via **WordPress Admin -> Plugins -> Installed Plugins -> PeeStream Video Player & DooPlay Integration**.
3. Navigate to **PeeStream** in the left sidebar menu.
4. Enter your **PeeStream API Key** (obtainable from `https://peestream.com/?op=my_account`).
5. Click **Verify API Connection** to ensure credentials are active.
6. Configure default player server name, audio language, and quality, then click **Save All Changes**.

---

## DooPlay Setup

- No theme code modifications are required. The plugin directly handles DooPlay's `repeatable_fields` structure:
  ```php
  array(
      array(
          'name'   => 'PeeStream HD',
          'select' => 'iframe',
          'idioma' => 'English',
          'quality'=> '1080p',
          'url'    => 'https://peeplayer.com/e/{file_code}'
      )
  )
  ```

---

## License

GPLv2 or later. Developed by the PeeStream Engineering Team.
