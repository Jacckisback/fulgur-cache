=== Fulgur Cache ===
Contributors: thierrygustin
Tags: cache, performance, pagespeed, critical css, ai
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Cache statique, CSS critique, optimiseur IA (Claude), JS différé, images AVIF/WebP, navigation instantanée. Open source.

== Description ==

Fulgur Cache rend un site WordPress ultra-rapide sans configuration technique :

* **Cache de page statique** servi avant WordPress (drop-in), et même sans PHP sur Apache/LiteSpeed (.htaccess) ou Nginx.
* **Pré-compression Brotli et gzip** des pages, en-têtes ETag/304.
* **Purge intelligente** : seule la page modifiée, l'accueil, les catégories, étiquettes, auteur et articles voisins sont vidés, puis re-préchargés aussitôt.
* **Préchargement** de tout le site en arrière-plan : aucun visiteur ne tombe sur une page froide.
* **Navigation instantanée** : Speculation Rules (prerender au survol) + repli léger pour Safari/Firefox.
* **JavaScript** : report (defer) avec ordre préservé, ou chargement à la première interaction.
* **CSS critique / suppression du CSS inutilisé** calculés sur le serveur, sans service externe.
* **Optimiseur IA (Claude)** : analyse serveur, charge et PageSpeed, applique les meilleurs réglages, vérifie et annule automatiquement en cas de régression.
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

== Services externes ==

Fulgur Cache ne contacte aucun service externe par défaut. Selon les fonctions activées :

* **API Anthropic (Claude)** — uniquement si une clé API est renseignée dans l'onglet Optimiseur IA. Données envoyées : rapport technique (configuration serveur, charge, liste des plugins actifs, réglages, mesures PageSpeed). Aucun contenu ni donnée de visiteur. Conditions : https://www.anthropic.com/legal/commercial-terms — Confidentialité : https://www.anthropic.com/legal/privacy
* **Google PageSpeed Insights** — lors d'une analyse IA ou du bouton « Score PageSpeed ». L'URL publique de la page d'accueil est envoyée. https://policies.google.com/privacy
* **Google Fonts** — en mode « Héberger localement », les fichiers de police sont téléchargés une fois puis servis depuis le site.
* **Cloudflare** — si un jeton API est renseigné, pour vider le cache Cloudflare. https://www.cloudflare.com/privacypolicy/

== Changelog ==

= 1.1.0 =
* CSS critique et suppression du CSS inutilisé (analyseur CSS en PHP).
* Optimiseur IA (Claude) avec vérification PageSpeed et retour arrière automatique.
* Pilote automatique hebdomadaire.
* Mesure de la charge (pages servies depuis le cache, pages générées).


= 1.0.0 =
* Première version.
