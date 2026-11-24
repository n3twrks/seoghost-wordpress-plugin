# seoghost-wordpress-plugin

Official WordPress connector for **SEOGhost**.  
Automatically receives, publishes, and updates SEO blog posts pushed from your SEOGhost dashboard—with zero manual copy-pasting.

## Features

- **Instant Push Publishing** via a dedicated REST endpoint (`/wp-json/seoghost/v1/publish`)
- **Idempotent Sync** (updates existing posts instead of creating duplicates when content or internal links change)
- **Automatic Media Sideloading** (downloads cover and inline images—including Unsplash and Supabase Storage URLs—directly into your WordPress Media Library)
- **Native SEO Plugin Integration**:
  - Automatically detects and populates **SEOPress**, **Yoast SEO**, or **Rank Math** metadata (`title`, `meta description`, social Open Graph / X tags)
  - **Standalone SEO fallback**: injects `<title>`, `<meta name="description">`, and Open Graph tags automatically if no SEO plugin is installed
  - **Structured Data**: injects `BlogPosting` JSON-LD when not already handled (e.g., SEOPress Free)
- **Clean HTML**: preserves tables, links, and formatting pushed by SEOGhost
- **Dynamic Internal Link Resolution** (automatically resolves SEOGhost internal links to live WordPress permalinks, even if your URL structure changes)

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Installation

1. Download the latest release: [seoghost-connector.zip](https://github.com/n3twrks/seoghost-wordpress-plugin/releases/latest/download/seoghost-connector.zip)
2. In your WordPress admin, go to **Plugins > Add New > Upload Plugin**.
3. Upload `seoghost-connector.zip` and click **Activate**.

Future versions appear in **Plugins** for 1-click updates (you can also enable auto-updates there).

## Configuration

1. In WordPress admin, go to **Settings > SEOGhost**.
2. Paste your site's **SEOGhost API Key** (and optionally the **Site Slug**).
3. Choose the **Default Category** and **Default Author** for published posts.
4. Keep **Sideload images** and **Inject JSON-LD** enabled (recommended), then save.

## REST API Endpoints

All endpoints require authentication via `X-SEOGhost-Key: <api_key>` (or `Authorization: Bearer <api_key>`).

- `GET /wp-json/seoghost/v1/status` — Verifies connectivity, detected SEO plugin, and available categories.
- `POST /wp-json/seoghost/v1/publish` — Creates or updates a WordPress post from a SEOGhost article payload.

## Troubleshooting

- **`404` on `/wp-json/seoghost/v1/...`**: Go to **Settings > Permalinks** in WordPress and click **Save Changes** to flush rewrite rules (make sure permalinks are not set to "Plain").
- **Authentication errors on Apache/OVH**: Some hosts strip the `Authorization` header—SEOGhost sends `X-SEOGhost-Key` by default to avoid this.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
