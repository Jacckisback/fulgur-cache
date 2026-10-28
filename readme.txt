=== Fulgur Cache ===
Contributors: thierrygustin
Tags: cache, performance, pagespeed, webp, core web vitals
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Cache statique, optimisation CSS/JS, images AVIF/WebP, navigation instantanée (Speculation Rules). Un clic pour un site ultra-rapide.

== Description ==

Fulgur Cache rend un site WordPress ultra-rapide sans configuration technique :

* **Cache de page statique** servi avant WordPress (drop-in), et même sans PHP sur Apache/LiteSpeed (.htaccess) ou Nginx.
* **Pré-compression Brotli et gzip** des pages, en-têtes ETag/304.
* **Purge intelligente** : seule la page modifiée, l'accueil, les catégories, étiquettes, auteur et articles voisins sont vidés, puis re-préchargés aussitôt.
* **Préchargement** de tout le site en arrière-plan : aucun visiteur ne tombe sur une page froide.
* **Navigation instantanée** : Speculation Rules (prerender au survol) + repli léger pour Safari/Firefox.
* **JavaScript** : report (defer) avec ordre préservé, ou chargement à la première interaction.
* **CSS** : minification, font-display: swap, Google Fonts hébergées localement (RGPD).
* **Images** : conversion WebP/AVIF automatique, lazy-load natif, détection et préchargement de l'image LCP, dimensions manquantes (CLS), façades YouTube.
* **103 Early Hints** : préchargement de l'image principale avant même le HTML.
* **Allègement de WordPress** : emojis, embeds, heartbeat, XML-RPC, dashicons, cart-fragments WooCommerce.
* **Base de données** : nettoyage manuel ou hebdomadaire.
* **CDN et Cloudflare** : réécriture des URL et purge automatique.
* **3 profils** (Sûr, Équilibré, Turbo), assistant de démarrage, test de vitesse intégré, score PageSpeed, mode « Tester sans risque », diagnostic avec réparation en un clic, WP-CLI.

== Installation ==

1. Envoyer le dossier `fulgur-cache` dans `wp-content/plugins/` (ou téléverser le zip).
2. Activer le plugin : l'assistant s'ouvre et applique le profil choisi.
3. Désactiver tout autre plugin de cache.

== WP-CLI ==

* `wp fulgur clear [--url=<url>]`
* `wp fulgur preload`
* `wp fulgur status`
* `wp fulgur profile <safe|balanced|turbo>`
* `wp fulgur images`
* `wp fulgur db`

== Filtres pour développeurs ==

* `fulgur_is_cacheable` (bool) — empêcher la mise en cache d'une page.
* `fulgur_optimize_page` (bool) — désactiver les optimisations sur une page.
* `fulgur_html_before` / `fulgur_html_after` (string) — modifier le HTML.
* `fulgur_purge_post_urls` (array, post_id) — URL purgées à la modification d'un contenu.
* `fulgur_preload_urls` (array) — URL préchargées.
* `fulgur_css_exclusions` (array) — CSS à ne pas minifier.
* Balise `<meta name="fulgur-skip">` ou attribut `data-fulgur-skip` sur une balise pour l'exclure.

== Changelog ==

= 1.0.0 =
* Première version.
