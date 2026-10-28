<?php
namespace Fulgur;

defined( 'ABSPATH' ) || exit;

/**
 * Optimiseur IA : Claude analyse le serveur, la charge, le HTML et PageSpeed,
 * propose les meilleurs réglages, puis Fulgur les applique, mesure et annule si le score baisse.
 *
 * Chaque étape tourne en arrière-plan (WP-Cron) pour ne jamais dépasser les délais d'exécution
 * des hébergements mutualisés ; l'interface interroge /ai/status.
 */
class Ai {

	const API_URL     = 'https://api.anthropic.com/v1/messages';
	const API_VERSION = '2026-10-21';
	const JOB         = 'fulgur_ai_job';
	const REPORT      = 'fulgur_ai_report';
	const BACKUP      = 'fulgur_ai_backup';
	const HISTORY     = 'fulgur_ai_history';
	const SECRETS     = 'fulgur_secrets';
	const HOOK        = 'fulgur_ai_step';
	const AUTOPILOT   = 'fulgur_ai_autopilot';

	/** Réglages que l'IA n'a jamais le droit de modifier. */
	const LOCKED = array( 'page_cache', 'profile', 'onboarding_done', 'cdn_enabled', 'cdn_url', 'cdn_exclusions', 'cloudflare_token', 'cloudflare_zone', 'ai_model', 'ai_auto', 'ai_psi' );

	public function __construct() {
		add_action( self::HOOK, array( __CLASS__, 'run_stage' ) );
		add_action( self::AUTOPILOT, array( __CLASS__, 'autopilot' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Clés API (hors réglages : jamais exportées ni renvoyées au navigateur) */
	/* ------------------------------------------------------------------ */

	public static function secret( $name ) {
		if ( 'anthropic' === $name && defined( 'FULGUR_ANTHROPIC_API_KEY' ) ) {
			return FULGUR_ANTHROPIC_API_KEY;
		}
		if ( 'psi' === $name && defined( 'FULGUR_PSI_API_KEY' ) ) {
			return FULGUR_PSI_API_KEY;
		}
		$secrets = (array) get_option( self::SECRETS, array() );
		return isset( $secrets[ $name ] ) ? (string) $secrets[ $name ] : '';
	}

	public static function set_secret( $name, $value ) {
		$secrets          = (array) get_option( self::SECRETS, array() );
		$secrets[ $name ] = sanitize_text_field( $value );
		update_option( self::SECRETS, $secrets, false );
	}

	public static function public_state() {
		$job = self::job();
		unset( $job['report'] );
		return array(
			'has_key'     => '' !== self::secret( 'anthropic' ),
			'key_locked'  => defined( 'FULGUR_ANTHROPIC_API_KEY' ),
			'has_psi_key' => '' !== self::secret( 'psi' ),
			'job'         => $job,
			'history'     => self::history(),
			'can_undo'    => (bool) get_option( self::BACKUP ),
		);
	}

	public static function test_key( $key ) {
		$res = wp_remote_get(
			'https://api.anthropic.com/v1/models/' . rawurlencode( Settings::get( 'ai_model' ) ),
			array(
				'timeout' => 15,
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => self::API_VERSION,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			return new \WP_Error( 'fulgur_ai_key', 401 === $code ? __( 'Anthropic rejected the API key.', 'fulgur-cache' ) : ( $data['error']['message'] ?? sprintf( __( 'Error %d', 'fulgur-cache' ), $code ) ) );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Tâches en arrière-plan                                              */
	/* ------------------------------------------------------------------ */

	public static function job() {
		return wp_parse_args(
			(array) get_option( self::JOB, array() ),
			array(
				'id'       => '',
				'stage'    => 'idle',
				'status'   => 'idle',
				'mode'     => 'manual',
				'locale'   => 'en_US',
				'message'  => '',
				'result'   => null,
				'verify'   => null,
				'selected' => array(),
				'started'  => 0,
				'updated'  => 0,
			)
		);
	}

	private static function save_job( array $job ) {
		$job['updated'] = time();
		update_option( self::JOB, $job, false );
		return $job;
	}

	/**
	 * Démarre une analyse complète (mode manual | suggest | auto).
	 */
	public static function start( $mode = 'manual' ) {
		if ( '' === self::secret( 'anthropic' ) ) {
			return new \WP_Error( 'fulgur_ai_key', __( 'Add your Anthropic API key first.', 'fulgur-cache' ) );
		}
		$job = self::save_job(
			array(
				'id'      => wp_generate_password( 8, false ),
				'stage'   => 'collect',
				'status'  => 'queued',
				'mode'    => $mode,
				'locale'  => is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ? get_user_locale() : get_locale(),
				'message' => __( 'Collecting measurements (server, load, PageSpeed)…', 'fulgur-cache' ),
				'started' => time(),
			)
		);
		self::schedule();
		return $job;
	}

	/**
	 * Applique les changements choisis, puis lance la vérification.
	 */
	public static function apply( array $keys ) {
		$job = self::job();
		if ( empty( $job['result']['changes'] ) ) {
			return new \WP_Error( 'fulgur_ai_nothing', __( 'No recommendation to apply.', 'fulgur-cache' ) );
		}
		$job['selected'] = array_values( array_intersect( $keys, wp_list_pluck( $job['result']['changes'], 'key' ) ) );
		if ( ! $job['selected'] ) {
			return new \WP_Error( 'fulgur_ai_nothing', __( 'Select at least one recommendation.', 'fulgur-cache' ) );
		}
		$job['stage']   = 'apply';
		$job['status']  = 'queued';
		$job['message'] = __( 'Applying settings…', 'fulgur-cache' );
		self::save_job( $job );
		self::schedule();
		return $job;
	}

	private static function schedule() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( time(), self::HOOK );
		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			spawn_cron();
		}
	}

	/**
	 * Si WP-Cron ne s'est pas déclenché (cron désactivé, loopback bloqué), l'étape est exécutée
	 * directement par la requête de suivi de l'interface.
	 */
	public static function maybe_run_inline() {
		$job = self::job();
		if ( 'queued' === $job['status'] && time() - (int) $job['updated'] > 15 ) {
			self::run_stage();
		}
		return self::public_state();
	}

	public static function run_stage() {
		@set_time_limit( 600 );
		@ignore_user_abort( true );

		$job = self::job();
		if ( 'queued' !== $job['status'] ) {
			return;
		}
		$job['status'] = 'running';
		$job           = self::save_job( $job );

		try {
			switch ( $job['stage'] ) {
				case 'collect':
					$report = Ai_Report::build( (bool) Settings::get( 'ai_psi' ) );
					update_option( self::REPORT, $report, false );
					$job = self::next( $job, 'analyze', __( 'Claude is analyzing your site…', 'fulgur-cache' ) );
					break;

				case 'analyze':
					$report        = (array) get_option( self::REPORT, array() );
					$job['result'] = self::analyze( $report, $job['locale'] );
					if ( is_wp_error( $job['result'] ) ) {
						throw new \RuntimeException( $job['result']->get_error_message() );
					}
					$job['result']['baseline'] = array(
						'mobile'  => $report['pagespeed']['mobile']['score'] ?? null,
						'desktop' => $report['pagespeed']['desktop']['score'] ?? null,
						'errors'  => $report['pagespeed']['mobile']['console_errors'] ?? null,
						'ttfb'    => $report['ttfb_ms']['after'] ?? null,
					);
					if ( 'auto' === $job['mode'] ) {
						$safe            = array_filter(
							$job['result']['changes'],
							static function ( $c ) {
								return 'safe' === $c['risk'];
							}
						);
						$job['selected'] = wp_list_pluck( $safe, 'key' );
						$job             = $job['selected'] ? self::next( $job, 'apply', __( 'Automatically applying safe settings…', 'fulgur-cache' ) ) : self::finish( $job, 'ready', __( 'No safe change to apply.', 'fulgur-cache' ) );
					} else {
						$job = self::finish( $job, 'ready', __( 'Recommendations ready.', 'fulgur-cache' ) );
						if ( 'suggest' === $job['mode'] ) {
							self::notify( $job );
						}
					}
					break;

				case 'apply':
					update_option( self::BACKUP, Settings::all(), false );
					$values = array();
					foreach ( $job['result']['changes'] as $change ) {
						if ( in_array( $change['key'], $job['selected'], true ) ) {
							$values[ $change['key'] ] = $change['value'];
						}
					}
					$values['profile'] = 'ai';
					Settings::update( $values );
					// La purge et le préchargement s'exécutent à la fin de cette requête (Purge::flush).
					$job = self::next( $job, 'verify', __( 'Checking: new PageSpeed test on the optimized site…', 'fulgur-cache' ), 45 );
					break;

				case 'verify':
					$job['verify'] = self::verify( $job );
					$job           = self::finish( $job, 'done', $job['verify']['message'] );
					if ( 'auto' === $job['mode'] ) {
						self::notify( $job );
					}
					break;
			}
		} catch ( \Throwable $e ) {
			$job = self::finish( $job, 'error', $e->getMessage() );
		}
	}

	private static function next( array $job, $stage, $message, $delay = 0 ) {
		$job['stage']   = $stage;
		$job['status']  = 'queued';
		$job['message'] = $message;
		$job            = self::save_job( $job );
		wp_schedule_single_event( time() + $delay, self::HOOK );
		return $job;
	}

	private static function finish( array $job, $stage, $message ) {
		$job['stage']   = $stage;
		$job['status']  = 'idle';
		$job['message'] = $message;
		return self::save_job( $job );
	}

	/**
	 * Mesure après application ; annule automatiquement si le site est plus lent ou a des erreurs JS.
	 */
	private static function verify( array $job ) {
		// Réchauffe la page d'accueil (cache + images) avant la mesure.
		wp_remote_get( home_url( '/' ), array( 'timeout' => 20, 'sslverify' => false ) );
		$check = wp_remote_get( home_url( '/' ), array( 'timeout' => 20, 'sslverify' => false ) );
		$ok    = ! is_wp_error( $check ) && 200 === wp_remote_retrieve_response_code( $check ) && false !== stripos( wp_remote_retrieve_body( $check ), '</html>' );

		$base   = $job['result']['baseline'];
		$after  = Settings::get( 'ai_psi' ) ? Ai_Report::pagespeed( home_url( '/' ), 'mobile' ) : array();
		$score  = $after['score'] ?? null;
		$errors = $after['console_errors'] ?? null;

		$reasons = array();
		if ( ! $ok ) {
			$reasons[] = __( 'the home page no longer displays correctly', 'fulgur-cache' );
		}
		if ( null !== $score && null !== $base['mobile'] && $score < $base['mobile'] - 3 ) {
			$reasons[] = sprintf( __( 'the mobile score dropped (%1$d → %2$d)', 'fulgur-cache' ), $base['mobile'], $score );
		}
		if ( null !== $errors && null !== $base['errors'] && $errors > $base['errors'] ) {
			$reasons[] = sprintf( __( 'new JavaScript errors detected (%1$d → %2$d)', 'fulgur-cache' ), $base['errors'], $errors );
		}

		$rolled_back = false;
		if ( $reasons ) {
			self::undo();
			$rolled_back = true;
		}

		$entry = array(
			'date'         => time(),
			'model'        => Settings::get( 'ai_model' ),
			'mode'         => $job['mode'],
			'score_before' => $base['mobile'],
			'score_after'  => $score,
			'changes'      => $job['selected'],
			'rolled_back'  => $rolled_back,
			'reason'       => implode( ' ; ', $reasons ),
		);
		$history = self::history();
		array_unshift( $history, $entry );
		update_option( self::HISTORY, array_slice( $history, 0, 10 ), false );

		if ( $rolled_back ) {
			$message = sprintf( __( 'Settings rolled back automatically: %s.', 'fulgur-cache' ), implode( '; ', $reasons ) );
		} elseif ( null !== $score && null !== $base['mobile'] ) {
			$message = sprintf( __( 'Settings applied. Mobile PageSpeed score: %1$d → %2$d.', 'fulgur-cache' ), $base['mobile'], $score );
		} else {
			$message = __( 'Settings applied and site checked.', 'fulgur-cache' );
		}
		return array(
			'score_before' => $base['mobile'],
			'score_after'  => $score,
			'metrics'      => $after['metrics'] ?? array(),
			'rolled_back'  => $rolled_back,
			'message'      => $message,
		);
	}

	public static function undo() {
		$backup = get_option( self::BACKUP );
		if ( ! is_array( $backup ) ) {
			return false;
		}
		delete_option( Settings::OPTION );
		Settings::reset_cache();
		Settings::update( $backup );
		delete_option( self::BACKUP );
		return true;
	}

	public static function history() {
		return (array) get_option( self::HISTORY, array() );
	}

	/* ------------------------------------------------------------------ */
	/* Appel à Claude                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array|\WP_Error Recommandations validées.
	 */
	public static function analyze( array $report, $locale = 'en_US' ) {
		$model   = Settings::get( 'ai_model' );
		$headers = array(
			'content-type'      => 'application/json',
			'x-api-key'         => self::secret( 'anthropic' ),
			'anthropic-version' => self::API_VERSION,
		);
		$body = array(
			'model'         => $model,
			'max_tokens'    => 16000,
			'system'        => self::system_prompt(),
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => 'Language for your answer: WordPress locale ' . $locale . ".\n\nHere is the site's technical report. Propose the best Fulgur Cache settings for this server and this load.\n\n" . wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				),
			),
			'output_config' => array(
				'effort' => 'high',
				'format' => array(
					'type'   => 'json_schema',
					'schema' => self::schema(),
				),
			),
		);
		// Si un classifieur de sécurité refuse la requête, l'API relance sur le modèle de repli recommandé.
		if ( in_array( $model, array( 'claude-opus-5-5', 'claude-sonnet-5-5' ), true ) ) {
			$body['fallbacks']        = 'default';
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}

		$res = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 300,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'fulgur_ai_http', sprintf( __( 'Could not connect to the Claude API: %s', 'fulgur-cache' ), $res->get_error_message() ) );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			$msg = $data['error']['message'] ?? sprintf( __( 'HTTP error %d', 'fulgur-cache' ), $code );
			if ( 401 === $code ) {
				$msg = __( 'Invalid API key.', 'fulgur-cache' );
			} elseif ( 429 === $code ) {
				$msg = __( 'Anthropic rate limit reached, try again in a few minutes.', 'fulgur-cache' );
			} elseif ( 529 === $code || $code >= 500 ) {
				$msg = __( 'The Claude API is temporarily overloaded, try again later.', 'fulgur-cache' );
			}
			return new \WP_Error( 'fulgur_ai_api', $msg );
		}

		$stop = $data['stop_reason'] ?? '';
		if ( 'refusal' === $stop ) {
			return new \WP_Error( 'fulgur_ai_refusal', __( 'Claude declined this analysis.', 'fulgur-cache' ) );
		}
		if ( 'max_tokens' === $stop ) {
			return new \WP_Error( 'fulgur_ai_truncated', __( 'Claude\'s answer was incomplete, try again.', 'fulgur-cache' ) );
		}

		$text = '';
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= $block['text'];
			}
		}
		$json = json_decode( $text, true );
		if ( ! is_array( $json ) ) {
			return new \WP_Error( 'fulgur_ai_json', __( 'Claude\'s answer could not be read.', 'fulgur-cache' ) );
		}

		$json['changes'] = self::validate_changes( (array) ( $json['changes'] ?? array() ) );
		$json['model']   = $data['model'] ?? $model;
		$json['usage']   = array(
			'input'  => (int) ( $data['usage']['input_tokens'] ?? 0 ),
			'output' => (int) ( $data['usage']['output_tokens'] ?? 0 ),
		);
		return $json;
	}

	/**
	 * Ne garde que des changements valides, autorisés et réellement différents de l'existant.
	 */
	public static function validate_changes( array $changes ) {
		$defaults = Settings::defaults();
		$choices  = Settings::choices();
		$current  = Settings::all();
		$valid    = array();
		foreach ( $changes as $c ) {
			$key = $c['key'] ?? '';
			if ( ! array_key_exists( $key, $defaults ) || in_array( $key, self::LOCKED, true ) || isset( $valid[ $key ] ) ) {
				continue;
			}
			$raw = (string) ( $c['value'] ?? '' );
			if ( is_bool( $defaults[ $key ] ) ) {
				$value = in_array( strtolower( trim( $raw ) ), array( 'true', '1', 'yes', 'on' ), true );
			} elseif ( is_int( $defaults[ $key ] ) ) {
				if ( ! is_numeric( trim( $raw ) ) ) {
					continue;
				}
				$value = max( 0, (int) $raw );
			} else {
				$value = str_replace( '\n', "\n", $raw );
				if ( isset( $choices[ $key ] ) && ! in_array( $value, $choices[ $key ], true ) ) {
					continue;
				}
			}
			if ( $value === $current[ $key ] ) {
				continue;
			}
			$valid[ $key ] = array(
				'key'      => $key,
				'value'    => $value,
				'current'  => $current[ $key ],
				'reason'   => sanitize_text_field( $c['reason'] ?? '' ),
				'risk'     => in_array( $c['risk'] ?? '', array( 'safe', 'moderate', 'advanced' ), true ) ? $c['risk'] : 'moderate',
				'impact'   => sanitize_text_field( $c['expected_impact'] ?? '' ),
			);
		}
		return array_values( $valid );
	}

	private static function schema() {
		$keys = array_values( array_diff( array_keys( Settings::defaults() ), self::LOCKED ) );
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'summary', 'diagnosis', 'changes', 'server_recommendations', 'expected_gain' ),
			'properties'           => array(
				'summary'                => array( 'type' => 'string' ),
				'diagnosis'              => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'title', 'detail', 'severity' ),
						'properties'           => array(
							'title'    => array( 'type' => 'string' ),
							'detail'   => array( 'type' => 'string' ),
							'severity' => array( 'type' => 'string', 'enum' => array( 'critical', 'high', 'medium', 'low' ) ),
						),
					),
				),
				'changes'                => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'key', 'value', 'reason', 'risk', 'expected_impact' ),
						'properties'           => array(
							'key'             => array( 'type' => 'string', 'enum' => $keys ),
							'value'           => array( 'type' => 'string' ),
							'reason'          => array( 'type' => 'string' ),
							'risk'            => array( 'type' => 'string', 'enum' => array( 'safe', 'moderate', 'advanced' ) ),
							'expected_impact' => array( 'type' => 'string' ),
						),
					),
				),
				'server_recommendations' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'expected_gain'          => array( 'type' => 'string' ),
			),
		);
	}

	private static function system_prompt() {
		$choices = Settings::choices();
		$lines   = array();
		foreach ( self::setting_docs() as $key => $doc ) {
			$default = Settings::defaults()[ $key ];
			$type    = is_bool( $default ) ? 'boolean ("true"/"false")' : ( is_int( $default ) ? 'integer' : ( isset( $choices[ $key ] ) ? 'one of: ' . implode( ', ', $choices[ $key ] ) : 'text, one value per line (\n)' ) );
			$lines[] = '- ' . $key . ' [' . $type . ']: ' . $doc;
		}
		return "You are an expert WordPress performance engineer (Core Web Vitals, Apache/Nginx/LiteSpeed servers, shared hosting and VPS). You configure the Fulgur Cache plugin for a site, based on a technical report.\n\n"
			. "Goal: the best possible mobile PageSpeed score and server response time WITHOUT breaking the site. A broken site is worse than a slow one.\n\n"
			. "Rules:\n"
			. "1. Only propose changes justified by a specific data point from the report (quote it in \"reason\").\n"
			. "2. Adapt to the server: little RAM or CPU, high load, shared hosting -> favor caching, compression and trimming WordPress; avoid anything CPU-heavy at page generation.\n"
			. "3. Adapt to the traffic: high traffic -> long cache lifespan and preloading; low traffic -> preloading is essential, otherwise visitors hit cold pages.\n"
			. "4. Page builders (Elementor, Divi...), sliders and cookie banners are sensitive to delayed JavaScript and CSS removal: add the needed exclusions (delay_exclusions, defer_exclusions, css_safelist) based on the script URLs in the report.\n"
			. "5. WooCommerce: do not remove cart-fragments if the site shows a mini-cart in the header on every page.\n"
			. "6. If the history shows a setting was rolled back automatically, do not propose it again unchanged.\n"
			. "7. risk: \"safe\" = cannot break the layout; \"moderate\" = needs checking; \"advanced\" = may break JavaScript features.\n"
			. "8. For text settings, \"value\" is the complete final list (existing entries kept + additions), one entry per line.\n"
			. "9. server_recommendations: hosting-side actions the plugin cannot do (PHP version, OPcache, Redis, HTTP/3, CDN, heavy plugins to replace...), concrete and prioritized.\n"
			. "10. Be concise and concrete. Do not include a setting that already has the right value.\n"
			. "11. Write every human-readable field (summary, diagnosis, reason, expected_impact, server_recommendations, expected_gain) in the language given in the request.\n\n"
			. "Available settings:\n" . implode( "\n", $lines );
	}

	private static function setting_docs() {
		return array(
			'cache_lifespan'        => 'hours before a cached page is regenerated (0 = unlimited, smart purge is enough).',
			'cache_mobile'          => 'separate mobile cache; only if the theme serves different HTML on mobile.',
			'cache_404'             => 'caches 404 pages (protects against bots).',
			'browser_cache'         => 'server compression + 1-year browser caching of static files (.htaccess).',
			'exclude_urls'          => 'URLs never cached.',
			'ignored_query_params'  => 'URL parameters ignored by the cache (tracking).',
			'exclude_cookies'       => 'cookies that disable the cache.',
			'exclude_user_agents'   => 'user agents excluded from the cache.',
			'minify_html'           => 'HTML minification.',
			'minify_css'            => 'minification of local CSS files.',
			'font_display_swap'     => 'adds font-display:swap to @font-face rules.',
			'google_fonts'          => 'off; swap (display=swap + preconnect); local (fonts hosted on the site, GDPR, one less connection).',
			'css_mode'              => 'off; critical (CSS used by the page inlined + full stylesheets loaded async, safe); remove (full stylesheets loaded only on first interaction, maximum gain, risk for elements added by JS).',
			'css_safelist'          => 'CSS class patterns always kept by css_mode (classes added dynamically by JS).',
			'defer_js'              => 'scripts deferred, order preserved, inline scripts turned into data URIs.',
			'defer_exclusions'      => 'patterns (URL or code) of scripts not to defer.',
			'delay_js'              => 'JavaScript runs only on first user interaction (huge TBT gain, risk for sliders/menus visible on load).',
			'delay_timeout'         => 'seconds before delayed scripts are forced to load (0 = interaction only).',
			'delay_exclusions'      => 'patterns (URL or code) of scripts loaded immediately despite delay_js.',
			'lazy_images'           => 'native lazy-loading of offscreen images.',
			'eager_images'          => 'number of above-the-fold images never lazy-loaded (the LCP image is detected and preloaded).',
			'image_dimensions'      => 'adds missing width/height (CLS).',
			'lazy_iframes'          => 'lazy-loading of iframes.',
			'youtube_facade'        => 'thumbnail instead of the YouTube player until click.',
			'next_gen_images'       => 'WebP/AVIF conversion and serving.',
			'image_format'          => 'webp (direct replacement) or avif (<picture> AVIF+WebP, needs an AVIF encoder).',
			'image_quality'         => 'conversion quality 50-95.',
			'preload_cache'         => 'preloads the whole site after a purge.',
			'speculation'           => 'Speculation Rules: off, prefetch, prerender (prerender = instant navigation but more server load).',
			'speculation_eagerness' => 'conservative (on click), moderate (on hover), eager.',
			'instant_fallback'      => 'hover prefetch for Safari/Firefox.',
			'preconnect_domains'    => 'critical third-party domains to preconnect.',
			'early_hints'           => 'preload Link headers (103 Early Hints).',
			'disable_emojis'        => 'removes the emoji script.',
			'disable_embeds'        => 'removes wp-embed.',
			'remove_jquery_migrate' => 'removes jQuery Migrate (risk with old plugins).',
			'heartbeat'             => 'Heartbeat API: default, reduce (60 s), disable_front, disable.',
			'disable_xmlrpc'        => 'disables XML-RPC (careful with Jetpack / mobile app).',
			'clean_head'            => 'cleans the HTML head.',
			'dashicons_visitors'    => 'removes Dashicons for visitors.',
			'wc_cart_fragments'     => 'removes WooCommerce cart-fragments outside shop pages.',
			'db_auto_clean'         => 'weekly database cleanup.',
			'db_revisions_keep'     => 'revisions kept per post.',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Pilote automatique                                                  */
	/* ------------------------------------------------------------------ */

	public static function schedule_autopilot() {
		$mode = Settings::get( 'ai_auto' );
		$next = wp_next_scheduled( self::AUTOPILOT );
		if ( 'off' === $mode || '' === self::secret( 'anthropic' ) ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::AUTOPILOT );
			}
			return;
		}
		if ( ! $next ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::AUTOPILOT );
		}
	}

	public static function autopilot() {
		$mode = Settings::get( 'ai_auto' );
		$job  = self::job();
		if ( 'off' === $mode || in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
			return;
		}
		self::start( $mode );
	}

	private static function notify( array $job ) {
		$subject = '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . __( 'Fulgur Cache: weekly AI analysis', 'fulgur-cache' );
		$lines   = array( $job['result']['summary'] ?? '', '' );
		if ( 'auto' === $job['mode'] && ! empty( $job['verify'] ) ) {
			$lines[] = $job['verify']['message'];
		} else {
			$lines[] = sprintf( _n( '%d recommendation awaiting approval.', '%d recommendations awaiting approval.', count( $job['result']['changes'] ?? array() ), 'fulgur-cache' ), count( $job['result']['changes'] ?? array() ) );
		}
		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=fulgur-cache#ai' );
		wp_mail( get_option( 'admin_email' ), $subject, implode( "\n", $lines ) );
	}
}
