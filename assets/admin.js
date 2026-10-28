/* Fulgur Cache — interface d'administration (vanilla JS, aucune dépendance). */
(function () {
	'use strict';

	var D = window.FulgurData;
	var app = document.getElementById('fulgur-app');
	var state = D.state;
	var draft = clone(state.settings);
	var tab = readTab();
	var dbData = null;

	/* ------------------------------------------------------------------ */
	/* Schéma des réglages                                                 */
	/* ------------------------------------------------------------------ */

	var RISK = {
		safe: ['Sans risque', 'safe'],
		moderate: ['À tester', 'moderate'],
		advanced: ['Avancé', 'advanced']
	};

	var TABS = [
		{ id: 'dashboard', icon: '◎', label: 'Tableau de bord' },
		{ id: 'ai', icon: '✦', label: 'Optimiseur IA', sections: [
			{ title: 'Analyse et optimisation par Claude', fields: [], custom: 'aiRun' },
			{ title: 'Connexion à Claude', fields: [
				{ key: 'ai_model', type: 'select', label: 'Modèle', options: { 'claude-opus-5-5': 'Claude Opus 5.5 — le plus précis (≈ 0,15 à 0,40 $ / analyse)', 'claude-sonnet-5-5': 'Claude Sonnet 5.5 — équilibré (≈ 0,08 à 0,20 $)', 'claude-haiku-5-5': 'Claude Haiku 5.5 — économique (≈ 0,01 $)' }, help: 'Facturé directement par Anthropic sur ta clé API. Une analyse par semaine suffit.' },
				{ key: 'ai_psi', type: 'toggle', label: 'Inclure les mesures Google PageSpeed', help: 'Claude reçoit les vrais Core Web Vitals (LCP, TBT, CLS) et les problèmes détectés par Lighthouse. Ajoute 1 à 2 minutes à l\'analyse.', risk: 'safe' },
				{ key: 'ai_auto', type: 'select', label: 'Pilote automatique', options: { off: 'Désactivé', suggest: 'Analyse chaque semaine et m\'envoie les recommandations par e-mail', auto: 'Analyse et applique chaque semaine les réglages sans risque (annulation auto si le score baisse)' } }
			], custom: 'aiKey' },
			{ title: 'Historique', fields: [], custom: 'aiHistory' }
		] },
		{ id: 'cache', icon: '⚡', label: 'Cache', sections: [
			{ title: 'Cache de page', fields: [
				{ key: 'page_cache', type: 'toggle', label: 'Activer le cache de page', help: 'Chaque page est générée une seule fois puis servie en HTML statique, sans PHP ni base de données. C\'est le réglage qui apporte le plus de vitesse.', risk: 'safe' },
				{ key: 'cache_lifespan', type: 'number', label: 'Durée de vie du cache', suffix: 'heures', help: 'Les pages sont régénérées après ce délai. 0 = jamais (la purge automatique suffit dans 99 % des cas).', min: 0 },
				{ key: 'browser_cache', type: 'toggle', label: 'Compression et cache navigateur', help: 'Brotli/gzip + mise en cache d\'un an des images, CSS, JS et polices dans le navigateur du visiteur.', risk: 'safe' },
				{ key: 'cache_mobile', type: 'toggle', label: 'Cache séparé pour mobile', help: 'Uniquement si ton thème génère un HTML différent sur mobile (rare). Laisse désactivé pour un thème responsive.' },
				{ key: 'cache_404', type: 'toggle', label: 'Mettre en cache les pages 404', help: 'Protège le serveur contre les robots qui testent des URL inexistantes.' }
			] },
			{ title: 'Exclusions', fields: [
				{ key: 'exclude_urls', type: 'textarea', label: 'URL jamais mises en cache', help: 'Une par ligne. Accepte une partie d\'URL (/panier/), un joker (/compte/*) ou une regex (#^/prive#).' },
				{ key: 'ignored_query_params', type: 'textarea', label: 'Paramètres d\'URL ignorés', help: 'Les liens de campagne (utm_source, fbclid…) servent la même page en cache au lieu de la régénérer.' },
				{ key: 'exclude_cookies', type: 'textarea', label: 'Cookies qui désactivent le cache', help: 'Un nom (ou début de nom) par ligne. Les cookies de connexion et de panier WooCommerce sont déjà gérés.' },
				{ key: 'exclude_user_agents', type: 'textarea', label: 'Navigateurs / robots exclus', help: 'Une partie du User-Agent par ligne.' }
			] }
		] },
		{ id: 'files', icon: '{ }', label: 'CSS & JavaScript', sections: [
			{ title: 'HTML & CSS', fields: [
				{ key: 'minify_html', type: 'toggle', label: 'Minifier le HTML', help: 'Supprime commentaires et espaces inutiles.', risk: 'safe' },
				{ key: 'minify_css', type: 'toggle', label: 'Minifier le CSS', help: 'Fichiers CSS allégés et mis en cache d\'un an.', risk: 'safe' },
				{ key: 'font_display_swap', type: 'toggle', label: 'Texte visible pendant le chargement des polices', help: 'Ajoute font-display: swap : le texte s\'affiche immédiatement avec une police système.', risk: 'safe' },
				{ key: 'css_mode', type: 'select', label: 'CSS critique / CSS inutilisé', options: { off: 'Désactivé', critical: 'CSS critique : CSS utile en ligne, le reste en asynchrone (recommandé)', remove: 'Supprimer le CSS inutilisé : le reste n\'est chargé qu\'à la première interaction' }, help: 'Chaque page reçoit uniquement les règles CSS qu\'elle utilise, directement dans le HTML : plus aucun CSS ne bloque l\'affichage. Calculé sur ton serveur, sans service externe.' },
				{ key: 'css_safelist', type: 'textarea', label: 'Classes CSS toujours conservées', help: 'Classes ajoutées par JavaScript (menus ouverts, sliders, pop-ups). Une partie de nom par ligne.', show: 'css_mode' },
				{ key: 'google_fonts', type: 'select', label: 'Google Fonts', options: { off: 'Ne rien changer', swap: 'Optimiser (swap + préconnexion)', local: 'Héberger localement (RGPD + plus rapide)' }, help: 'L\'hébergement local supprime la connexion à Google : plus rapide et conforme RGPD.' }
			] },
			{ title: 'JavaScript', fields: [
				{ key: 'defer_js', type: 'toggle', label: 'Reporter le JavaScript (defer)', help: 'Les scripts s\'exécutent après l\'affichage de la page, dans leur ordre d\'origine.', risk: 'moderate' },
				{ key: 'defer_exclusions', type: 'textarea', label: 'Scripts à ne pas reporter', help: 'Une partie d\'URL ou de code par ligne.', show: 'defer_js' },
				{ key: 'delay_js', type: 'toggle', label: 'Charger le JavaScript à la première interaction', help: 'Le gain le plus spectaculaire sur PageSpeed : aucun script ne s\'exécute avant que le visiteur bouge la souris, touche l\'écran ou fasse défiler. Teste ton site après activation.', risk: 'advanced' },
				{ key: 'delay_timeout', type: 'number', label: 'Chargement forcé après', suffix: 'secondes', help: '0 = uniquement à l\'interaction. Mets 5 à 10 s si tu as des scripts de statistiques.', min: 0, show: 'delay_js' },
				{ key: 'delay_exclusions', type: 'textarea', label: 'Scripts à charger immédiatement', help: 'Une partie d\'URL ou de code par ligne (ex. : bandeau cookies, slider en haut de page).', show: 'delay_js' }
			] }
		] },
		{ id: 'media', icon: '▣', label: 'Médias', sections: [
			{ title: 'Chargement des images', fields: [
				{ key: 'lazy_images', type: 'toggle', label: 'Chargement différé des images', help: 'Les images hors écran ne sont chargées qu\'à l\'approche du défilement (lazy-load natif du navigateur).', risk: 'safe' },
				{ key: 'eager_images', type: 'number', label: 'Images du haut de page jamais différées', suffix: 'images', help: 'La plus grande image visible (LCP) est détectée automatiquement, préchargée et prioritaire.', min: 0 },
				{ key: 'image_dimensions', type: 'toggle', label: 'Ajouter les dimensions manquantes', help: 'Supprime les décalages de mise en page (CLS).', risk: 'safe' },
				{ key: 'lazy_iframes', type: 'toggle', label: 'Chargement différé des iframes', help: 'Cartes, formulaires, vidéos intégrées.', risk: 'safe' },
				{ key: 'youtube_facade', type: 'toggle', label: 'Façade YouTube', help: 'Affiche une vignette à la place du lecteur (−1 Mo par vidéo). Le lecteur se charge au clic.', risk: 'safe' }
			] },
			{ title: 'Formats nouvelle génération', fields: [
				{ key: 'next_gen_images', type: 'toggle', label: 'Convertir et servir en WebP / AVIF', help: 'Images 30 à 50 % plus légères, sans perte visible. Les originaux sont conservés.', risk: 'safe' },
				{ key: 'image_format', type: 'select', label: 'Format', options: { webp: 'WebP (recommandé, aucun impact sur le thème)', avif: 'AVIF + WebP (le plus léger, via <picture>)' }, show: 'next_gen_images' },
				{ key: 'image_quality', type: 'range', label: 'Qualité', min: 50, max: 95, show: 'next_gen_images', help: '78 est le meilleur compromis poids / qualité.' }
			], custom: 'bulkImages' }
		] },
		{ id: 'preload', icon: '➜', label: 'Préchargement', sections: [
			{ title: 'Cache toujours chaud', fields: [
				{ key: 'preload_cache', type: 'toggle', label: 'Précharger le cache', help: 'Après chaque purge, toutes les pages sont régénérées en arrière-plan : aucun visiteur ne tombe sur une page lente.', risk: 'safe' }
			], custom: 'preloadStatus' },
			{ title: 'Navigation instantanée', fields: [
				{ key: 'speculation', type: 'select', label: 'Speculation Rules', options: { off: 'Désactivé', prefetch: 'Prefetch (télécharge la page suivante)', prerender: 'Prerender (affiche la page suivante instantanément)' }, help: 'Technologie native de Chrome/Edge : la page suivante est préparée pendant que le visiteur survole le lien. Clic = affichage en 0 ms.' },
				{ key: 'speculation_eagerness', type: 'select', label: 'Déclenchement', options: { conservative: 'Au clic (économe)', moderate: 'Au survol (recommandé)', eager: 'Dès que possible (gourmand)' }, show: 'speculation' },
				{ key: 'instant_fallback', type: 'toggle', label: 'Préchargement au survol pour Safari et Firefox', help: 'Équivalent léger (1 Ko) pour les navigateurs sans Speculation Rules.', risk: 'safe' },
				{ key: 'early_hints', type: 'toggle', label: 'En-têtes de préchargement (103 Early Hints)', help: 'Le navigateur commence à télécharger l\'image principale et les polices avant même de recevoir le HTML (Cloudflare, LiteSpeed).', risk: 'safe' },
				{ key: 'preconnect_domains', type: 'textarea', label: 'Domaines à préconnecter', help: 'Domaines tiers critiques, un par ligne (ex. : cdn.exemple.com).' }
			] }
		] },
		{ id: 'wordpress', icon: 'W', label: 'Allègement WP', sections: [
			{ title: 'Ce que WordPress charge inutilement', fields: [
				{ key: 'disable_emojis', type: 'toggle', label: 'Désactiver les emojis WordPress', help: 'Les navigateurs affichent les emojis nativement. −1 requête.', risk: 'safe' },
				{ key: 'disable_embeds', type: 'toggle', label: 'Désactiver les embeds WordPress', help: 'Retire wp-embed.js. Les vidéos YouTube, tweets, etc. fonctionnent toujours.', risk: 'safe' },
				{ key: 'dashicons_visitors', type: 'toggle', label: 'Retirer les Dashicons pour les visiteurs', help: 'Police d\'icônes de l\'admin (46 Ko) inutile sur le site public.', risk: 'safe' },
				{ key: 'clean_head', type: 'toggle', label: 'Nettoyer l\'en-tête HTML', help: 'Retire RSD, WLW, shortlink et la version de WordPress (sécurité).', risk: 'safe' },
				{ key: 'disable_xmlrpc', type: 'toggle', label: 'Désactiver XML-RPC', help: 'Ferme une porte d\'entrée des attaques par force brute. Désactive si tu utilises l\'app mobile WordPress ou Jetpack.', risk: 'safe' },
				{ key: 'remove_jquery_migrate', type: 'toggle', label: 'Retirer jQuery Migrate', help: 'Inutile avec les thèmes et plugins à jour.', risk: 'moderate' },
				{ key: 'heartbeat', type: 'select', label: 'API Heartbeat', options: { 'default': 'Par défaut (15 s)', reduce: 'Réduite (60 s)', disable_front: 'Désactivée sur le site public', disable: 'Désactivée partout sauf l\'éditeur' }, help: 'Requêtes AJAX régulières qui chargent le serveur.' },
				{ key: 'wc_cart_fragments', type: 'toggle', label: 'WooCommerce : désactiver cart-fragments hors boutique', help: 'Supprime une requête AJAX non cachable sur toutes les pages hors boutique.', risk: 'moderate' }
			] }
		] },
		{ id: 'database', icon: '◫', label: 'Base de données', sections: [
			{ title: 'Nettoyage', fields: [], custom: 'database' },
			{ title: 'Automatisation', fields: [
				{ key: 'db_auto_clean', type: 'toggle', label: 'Nettoyage automatique hebdomadaire', help: 'Révisions, brouillons auto, spam, corbeille, transients expirés.', risk: 'safe' },
				{ key: 'db_revisions_keep', type: 'number', label: 'Révisions conservées par article', suffix: 'révisions', min: 0 }
			] }
		] },
		{ id: 'cdn', icon: '☁', label: 'CDN', sections: [
			{ title: 'CDN', fields: [
				{ key: 'cdn_enabled', type: 'toggle', label: 'Servir les fichiers statiques via un CDN', help: 'Images, CSS, JS et polices sont servis depuis le CDN (BunnyCDN, KeyCDN…).' },
				{ key: 'cdn_url', type: 'text', label: 'URL du CDN', placeholder: 'https://cdn.monsite.com', show: 'cdn_enabled' },
				{ key: 'cdn_exclusions', type: 'textarea', label: 'Fichiers exclus du CDN', show: 'cdn_enabled' }
			] },
			{ title: 'Cloudflare', fields: [
				{ key: 'cloudflare_token', type: 'password', label: 'Jeton API', placeholder: 'Permission : Zone › Cache Purge', help: 'Le cache Cloudflare est vidé automatiquement en même temps que celui du site.' },
				{ key: 'cloudflare_zone', type: 'text', label: 'ID de zone', placeholder: 'Visible dans l\'aperçu du domaine Cloudflare' }
			], custom: 'cloudflare' }
		] },
		{ id: 'tools', icon: '⚙', label: 'Outils', sections: [
			{ title: 'Diagnostic complet', fields: [], custom: 'diagnostics' },
			{ title: 'Configuration Nginx', fields: [], custom: 'nginx' },
			{ title: 'Sauvegarde des réglages', fields: [], custom: 'importExport' }
		] }
	];

	/* ------------------------------------------------------------------ */
	/* Utilitaires                                                         */
	/* ------------------------------------------------------------------ */

	function clone(o) { return JSON.parse(JSON.stringify(o)); }

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function readTab() {
		var h = (location.hash || '').replace('#', '');
		return h || 'dashboard';
	}

	function size(bytes) {
		if (!bytes) return '0 Ko';
		var u = ['o', 'Ko', 'Mo', 'Go'], i = Math.min(3, Math.floor(Math.log(bytes) / Math.log(1024)));
		return (bytes / Math.pow(1024, i)).toFixed(i > 1 ? 1 : 0).replace('.', ',') + ' ' + u[i];
	}

	function dirty() { return JSON.stringify(draft) !== JSON.stringify(state.settings); }

	function api(path, method, body) {
		return fetch(D.rest + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': D.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) {
			return r.json().then(function (data) {
				if (!r.ok) throw new Error(data && data.message ? data.message : 'Erreur ' + r.status);
				return data;
			});
		});
	}

	function setState(s) {
		var keepDraft = dirty();
		state = s;
		if (!keepDraft) draft = clone(s.settings);
		render();
	}

	var toastTimer;
	function toast(msg, type) {
		var t = document.getElementById('fulgur-toast');
		if (!t) {
			t = document.createElement('div');
			t.id = 'fulgur-toast';
			t.setAttribute('role', 'status');
			document.body.appendChild(t);
		}
		t.className = 'fulgur-toast is-' + (type || 'ok') + ' is-visible';
		t.textContent = msg;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { t.className = t.className.replace(' is-visible', ''); }, 3800);
	}

	function busy(btn, on, label) {
		if (!btn) return;
		if (on) { btn.dataset.label = btn.innerHTML; btn.innerHTML = '<span class="fulgur-spin"></span>' + (label || 'En cours…'); btn.disabled = true; }
		else { btn.innerHTML = btn.dataset.label || btn.innerHTML; btn.disabled = false; }
	}

	/* ------------------------------------------------------------------ */
	/* Rendu                                                               */
	/* ------------------------------------------------------------------ */

	function render() {
		var s = state.settings;
		var health = healthScore();
		var html = '';

		html += '<header class="fulgur-header">' +
			'<div class="fulgur-brand"><span class="fulgur-logo">⚡</span><div><h1>Fulgur Cache</h1><span class="fulgur-version">v' + esc(D.version) + '</span></div></div>' +
			'<div class="fulgur-status ' + (s.page_cache ? 'is-on' : 'is-off') + '"><span class="dot"></span>' + (s.page_cache ? 'Cache actif · ' + state.usage.pages + ' page' + (state.usage.pages > 1 ? 's' : '') : 'Cache désactivé') + '</div>' +
			'<div class="fulgur-actions">' +
			'<a class="fulgur-btn is-ghost" href="' + esc(D.preview) + '" target="_blank" rel="noopener" title="Voir la page d\'accueil avec les optimisations, sans rien mettre en cache">Tester sans risque ↗</a>' +
			'<button class="fulgur-btn is-secondary" data-action="preload">Précharger</button>' +
			'<button class="fulgur-btn is-primary" data-action="purge">Vider le cache</button>' +
			'</div></header>';

		html += '<div class="fulgur-layout"><nav class="fulgur-nav" aria-label="Sections">';
		TABS.forEach(function (t) {
			var badge = t.id === 'tools' && health.errors ? '<span class="fulgur-badge-count">' + health.errors + '</span>' : '';
			html += '<a href="#' + t.id + '" class="' + (tab === t.id ? 'is-active' : '') + '" data-tab="' + t.id + '"><span class="ico" aria-hidden="true">' + t.icon + '</span>' + esc(t.label) + badge + '</a>';
		});
		html += '</nav><main class="fulgur-main">';

		var current = TABS.filter(function (t) { return t.id === tab; })[0] || TABS[0];
		html += current.id === 'dashboard' ? dashboard(health) : settingsTab(current);

		html += '</main></div>';

		html += '<div class="fulgur-savebar ' + (dirty() ? 'is-visible' : '') + '"><span>Modifications non enregistrées</span>' +
			'<button class="fulgur-btn is-ghost" data-action="discard">Annuler</button>' +
			'<button class="fulgur-btn is-primary" data-action="save">Enregistrer et appliquer</button></div>';

		if (!s.onboarding_done) html += onboarding();

		app.innerHTML = html;
	}

	function healthScore() {
		var errors = 0, warnings = 0, ok = 0;
		state.diagnostics.forEach(function (d) {
			if (d.status === 'error') errors++;
			else if (d.status === 'warning') warnings++;
			else if (d.status === 'ok') ok++;
		});
		return { errors: errors, warnings: warnings, ok: ok };
	}

	function dashboard(health) {
		var st = state.stats, h = '';
		var speedup = st.speed_before && st.speed_after ? Math.max(1, st.speed_before / st.speed_after) : 0;

		h += '<section class="fulgur-hero">' +
			'<div><h2>' + (speedup ? 'Ton site répond <strong>' + speedup.toFixed(1).replace('.', ',') + '× plus vite</strong>' : 'Mesure le gain de vitesse de ton site') + '</h2>' +
			'<p>' + (speedup ? 'Temps de réponse serveur : ' + st.speed_before + ' ms sans cache → <strong>' + st.speed_after + ' ms</strong> avec Fulgur.' : 'Le test compare le temps de réponse de ta page d\'accueil sans cache et avec cache.') + '</p></div>' +
			'<div class="fulgur-hero-actions"><button class="fulgur-btn is-light" data-action="speedtest">Lancer le test serveur</button>' +
			'<button class="fulgur-btn is-light" data-action="pagespeed">Score PageSpeed mobile</button>' +
			'<a class="fulgur-btn is-gold" href="#ai" data-tab="ai">✦ Optimiser avec l\'IA</a></div>' +
			'<div id="fulgur-psi" class="fulgur-psi"></div></section>';

		h += '<div class="fulgur-kpis">' +
			kpi('Pages en cache', state.usage.pages, size(state.usage.size)) +
			kpi('Pages préchargées', st.preloaded, state.preload.pending ? state.preload.pending + ' en file d\'attente' : 'File vide') +
			kpi('Images', state.images.total, state.images.webp ? (state.images.avif ? 'WebP + AVIF disponibles' : 'WebP disponible') : 'Conversion indisponible') +
			kpi('Santé', health.errors ? health.errors + ' problème' + (health.errors > 1 ? 's' : '') : 'Parfaite', health.errors ? 'Voir le diagnostic' : health.ok + ' vérifications OK', health.errors ? 'is-bad' : 'is-good') +
			'</div>';

		h += '<section class="fulgur-card"><div class="fulgur-card-head"><h3>Niveau d\'optimisation</h3><p>Un clic suffit. Tu peux ensuite affiner chaque réglage.</p></div>' + profiles(false) + '</section>';

		var issues = state.diagnostics.filter(function (d) { return d.status === 'error' || d.status === 'warning'; });
		if (issues.length) {
			h += '<section class="fulgur-card"><div class="fulgur-card-head"><h3>À corriger</h3></div>' + checks(issues) + '</section>';
		}
		return h;
	}

	function kpi(label, value, sub, cls) {
		return '<div class="fulgur-kpi ' + (cls || '') + '"><span class="label">' + esc(label) + '</span><span class="value">' + esc(value) + '</span><span class="sub">' + esc(sub) + '</span></div>';
	}

	function profiles(onboarding) {
		var h = '<div class="fulgur-profiles">';
		Object.keys(state.profiles).forEach(function (id) {
			var p = state.profiles[id];
			var active = state.settings.profile === id;
			h += '<button class="fulgur-profile ' + (active ? 'is-active' : '') + (id === 'balanced' ? ' is-recommended' : '') + '" data-action="profile" data-profile="' + id + '"' + (onboarding ? ' data-onboarding="1"' : '') + '>' +
				(id === 'balanced' ? '<span class="ribbon">Recommandé</span>' : '') +
				'<span class="name">' + esc(p.label) + '</span><span class="gain">' + esc(p.gain) + '</span><span class="desc">' + esc(p.description) + '</span>' +
				(active ? '<span class="check">✓ Actif</span>' : '') + '</button>';
		});
		h += '</div>';
		if (!onboarding && state.settings.profile === 'custom') h += '<p class="fulgur-note">Réglages personnalisés actifs.</p>';
		if (!onboarding && state.settings.profile === 'ai') h += '<p class="fulgur-note">✦ Réglages optimisés par l\'IA pour ton serveur.</p>';
		return h;
	}

	function checks(list) {
		var icons = { ok: '✓', warning: '!', error: '✕', info: 'i' };
		var h = '<ul class="fulgur-checks">';
		list.forEach(function (c) {
			h += '<li class="is-' + c.status + '"><span class="ico">' + icons[c.status] + '</span><div><strong>' + esc(c.title) + '</strong>' + (c.detail ? '<p>' + esc(c.detail) + '</p>' : '') + '</div>' +
				(c.action === 'repair' ? '<button class="fulgur-btn is-small" data-action="repair">Réparer</button>' : '') +
				(c.action === 'nginx' ? '<a class="fulgur-btn is-small is-ghost" href="#tools" data-tab="tools">Voir</a>' : '') + '</li>';
		});
		return h + '</ul>';
	}

	function settingsTab(t) {
		var h = '<div class="fulgur-tab-head"><h2>' + esc(t.label) + '</h2></div>';
		t.sections.forEach(function (sec) {
			h += '<section class="fulgur-card"><div class="fulgur-card-head"><h3>' + esc(sec.title) + '</h3></div>';
			sec.fields.forEach(function (f) {
				if (f.show && (!draft[f.show] || draft[f.show] === 'off')) return;
				h += field(f);
			});
			if (sec.custom) h += CUSTOM[sec.custom]();
			h += '</section>';
		});
		return h;
	}

	function field(f) {
		var v = draft[f.key], id = 'fulgur-f-' + f.key, input = '';
		var risk = f.risk ? '<span class="fulgur-risk is-' + RISK[f.risk][1] + '">' + RISK[f.risk][0] + '</span>' : '';
		switch (f.type) {
			case 'toggle':
				input = '<label class="fulgur-switch"><input type="checkbox" id="' + id + '" data-key="' + f.key + '"' + (v ? ' checked' : '') + '><span></span></label>';
				break;
			case 'number':
				input = '<div class="fulgur-number"><input type="number" id="' + id + '" data-key="' + f.key + '" value="' + esc(v) + '" min="' + (f.min || 0) + '">' + (f.suffix ? '<span>' + esc(f.suffix) + '</span>' : '') + '</div>';
				break;
			case 'range':
				input = '<div class="fulgur-range"><input type="range" id="' + id + '" data-key="' + f.key + '" value="' + esc(v) + '" min="' + f.min + '" max="' + f.max + '"><output>' + esc(v) + '</output></div>';
				break;
			case 'select':
				input = '<select id="' + id + '" data-key="' + f.key + '">' + Object.keys(f.options).map(function (k) {
					return '<option value="' + esc(k) + '"' + (String(v) === k ? ' selected' : '') + '>' + esc(f.options[k]) + '</option>';
				}).join('') + '</select>';
				break;
			case 'textarea':
				input = '<textarea id="' + id + '" data-key="' + f.key + '" rows="4" spellcheck="false">' + esc(v) + '</textarea>';
				break;
			default:
				input = '<input type="' + (f.type === 'password' ? 'password' : 'text') + '" id="' + id + '" data-key="' + f.key + '" value="' + esc(v) + '" placeholder="' + esc(f.placeholder || '') + '" autocomplete="off">';
		}
		var wide = f.type === 'textarea' || f.type === 'text' || f.type === 'password' || f.type === 'select';
		return '<div class="fulgur-field' + (wide ? ' is-wide' : '') + '"><div class="fulgur-field-text"><label for="' + id + '">' + esc(f.label) + risk + '</label>' +
			(f.help ? '<p>' + esc(f.help) + '</p>' : '') + '</div><div class="fulgur-field-input">' + input + '</div></div>';
	}

	var STAGES = [['collect', 'Mesures'], ['analyze', 'Analyse Claude'], ['ready', 'Recommandations'], ['apply', 'Application'], ['verify', 'Vérification']];
	var SEVERITY = { critical: 'Critique', high: 'Important', medium: 'Moyen', low: 'Mineur' };
	var aiSelected = null;

	function labelFor(key) {
		var found = key;
		TABS.forEach(function (t) { (t.sections || []).forEach(function (sec) { sec.fields.forEach(function (f) { if (f.key === key) found = f.label; }); }); });
		return found;
	}

	function aiBusy() { var j = state.ai.job; return j.status === 'queued' || j.status === 'running'; }

	function fmtValue(v) {
		if (v === true) return 'activé';
		if (v === false) return 'désactivé';
		var s = String(v);
		return s.indexOf('\n') > -1 ? s.split('\n').filter(Boolean).length + ' entrées' : s;
	}

	var CUSTOM = {
		aiRun: function () {
			var ai = state.ai, job = ai.job, h = '';
			if (!ai.has_key) {
				return '<div class="fulgur-ai-intro"><p><strong>Claude analyse ton serveur, la charge réelle, le HTML et les mesures PageSpeed, puis choisit les meilleurs réglages pour ton site.</strong> Fulgur les applique, re-mesure et annule automatiquement si le score baisse ou si une erreur apparaît.</p>' +
					'<p>Pour commencer, colle ta clé API Anthropic plus bas (console.anthropic.com → API Keys).</p></div>';
			}
			var idx = -1;
			STAGES.forEach(function (st, i) { if (st[0] === job.stage) idx = i; });
			if (job.stage === 'done') idx = STAGES.length;
			if (job.stage !== 'idle') {
				h += '<ol class="fulgur-stepper">' + STAGES.map(function (st, i) {
					var cls = i < idx ? 'is-done' : (i === idx ? (job.stage === 'error' ? 'is-error' : (aiBusy() ? 'is-running' : 'is-done')) : '');
					return '<li class="' + cls + '"><span></span>' + st[1] + '</li>';
				}).join('') + '</ol>';
				h += '<p class="fulgur-ai-msg ' + (job.stage === 'error' ? 'is-error' : '') + '">' + (aiBusy() ? '<span class="fulgur-spin"></span> ' : '') + esc(job.message) + '</p>';
			}
			if (!aiBusy()) {
				h += '<div class="fulgur-inline"><div><strong>' + (job.stage === 'idle' ? 'Prêt pour une première analyse' : 'Nouvelle analyse') + '</strong><p>Durée : 1 à 4 minutes. Seules des données techniques sont envoyées (aucun contenu, aucune donnée personnelle).</p></div>' +
					'<button class="fulgur-btn is-primary" data-action="ai-start">✦ Analyser et optimiser mon site</button></div>';
			}
			var r = job.result;
			if (r && (job.stage === 'ready' || job.stage === 'done' || job.stage === 'apply' || job.stage === 'verify')) {
				h += '<div class="fulgur-ai-result"><p class="fulgur-ai-summary">' + esc(r.summary) + '</p>' +
					(r.expected_gain ? '<p class="fulgur-ai-gain">Gain attendu : <strong>' + esc(r.expected_gain) + '</strong></p>' : '');
				if (r.diagnosis && r.diagnosis.length) {
					h += '<h4>Diagnostic</h4><ul class="fulgur-diag">' + r.diagnosis.map(function (d) {
						return '<li class="is-' + esc(d.severity) + '"><span class="sev">' + esc(SEVERITY[d.severity] || d.severity) + '</span><div><strong>' + esc(d.title) + '</strong><p>' + esc(d.detail) + '</p></div></li>';
					}).join('') + '</ul>';
				}
				if (r.changes && r.changes.length) {
					if (aiSelected === null) aiSelected = r.changes.filter(function (c) { return c.risk !== 'advanced'; }).map(function (c) { return c.key; });
					var editable = job.stage === 'ready';
					h += '<h4>Réglages proposés</h4><ul class="fulgur-changes">' + r.changes.map(function (c) {
						var checked = editable ? aiSelected.indexOf(c.key) > -1 : (job.selected || []).indexOf(c.key) > -1;
						return '<li><label><input type="checkbox" data-ai-key="' + esc(c.key) + '"' + (checked ? ' checked' : '') + (editable ? '' : ' disabled') + '>' +
							'<div><strong>' + esc(labelFor(c.key)) + '</strong> <code class="fulgur-key">' + esc(c.key) + '</code> <span class="fulgur-risk is-' + esc(c.risk) + '">' + RISK[c.risk][0] + '</span>' +
							'<span class="fulgur-diff">' + esc(fmtValue(c.current)) + ' → <b>' + esc(fmtValue(c.value)) + '</b></span>' +
							'<p>' + esc(c.reason) + (c.impact ? ' <em>' + esc(c.impact) + '</em>' : '') + '</p></div></label></li>';
					}).join('') + '</ul>';
					if (editable) h += '<div class="fulgur-inline"><p>Les réglages actuels sont sauvegardés : un clic suffit pour revenir en arrière.</p><button class="fulgur-btn is-primary" data-action="ai-apply">Appliquer et vérifier</button></div>';
				} else {
					h += '<p class="fulgur-note">Aucun changement nécessaire : ta configuration est déjà optimale pour ce serveur.</p>';
				}
				if (r.server_recommendations && r.server_recommendations.length) {
					h += '<h4>Côté hébergement</h4><ul class="fulgur-server">' + r.server_recommendations.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('') + '</ul>';
				}
				if (r.usage) h += '<p class="fulgur-note">Modèle : ' + esc(r.model) + ' · ' + r.usage.input + ' jetons envoyés, ' + r.usage.output + ' reçus.</p>';
				h += '</div>';
			}
			if (job.verify) {
				var v = job.verify;
				h += '<div class="fulgur-verify ' + (v.rolled_back ? 'is-bad' : 'is-good') + '">' +
					(v.score_before != null && v.score_after != null ? '<span class="scores">' + v.score_before + ' → <b>' + v.score_after + '</b></span>' : '') +
					'<p>' + esc(v.message) + '</p></div>';
			}
			if (ai.can_undo && !aiBusy()) {
				h += '<div class="fulgur-inline"><p>Les derniers réglages appliqués par l\'IA peuvent être annulés.</p><button class="fulgur-btn is-danger" data-action="ai-undo">Revenir aux réglages précédents</button></div>';
			}
			return h;
		},
		aiKey: function () {
			var ai = state.ai;
			var h = '<div class="fulgur-field is-wide"><div class="fulgur-field-text"><label for="fulgur-ai-key">Clé API Anthropic</label><p>' +
				(ai.key_locked ? 'Définie dans wp-config.php (FULGUR_ANTHROPIC_API_KEY).' : 'Stockée sur ton serveur, jamais renvoyée au navigateur. Plus sûr : la définir dans wp-config.php avec <code>define( \'FULGUR_ANTHROPIC_API_KEY\', \'…\' );</code>') + '</p></div>' +
				'<div class="fulgur-field-input">' + (ai.has_key
					? '<div class="fulgur-connected">✓ Connecté' + (ai.key_locked ? '' : ' <button class="fulgur-link" data-action="ai-key-remove">Retirer</button>') + '</div>'
					: '<div class="fulgur-keyrow"><input type="password" id="fulgur-ai-key" placeholder="sk-ant-…" autocomplete="off"><button class="fulgur-btn is-secondary" data-action="ai-key-save">Connecter</button></div>') +
				'</div></div>';
			h += '<div class="fulgur-field is-wide"><div class="fulgur-field-text"><label for="fulgur-psi-key">Clé API PageSpeed (facultatif)</label><p>Sans clé, Google limite le nombre d\'analyses. Gratuite sur console.cloud.google.com.</p></div>' +
				'<div class="fulgur-field-input"><div class="fulgur-keyrow"><input type="password" id="fulgur-psi-key" placeholder="' + (ai.has_psi_key ? '•••••••• (enregistrée)' : 'AIza…') + '" autocomplete="off"><button class="fulgur-btn is-secondary" data-action="ai-psi-save">Enregistrer</button></div></div></div>';
			return h;
		},
		aiHistory: function () {
			var hist = state.ai.history || [];
			if (!hist.length) return '<p class="fulgur-note">Aucune optimisation appliquée pour le moment.</p>';
			return '<table class="fulgur-table"><thead><tr><th>Date</th><th>Mode</th><th>Score mobile</th><th>Réglages</th><th>Résultat</th></tr></thead><tbody>' + hist.map(function (e) {
				return '<tr><td>' + new Date(e.date * 1000).toLocaleDateString('fr-FR') + '</td><td>' + (e.mode === 'auto' ? 'Automatique' : 'Manuel') + '</td><td>' + (e.score_before != null ? e.score_before + ' → ' + (e.score_after != null ? e.score_after : '?') : '—') + '</td><td>' + esc((e.changes || []).join(', ')) + '</td><td>' + (e.rolled_back ? '<span class="fulgur-risk is-advanced">Annulé</span> ' + esc(e.reason) : '<span class="fulgur-risk is-safe">Appliqué</span>') + '</td></tr>';
			}).join('') + '</tbody></table>';
		},
		bulkImages: function () {
			if (!state.images.webp) return '<div class="fulgur-inline is-warn">La conversion d\'images n\'est pas disponible sur ce serveur (GD avec WebP ou Imagick requis).</div>';
			return '<div class="fulgur-inline"><div><strong>Médiathèque existante</strong><p>' + state.images.total + ' images JPEG/PNG. Les nouvelles images sont converties automatiquement à l\'envoi ; les images du thème sont converties en arrière-plan' + (state.images.queue ? ' (' + state.images.queue + ' en file)' : '') + '.</p>' +
				'<div class="fulgur-progress" id="fulgur-img-progress" hidden><div></div></div></div>' +
				'<button class="fulgur-btn is-secondary" data-action="bulk-images">Convertir toute la médiathèque</button></div>';
		},
		preloadStatus: function () {
			var p = state.preload;
			return '<div class="fulgur-inline"><div><strong>' + (p.pending ? p.pending + ' pages en cours de préchargement' : 'Toutes les pages sont prêtes') + '</strong><p>' + state.stats.preloaded + ' pages préchargées au total.</p></div>' +
				'<button class="fulgur-btn is-secondary" data-action="preload">Tout précharger maintenant</button></div>';
		},
		database: function () {
			if (!dbData) {
				setTimeout(loadDb, 0);
				return '<div class="fulgur-inline"><span class="fulgur-spin"></span> Analyse de la base de données…</div>';
			}
			var h = '<ul class="fulgur-db">';
			Object.keys(dbData.tasks).forEach(function (k) {
				var n = dbData.counts[k] || 0;
				h += '<li><label><input type="checkbox" data-db="' + k + '"' + (n ? ' checked' : '') + '> ' + esc(dbData.tasks[k]) + '</label><span class="count ' + (n ? 'has' : '') + '">' + (k === 'optimize' ? (n ? n + ' tables' : 'OK') : n) + '</span></li>';
			});
			return h + '</ul><div class="fulgur-inline"><p>Les révisions récentes (voir réglage ci-dessous) sont toujours conservées.</p><button class="fulgur-btn is-secondary" data-action="db-clean">Nettoyer la sélection</button></div>';
		},
		cloudflare: function () {
			return '<div class="fulgur-inline"><p>Enregistre d\'abord tes identifiants, puis teste la connexion.</p><button class="fulgur-btn is-secondary" data-action="cf-test">Tester la connexion</button></div>';
		},
		diagnostics: function () {
			return checks(state.diagnostics) + '<div class="fulgur-inline"><p>Réinstalle le moteur de cache, wp-config.php et les règles .htaccess.</p><button class="fulgur-btn is-secondary" data-action="repair">Tout réparer</button></div>';
		},
		nginx: function () {
			return '<p class="fulgur-note">Sur Nginx, le cache fonctionne déjà via PHP. Pour le service 100 % statique, ajoute ce bloc dans la configuration <code>server</code> (ou envoie-le à ton hébergeur).</p>' +
				'<pre class="fulgur-code" id="fulgur-nginx">' + esc(state.nginx) + '</pre><button class="fulgur-btn is-small is-ghost" data-action="copy-nginx">Copier</button>';
		},
		importExport: function () {
			return '<div class="fulgur-inline"><p>Transfère tes réglages vers un autre site.</p><div class="fulgur-btn-group">' +
				'<button class="fulgur-btn is-secondary" data-action="export">Exporter</button>' +
				'<label class="fulgur-btn is-secondary">Importer<input type="file" accept="application/json" data-action="import" hidden></label>' +
				'<button class="fulgur-btn is-danger" data-action="reset">Réinitialiser</button></div></div>';
		}
	};

	function onboarding() {
		return '<div class="fulgur-modal" role="dialog" aria-modal="true" aria-labelledby="fulgur-ob-title"><div class="fulgur-modal-box">' +
			'<div id="fulgur-ob-step1"><span class="fulgur-logo is-big">⚡</span><h2 id="fulgur-ob-title">Bienvenue dans Fulgur Cache</h2>' +
			'<p>Le cache est déjà actif. Choisis maintenant ton niveau d\'optimisation : tu pourras le changer à tout moment.</p>' +
			profiles(true) +
			'<button class="fulgur-link" data-action="skip-onboarding">Passer, je réglerai moi-même</button></div>' +
			'<div id="fulgur-ob-step2" hidden><span class="fulgur-logo is-big">⚡</span><h2>Optimisation en cours…</h2><ol class="fulgur-steps">' +
			'<li data-step="settings">Application des réglages</li><li data-step="preload">Préchargement des pages</li><li data-step="speed">Mesure de la vitesse</li></ol>' +
			'<div id="fulgur-ob-result"></div></div></div></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	function loadDb() {
		api('/database').then(function (d) { dbData = d; render(); }).catch(function (e) { toast(e.message, 'error'); });
	}

	var ACTIONS = {
		purge: function (btn) {
			busy(btn, true, 'Vidage…');
			api('/purge', 'POST').then(function (s) { setState(s); toast('Cache vidé. Les pages se reconstruisent en arrière-plan.'); })
				.catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
		},
		preload: function (btn) {
			busy(btn, true, 'Lancement…');
			api('/preload', 'POST').then(function (s) { setState(s); toast('Préchargement lancé : ' + s.preload.pending + ' pages.'); })
				.catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
		},
		save: function (btn) {
			busy(btn, true, 'Enregistrement…');
			api('/settings', 'POST', { values: draft }).then(function (s) {
				state = s; draft = clone(s.settings); render(); toast('Réglages appliqués. Cache vidé et en cours de reconstruction.');
			}).catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
		},
		discard: function () { draft = clone(state.settings); render(); },
		profile: function (btn) {
			var id = btn.dataset.profile;
			if (btn.dataset.onboarding) return runOnboarding(id);
			if (dirty() && !confirm('Appliquer ce profil remplacera tes modifications non enregistrées. Continuer ?')) return;
			busy(btn, true, 'Application…');
			api('/profile', 'POST', { profile: id }).then(function (s) {
				state = s; draft = clone(s.settings); render(); toast('Profil « ' + s.profiles[id].label + ' » appliqué.');
			}).catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
		},
		'skip-onboarding': function () {
			api('/settings', 'POST', { values: { onboarding_done: true, profile: state.settings.profile } }).then(function (s) {
				s.settings.onboarding_done = true; state = s; draft = clone(s.settings); render();
			});
		},
		repair: function (btn) {
			busy(btn, true, 'Réparation…');
			api('/repair', 'POST').then(function (s) {
				setState(s);
				var bad = s.diagnostics.filter(function (d) { return d.status === 'error'; }).length;
				toast(bad ? 'Certains points nécessitent une action manuelle.' : 'Tout est réparé.', bad ? 'warn' : 'ok');
			}).catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
		},
		speedtest: function (btn) {
			busy(btn, true, 'Mesure…');
			api('/speedtest', 'POST').then(function () { return api('/status'); }).then(function (s) { setState(s); })
				.catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
		},
		pagespeed: function (btn) {
			var box = document.getElementById('fulgur-psi');
			busy(btn, true, 'Analyse Google (30 s)…');
			var url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?category=performance&strategy=mobile&url=' + encodeURIComponent(state.home);
			fetch(url).then(function (r) { return r.json(); }).then(function (d) {
				busy(btn, false);
				if (!d.lighthouseResult) throw new Error(d.error && d.error.message ? d.error.message : 'Analyse impossible');
				var lr = d.lighthouseResult, score = Math.round(lr.categories.performance.score * 100), a = lr.audits;
				var cls = score >= 90 ? 'is-good' : score >= 50 ? 'is-avg' : 'is-bad';
				box.innerHTML = '<div class="fulgur-gauge ' + cls + '" style="--p:' + score + '"><span>' + score + '</span></div><ul>' +
					['largest-contentful-paint', 'total-blocking-time', 'cumulative-layout-shift', 'speed-index'].map(function (k) {
						return a[k] ? '<li><span>' + esc(a[k].title) + '</span><strong>' + esc(a[k].displayValue) + '</strong></li>' : '';
					}).join('') + '</ul>';
			}).catch(function (e) {
				busy(btn, false);
				box.innerHTML = '<p>PageSpeed indisponible (' + esc(e.message) + '). <a href="https://pagespeed.web.dev/analysis?url=' + encodeURIComponent(state.home) + '" target="_blank" rel="noopener">Ouvrir PageSpeed Insights ↗</a></p>';
			});
		},
		'bulk-images': function (btn) {
			var bar = document.getElementById('fulgur-img-progress');
			bar.hidden = false;
			busy(btn, true, 'Conversion…');
			var converted = 0;
			(function step(offset) {
				api('/images/bulk', 'POST', { offset: offset }).then(function (r) {
					converted += r.converted;
					bar.firstChild.style.width = (r.total ? Math.min(100, (r.offset / r.total) * 100) : 100) + '%';
					if (!r.done) return step(r.offset);
					busy(btn, false);
					toast(converted + ' fichiers convertis. Le cache est régénéré avec les nouvelles images.');
				}).catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
			})(0);
		},
		'db-clean': function (btn) {
			var tasks = [].slice.call(app.querySelectorAll('[data-db]:checked')).map(function (i) { return i.dataset.db; });
			if (!tasks.length) return toast('Sélectionne au moins un élément.', 'warn');
			if (!confirm('Le nettoyage est définitif. Pense à avoir une sauvegarde récente. Continuer ?')) return;
			busy(btn, true, 'Nettoyage…');
			api('/database/clean', 'POST', { tasks: tasks }).then(function (d) {
				dbData = d; render(); toast(d.removed + ' éléments supprimés.');
			}).catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
		},
		'cf-test': function (btn) {
			if (dirty()) return toast('Enregistre d\'abord tes identifiants Cloudflare.', 'warn');
			busy(btn, true, 'Test…');
			api('/cloudflare', 'POST').then(function (d) { busy(btn, false); toast('Connecté à Cloudflare : ' + d.zone); })
				.catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
		},
		'copy-nginx': function () {
			navigator.clipboard.writeText(state.nginx).then(function () { toast('Configuration copiée.'); });
		},
		export: function () {
			api('/export').then(function (d) {
				var a = document.createElement('a');
				a.href = URL.createObjectURL(new Blob([JSON.stringify(d, null, 2)], { type: 'application/json' }));
				a.download = 'fulgur-cache-reglages.json';
				a.click();
			});
		},
		reset: function (btn) {
			if (!confirm('Revenir aux réglages par défaut (profil Équilibré) ?')) return;
			busy(btn, true);
			api('/reset', 'POST').then(function (s) { state = s; draft = clone(s.settings); render(); toast('Réglages réinitialisés.'); });
		}
	};

	var pollTimer = null;
	function poll() {
		clearTimeout(pollTimer);
		if (!aiBusy()) return;
		pollTimer = setTimeout(function () {
			api('/ai/status').then(function (ai) {
				var was = state.ai.job.stage;
				state.ai = ai;
				if (ai.job.stage !== was && (ai.job.stage === 'done' || ai.job.stage === 'ready')) {
					return api('/status').then(function (s) { state = s; draft = clone(s.settings); });
				}
			}).then(function () { if (tab === 'ai') render(); poll(); })
				.catch(function () { poll(); });
		}, 4000);
	}

	ACTIONS['ai-key-save'] = function (btn) {
		var input = document.getElementById('fulgur-ai-key');
		if (!input.value.trim()) return toast('Colle ta clé API Anthropic.', 'warn');
		busy(btn, true, 'Vérification…');
		api('/ai/key', 'POST', { key: input.value.trim() }).then(function (s) { setState(s); toast('Claude est connecté.'); })
			.catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
	};
	ACTIONS['ai-key-remove'] = function () {
		if (!confirm('Retirer la clé API ? Le pilote automatique sera désactivé.')) return;
		api('/ai/key', 'POST', { remove: true }).then(function (s) { setState(s); });
	};
	ACTIONS['ai-psi-save'] = function (btn) {
		var input = document.getElementById('fulgur-psi-key');
		busy(btn, true);
		api('/ai/key', 'POST', { psi_key: input.value.trim() }).then(function (s) { setState(s); toast('Clé PageSpeed enregistrée.'); })
			.catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
	};
	ACTIONS['ai-start'] = function (btn) {
		if (dirty()) return toast('Enregistre d\'abord tes modifications.', 'warn');
		busy(btn, true, 'Démarrage…');
		aiSelected = null;
		api('/ai/start', 'POST').then(function (ai) { state.ai = ai; render(); poll(); })
			.catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
	};
	ACTIONS['ai-apply'] = function (btn) {
		busy(btn, true, 'Application…');
		api('/ai/apply', 'POST', { keys: aiSelected || [] }).then(function (ai) { state.ai = ai; render(); poll(); })
			.catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
	};
	ACTIONS['ai-undo'] = function (btn) {
		if (!confirm('Revenir aux réglages d\'avant la dernière optimisation IA ?')) return;
		busy(btn, true);
		api('/ai/undo', 'POST').then(function (s) { state = s; draft = clone(s.settings); render(); toast('Réglages précédents restaurés.'); });
	};

	function runOnboarding(profile) {
		document.getElementById('fulgur-ob-step1').hidden = true;
		document.getElementById('fulgur-ob-step2').hidden = false;
		var mark = function (step, cls) {
			var li = app.querySelector('[data-step="' + step + '"]');
			if (li) li.className = cls;
		};
		mark('settings', 'is-running');
		api('/profile', 'POST', { profile: profile, onboarding: true }).then(function (s) {
			state = s; draft = clone(s.settings);
			mark('settings', 'is-done'); mark('preload', 'is-running');
			return api('/preload', 'POST');
		}).then(function () {
			mark('preload', 'is-done'); mark('speed', 'is-running');
			return api('/speedtest', 'POST');
		}).then(function (r) {
			mark('speed', 'is-done');
			var res = document.getElementById('fulgur-ob-result');
			var x = r.before && r.after ? (r.before / r.after) : 0;
			res.innerHTML = (x > 1
				? '<div class="fulgur-result"><span class="big">' + x.toFixed(1).replace('.', ',') + '×</span><p>plus rapide : ' + r.before + ' ms → <strong>' + r.after + ' ms</strong></p></div>'
				: '<div class="fulgur-result"><p>Ton site est optimisé.</p></div>') +
				'<button class="fulgur-btn is-primary" data-action="finish-onboarding">Voir mon tableau de bord</button>';
		}).catch(function (e) {
			toast(e.message, 'error');
			document.getElementById('fulgur-ob-result').innerHTML = '<button class="fulgur-btn is-primary" data-action="finish-onboarding">Continuer</button>';
		});
	}
	ACTIONS['finish-onboarding'] = function () {
		api('/status').then(function (s) { state = s; draft = clone(s.settings); render(); });
	};

	/* ------------------------------------------------------------------ */
	/* Événements                                                          */
	/* ------------------------------------------------------------------ */

	app.addEventListener('click', function (e) {
		var t = e.target.closest('[data-tab]');
		if (t) {
			e.preventDefault();
			tab = t.dataset.tab;
			history.replaceState(null, '', '#' + tab);
			render();
			window.scrollTo({ top: 0 });
			return;
		}
		var a = e.target.closest('[data-action]');
		if (a && a.tagName !== 'INPUT' && ACTIONS[a.dataset.action]) {
			e.preventDefault();
			ACTIONS[a.dataset.action](a);
		}
	});

	function onInput(e) {
		var el = e.target, key = el.dataset.key;
		if (el.dataset.aiKey) {
			aiSelected = aiSelected || [];
			var k = el.dataset.aiKey, i = aiSelected.indexOf(k);
			if (el.checked && i < 0) aiSelected.push(k);
			if (!el.checked && i > -1) aiSelected.splice(i, 1);
			return;
		}
		if (key) {
			var prev = draft[key];
			if (el.type === 'checkbox') draft[key] = el.checked;
			else if (el.type === 'number' || el.type === 'range') draft[key] = parseInt(el.value, 10) || 0;
			else draft[key] = el.value;
			if (el.type === 'range') el.nextElementSibling.textContent = el.value;
			var bar = app.querySelector('.fulgur-savebar');
			if (bar) bar.classList.toggle('is-visible', dirty());
			// Les champs conditionnels apparaissent / disparaissent.
			if (el.type === 'checkbox' || el.tagName === 'SELECT') {
				if ((prev === 'off') !== (draft[key] === 'off') || el.type === 'checkbox') render();
			}
			return;
		}
		if (el.dataset.action === 'import' && el.files[0]) {
			el.files[0].text().then(function (txt) {
				return api('/import', 'POST', { data: JSON.parse(txt) });
			}).then(function (s) { state = s; draft = clone(s.settings); render(); toast('Réglages importés.'); })
				.catch(function (err) { toast(err.message, 'error'); });
		}
	}
	app.addEventListener('change', onInput);
	app.addEventListener('input', function (e) { if (e.target.type !== 'checkbox' && e.target.tagName !== 'SELECT' && e.target.type !== 'file') onInput(e); });

	window.addEventListener('beforeunload', function (e) { if (dirty()) { e.preventDefault(); e.returnValue = ''; } });
	window.addEventListener('hashchange', function () { tab = readTab(); render(); });
	document.addEventListener('keydown', function (e) {
		if ((e.metaKey || e.ctrlKey) && e.key === 's' && dirty()) { e.preventDefault(); ACTIONS.save(app.querySelector('[data-action="save"]')); }
	});

	render();
	poll();
})();
