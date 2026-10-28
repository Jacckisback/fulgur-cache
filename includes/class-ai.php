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
			return new \WP_Error( 'fulgur_ai_key', 401 === $code ? 'Clé API refusée par Anthropic.' : ( $data['error']['message'] ?? 'Erreur ' . $code ) );
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
			return new \WP_Error( 'fulgur_ai_key', 'Ajoute d\'abord ta clé API Anthropic.' );
		}
		$job = self::save_job(
			array(
				'id'      => wp_generate_password( 8, false ),
				'stage'   => 'collect',
				'status'  => 'queued',
				'mode'    => $mode,
				'message' => 'Collecte des mesures (serveur, charge, PageSpeed)…',
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
			return new \WP_Error( 'fulgur_ai_nothing', 'Aucune recommandation à appliquer.' );
		}
		$job['selected'] = array_values( array_intersect( $keys, wp_list_pluck( $job['result']['changes'], 'key' ) ) );
		if ( ! $job['selected'] ) {
			return new \WP_Error( 'fulgur_ai_nothing', 'Sélectionne au moins une recommandation.' );
		}
		$job['stage']   = 'apply';
		$job['status']  = 'queued';
		$job['message'] = 'Application des réglages…';
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
					$job = self::next( $job, 'analyze', 'Claude analyse ton site…' );
					break;

				case 'analyze':
					$report        = (array) get_option( self::REPORT, array() );
					$job['result'] = self::analyze( $report );
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
						$job             = $job['selected'] ? self::next( $job, 'apply', 'Application automatique des réglages sans risque…' ) : self::finish( $job, 'ready', 'Aucun changement sans risque à appliquer.' );
					} else {
						$job = self::finish( $job, 'ready', 'Recommandations prêtes.' );
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
					$job = self::next( $job, 'verify', 'Vérification : nouveau test PageSpeed sur le site optimisé…', 45 );
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
			$reasons[] = 'la page d\'accueil ne s\'affiche plus correctement';
		}
		if ( null !== $score && null !== $base['mobile'] && $score < $base['mobile'] - 3 ) {
			$reasons[] = sprintf( 'le score mobile a baissé (%d → %d)', $base['mobile'], $score );
		}
		if ( null !== $errors && null !== $base['errors'] && $errors > $base['errors'] ) {
			$reasons[] = sprintf( 'nouvelles erreurs JavaScript détectées (%d → %d)', $base['errors'], $errors );
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
			$message = 'Réglages annulés automatiquement : ' . implode( ' ; ', $reasons ) . '.';
		} elseif ( null !== $score && null !== $base['mobile'] ) {
			$message = sprintf( 'Réglages appliqués. Score PageSpeed mobile : %d → %d.', $base['mobile'], $score );
		} else {
			$message = 'Réglages appliqués et site vérifié.';
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
	public static function analyze( array $report ) {
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
					'content' => "Voici le rapport technique du site. Propose les meilleurs réglages Fulgur Cache pour ce serveur et cette charge.\n\n" . wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
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
			return new \WP_Error( 'fulgur_ai_http', 'Connexion à l\'API Claude impossible : ' . $res->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			$msg = $data['error']['message'] ?? 'Erreur HTTP ' . $code;
			if ( 401 === $code ) {
				$msg = 'Clé API invalide.';
			} elseif ( 429 === $code ) {
				$msg = 'Limite de requêtes Anthropic atteinte, réessaie dans quelques minutes.';
			} elseif ( 529 === $code || $code >= 500 ) {
				$msg = 'L\'API Claude est momentanément surchargée, réessaie plus tard.';
			}
			return new \WP_Error( 'fulgur_ai_api', $msg );
		}

		$stop = $data['stop_reason'] ?? '';
		if ( 'refusal' === $stop ) {
			return new \WP_Error( 'fulgur_ai_refusal', 'Claude a refusé cette analyse.' );
		}
		if ( 'max_tokens' === $stop ) {
			return new \WP_Error( 'fulgur_ai_truncated', 'Réponse de Claude incomplète, réessaie.' );
		}

		$text = '';
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= $block['text'];
			}
		}
		$json = json_decode( $text, true );
		if ( ! is_array( $json ) ) {
			return new \WP_Error( 'fulgur_ai_json', 'Réponse de Claude illisible.' );
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
			$type    = is_bool( Settings::defaults()[ $key ] ) ? 'booléen ("true"/"false")' : ( is_int( Settings::defaults()[ $key ] ) ? 'entier' : ( isset( $choices[ $key ] ) ? 'un parmi : ' . implode( ', ', $choices[ $key ] ) : 'texte, une valeur par ligne (\n)' ) );
			$lines[] = '- ' . $key . ' [' . $type . '] : ' . $doc;
		}
		return "Tu es un ingénieur expert en performance WordPress (Core Web Vitals, serveurs Apache/Nginx/LiteSpeed, hébergement mutualisé et VPS). Tu configures le plugin de cache Fulgur Cache d'un site à partir d'un rapport technique.\n\n"
			. "Objectif : le meilleur score PageSpeed mobile et le meilleur temps de réponse possible SANS casser le site. Un site cassé est pire qu'un site lent.\n\n"
			. "Règles :\n"
			. "1. Ne propose que des changements justifiés par une donnée précise du rapport (cite-la dans « reason »).\n"
			. "2. Adapte-toi au serveur : peu de RAM ou de CPU, charge élevée, hébergement mutualisé → privilégie le cache, la compression et l'allègement ; évite ce qui consomme du CPU à la génération.\n"
			. "3. Adapte-toi à la charge : beaucoup de trafic → durée de vie du cache longue et préchargement ; faible trafic → préchargement indispensable car les visiteurs tombent sinon sur des pages froides.\n"
			. "4. Les constructeurs de pages (Elementor, Divi…), sliders et bandeaux cookies sont sensibles au chargement différé du JavaScript et à la suppression du CSS : ajoute les exclusions nécessaires (delay_exclusions, defer_exclusions, css_safelist) en t'appuyant sur les URL de scripts du rapport.\n"
			. "5. WooCommerce : ne retire pas cart-fragments si le site affiche un mini-panier dans l'en-tête sur toutes les pages.\n"
			. "6. Si l'historique montre qu'un réglage a été annulé automatiquement, ne le repropose pas tel quel.\n"
			. "7. risk : « safe » = ne peut pas casser l'affichage ; « moderate » = à vérifier ; « advanced » = peut casser des fonctionnalités JavaScript.\n"
			. "8. Pour les champs texte, « value » est la liste complète finale (existant conservé + ajouts), une entrée par ligne.\n"
			. "9. server_recommendations : actions côté hébergement que le plugin ne peut pas faire (version PHP, OPcache, Redis, HTTP/3, CDN, plugins lourds à remplacer…), concrètes et priorisées.\n"
			. "10. Réponds en français, de façon concise et concrète. N'inclus pas un réglage déjà à la bonne valeur.\n\n"
			. "Réglages disponibles :\n" . implode( "\n", $lines );
	}

	private static function setting_docs() {
		return array(
			'cache_lifespan'        => 'heures avant régénération d\'une page en cache (0 = illimité, la purge intelligente suffit).',
			'cache_mobile'          => 'cache séparé mobile ; uniquement si le thème sert un HTML différent sur mobile.',
			'cache_404'             => 'met en cache les pages 404 (protège contre les robots).',
			'browser_cache'         => 'compression serveur + cache navigateur 1 an des fichiers statiques (.htaccess).',
			'exclude_urls'          => 'URL jamais mises en cache.',
			'ignored_query_params'  => 'paramètres d\'URL ignorés pour le cache (tracking).',
			'exclude_cookies'       => 'cookies qui désactivent le cache.',
			'exclude_user_agents'   => 'user-agents exclus du cache.',
			'minify_html'           => 'minification HTML.',
			'minify_css'            => 'minification des fichiers CSS locaux.',
			'font_display_swap'     => 'ajoute font-display:swap aux @font-face.',
			'google_fonts'          => 'off ; swap (display=swap + préconnexion) ; local (polices hébergées sur le site, RGPD, une connexion en moins).',
			'css_mode'              => 'off ; critical (CSS utilisé par la page inline + feuilles complètes en asynchrone, sûr) ; remove (feuilles complètes chargées seulement à la première interaction, gain maximal, risque sur les éléments ajoutés par JS).',
			'css_safelist'          => 'motifs de classes CSS toujours conservés par css_mode (classes ajoutées dynamiquement par JS).',
			'defer_js'              => 'scripts en defer, ordre préservé, scripts inline transformés en data-URI.',
			'defer_exclusions'      => 'motifs (URL ou code) de scripts à ne pas reporter.',
			'delay_js'              => 'JavaScript exécuté seulement à la première interaction (énorme gain TBT, risque sur sliders/menus visibles au chargement).',
			'delay_timeout'         => 'secondes avant chargement forcé des scripts différés (0 = interaction uniquement).',
			'delay_exclusions'      => 'motifs (URL ou code) de scripts chargés immédiatement malgré delay_js.',
			'lazy_images'           => 'lazy-load natif des images hors écran.',
			'eager_images'          => 'nombre d\'images en haut de page jamais différées (l\'image LCP est détectée et préchargée).',
			'image_dimensions'      => 'ajoute width/height manquants (CLS).',
			'lazy_iframes'          => 'lazy-load des iframes.',
			'youtube_facade'        => 'vignette à la place du lecteur YouTube jusqu\'au clic.',
			'next_gen_images'       => 'conversion et service WebP/AVIF.',
			'image_format'          => 'webp (remplacement direct) ou avif (<picture> AVIF+WebP, nécessite un encodeur AVIF).',
			'image_quality'         => 'qualité de conversion 50-95.',
			'preload_cache'         => 'préchargement de tout le site après purge.',
			'speculation'           => 'Speculation Rules : off, prefetch, prerender (prerender = navigation instantanée mais charge serveur accrue).',
			'speculation_eagerness' => 'conservative (au clic), moderate (au survol), eager.',
			'instant_fallback'      => 'prefetch au survol pour Safari/Firefox.',
			'preconnect_domains'    => 'domaines tiers critiques à préconnecter.',
			'early_hints'           => 'en-têtes Link de préchargement (103 Early Hints).',
			'disable_emojis'        => 'retire le script emoji.',
			'disable_embeds'        => 'retire wp-embed.',
			'remove_jquery_migrate' => 'retire jQuery Migrate (risque avec vieux plugins).',
			'heartbeat'             => 'API Heartbeat : default, reduce (60 s), disable_front, disable.',
			'disable_xmlrpc'        => 'désactive XML-RPC (attention Jetpack / app mobile).',
			'clean_head'            => 'nettoie l\'en-tête HTML.',
			'dashicons_visitors'    => 'retire les Dashicons pour les visiteurs.',
			'wc_cart_fragments'     => 'retire cart-fragments WooCommerce hors pages boutique.',
			'db_auto_clean'         => 'nettoyage hebdomadaire de la base.',
			'db_revisions_keep'     => 'révisions conservées par article.',
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
		$subject = '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] Fulgur Cache : analyse IA hebdomadaire';
		$lines   = array( $job['result']['summary'] ?? '', '' );
		if ( 'auto' === $job['mode'] && ! empty( $job['verify'] ) ) {
			$lines[] = $job['verify']['message'];
		} else {
			$lines[] = count( $job['result']['changes'] ?? array() ) . ' recommandation(s) en attente de validation.';
		}
		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=fulgur-cache#ai' );
		wp_mail( get_option( 'admin_email' ), $subject, implode( "\n", $lines ) );
	}
}
