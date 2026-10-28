# ⚡ Fulgur Cache

**Le plugin de cache WordPress open source avec un optimiseur IA.**
Cache statique, CSS critique, JavaScript différé, images AVIF/WebP, navigation instantanée — et Claude qui choisit les meilleurs réglages pour *ton* serveur et *ta* charge, puis vérifie qu'ils fonctionnent.

Créé par [Thierry Gustin](https://thierrygustin.com) · Licence GPL-2.0-or-later · Gratuit, sans version « Pro ».

> *English summary below.*

---

## Pourquoi Fulgur ?

| | Fulgur Cache | Plugins de cache classiques |
|---|---|---|
| Cache statique servi sans PHP (.htaccess / Nginx) | ✅ | ✅ |
| Pré-compression Brotli + gzip, ETag / 304 | ✅ | partiel |
| CSS critique / suppression du CSS inutilisé **calculés sur ton serveur** | ✅ (gratuit, sans service externe) | souvent payant ou via service cloud |
| JavaScript à la première interaction, ordre préservé | ✅ | ✅ (versions payantes) |
| Navigation instantanée (Speculation Rules prerender) | ✅ | rare |
| 103 Early Hints | ✅ | rare |
| Conversion AVIF/WebP intégrée | ✅ | plugin séparé |
| **Optimiseur IA** : analyse serveur + charge + PageSpeed, applique, vérifie, annule si régression | ✅ | ❌ |

## Fonctionnalités

**Cache**
- Drop-in `advanced-cache.php` : les pages sont servies avant le chargement de WordPress.
- Règles `.htaccess` (Apache/LiteSpeed) et configuration Nginx générées : service 100 % statique, sans PHP.
- Purge intelligente : seule la page modifiée, l'accueil, ses catégories et étiquettes, l'auteur et les articles voisins sont vidés, puis re-préchargés aussitôt.
- Préchargement de tout le site en arrière-plan, paramètres de tracking (`utm_*`, `fbclid`…) ignorés, cache mobile séparé en option, compatible WooCommerce.

**CSS**
- **CSS critique** : pour chaque page, Fulgur inventorie balises, classes, id et attributs, ne garde que les règles CSS utilisées et les injecte dans le `<head>`. Les feuilles complètes restent à leur place (ordre de la cascade conservé) et se chargent en asynchrone.
- **Suppression du CSS inutilisé** : les feuilles complètes ne se chargent qu'à la première interaction.
- Analyse en PHP pur (`@media`, `@supports`, `@layer`, CSS imbriqué, `:is()`, `:not()`, classes Tailwind échappées), `@font-face` et `@keyframes` inutilisés supprimés. Exemple mesuré : 646 Ko de CSS → 20 Ko utilisés.
- Minification, `font-display: swap`, Google Fonts hébergées localement (RGPD).

**JavaScript**
- Report (`defer`) avec ordre d'exécution préservé (les scripts inline suivent via data-URI, les scripts liés à un script exclu restent avec lui).
- Chargement à la première interaction, avec rejeu de `DOMContentLoaded` et `load`.

**Médias**
- Lazy-load natif, détection de l'image LCP (préchargée, `fetchpriority="high"`), dimensions manquantes ajoutées (CLS), façades YouTube.
- Conversion WebP/AVIF à l'envoi, en masse ou à la volée pour les images du thème.

**Navigation instantanée**
- Speculation Rules (prerender au survol), intégrées aux règles natives de WordPress 6.8+, et repli léger pour Safari/Firefox.

**Allègement de WordPress, base de données, CDN, Cloudflare, WP-CLI.**

## ✦ L'optimiseur IA

1. **Mesures** : configuration du serveur (CPU, RAM, OPcache, PHP, Redis, Brotli…), charge réelle (pages servies depuis le cache, pages générées, temps de génération, pic horaire), WordPress (thème, plugins, constructeur de pages), audit du HTML d'origine, temps de réponse, et Google PageSpeed mobile + desktop (Core Web Vitals, élément LCP, problèmes Lighthouse).
2. **Analyse** : Claude (API Anthropic, sorties JSON structurées) reçoit ce rapport et la documentation de chaque réglage, puis renvoie un diagnostic, des réglages justifiés par une donnée précise et des recommandations d'hébergement.
3. **Validation** : chaque proposition est contrôlée (réglage autorisé, valeur valide). Le cache, le CDN et les clés ne sont jamais modifiables par l'IA.
4. **Application** : tu coches ce que tu veux (les réglages risqués sont décochés par défaut). Les réglages actuels sont sauvegardés.
5. **Vérification** : nouveau test PageSpeed. Si le score baisse, si la page ne s'affiche plus ou si de nouvelles erreurs JavaScript apparaissent, **retour arrière automatique**. L'historique est renvoyé à Claude aux analyses suivantes.

**Pilote automatique** (facultatif) : analyse hebdomadaire avec envoi des recommandations par e-mail, ou application automatique des seuls réglages sans risque.

**Confidentialité** : seules des données techniques sont envoyées (aucun contenu, aucune donnée de visiteur). La clé API reste sur ton serveur ; le plus sûr est de la mettre dans `wp-config.php` :

```php
define( 'FULGUR_ANTHROPIC_API_KEY', 'sk-ant-...' );
define( 'FULGUR_PSI_API_KEY', 'AIza...' ); // facultatif
```

**Coût** : facturé par Anthropic sur ta clé. Environ 0,15 à 0,40 $ par analyse avec Claude Opus 5.5, 0,08 à 0,20 $ avec Sonnet 5.5, environ 0,01 $ avec Haiku 5.5.

## Installation

1. Télécharge le zip, puis dans WordPress : Extensions → Ajouter → Téléverser.
2. Active le plugin : l'assistant applique le profil choisi (Sûr / Équilibré / Turbo) et mesure le gain.
3. Désactive tout autre plugin de cache.
4. Facultatif : onglet **Optimiseur IA** → colle ta clé API Anthropic → « Analyser et optimiser mon site ».

Prérequis : WordPress 6.2+, PHP 7.4+ (8.2+ recommandé), permaliens activés.

## WP-CLI

```bash
wp fulgur clear [--url=<url>]
wp fulgur preload
wp fulgur status
wp fulgur profile <safe|balanced|turbo>
wp fulgur images
wp fulgur db
```

## Pour les développeurs

| Filtre / action | Rôle |
|---|---|
| `fulgur_is_cacheable` | Empêcher la mise en cache d'une page |
| `fulgur_optimize_page` | Désactiver les optimisations sur une page |
| `fulgur_html_before`, `fulgur_html_after` | Modifier le HTML |
| `fulgur_purge_post_urls` | URL purgées à la modification d'un contenu |
| `fulgur_preload_urls` | URL préchargées |
| `fulgur_css_exclusions` | CSS à ne pas minifier |
| `fulgur_purged_url`, `fulgur_purged_all` | Actions après purge |

Exclure un élément : attribut `data-fulgur-skip` sur une balise, ou `<meta name="fulgur-skip">` pour toute la page.

### Architecture

```
fulgur-cache.php              Amorçage et autoloader
templates/advanced-cache.php  Drop-in (copié dans wp-content)
includes/serve.php            Service du cache avant WordPress (sans dépendance WP)
includes/class-page-cache.php Capture, stockage (HTML + .gz + .br), purge
includes/class-optimizer.php  Pipeline d'optimisation du HTML
includes/class-used-css.php   CSS critique / CSS inutilisé (analyseur CSS en PHP)
includes/class-assets.php     Minification CSS, Google Fonts locales
includes/class-media.php      Images, LCP, iframes, YouTube
includes/class-images.php     Conversion WebP/AVIF
includes/class-ai*.php        Optimiseur IA (rapport, appel Claude, vérification)
includes/class-metrics.php    Mesure de charge
includes/class-installer.php  Drop-in, wp-config, .htaccess, Nginx
assets/                       Interface d'administration (JS vanilla, sans build)
```

## Contribuer

Les issues et pull requests sont bienvenues : compatibilité avec un thème ou un plugin, traductions, tests. Merci de décrire l'environnement (hébergeur, serveur web, thème, constructeur de pages).

---

## English summary

Fulgur Cache is a free, open-source WordPress performance plugin: static page cache served before WordPress (or without PHP via .htaccess/Nginx), Brotli/gzip pre-compression, smart purge and preload, **on-server critical CSS / unused CSS removal** (no external service), JavaScript defer or delay-until-interaction, AVIF/WebP conversion, LCP detection, Speculation Rules prerendering, 103 Early Hints, database cleanup, CDN/Cloudflare and WP-CLI.

Its unique feature is the **AI optimizer**: connect your Anthropic API key, and Claude analyzes your server resources, real traffic load, HTML and Google PageSpeed data, then recommends settings with a justification for each. Fulgur applies the ones you approve, re-runs PageSpeed and **automatically rolls back** if the score drops or new JavaScript errors appear. An optional weekly autopilot is included. The admin UI is currently in French; English translation is welcome.

License: GPL-2.0-or-later.
