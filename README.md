# ⚡ Fulgur Cache

**The open-source WordPress caching plugin with an AI optimizer.**
Static page cache, critical CSS, delayed JavaScript, AVIF/WebP images, instant navigation — and Claude picking the best settings for *your* server and *your* traffic, then checking they actually work.

Built by [Thierry Gustin](https://thierrygustin.com) · GPL-2.0-or-later · Free, no “Pro” version.
Interface available in **English** and **French** (more languages welcome).

> 🇫🇷 *Résumé en français plus bas.*

---

## Why Fulgur?

| | Fulgur Cache | Typical caching plugins |
|---|---|---|
| Static cache served without PHP (.htaccess / Nginx) | ✅ | ✅ |
| Brotli + gzip pre-compression, ETag / 304 | ✅ | partial |
| Critical CSS / unused CSS removal **computed on your server** | ✅ free, no external service | often paid or cloud-based |
| JavaScript delayed until first interaction, order preserved | ✅ | ✅ (paid tiers) |
| Instant navigation (Speculation Rules prerender) | ✅ | rare |
| 103 Early Hints | ✅ | rare |
| Built-in AVIF/WebP conversion | ✅ | separate plugin |
| **AI optimizer**: analyzes server + load + PageSpeed, applies, verifies, rolls back on regression | ✅ | ❌ |

## Features

**Cache**
- `advanced-cache.php` drop-in: pages are served before WordPress loads.
- Generated `.htaccess` rules (Apache/LiteSpeed) and Nginx configuration: 100% static serving, no PHP.
- Smart purge: only the edited page, the home page, its categories and tags, the author and adjacent posts are cleared, then preloaded again right away.
- Whole-site background preloading, tracking parameters (`utm_*`, `fbclid`…) ignored, optional separate mobile cache, WooCommerce-aware.

**CSS**
- **Critical CSS**: for each page, Fulgur inventories tags, classes, ids and attributes, keeps only the CSS rules that can apply, and inlines them in the `<head>`. Full stylesheets stay in place (cascade order preserved) and load asynchronously.
- **Unused CSS removal**: full stylesheets only load on first user interaction.
- Pure PHP parser (`@media`, `@supports`, `@layer`, CSS nesting, `:is()`, `:not()`, escaped Tailwind classes); unused `@font-face` and `@keyframes` dropped. Measured example: 646 KB of CSS → 20 KB actually used.
- Minification, `font-display: swap`, Google Fonts hosted locally (GDPR).

**JavaScript**
- `defer` with preserved execution order (inline scripts follow through data URIs; inline scripts tied to an excluded script stay with it).
- Delay until first interaction, replaying `DOMContentLoaded` and `load`.

**Media**
- Native lazy-loading, LCP image detection (preloaded, `fetchpriority="high"`), missing dimensions added (CLS), YouTube facades.
- WebP/AVIF conversion on upload, in bulk, or on the fly for theme images.

**Instant navigation**
- Speculation Rules (prerender on hover), integrated with WordPress 6.8+ native rules, plus a lightweight fallback for Safari/Firefox.

**WordPress cleanup, database cleanup, CDN, Cloudflare, WP-CLI.**

## ✦ The AI optimizer

1. **Measure**: server configuration (CPU, RAM, OPcache, PHP, Redis, Brotli…), real load (pages served from cache, pages generated, generation time, peak hour), WordPress (theme, plugins, page builder), audit of the original HTML, response time, and Google PageSpeed mobile + desktop (Core Web Vitals, LCP element, Lighthouse issues).
2. **Analyze**: Claude (Anthropic API, structured JSON output) receives this report and the documentation of every setting, then returns a diagnosis, settings each justified by a specific measurement, and hosting recommendations — in your admin language.
3. **Validate**: every proposal is checked (allowed setting, valid value). The cache itself, the CDN and API keys can never be changed by the AI.
4. **Apply**: you tick what you want (risky settings are unticked by default). Current settings are backed up.
5. **Verify**: PageSpeed runs again. If the score drops, the page stops rendering, or new JavaScript errors appear, **settings are rolled back automatically**. The history is sent to Claude on later analyses.

**Autopilot** (optional): weekly analysis with recommendations by email, or automatic application of safe settings only.

**Privacy**: only technical data is sent (no content, no visitor data). The API key stays on your server; the safest option is `wp-config.php`:

```php
define( 'FULGUR_ANTHROPIC_API_KEY', 'sk-ant-...' );
define( 'FULGUR_PSI_API_KEY', 'AIza...' ); // optional
```

**Cost**: billed by Anthropic on your key. Roughly $0.15–0.40 per analysis with Claude Opus 5.5, $0.08–0.20 with Sonnet 5.5, about $0.01 with Haiku 5.5.

## Installation

1. Download `fulgur-cache.zip` from the [latest release](../../releases), then in WordPress: Plugins → Add New → Upload.
2. Activate: the onboarding applies the profile you pick (Safe / Balanced / Turbo) and measures the gain.
3. Deactivate any other caching plugin.
4. Optional: **AI Optimizer** tab → paste your Anthropic API key → “Analyze and optimize my site”.

Requirements: WordPress 6.2+, PHP 7.4+ (8.2+ recommended), pretty permalinks.

## WP-CLI

```bash
wp fulgur clear [--url=<url>]
wp fulgur preload
wp fulgur status
wp fulgur profile <safe|balanced|turbo>
wp fulgur images
wp fulgur db
```

## For developers

| Filter / action | Purpose |
|---|---|
| `fulgur_is_cacheable` | Prevent a page from being cached |
| `fulgur_optimize_page` | Disable optimizations on a page |
| `fulgur_html_before`, `fulgur_html_after` | Alter the HTML |
| `fulgur_purge_post_urls` | URLs purged when content changes |
| `fulgur_preload_urls` | URLs to preload |
| `fulgur_css_exclusions` | CSS files not to minify |
| `fulgur_purged_url`, `fulgur_purged_all` | Actions fired after a purge |

Exclude an element with the `data-fulgur-skip` attribute, or a whole page with `<meta name="fulgur-skip">`.

### Architecture

```
fulgur-cache.php              Bootstrap and autoloader
templates/advanced-cache.php  Drop-in (copied to wp-content)
includes/serve.php            Cache serving before WordPress (no WP dependency)
includes/class-page-cache.php Capture, storage (HTML + .gz + .br), purge
includes/class-optimizer.php  HTML optimization pipeline
includes/class-used-css.php   Critical / unused CSS (PHP CSS parser)
includes/class-assets.php     CSS minification, local Google Fonts
includes/class-media.php      Images, LCP, iframes, YouTube
includes/class-images.php     WebP/AVIF conversion
includes/class-ai*.php        AI optimizer (report, Claude call, verification)
includes/class-metrics.php    Load metrics
includes/class-installer.php  Drop-in, wp-config, .htaccess, Nginx
assets/                       Admin UI (vanilla JS, no build step)
languages/                    Translations (.pot, .po, .mo, .l10n.php, JS .json)
bin/i18n.py                   Translation tooling
```

## Translating

1. Copy `languages/fulgur-cache.pot` to `languages/fulgur-cache-<locale>.po` (e.g. `fulgur-cache-es_ES.po`) and translate it with [Poedit](https://poedit.net/) or any editor.
2. Run `python3 bin/i18n.py compile` to build the `.mo`, `.l10n.php` and JavaScript `.json` files.
3. Open a pull request.

After changing strings in the code, run `python3 bin/i18n.py pot` to refresh the template.

## Contributing

Issues and pull requests are welcome: compatibility with a theme or plugin, translations, tests. Please describe your environment (host, web server, theme, page builder).

---

## 🇫🇷 En français

Fulgur Cache est un plugin de performance WordPress libre et gratuit : cache statique servi avant WordPress (ou sans PHP via .htaccess/Nginx), pré-compression Brotli/gzip, purge intelligente et préchargement, **CSS critique / suppression du CSS inutilisé calculés sur le serveur** (sans service externe), JavaScript différé ou chargé à la première interaction, conversion AVIF/WebP, détection de l'image LCP, pré-rendu Speculation Rules, 103 Early Hints, nettoyage de base de données, CDN/Cloudflare et WP-CLI.

Sa particularité : l'**optimiseur IA**. Avec ta clé API Anthropic, Claude analyse les ressources du serveur, la charge réelle, le HTML et les mesures PageSpeed, puis recommande des réglages justifiés. Fulgur applique ceux que tu valides, relance PageSpeed et **revient automatiquement en arrière** si le score baisse ou si des erreurs JavaScript apparaissent. Pilote automatique hebdomadaire en option.

L'interface s'affiche en français si ton administration WordPress est en français.

Licence : GPL-2.0-or-later.
