=== Fulgur Cache ===
Contributors: thierrygustin
Tags: cache, performance, pagespeed, critical css, ai
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Static cache, critical CSS, AI optimizer (Claude), delayed JS, AVIF/WebP images, instant navigation. Open source.

== Description ==

Fulgur Cache makes a WordPress site extremely fast without technical setup:

* **Static page cache** served before WordPress (drop-in), and even without PHP on Apache/LiteSpeed (.htaccess) or Nginx.
* **Brotli and gzip pre-compression** of pages, ETag/304 headers.
* **Smart purge**: only the edited page, the home page, categories, tags, author and adjacent posts are cleared, then preloaded again.
* **Preloading** of the whole site in the background: no visitor ever hits a cold page.
* **Critical CSS / unused CSS removal** computed on your server, with no external service.
* **AI optimizer (Claude)**: analyzes server, load and PageSpeed, applies the best settings, verifies and rolls back automatically on regression.
* **Instant navigation**: Speculation Rules (prerender on hover) + lightweight fallback for Safari/Firefox.
* **JavaScript**: defer with preserved order, or delay until first interaction.
* **CSS**: minification, font-display: swap, locally hosted Google Fonts (GDPR).
* **Images**: automatic WebP/AVIF conversion, native lazy-loading, LCP image detection and preload, missing dimensions (CLS), YouTube facades.
* **103 Early Hints**: the main image is preloaded before the HTML arrives.
* **WordPress cleanup**: emojis, embeds, heartbeat, XML-RPC, dashicons, WooCommerce cart-fragments.
* **Database** cleanup, manual or weekly.
* **CDN and Cloudflare**: URL rewriting and automatic purge.
* **3 profiles** (Safe, Balanced, Turbo), onboarding wizard, built-in speed test, PageSpeed score, “Test safely” preview mode, diagnostics with one-click repair, WP-CLI.

Interface in English and French.

== Installation ==

1. Upload the `fulgur-cache` folder to `wp-content/plugins/` (or upload the zip).
2. Activate the plugin: the wizard opens and applies the chosen profile.
3. Deactivate any other caching plugin.

== Frequently Asked Questions ==

= Is the AI optimizer required? =

No. Everything works without it. The AI optimizer is optional and only runs if you add an Anthropic API key.

= Where do I put the API key? =

In the AI Optimizer tab, or more safely in wp-config.php: `define( 'FULGUR_ANTHROPIC_API_KEY', 'sk-ant-...' );`

== WP-CLI ==

* `wp fulgur clear [--url=<url>]`
* `wp fulgur preload`
* `wp fulgur status`
* `wp fulgur profile <safe|balanced|turbo>`
* `wp fulgur images`
* `wp fulgur db`

== Developer filters ==

* `fulgur_is_cacheable` (bool) — prevent a page from being cached.
* `fulgur_optimize_page` (bool) — disable optimizations on a page.
* `fulgur_html_before` / `fulgur_html_after` (string) — alter the HTML.
* `fulgur_purge_post_urls` (array, post_id) — URLs purged when content changes.
* `fulgur_preload_urls` (array) — URLs to preload.
* `fulgur_css_exclusions` (array) — CSS files not to minify.
* `<meta name="fulgur-skip">` tag or `data-fulgur-skip` attribute to exclude a page or an element.

== External services ==

Fulgur Cache contacts no external service by default. Depending on the features you enable:

* **Anthropic API (Claude)** — only if an API key is set in the AI Optimizer tab. Data sent: a technical report (server configuration, load, list of active plugins, settings, PageSpeed measurements). No content and no visitor data. Terms: https://www.anthropic.com/legal/commercial-terms — Privacy: https://www.anthropic.com/legal/privacy
* **Google PageSpeed Insights** — during an AI analysis or when you click “Mobile PageSpeed score”. The public URL of the home page is sent. https://policies.google.com/privacy
* **Google Fonts** — in “Host locally” mode, font files are downloaded once and then served from your site.
* **Cloudflare** — if an API token is set, to purge the Cloudflare cache. https://www.cloudflare.com/privacypolicy/

== Changelog ==

= 1.2.0 =
* English interface, French translation included (wp.i18n for the admin app).
* AI answers follow the admin language.
* Translation tooling (`bin/i18n.py`).

= 1.1.0 =
* Critical CSS and unused CSS removal (PHP CSS parser).
* AI optimizer (Claude) with PageSpeed verification and automatic rollback.
* Weekly autopilot.
* Load metrics (pages served from cache, pages generated).

= 1.0.0 =
* First release.
