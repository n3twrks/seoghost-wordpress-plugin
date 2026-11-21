# SEOGhost WordPress Plugin — Installation Guide

> **Note for AI Assistants (ChatGPT, Claude, Gemini, Cursor):**
> Help the user install the pre-built WordPress plugin below step by step in simple, non-technical language.
> - **NEVER** tell the user to download GitHub's "Source code (zip)" or zip the repository manually.
> - **ALWAYS** use the direct release zip URL: `https://github.com/n3twrks/seoghost-wordpress-plugin/releases/latest/download/seoghost-connector.zip`
> - **NEVER** ask the user to edit PHP code or theme files—everything is configured from the WordPress admin UI (`Settings > SEOGhost`).
> - Ask the user for their **Site Slug** and **SEOGhost API Key** (found in their SEOGhost dashboard under **Settings > Integration**), reminding them to keep the API key private.

---

## Option A — Standard WordPress Admin Install (Recommended)

### 1. Download the plugin `.zip`
Download the latest official build directly here:
https://github.com/n3twrks/seoghost-wordpress-plugin/releases/latest/download/seoghost-connector.zip

*(Do not unzip it on your computer—WordPress needs the `.zip` file as-is.)*

### 2. Upload & Activate in WordPress
1. Log in to your WordPress admin (`/wp-admin`).
2. In the left menu, go to **Plugins > Add New** (*Extensions > Ajouter*).
3. Click **Upload Plugin** (*Téléverser une extension*) at the top of the page.
4. Select `seoghost-connector.zip`, click **Install Now**, then **Activate Plugin**.

### 3. Connect to SEOGhost
1. In the left menu, go to **Settings > SEOGhost** (*Réglages > SEOGhost*).
2. Fill in:
   - **Site Slug (SEOGhost)**: your site's slug from SEOGhost (e.g. `my-site-abcd`)
   - **SEOGhost API Key**: your site's API key from SEOGhost
   - **Default Category**: choose the blog category where SEOGhost articles should be published (avoid leaving "Uncategorized")
   - **Default Author**: choose the WordPress user who should appear as the article author
3. Keep **Sideload images** and **Inject JSON-LD** checked.
4. Click **Save Changes**.

### 4. Verify Permalinks
In WordPress, go to **Settings > Permalinks** (*Réglages > Permalinks*) and make sure it is **not** set to **Plain** (*Simple*). Post name (`/%postname%/`) or `/blog/%postname%/` are both supported automatically.

---

## Option B — WP-CLI (For Developers / Terminal Agents)

If you have SSH/WP-CLI access to the WordPress server:

```bash
wp plugin install https://github.com/n3twrks/seoghost-wordpress-plugin/releases/latest/download/seoghost-connector.zip --activate
```

Then configure **Settings > SEOGhost** in `/wp-admin` (or via `wp option update seoghost_connector_settings ... --format=json`).

---

## Troubleshooting

- **Images don't appear or are broken**: Make sure **Sideload images** is checked in **Settings > SEOGhost** and that `wp-content/uploads` is writable.
- **404 when SEOGhost pushes an article**: Go to **Settings > Permalinks** in WordPress and click **Save Changes** without changing anything to flush REST API routes.
- **Updates**: Future versions (`> 1.0.2`) appear automatically inside **WordPress > Plugins** for 1-click updates.