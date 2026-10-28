/* Fulgur Cache — admin interface (vanilla JS, translations via wp.i18n). */
(function () {
	'use strict';

	var D = window.FulgurData;
	var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;
	var LOCALE = D.locale || 'en-US';
	var app = document.getElementById('fulgur-app');
	var state = D.state;
	var draft = clone(state.settings);
	var tab = readTab();
	var dbData = null;

	/* ------------------------------------------------------------------ */
	/* Settings schema                                                     */
	/* ------------------------------------------------------------------ */

	var RISK = {
		safe: [__('No risk', 'fulgur-cache'), 'safe'],
		moderate: [__('Test it', 'fulgur-cache'), 'moderate'],
		advanced: [__('Advanced', 'fulgur-cache'), 'advanced']
	};

	var TABS = [
		{ id: 'dashboard', icon: '◎', label: __('Dashboard', 'fulgur-cache') },
		{ id: 'ai', icon: '✦', label: __('AI Optimizer', 'fulgur-cache'), sections: [
			{ title: __('Analysis and optimization by Claude', 'fulgur-cache'), fields: [], custom: 'aiRun' },
			{ title: __('Connection to Claude', 'fulgur-cache'), fields: [
				{ key: 'ai_model', type: 'select', label: __('Model', 'fulgur-cache'), options: { 'claude-opus-5-5': __('Claude Opus 5.5 — most accurate (≈ $0.15 to $0.40 per analysis)', 'fulgur-cache'), 'claude-sonnet-5-5': __('Claude Sonnet 5.5 — balanced (≈ $0.08 to $0.20)', 'fulgur-cache'), 'claude-haiku-5-5': __('Claude Haiku 5.5 — budget (≈ $0.01)', 'fulgur-cache') }, help: __('Billed directly by Anthropic on your API key. One analysis a week is enough.', 'fulgur-cache') },
				{ key: 'ai_psi', type: 'toggle', label: __('Include Google PageSpeed measurements', 'fulgur-cache'), help: __('Claude gets the real Core Web Vitals (LCP, TBT, CLS) and the issues found by Lighthouse. Adds 1 to 2 minutes to the analysis.', 'fulgur-cache'), risk: 'safe' },
				{ key: 'ai_auto', type: 'select', label: __('Autopilot', 'fulgur-cache'), options: { off: __('Off', 'fulgur-cache'), suggest: __('Analyze every week and email me the recommendations', 'fulgur-cache'), auto: __('Analyze and apply safe settings every week (automatic rollback if the score drops)', 'fulgur-cache') } }
			], custom: 'aiKey' },
			{ title: __('History', 'fulgur-cache'), fields: [], custom: 'aiHistory' }
		] },
		{ id: 'cache', icon: '⚡', label: __('Cache', 'fulgur-cache'), sections: [
			{ title: __('Page cache', 'fulgur-cache'), fields: [
				{ key: 'page_cache', type: 'toggle', label: __('Enable page cache', 'fulgur-cache'), help: __('Each page is generated once, then served as static HTML, without PHP or database. This is the setting that brings the most speed.', 'fulgur-cache'), risk: 'safe' },
				{ key: 'cache_lifespan', type: 'number', label: __('Cache lifespan', 'fulgur-cache'), suffix: __('hours', 'fulgur-cache'), help: __('Pages are regenerated after this delay. 0 = never (automatic purging is enough in 99% of cases).', 'fulgur-cache'), min: 0 },
				{ key: 'browser_cache', type: 'toggle', label: __('Compression and browser cache', 'fulgur-cache'), help: __('Brotli/gzip + one-year caching of images, CSS, JS and fonts in the visitor\'s browser.', 'fulgur-cache'), risk: 'safe' },
				{ key: 'cache_mobile', type: 'toggle', label: __('Separate mobile cache', 'fulgur-cache'), help: __('Only if your theme generates different HTML on mobile (rare). Leave off for a responsive theme.', 'fulgur-cache') },
				{ key: 'cache_404', type: 'toggle', label: __('Cache 404 pages', 'fulgur-cache'), help: __('Protects the server against bots probing non-existent URLs.', 'fulgur-cache') }
			] },
			{ title: __('Exclusions', 'fulgur-cache'), fields: [
				{ key: 'exclude_urls', type: 'textarea', label: __('URLs never cached', 'fulgur-cache'), help: __('One per line. Accepts part of a URL (/cart/), a wildcard (/account/*) or a regex (#^/private#).', 'fulgur-cache') },
				{ key: 'ignored_query_params', type: 'textarea', label: __('Ignored URL parameters', 'fulgur-cache'), help: __('Campaign links (utm_source, fbclid…) get the same cached page instead of regenerating it.', 'fulgur-cache') },
				{ key: 'exclude_cookies', type: 'textarea', label: __('Cookies that disable the cache', 'fulgur-cache'), help: __('One name (or name prefix) per line. Login and WooCommerce cart cookies are already handled.', 'fulgur-cache') },
				{ key: 'exclude_user_agents', type: 'textarea', label: __('Excluded browsers / bots', 'fulgur-cache'), help: __('Part of the User-Agent, one per line.', 'fulgur-cache') }
			] }
		] },
		{ id: 'files', icon: '{ }', label: __('CSS & JavaScript', 'fulgur-cache'), sections: [
			{ title: __('HTML & CSS', 'fulgur-cache'), fields: [
				{ key: 'minify_html', type: 'toggle', label: __('Minify HTML', 'fulgur-cache'), help: __('Removes comments and useless whitespace.', 'fulgur-cache'), risk: 'safe' },
				{ key: 'minify_css', type: 'toggle', label: __('Minify CSS', 'fulgur-cache'), help: __('Lighter CSS files, cached for one year.', 'fulgur-cache'), risk: 'safe' },
				{ key: 'font_display_swap', type: 'toggle', label: __('Visible text while fonts load', 'fulgur-cache'), help: __('Adds font-display: swap: text shows immediately with a system font.', 'fulgur-cache'), risk: 'safe' },
				{ key: 'css_mode', type: 'select', label: __('Critical CSS / unused CSS', 'fulgur-cache'), options: { off: __('Off', 'fulgur-cache'), critical: __('Critical CSS: used CSS inlined, the rest loaded async (recommended)', 'fulgur-cache'), remove: __('Remove unused CSS: the rest is only loaded on first interaction', 'fulgur-cache') }, help: __('Each page only gets the CSS rules it uses, right in the HTML: no CSS blocks rendering anymore. Computed on your server, no external service.', 'fulgur-cache') },
				{ key: 'css_safelist', type: 'textarea', label: __('CSS classes always kept', 'fulgur-cache'), help: __('Classes added by JavaScript (open menus, sliders, pop-ups). Part of a name, one per line.', 'fulgur-cache'), show: 'css_mode' },
				{ key: 'google_fonts', type: 'select', label: __('Google Fonts', 'fulgur-cache'), options: { off: __('Leave unchanged', 'fulgur-cache'), swap: __('Optimize (swap + preconnect)', 'fulgur-cache'), local: __('Host locally (GDPR + faster)', 'fulgur-cache') }, help: __('Local hosting removes the connection to Google: faster and GDPR-compliant.', 'fulgur-cache') }
			] },
			{ title: __('JavaScript', 'fulgur-cache'), fields: [
				{ key: 'defer_js', type: 'toggle', label: __('Defer JavaScript', 'fulgur-cache'), help: __('Scripts run after the page is displayed, in their original order.', 'fulgur-cache'), risk: 'moderate' },
				{ key: 'defer_exclusions', type: 'textarea', label: __('Scripts not to defer', 'fulgur-cache'), help: __('Part of a URL or code, one per line.', 'fulgur-cache'), show: 'defer_js' },
				{ key: 'delay_js', type: 'toggle', label: __('Load JavaScript on first interaction', 'fulgur-cache'), help: __('The most spectacular PageSpeed gain: no script runs until the visitor moves the mouse, touches the screen or scrolls. Test your site after enabling it.', 'fulgur-cache'), risk: 'advanced' },
				{ key: 'delay_timeout', type: 'number', label: __('Force loading after', 'fulgur-cache'), suffix: __('seconds', 'fulgur-cache'), help: __('0 = on interaction only. Use 5 to 10 s if you have analytics scripts.', 'fulgur-cache'), min: 0, show: 'delay_js' },
				{ key: 'delay_exclusions', type: 'textarea', label: __('Scripts to load immediately', 'fulgur-cache'), help: __('Part of a URL or code, one per line (e.g. cookie banner, slider at the top of the page).', 'fulgur-cache'), show: 'delay_js' }
			] }
		] },
		{ id: 'media', icon: '▣', label: __('Media', 'fulgur-cache'), sections: [
			{ title: __('Image loading', 'fulgur-cache'), fields: [
				{ key: 'lazy_images', type: 'toggle', label: __('Lazy-load images', 'fulgur-cache'), help: __('Offscreen images only load as the visitor scrolls near them (native browser lazy-loading).', 'fulgur-cache'), risk: 'safe' },
				{ key: 'eager_images', type: 'number', label: __('Top-of-page images never lazy-loaded', 'fulgur-cache'), suffix: __('images', 'fulgur-cache'), help: __('The largest visible image (LCP) is detected automatically, preloaded and prioritized.', 'fulgur-cache'), min: 0 },
				{ key: 'image_dimensions', type: 'toggle', label: __('Add missing dimensions', 'fulgur-cache'), help: __('Removes layout shifts (CLS).', 'fulgur-cache'), risk: 'safe' },
				{ key: 'lazy_iframes', type: 'toggle', label: __('Lazy-load iframes', 'fulgur-cache'), help: __('Maps, forms, embedded videos.', 'fulgur-cache'), risk: 'safe' },
				{ key: 'youtube_facade', type: 'toggle', label: __('YouTube facade', 'fulgur-cache'), help: __('Shows a thumbnail instead of the player (−1 MB per video). The player loads on click.', 'fulgur-cache'), risk: 'safe' }
			] },
			{ title: __('Next-gen formats', 'fulgur-cache'), fields: [
				{ key: 'next_gen_images', type: 'toggle', label: __('Convert and serve WebP / AVIF', 'fulgur-cache'), help: __('Images 30 to 50% lighter, with no visible loss. Originals are kept.', 'fulgur-cache'), risk: 'safe' },
				{ key: 'image_format', type: 'select', label: __('Format', 'fulgur-cache'), options: { webp: __('WebP (recommended, no impact on the theme)', 'fulgur-cache'), avif: __('AVIF + WebP (lightest, via <picture>)', 'fulgur-cache') }, show: 'next_gen_images' },
				{ key: 'image_quality', type: 'range', label: __('Quality', 'fulgur-cache'), min: 50, max: 95, show: 'next_gen_images', help: __('78 is the best size / quality trade-off.', 'fulgur-cache') }
			], custom: 'bulkImages' }
		] },
		{ id: 'preload', icon: '➜', label: __('Preloading', 'fulgur-cache'), sections: [
			{ title: __('Always-warm cache', 'fulgur-cache'), fields: [
				{ key: 'preload_cache', type: 'toggle', label: __('Preload the cache', 'fulgur-cache'), help: __('After each purge, every page is regenerated in the background: no visitor ever hits a slow page.', 'fulgur-cache'), risk: 'safe' }
			], custom: 'preloadStatus' },
			{ title: __('Instant navigation', 'fulgur-cache'), fields: [
				{ key: 'speculation', type: 'select', label: __('Speculation Rules', 'fulgur-cache'), options: { off: __('Off', 'fulgur-cache'), prefetch: __('Prefetch (downloads the next page)', 'fulgur-cache'), prerender: __('Prerender (shows the next page instantly)', 'fulgur-cache') }, help: __('Native Chrome/Edge technology: the next page is prepared while the visitor hovers the link. Click = displayed in 0 ms.', 'fulgur-cache') },
				{ key: 'speculation_eagerness', type: 'select', label: __('Trigger', 'fulgur-cache'), options: { conservative: __('On click (light)', 'fulgur-cache'), moderate: __('On hover (recommended)', 'fulgur-cache'), eager: __('As soon as possible (heavy)', 'fulgur-cache') }, show: 'speculation' },
				{ key: 'instant_fallback', type: 'toggle', label: __('Hover preloading for Safari and Firefox', 'fulgur-cache'), help: __('Lightweight equivalent (1 KB) for browsers without Speculation Rules.', 'fulgur-cache'), risk: 'safe' },
				{ key: 'early_hints', type: 'toggle', label: __('Preload headers (103 Early Hints)', 'fulgur-cache'), help: __('The browser starts downloading the main image and fonts before it even receives the HTML (Cloudflare, LiteSpeed).', 'fulgur-cache'), risk: 'safe' },
				{ key: 'preconnect_domains', type: 'textarea', label: __('Domains to preconnect', 'fulgur-cache'), help: __('Critical third-party domains, one per line (e.g. cdn.example.com).', 'fulgur-cache') }
			] }
		] },
		{ id: 'wordpress', icon: 'W', label: __('WP cleanup', 'fulgur-cache'), sections: [
			{ title: __('What WordPress loads for nothing', 'fulgur-cache'), fields: [
				{ key: 'disable_emojis', type: 'toggle', label: __('Disable WordPress emojis', 'fulgur-cache'), help: __('Browsers display emojis natively. −1 request.', 'fulgur-cache'), risk: 'safe' },
				{ key: 'disable_embeds', type: 'toggle', label: __('Disable WordPress embeds', 'fulgur-cache'), help: __('Removes wp-embed.js. YouTube videos, tweets, etc. still work.', 'fulgur-cache'), risk: 'safe' },
				{ key: 'dashicons_visitors', type: 'toggle', label: __('Remove Dashicons for visitors', 'fulgur-cache'), help: __('Admin icon font (46 KB) useless on the public site.', 'fulgur-cache'), risk: 'safe' },
				{ key: 'clean_head', type: 'toggle', label: __('Clean the HTML head', 'fulgur-cache'), help: __('Removes RSD, WLW, shortlink and the WordPress version (security).', 'fulgur-cache'), risk: 'safe' },
				{ key: 'disable_xmlrpc', type: 'toggle', label: __('Disable XML-RPC', 'fulgur-cache'), help: __('Closes an entry point for brute-force attacks. Turn off if you use the WordPress mobile app or Jetpack.', 'fulgur-cache'), risk: 'safe' },
				{ key: 'remove_jquery_migrate', type: 'toggle', label: __('Remove jQuery Migrate', 'fulgur-cache'), help: __('Useless with up-to-date themes and plugins.', 'fulgur-cache'), risk: 'moderate' },
				{ key: 'heartbeat', type: 'select', label: __('Heartbeat API', 'fulgur-cache'), options: { 'default': __('Default (15 s)', 'fulgur-cache'), reduce: __('Reduced (60 s)', 'fulgur-cache'), disable_front: __('Disabled on the public site', 'fulgur-cache'), disable: __('Disabled everywhere except the editor', 'fulgur-cache') }, help: __('Regular AJAX requests that load the server.', 'fulgur-cache') },
				{ key: 'wc_cart_fragments', type: 'toggle', label: __('WooCommerce: disable cart-fragments outside the shop', 'fulgur-cache'), help: __('Removes an uncacheable AJAX request on every non-shop page.', 'fulgur-cache'), risk: 'moderate' }
			] }
		] },
		{ id: 'database', icon: '◫', label: __('Database', 'fulgur-cache'), sections: [
			{ title: __('Cleanup', 'fulgur-cache'), fields: [], custom: 'database' },
			{ title: __('Automation', 'fulgur-cache'), fields: [
				{ key: 'db_auto_clean', type: 'toggle', label: __('Weekly automatic cleanup', 'fulgur-cache'), help: __('Revisions, auto-drafts, spam, trash, expired transients.', 'fulgur-cache'), risk: 'safe' },
				{ key: 'db_revisions_keep', type: 'number', label: __('Revisions kept per post', 'fulgur-cache'), suffix: __('revisions', 'fulgur-cache'), min: 0 }
			] }
		] },
		{ id: 'cdn', icon: '☁', label: __('CDN', 'fulgur-cache'), sections: [
			{ title: __('CDN', 'fulgur-cache'), fields: [
				{ key: 'cdn_enabled', type: 'toggle', label: __('Serve static files through a CDN', 'fulgur-cache'), help: __('Images, CSS, JS and fonts are served from the CDN (BunnyCDN, KeyCDN…).', 'fulgur-cache') },
				{ key: 'cdn_url', type: 'text', label: __('CDN URL', 'fulgur-cache'), placeholder: 'https://cdn.example.com', show: 'cdn_enabled' },
				{ key: 'cdn_exclusions', type: 'textarea', label: __('Files excluded from the CDN', 'fulgur-cache'), show: 'cdn_enabled' }
			] },
			{ title: __('Cloudflare', 'fulgur-cache'), fields: [
				{ key: 'cloudflare_token', type: 'password', label: __('API token', 'fulgur-cache'), placeholder: __('Permission: Zone › Cache Purge', 'fulgur-cache'), help: __('The Cloudflare cache is cleared automatically together with the site cache.', 'fulgur-cache') },
				{ key: 'cloudflare_zone', type: 'text', label: __('Zone ID', 'fulgur-cache'), placeholder: __('Shown on the Cloudflare domain overview', 'fulgur-cache') }
			], custom: 'cloudflare' }
		] },
		{ id: 'tools', icon: '⚙', label: __('Tools', 'fulgur-cache'), sections: [
			{ title: __('Full diagnostics', 'fulgur-cache'), fields: [], custom: 'diagnostics' },
			{ title: __('Nginx configuration', 'fulgur-cache'), fields: [], custom: 'nginx' },
			{ title: __('Settings backup', 'fulgur-cache'), fields: [], custom: 'importExport' }
		] }
	];

	/* ------------------------------------------------------------------ */
	/* Utilities                                                           */
	/* ------------------------------------------------------------------ */

	function clone(o) { return JSON.parse(JSON.stringify(o)); }

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function num(n, digits) {
		return Number(n).toLocaleString(LOCALE, { minimumFractionDigits: digits || 0, maximumFractionDigits: digits || 0 });
	}

	function readTab() {
		var h = (location.hash || '').replace('#', '');
		return h || 'dashboard';
	}

	function size(bytes) {
		/* translators: abbreviations for byte, kilobyte, megabyte, gigabyte. */
		var u = [__('B', 'fulgur-cache'), __('KB', 'fulgur-cache'), __('MB', 'fulgur-cache'), __('GB', 'fulgur-cache')];
		if (!bytes) return '0 ' + u[1];
		var i = Math.min(3, Math.floor(Math.log(bytes) / Math.log(1024)));
		return num(bytes / Math.pow(1024, i), i > 1 ? 1 : 0) + ' ' + u[i];
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
				if (!r.ok) throw new Error(data && data.message ? data.message : sprintf(__('Error %d', 'fulgur-cache'), r.status));
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
		if (on) { btn.dataset.label = btn.innerHTML; btn.innerHTML = '<span class="fulgur-spin"></span>' + esc(label || __('Working…', 'fulgur-cache')); btn.disabled = true; }
		else { btn.innerHTML = btn.dataset.label || btn.innerHTML; btn.disabled = false; }
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	function render() {
		var s = state.settings;
		var health = healthScore();
		var html = '';

		html += '<header class="fulgur-header">' +
			'<div class="fulgur-brand"><span class="fulgur-logo">⚡</span><div><h1>Fulgur Cache</h1><span class="fulgur-version">v' + esc(D.version) + '</span></div></div>' +
			'<div class="fulgur-status ' + (s.page_cache ? 'is-on' : 'is-off') + '"><span class="dot"></span>' + esc(s.page_cache ? sprintf(_n('Cache active · %d page', 'Cache active · %d pages', state.usage.pages, 'fulgur-cache'), state.usage.pages) : __('Cache disabled', 'fulgur-cache')) + '</div>' +
			'<div class="fulgur-actions">' +
			'<a class="fulgur-btn is-ghost" href="' + esc(D.preview) + '" target="_blank" rel="noopener" title="' + esc(__('View the home page with optimizations, without caching anything', 'fulgur-cache')) + '">' + esc(__('Test safely', 'fulgur-cache')) + ' ↗</a>' +
			'<button class="fulgur-btn is-secondary" data-action="preload">' + esc(__('Preload', 'fulgur-cache')) + '</button>' +
			'<button class="fulgur-btn is-primary" data-action="purge">' + esc(__('Clear cache', 'fulgur-cache')) + '</button>' +
			'</div></header>';

		html += '<div class="fulgur-layout"><nav class="fulgur-nav" aria-label="' + esc(__('Sections', 'fulgur-cache')) + '">';
		TABS.forEach(function (t) {
			var badge = t.id === 'tools' && health.errors ? '<span class="fulgur-badge-count">' + health.errors + '</span>' : '';
			html += '<a href="#' + t.id + '" class="' + (tab === t.id ? 'is-active' : '') + '" data-tab="' + t.id + '"><span class="ico" aria-hidden="true">' + t.icon + '</span>' + esc(t.label) + badge + '</a>';
		});
		html += '</nav><main class="fulgur-main">';

		var current = TABS.filter(function (t) { return t.id === tab; })[0] || TABS[0];
		html += current.id === 'dashboard' ? dashboard(health) : settingsTab(current);

		html += '</main></div>';

		html += '<div class="fulgur-savebar ' + (dirty() ? 'is-visible' : '') + '"><span>' + esc(__('Unsaved changes', 'fulgur-cache')) + '</span>' +
			'<button class="fulgur-btn is-ghost" data-action="discard">' + esc(__('Discard', 'fulgur-cache')) + '</button>' +
			'<button class="fulgur-btn is-primary" data-action="save">' + esc(__('Save and apply', 'fulgur-cache')) + '</button></div>';

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
			/* translators: %s: speed multiplier, e.g. 22.2 */
			'<div><h2>' + (speedup ? sprintf(esc(__('Your site responds %s faster', 'fulgur-cache')), '<strong>' + num(speedup, 1) + '×</strong>') : esc(__('Measure your site\'s speed gain', 'fulgur-cache'))) + '</h2>' +
			/* translators: 1: response time without cache in ms, 2: response time with cache in ms */
			'<p>' + (speedup ? sprintf(esc(__('Server response time: %1$s ms without cache → %2$s ms with Fulgur.', 'fulgur-cache')), st.speed_before, '<strong>' + st.speed_after + '</strong>') : esc(__('The test compares your home page response time without and with cache.', 'fulgur-cache'))) + '</p></div>' +
			'<div class="fulgur-hero-actions"><button class="fulgur-btn is-light" data-action="speedtest">' + esc(__('Run server test', 'fulgur-cache')) + '</button>' +
			'<button class="fulgur-btn is-light" data-action="pagespeed">' + esc(__('Mobile PageSpeed score', 'fulgur-cache')) + '</button>' +
			'<a class="fulgur-btn is-gold" href="#ai" data-tab="ai">✦ ' + esc(__('Optimize with AI', 'fulgur-cache')) + '</a></div>' +
			'<div id="fulgur-psi" class="fulgur-psi"></div></section>';

		h += '<div class="fulgur-kpis">' +
			kpi(__('Cached pages', 'fulgur-cache'), num(state.usage.pages), size(state.usage.size)) +
			kpi(__('Preloaded pages', 'fulgur-cache'), num(st.preloaded), state.preload.pending ? sprintf(__('%d queued', 'fulgur-cache'), state.preload.pending) : __('Queue empty', 'fulgur-cache')) +
			kpi(__('Images', 'fulgur-cache'), num(state.images.total), state.images.webp ? (state.images.avif ? __('WebP + AVIF available', 'fulgur-cache') : __('WebP available', 'fulgur-cache')) : __('Conversion unavailable', 'fulgur-cache')) +
			kpi(__('Health', 'fulgur-cache'), health.errors ? sprintf(_n('%d issue', '%d issues', health.errors, 'fulgur-cache'), health.errors) : __('Perfect', 'fulgur-cache'), health.errors ? __('View diagnostics', 'fulgur-cache') : sprintf(_n('%d check passed', '%d checks passed', health.ok, 'fulgur-cache'), health.ok), health.errors ? 'is-bad' : 'is-good') +
			'</div>';

		h += '<section class="fulgur-card"><div class="fulgur-card-head"><h3>' + esc(__('Optimization level', 'fulgur-cache')) + '</h3><p>' + esc(__('One click is enough. You can then fine-tune each setting.', 'fulgur-cache')) + '</p></div>' + profiles(false) + '</section>';

		var issues = state.diagnostics.filter(function (d) { return d.status === 'error' || d.status === 'warning'; });
		if (issues.length) {
			h += '<section class="fulgur-card"><div class="fulgur-card-head"><h3>' + esc(__('To fix', 'fulgur-cache')) + '</h3></div>' + checks(issues) + '</section>';
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
				(id === 'balanced' ? '<span class="ribbon">' + esc(__('Recommended', 'fulgur-cache')) + '</span>' : '') +
				'<span class="name">' + esc(p.label) + '</span><span class="gain">' + esc(p.gain) + '</span><span class="desc">' + esc(p.description) + '</span>' +
				(active ? '<span class="check">✓ ' + esc(__('Active', 'fulgur-cache')) + '</span>' : '') + '</button>';
		});
		h += '</div>';
		if (!onboarding && state.settings.profile === 'custom') h += '<p class="fulgur-note">' + esc(__('Custom settings active.', 'fulgur-cache')) + '</p>';
		if (!onboarding && state.settings.profile === 'ai') h += '<p class="fulgur-note">✦ ' + esc(__('Settings optimized by AI for your server.', 'fulgur-cache')) + '</p>';
		return h;
	}

	function checks(list) {
		var icons = { ok: '✓', warning: '!', error: '✕', info: 'i' };
		var h = '<ul class="fulgur-checks">';
		list.forEach(function (c) {
			h += '<li class="is-' + c.status + '"><span class="ico">' + icons[c.status] + '</span><div><strong>' + esc(c.title) + '</strong>' + (c.detail ? '<p>' + esc(c.detail) + '</p>' : '') + '</div>' +
				(c.action === 'repair' ? '<button class="fulgur-btn is-small" data-action="repair">' + esc(__('Repair', 'fulgur-cache')) + '</button>' : '') +
				(c.action === 'nginx' ? '<a class="fulgur-btn is-small is-ghost" href="#tools" data-tab="tools">' + esc(__('View', 'fulgur-cache')) + '</a>' : '') + '</li>';
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
		var risk = f.risk ? '<span class="fulgur-risk is-' + RISK[f.risk][1] + '">' + esc(RISK[f.risk][0]) + '</span>' : '';
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

	var STAGES = [
		['collect', __('Measurements', 'fulgur-cache')],
		['analyze', __('Claude analysis', 'fulgur-cache')],
		['ready', __('Recommendations', 'fulgur-cache')],
		['apply', __('Applying', 'fulgur-cache')],
		['verify', __('Verification', 'fulgur-cache')]
	];
	var SEVERITY = { critical: __('Critical', 'fulgur-cache'), high: __('High', 'fulgur-cache'), medium: __('Medium', 'fulgur-cache'), low: __('Low', 'fulgur-cache') };
	var aiSelected = null;

	function labelFor(key) {
		var found = key;
		TABS.forEach(function (t) { (t.sections || []).forEach(function (sec) { sec.fields.forEach(function (f) { if (f.key === key) found = f.label; }); }); });
		return found;
	}

	function aiBusy() { var j = state.ai.job; return j.status === 'queued' || j.status === 'running'; }

	function fmtValue(v) {
		if (v === true) return __('on', 'fulgur-cache');
		if (v === false) return __('off', 'fulgur-cache');
		var s = String(v);
		if (s.indexOf('\n') < 0) return s;
		var n = s.split('\n').filter(Boolean).length;
		return sprintf(_n('%d entry', '%d entries', n, 'fulgur-cache'), n);
	}

	var CUSTOM = {
		aiRun: function () {
			var ai = state.ai, job = ai.job, h = '';
			if (!ai.has_key) {
				return '<div class="fulgur-ai-intro"><p><strong>' + esc(__('Claude analyzes your server, the real load, the HTML and the PageSpeed measurements, then picks the best settings for your site.', 'fulgur-cache')) + '</strong> ' +
					esc(__('Fulgur applies them, measures again and rolls back automatically if the score drops or an error appears.', 'fulgur-cache')) + '</p>' +
					'<p>' + esc(__('To start, paste your Anthropic API key below (console.anthropic.com → API Keys).', 'fulgur-cache')) + '</p></div>';
			}
			var idx = -1;
			STAGES.forEach(function (st, i) { if (st[0] === job.stage) idx = i; });
			if (job.stage === 'done') idx = STAGES.length;
			if (job.stage !== 'idle') {
				h += '<ol class="fulgur-stepper">' + STAGES.map(function (st, i) {
					var cls = i < idx ? 'is-done' : (i === idx ? (job.stage === 'error' ? 'is-error' : (aiBusy() ? 'is-running' : 'is-done')) : '');
					return '<li class="' + cls + '"><span></span>' + esc(st[1]) + '</li>';
				}).join('') + '</ol>';
				h += '<p class="fulgur-ai-msg ' + (job.stage === 'error' ? 'is-error' : '') + '">' + (aiBusy() ? '<span class="fulgur-spin"></span> ' : '') + esc(job.message) + '</p>';
			}
			if (!aiBusy()) {
				h += '<div class="fulgur-inline"><div><strong>' + esc(job.stage === 'idle' ? __('Ready for a first analysis', 'fulgur-cache') : __('New analysis', 'fulgur-cache')) + '</strong><p>' + esc(__('Takes 1 to 4 minutes. Only technical data is sent (no content, no personal data).', 'fulgur-cache')) + '</p></div>' +
					'<button class="fulgur-btn is-primary" data-action="ai-start">✦ ' + esc(__('Analyze and optimize my site', 'fulgur-cache')) + '</button></div>';
			}
			var r = job.result;
			if (r && (job.stage === 'ready' || job.stage === 'done' || job.stage === 'apply' || job.stage === 'verify')) {
				h += '<div class="fulgur-ai-result"><p class="fulgur-ai-summary">' + esc(r.summary) + '</p>' +
					(r.expected_gain ? '<p class="fulgur-ai-gain">' + esc(__('Expected gain:', 'fulgur-cache')) + ' <strong>' + esc(r.expected_gain) + '</strong></p>' : '');
				if (r.diagnosis && r.diagnosis.length) {
					h += '<h4>' + esc(__('Diagnosis', 'fulgur-cache')) + '</h4><ul class="fulgur-diag">' + r.diagnosis.map(function (d) {
						return '<li class="is-' + esc(d.severity) + '"><span class="sev">' + esc(SEVERITY[d.severity] || d.severity) + '</span><div><strong>' + esc(d.title) + '</strong><p>' + esc(d.detail) + '</p></div></li>';
					}).join('') + '</ul>';
				}
				if (r.changes && r.changes.length) {
					if (aiSelected === null) aiSelected = r.changes.filter(function (c) { return c.risk !== 'advanced'; }).map(function (c) { return c.key; });
					var editable = job.stage === 'ready';
					h += '<h4>' + esc(__('Proposed settings', 'fulgur-cache')) + '</h4><ul class="fulgur-changes">' + r.changes.map(function (c) {
						var checked = editable ? aiSelected.indexOf(c.key) > -1 : (job.selected || []).indexOf(c.key) > -1;
						return '<li><label><input type="checkbox" data-ai-key="' + esc(c.key) + '"' + (checked ? ' checked' : '') + (editable ? '' : ' disabled') + '>' +
							'<div><strong>' + esc(labelFor(c.key)) + '</strong> <code class="fulgur-key">' + esc(c.key) + '</code> <span class="fulgur-risk is-' + esc(c.risk) + '">' + esc(RISK[c.risk][0]) + '</span>' +
							'<span class="fulgur-diff">' + esc(fmtValue(c.current)) + ' → <b>' + esc(fmtValue(c.value)) + '</b></span>' +
							'<p>' + esc(c.reason) + (c.impact ? ' <em>' + esc(c.impact) + '</em>' : '') + '</p></div></label></li>';
					}).join('') + '</ul>';
					if (editable) h += '<div class="fulgur-inline"><p>' + esc(__('Your current settings are backed up: one click is enough to go back.', 'fulgur-cache')) + '</p><button class="fulgur-btn is-primary" data-action="ai-apply">' + esc(__('Apply and verify', 'fulgur-cache')) + '</button></div>';
				} else {
					h += '<p class="fulgur-note">' + esc(__('No change needed: your configuration is already optimal for this server.', 'fulgur-cache')) + '</p>';
				}
				if (r.server_recommendations && r.server_recommendations.length) {
					h += '<h4>' + esc(__('On the hosting side', 'fulgur-cache')) + '</h4><ul class="fulgur-server">' + r.server_recommendations.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('') + '</ul>';
				}
				/* translators: 1: model name, 2: tokens sent, 3: tokens received */
				if (r.usage) h += '<p class="fulgur-note">' + esc(sprintf(__('Model: %1$s · %2$s tokens sent, %3$s received.', 'fulgur-cache'), r.model, num(r.usage.input), num(r.usage.output))) + '</p>';
				h += '</div>';
			}
			if (job.verify) {
				var v = job.verify;
				h += '<div class="fulgur-verify ' + (v.rolled_back ? 'is-bad' : 'is-good') + '">' +
					(v.score_before != null && v.score_after != null ? '<span class="scores">' + v.score_before + ' → <b>' + v.score_after + '</b></span>' : '') +
					'<p>' + esc(v.message) + '</p></div>';
			}
			if (ai.can_undo && !aiBusy()) {
				h += '<div class="fulgur-inline"><p>' + esc(__('The last settings applied by the AI can be undone.', 'fulgur-cache')) + '</p><button class="fulgur-btn is-danger" data-action="ai-undo">' + esc(__('Restore previous settings', 'fulgur-cache')) + '</button></div>';
			}
			return h;
		},
		aiKey: function () {
			var ai = state.ai;
			var h = '<div class="fulgur-field is-wide"><div class="fulgur-field-text"><label for="fulgur-ai-key">' + esc(__('Anthropic API key', 'fulgur-cache')) + '</label><p>' +
				(ai.key_locked ? esc(__('Defined in wp-config.php (FULGUR_ANTHROPIC_API_KEY).', 'fulgur-cache')) : esc(__('Stored on your server, never sent back to the browser. Safer: define it in wp-config.php with', 'fulgur-cache')) + ' <code>define( \'FULGUR_ANTHROPIC_API_KEY\', \'…\' );</code>') + '</p></div>' +
				'<div class="fulgur-field-input">' + (ai.has_key
					? '<div class="fulgur-connected">✓ ' + esc(__('Connected', 'fulgur-cache')) + (ai.key_locked ? '' : ' <button class="fulgur-link" data-action="ai-key-remove">' + esc(__('Remove', 'fulgur-cache')) + '</button>') + '</div>'
					: '<div class="fulgur-keyrow"><input type="password" id="fulgur-ai-key" placeholder="sk-ant-…" autocomplete="off"><button class="fulgur-btn is-secondary" data-action="ai-key-save">' + esc(__('Connect', 'fulgur-cache')) + '</button></div>') +
				'</div></div>';
			h += '<div class="fulgur-field is-wide"><div class="fulgur-field-text"><label for="fulgur-psi-key">' + esc(__('PageSpeed API key (optional)', 'fulgur-cache')) + '</label><p>' + esc(__('Without a key, Google limits the number of analyses. Free on console.cloud.google.com.', 'fulgur-cache')) + '</p></div>' +
				'<div class="fulgur-field-input"><div class="fulgur-keyrow"><input type="password" id="fulgur-psi-key" placeholder="' + esc(ai.has_psi_key ? '•••••••• ' + __('(saved)', 'fulgur-cache') : 'AIza…') + '" autocomplete="off"><button class="fulgur-btn is-secondary" data-action="ai-psi-save">' + esc(__('Save', 'fulgur-cache')) + '</button></div></div></div>';
			return h;
		},
		aiHistory: function () {
			var hist = state.ai.history || [];
			if (!hist.length) return '<p class="fulgur-note">' + esc(__('No optimization applied yet.', 'fulgur-cache')) + '</p>';
			return '<table class="fulgur-table"><thead><tr><th>' + esc(__('Date', 'fulgur-cache')) + '</th><th>' + esc(__('Mode', 'fulgur-cache')) + '</th><th>' + esc(__('Mobile score', 'fulgur-cache')) + '</th><th>' + esc(__('Settings', 'fulgur-cache')) + '</th><th>' + esc(__('Result', 'fulgur-cache')) + '</th></tr></thead><tbody>' + hist.map(function (e) {
				return '<tr><td>' + esc(new Date(e.date * 1000).toLocaleDateString(LOCALE)) + '</td><td>' + esc(e.mode === 'auto' ? __('Automatic', 'fulgur-cache') : __('Manual', 'fulgur-cache')) + '</td><td>' + (e.score_before != null ? e.score_before + ' → ' + (e.score_after != null ? e.score_after : '?') : '—') + '</td><td>' + esc((e.changes || []).join(', ')) + '</td><td>' + (e.rolled_back ? '<span class="fulgur-risk is-advanced">' + esc(__('Rolled back', 'fulgur-cache')) + '</span> ' + esc(e.reason) : '<span class="fulgur-risk is-safe">' + esc(__('Applied', 'fulgur-cache')) + '</span>') + '</td></tr>';
			}).join('') + '</tbody></table>';
		},
		bulkImages: function () {
			if (!state.images.webp) return '<div class="fulgur-inline is-warn">' + esc(__('Image conversion is not available on this server (GD with WebP or Imagick required).', 'fulgur-cache')) + '</div>';
			/* translators: %d: number of JPEG/PNG images in the media library */
			var text = sprintf(_n('%d JPEG/PNG image.', '%d JPEG/PNG images.', state.images.total, 'fulgur-cache'), state.images.total) + ' ' +
				__('New images are converted automatically on upload; theme images are converted in the background.', 'fulgur-cache') +
				(state.images.queue ? ' ' + sprintf(__('(%d queued)', 'fulgur-cache'), state.images.queue) : '');
			return '<div class="fulgur-inline"><div><strong>' + esc(__('Existing media library', 'fulgur-cache')) + '</strong><p>' + esc(text) + '</p>' +
				'<div class="fulgur-progress" id="fulgur-img-progress" hidden><div></div></div></div>' +
				'<button class="fulgur-btn is-secondary" data-action="bulk-images">' + esc(__('Convert the whole media library', 'fulgur-cache')) + '</button></div>';
		},
		preloadStatus: function () {
			var p = state.preload;
			return '<div class="fulgur-inline"><div><strong>' + esc(p.pending ? sprintf(_n('%d page being preloaded', '%d pages being preloaded', p.pending, 'fulgur-cache'), p.pending) : __('All pages are ready', 'fulgur-cache')) + '</strong><p>' + esc(sprintf(_n('%d page preloaded in total.', '%d pages preloaded in total.', state.stats.preloaded, 'fulgur-cache'), state.stats.preloaded)) + '</p></div>' +
				'<button class="fulgur-btn is-secondary" data-action="preload">' + esc(__('Preload everything now', 'fulgur-cache')) + '</button></div>';
		},
		database: function () {
			if (!dbData) {
				setTimeout(loadDb, 0);
				return '<div class="fulgur-inline"><span class="fulgur-spin"></span> ' + esc(__('Analyzing the database…', 'fulgur-cache')) + '</div>';
			}
			var h = '<ul class="fulgur-db">';
			Object.keys(dbData.tasks).forEach(function (k) {
				var n = dbData.counts[k] || 0;
				h += '<li><label><input type="checkbox" data-db="' + k + '"' + (n ? ' checked' : '') + '> ' + esc(dbData.tasks[k]) + '</label><span class="count ' + (n ? 'has' : '') + '">' + esc(k === 'optimize' ? (n ? sprintf(_n('%d table', '%d tables', n, 'fulgur-cache'), n) : __('OK', 'fulgur-cache')) : num(n)) + '</span></li>';
			});
			return h + '</ul><div class="fulgur-inline"><p>' + esc(__('Recent revisions (see the setting below) are always kept.', 'fulgur-cache')) + '</p><button class="fulgur-btn is-secondary" data-action="db-clean">' + esc(__('Clean selection', 'fulgur-cache')) + '</button></div>';
		},
		cloudflare: function () {
			return '<div class="fulgur-inline"><p>' + esc(__('Save your credentials first, then test the connection.', 'fulgur-cache')) + '</p><button class="fulgur-btn is-secondary" data-action="cf-test">' + esc(__('Test connection', 'fulgur-cache')) + '</button></div>';
		},
		diagnostics: function () {
			return checks(state.diagnostics) + '<div class="fulgur-inline"><p>' + esc(__('Reinstalls the cache engine, wp-config.php and the .htaccess rules.', 'fulgur-cache')) + '</p><button class="fulgur-btn is-secondary" data-action="repair">' + esc(__('Repair everything', 'fulgur-cache')) + '</button></div>';
		},
		nginx: function () {
			return '<p class="fulgur-note">' + esc(__('On Nginx, the cache already works through PHP. For 100% static serving, add this block to the server configuration (or send it to your host).', 'fulgur-cache')) + '</p>' +
				'<pre class="fulgur-code" id="fulgur-nginx">' + esc(state.nginx) + '</pre><button class="fulgur-btn is-small is-ghost" data-action="copy-nginx">' + esc(__('Copy', 'fulgur-cache')) + '</button>';
		},
		importExport: function () {
			return '<div class="fulgur-inline"><p>' + esc(__('Transfer your settings to another site.', 'fulgur-cache')) + '</p><div class="fulgur-btn-group">' +
				'<button class="fulgur-btn is-secondary" data-action="export">' + esc(__('Export', 'fulgur-cache')) + '</button>' +
				'<label class="fulgur-btn is-secondary">' + esc(__('Import', 'fulgur-cache')) + '<input type="file" accept="application/json" data-action="import" hidden></label>' +
				'<button class="fulgur-btn is-danger" data-action="reset">' + esc(__('Reset', 'fulgur-cache')) + '</button></div></div>';
		}
	};

	function onboarding() {
		return '<div class="fulgur-modal" role="dialog" aria-modal="true" aria-labelledby="fulgur-ob-title"><div class="fulgur-modal-box">' +
			'<div id="fulgur-ob-step1"><span class="fulgur-logo is-big">⚡</span><h2 id="fulgur-ob-title">' + esc(__('Welcome to Fulgur Cache', 'fulgur-cache')) + '</h2>' +
			'<p>' + esc(__('The cache is already active. Now choose your optimization level: you can change it at any time.', 'fulgur-cache')) + '</p>' +
			profiles(true) +
			'<button class="fulgur-link" data-action="skip-onboarding">' + esc(__('Skip, I\'ll configure it myself', 'fulgur-cache')) + '</button></div>' +
			'<div id="fulgur-ob-step2" hidden><span class="fulgur-logo is-big">⚡</span><h2>' + esc(__('Optimizing…', 'fulgur-cache')) + '</h2><ol class="fulgur-steps">' +
			'<li data-step="settings">' + esc(__('Applying settings', 'fulgur-cache')) + '</li><li data-step="preload">' + esc(__('Preloading pages', 'fulgur-cache')) + '</li><li data-step="speed">' + esc(__('Measuring speed', 'fulgur-cache')) + '</li></ol>' +
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
			busy(btn, true, __('Clearing…', 'fulgur-cache'));
			api('/purge', 'POST').then(function (s) { setState(s); toast(__('Cache cleared. Pages are being rebuilt in the background.', 'fulgur-cache')); })
				.catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
		},
		preload: function (btn) {
			busy(btn, true, __('Starting…', 'fulgur-cache'));
			api('/preload', 'POST').then(function (s) { setState(s); toast(sprintf(_n('Preloading started: %d page.', 'Preloading started: %d pages.', s.preload.pending, 'fulgur-cache'), s.preload.pending)); })
				.catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
		},
		save: function (btn) {
			busy(btn, true, __('Saving…', 'fulgur-cache'));
			api('/settings', 'POST', { values: draft }).then(function (s) {
				state = s; draft = clone(s.settings); render(); toast(__('Settings applied. Cache cleared and being rebuilt.', 'fulgur-cache'));
			}).catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
		},
		discard: function () { draft = clone(state.settings); render(); },
		profile: function (btn) {
			var id = btn.dataset.profile;
			if (btn.dataset.onboarding) return runOnboarding(id);
			if (dirty() && !confirm(__('Applying this profile will replace your unsaved changes. Continue?', 'fulgur-cache'))) return;
			busy(btn, true, __('Applying…', 'fulgur-cache'));
			api('/profile', 'POST', { profile: id }).then(function (s) {
				/* translators: %s: profile name */
				state = s; draft = clone(s.settings); render(); toast(sprintf(__('“%s” profile applied.', 'fulgur-cache'), s.profiles[id].label));
			}).catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
		},
		'skip-onboarding': function () {
			api('/settings', 'POST', { values: { onboarding_done: true, profile: state.settings.profile } }).then(function (s) {
				s.settings.onboarding_done = true; state = s; draft = clone(s.settings); render();
			});
		},
		repair: function (btn) {
			busy(btn, true, __('Repairing…', 'fulgur-cache'));
			api('/repair', 'POST').then(function (s) {
				setState(s);
				var bad = s.diagnostics.filter(function (d) { return d.status === 'error'; }).length;
				toast(bad ? __('Some items need manual action.', 'fulgur-cache') : __('Everything is repaired.', 'fulgur-cache'), bad ? 'warn' : 'ok');
			}).catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
		},
		speedtest: function (btn) {
			busy(btn, true, __('Measuring…', 'fulgur-cache'));
			api('/speedtest', 'POST').then(function () { return api('/status'); }).then(function (s) { setState(s); })
				.catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
		},
		pagespeed: function (btn) {
			var box = document.getElementById('fulgur-psi');
			busy(btn, true, __('Google analysis (30 s)…', 'fulgur-cache'));
			var url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?category=performance&strategy=mobile&locale=' + encodeURIComponent(LOCALE) + '&url=' + encodeURIComponent(state.home);
			fetch(url).then(function (r) { return r.json(); }).then(function (d) {
				busy(btn, false);
				if (!d.lighthouseResult) throw new Error(d.error && d.error.message ? d.error.message : __('Analysis failed', 'fulgur-cache'));
				var lr = d.lighthouseResult, score = Math.round(lr.categories.performance.score * 100), a = lr.audits;
				var cls = score >= 90 ? 'is-good' : score >= 50 ? 'is-avg' : 'is-bad';
				box.innerHTML = '<div class="fulgur-gauge ' + cls + '" style="--p:' + score + '"><span>' + score + '</span></div><ul>' +
					['largest-contentful-paint', 'total-blocking-time', 'cumulative-layout-shift', 'speed-index'].map(function (k) {
						return a[k] ? '<li><span>' + esc(a[k].title) + '</span><strong>' + esc(a[k].displayValue) + '</strong></li>' : '';
					}).join('') + '</ul>';
			}).catch(function (e) {
				busy(btn, false);
				box.innerHTML = '<p>' + esc(sprintf(__('PageSpeed unavailable (%s).', 'fulgur-cache'), e.message)) + ' <a href="https://pagespeed.web.dev/analysis?url=' + encodeURIComponent(state.home) + '" target="_blank" rel="noopener">' + esc(__('Open PageSpeed Insights', 'fulgur-cache')) + ' ↗</a></p>';
			});
		},
		'bulk-images': function (btn) {
			var bar = document.getElementById('fulgur-img-progress');
			bar.hidden = false;
			busy(btn, true, __('Converting…', 'fulgur-cache'));
			var converted = 0;
			(function step(offset) {
				api('/images/bulk', 'POST', { offset: offset }).then(function (r) {
					converted += r.converted;
					bar.firstChild.style.width = (r.total ? Math.min(100, (r.offset / r.total) * 100) : 100) + '%';
					if (!r.done) return step(r.offset);
					busy(btn, false);
					toast(sprintf(_n('%d file converted. The cache is being rebuilt with the new images.', '%d files converted. The cache is being rebuilt with the new images.', converted, 'fulgur-cache'), converted));
				}).catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
			})(0);
		},
		'db-clean': function (btn) {
			var tasks = [].slice.call(app.querySelectorAll('[data-db]:checked')).map(function (i) { return i.dataset.db; });
			if (!tasks.length) return toast(__('Select at least one item.', 'fulgur-cache'), 'warn');
			if (!confirm(__('Cleanup is permanent. Make sure you have a recent backup. Continue?', 'fulgur-cache'))) return;
			busy(btn, true, __('Cleaning…', 'fulgur-cache'));
			api('/database/clean', 'POST', { tasks: tasks }).then(function (d) {
				dbData = d; render(); toast(sprintf(_n('%d item removed.', '%d items removed.', d.removed, 'fulgur-cache'), d.removed));
			}).catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
		},
		'cf-test': function (btn) {
			if (dirty()) return toast(__('Save your Cloudflare credentials first.', 'fulgur-cache'), 'warn');
			busy(btn, true, __('Testing…', 'fulgur-cache'));
			/* translators: %s: Cloudflare zone (domain) name */
			api('/cloudflare', 'POST').then(function (d) { busy(btn, false); toast(sprintf(__('Connected to Cloudflare: %s', 'fulgur-cache'), d.zone)); })
				.catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
		},
		'copy-nginx': function () {
			navigator.clipboard.writeText(state.nginx).then(function () { toast(__('Configuration copied.', 'fulgur-cache')); });
		},
		export: function () {
			api('/export').then(function (d) {
				var a = document.createElement('a');
				a.href = URL.createObjectURL(new Blob([JSON.stringify(d, null, 2)], { type: 'application/json' }));
				a.download = 'fulgur-cache-settings.json';
				a.click();
			});
		},
		reset: function (btn) {
			if (!confirm(__('Go back to the default settings (Balanced profile)?', 'fulgur-cache'))) return;
			busy(btn, true);
			api('/reset', 'POST').then(function (s) { state = s; draft = clone(s.settings); render(); toast(__('Settings reset.', 'fulgur-cache')); });
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
		if (!input.value.trim()) return toast(__('Paste your Anthropic API key.', 'fulgur-cache'), 'warn');
		busy(btn, true, __('Checking…', 'fulgur-cache'));
		api('/ai/key', 'POST', { key: input.value.trim() }).then(function (s) { setState(s); toast(__('Claude is connected.', 'fulgur-cache')); })
			.catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
	};
	ACTIONS['ai-key-remove'] = function () {
		if (!confirm(__('Remove the API key? Autopilot will be disabled.', 'fulgur-cache'))) return;
		api('/ai/key', 'POST', { remove: true }).then(function (s) { setState(s); });
	};
	ACTIONS['ai-psi-save'] = function (btn) {
		var input = document.getElementById('fulgur-psi-key');
		busy(btn, true);
		api('/ai/key', 'POST', { psi_key: input.value.trim() }).then(function (s) { setState(s); toast(__('PageSpeed key saved.', 'fulgur-cache')); })
			.catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
	};
	ACTIONS['ai-start'] = function (btn) {
		if (dirty()) return toast(__('Save your changes first.', 'fulgur-cache'), 'warn');
		busy(btn, true, __('Starting…', 'fulgur-cache'));
		aiSelected = null;
		api('/ai/start', 'POST').then(function (ai) { state.ai = ai; render(); poll(); })
			.catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
	};
	ACTIONS['ai-apply'] = function (btn) {
		busy(btn, true, __('Applying…', 'fulgur-cache'));
		api('/ai/apply', 'POST', { keys: aiSelected || [] }).then(function (ai) { state.ai = ai; render(); poll(); })
			.catch(function (e) { busy(btn, false); toast(e.message, 'error'); });
	};
	ACTIONS['ai-undo'] = function (btn) {
		if (!confirm(__('Go back to the settings from before the last AI optimization?', 'fulgur-cache'))) return;
		busy(btn, true);
		api('/ai/undo', 'POST').then(function (s) { state = s; draft = clone(s.settings); render(); toast(__('Previous settings restored.', 'fulgur-cache')); });
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
				/* translators: 1: response time without cache in ms, 2: response time with cache in ms */
				? '<div class="fulgur-result"><span class="big">' + num(x, 1) + '×</span><p>' + sprintf(esc(__('faster: %1$s ms → %2$s ms', 'fulgur-cache')), r.before, '<strong>' + r.after + '</strong>') + '</p></div>'
				: '<div class="fulgur-result"><p>' + esc(__('Your site is optimized.', 'fulgur-cache')) + '</p></div>') +
				'<button class="fulgur-btn is-primary" data-action="finish-onboarding">' + esc(__('Go to my dashboard', 'fulgur-cache')) + '</button>';
		}).catch(function (e) {
			toast(e.message, 'error');
			document.getElementById('fulgur-ob-result').innerHTML = '<button class="fulgur-btn is-primary" data-action="finish-onboarding">' + esc(__('Continue', 'fulgur-cache')) + '</button>';
		});
	}
	ACTIONS['finish-onboarding'] = function () {
		api('/status').then(function (s) { state = s; draft = clone(s.settings); render(); });
	};

	/* ------------------------------------------------------------------ */
	/* Events                                                              */
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
			// Conditional fields appear / disappear.
			if (el.type === 'checkbox' || el.tagName === 'SELECT') {
				if ((prev === 'off') !== (draft[key] === 'off') || el.type === 'checkbox') render();
			}
			return;
		}
		if (el.dataset.action === 'import' && el.files[0]) {
			el.files[0].text().then(function (txt) {
				return api('/import', 'POST', { data: JSON.parse(txt) });
			}).then(function (s) { state = s; draft = clone(s.settings); render(); toast(__('Settings imported.', 'fulgur-cache')); })
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
