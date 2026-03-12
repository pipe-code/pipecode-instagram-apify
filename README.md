# Pipecode Instagram Apify

A WordPress plugin that syncs Instagram posts via [Apify](https://apify.com/) and stores them in a custom database table. It includes an admin panel with manual sync, live settings, a paginated post viewer, and a public REST API.

Built by [Pipecode](https://pipe-code.github.io/).

---

## Features

- Fetches Instagram posts by running an Apify actor task on demand or on a daily schedule
- Stores posts in a dedicated custom table (`wp_instagram_posts`) — no CPT bloat
- Downloads cover images and carousel children directly into the WordPress Media Library
- Admin panel with:
  - Live Apify endpoint configuration with inline validation
  - Manual sync button with real-time log output
  - Paginated post viewer
  - REST API URL display with one-click copy
- Public REST API endpoint to consume posts from any frontend
- Full data cleanup on plugin deactivation (table + options)

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- An [Apify](https://apify.com/) account (free tier works)
- An Apify actor task configured to scrape an Instagram profile

---

## Installation

1. Copy the `pipecode-instagram-apify` folder into `wp-content/plugins/`.
2. Go to **Plugins → Installed Plugins** in your WordPress admin and activate **Pipecode Instagram Apify**.
3. The plugin creates the `wp_instagram_posts` table automatically on activation.
4. Navigate to **Instagram Apify → Dashboard** and fill in your Apify settings.

---

## Apify Setup

### 1. Create an Apify account

Sign up at [apify.com](https://apify.com/). The free plan ($0/month) includes $5 of monthly compute credits, which is sufficient for daily syncs of small Instagram profiles.

### 2. Create an Actor Task

1. In the Apify Console, go to **Actors** and find an Instagram scraper (e.g. `apify/instagram-scraper`).
2. Click **Create task** and configure it to target the Instagram username(s) you want to scrape.
3. Save the task. In the task detail page, copy the **Run endpoint** URL — it looks like:

```
https://api.apify.com/v2/actor-tasks/{username}~{task-name}/run-sync-get-dataset-items
```

### 3. Get your API Token

In the Apify Console, go to **Settings → Integrations** and copy your **Personal API token**.

### Multiple Instagram accounts

You can handle multiple Instagram accounts from a **single Apify account** by creating one task per account:

```
Apify account
├── Task: client-a-instagram  →  endpoint A  →  WordPress site A
├── Task: client-b-instagram  →  endpoint B  →  WordPress site B
└── Task: client-c-instagram  →  endpoint C  →  WordPress site C
```

Install this plugin on each WordPress site and point each one to its corresponding endpoint.

---

## Configuration

Go to **Instagram Apify → Dashboard → Apify Settings**.

| Field | Description |
|---|---|
| **Apify Endpoint URL** | The full `run-sync-get-dataset-items` URL for your actor task. Must start with `https://api.apify.com/`. |
| **Apify Token** | Your personal Apify API token. Leave blank on subsequent saves to keep the stored value. |

The endpoint field validates in real time as you type. The **Sync Now** button and the daily cron job are both blocked until a valid endpoint is saved.

---

## Admin Panel

### Dashboard (`Instagram Apify → Dashboard`)

- **Stats row** — total posts stored, last sync time (UTC), and next scheduled sync.
- **Apify Settings** — configure the endpoint and token (see above).
- **Manual Sync** — triggers a sync immediately. A live log shows the result for each post (`✓ synced` or `✗ error`). The button is disabled when no valid endpoint is configured.
- **REST API** — displays the public endpoint URL with a copy button.

### Posts (`Instagram Apify → Posts`)

A paginated table (20 posts per page) of all synced posts, showing:

- Thumbnail (linked to the original Instagram post)
- Instagram Post ID
- Type badge (`Image`, `Video`, `Sidecar`)
- Caption excerpt + first 5 hashtags
- Like and comment counts
- Original post date and sync date

---

## Sync Behavior

### Manual sync

Click **Sync Now** in the Dashboard. The plugin calls the Apify endpoint, waits for the actor to finish, and processes the returned items.

### Automatic sync (cron)

The plugin registers a WordPress cron event that fires **every day at 06:00 AM UTC**. The schedule is created on activation and removed on deactivation.

> **Note:** WordPress cron runs on page load, so it fires around 06:00 UTC when the next visitor hits the site. For precise scheduling, configure a real server cron to hit `wp-cron.php` directly.

### Upsert logic

For each item returned by Apify:

1. Look up the post by `post_id` (Instagram's internal ID).
2. If it exists → update all fields.
3. If it does not exist → insert a new row.

This means repeated syncs are safe and idempotent — no duplicates are created.

### Image download

- **Cover image** (`displayUrl`) — downloaded and added to the WordPress Media Library. The stored `display_url` column contains the local WordPress URL.
- **Carousel children** (`childPosts`) — each child image is also downloaded. Their URLs are stored as a JSON array in the `images` column.

---

## Database Table

The plugin creates one custom table: `{prefix}instagram_posts`.

| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Auto-increment primary key |
| `post_id` | `VARCHAR(255)` | Instagram post ID (unique) |
| `caption` | `LONGTEXT` | Post caption |
| `display_url` | `VARCHAR(2048)` | Local WP URL of the cover image |
| `type` | `VARCHAR(64)` | Post type (`Image`, `Video`, `Sidecar`) |
| `hashtags` | `TEXT` | Comma-separated hashtag list |
| `mentions` | `TEXT` | Comma-separated mention list |
| `url` | `VARCHAR(2048)` | Original Instagram post URL |
| `alt` | `VARCHAR(512)` | Alt text from Instagram |
| `likes_count` | `INT` | Number of likes |
| `comments_count` | `INT` | Number of comments |
| `timestamp` | `DATETIME` | Original post date (Instagram) |
| `images` | `LONGTEXT` | JSON array of local carousel image URLs |
| `created_at` | `DATETIME` | Row creation timestamp |
| `updated_at` | `DATETIME` | Last update timestamp |

---

## REST API

### Endpoint

```
GET /wp-json/pipecode/v1/instagram
```

### Parameters

| Parameter | Type | Default | Range | Description |
|---|---|---|---|---|
| `count` | integer | `12` | `1–100` | Number of posts to return (newest first) |

### Example request

```
GET https://yoursite.com/wp-json/pipecode/v1/instagram?count=6
```

### Example response

```json
[
  {
    "id": 42,
    "post_id": "3456789012345678901",
    "caption": "Golden hour at the lake 🌅 #nature #photography",
    "display_url": "https://yoursite.com/wp-content/uploads/2025/03/ig-AbCdEfGh-cover.jpg",
    "type": "Image",
    "hashtags": ["nature", "photography"],
    "mentions": [],
    "url": "https://www.instagram.com/p/AbCdEfGh/",
    "alt": "A person standing by a lake at sunset",
    "likes_count": 1284,
    "comments_count": 37,
    "timestamp": "2025-03-08 18:45:00",
    "images": []
  }
]
```

For carousel posts, the `images` array contains the local URLs of all child images:

```json
{
  "type": "Sidecar",
  "display_url": "https://yoursite.com/wp-content/uploads/2025/03/ig-XyZw-cover.jpg",
  "images": [
    "https://yoursite.com/wp-content/uploads/2025/03/ig-XyZw-child-1.jpg",
    "https://yoursite.com/wp-content/uploads/2025/03/ig-XyZw-child-2.jpg",
    "https://yoursite.com/wp-content/uploads/2025/03/ig-XyZw-child-3.jpg"
  ]
}
```

The endpoint is **public** (no authentication required).

---

## Deactivation

Deactivating the plugin:

1. Cancels the daily cron event.
2. Drops the `wp_instagram_posts` table.
3. Deletes all plugin options (`pc_instagram_settings`, `pc_instagram_last_sync`, `pc_instagram_db_version`).

> **Warning:** All synced post records are permanently deleted on deactivation. Downloaded images in the Media Library are **not** deleted.

---

## File Structure

```
pipecode-instagram-apify/
├── pipecode-instagram-apify.php       Main plugin file — constants, hooks, activation/deactivation
├── README.md
├── includes/
│   ├── class-instagram-db.php         Database layer — create/drop table, upsert, paginated queries
│   ├── class-instagram-sync.php       Apify integration — fetch, validate, process, download images
│   ├── class-instagram-admin.php      Admin panel — menus, settings form, AJAX handlers, page rendering
│   └── class-instagram-api.php        REST API — route registration and response formatting
└── assets/
    ├── css/admin.css                  Admin panel styles
    └── js/admin.js                    Settings form validation, sync button, copy utility
```

---

## License

GPL v2 or later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
