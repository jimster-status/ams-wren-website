# AMS Wren Website

WordPress plugin that gives **Wren Workspace** (AMS AI Village, Website & Automation) what it needs on a site:

- exposes a page's Elementor layout and Yoast SEO fields over the REST API, to users who can edit that page;
- clears Elementor's and SiteGround's caches when a page is saved or goes live/comes down, so visitors never get an old version;
- `GET /wp-json/ams-wren/v1/status` (plugin version, exposed fields) and `POST /wp-json/ams-wren/v1/pages/{id}/refresh`;
- updates itself from this repository: sites show the normal WordPress "update now" notice.

Wren never publishes or replaces a live page on its own; that is always Jimy's click in Wren.

## Releasing

1. Change `ams-wren-website/ams-wren-website.php` and bump **both** `Version:` in the header and `AMS_WREN_WEBSITE_VERSION`.
2. `./release.sh "What changed"` builds `releases/ams-wren-website-X.Y.Z.zip` and rewrites `update.json`.
3. Commit and push to `main`. Sites see the update within a few hours, or right away via *Dashboard → Updates → Check again*.

The plugin only installs zips hosted in this repository. Anyone who can push here can change code on every site using the plugin, so keep write access to Jimy (and the Claude GitHub App, limited to this repository).
