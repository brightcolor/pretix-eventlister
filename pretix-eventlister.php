<?php
/**
 * Plugin Name: Pretix Eventlister
 * Description: Displays pretix events in a modern, responsive WordPress layout.
 * Version: 2.0.0
 * Author: bright color
 * Author URI: https://github.com/brightcolor/pretix-eventlister
 * Text Domain: pretix-eventlister
 * Update URI: https://github.com/brightcolor/pretix-eventlister
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Pretix_Eventlister {
	const VERSION = '2.0.0';
	const PLUGIN_SLUG = 'pretix-eventlister';
	const OPTION_KEY = 'pretix_eventlister_options';
	const CACHE_PREFIX = 'pretix_eventlister_';
	const GITHUB_REPOSITORY = 'brightcolor/pretix-eventlister';
	const GITHUB_REPOSITORY_URL = 'https://github.com/brightcolor/pretix-eventlister';
	const GITHUB_RELEASES_API = 'https://api.github.com/repos/brightcolor/pretix-eventlister/releases/latest';
	const GITHUB_RELEASE_CACHE_KEY = 'pretix_eventlister_github_release';
	const GITHUB_RELEASE_CACHE_TTL = 21600;
	const MINIMUM_PHP = '7.4';
	const CPT = 'pretix_eventlister_event';
	const CRON_HOOK = 'pretix_eventlister_sync_events';
	private $inline_translations = array();
	private $manual_override_cache = array();

	public function __construct() {
		add_action('plugins_loaded', array($this, 'load_textdomain'));
		add_action('init', array($this, 'register_blocks'));
		add_action('init', array($this, 'register_cpt'));
		add_action('init', array($this, 'register_cron'));
		add_action('wp_ajax_pretix_eventlister_ics', array($this, 'download_ics'));
		add_action('wp_ajax_nopriv_pretix_eventlister_ics', array($this, 'download_ics'));
		add_action('admin_post_pretix_eventlister_flush_cache', array($this, 'handle_admin_flush_cache'));
		add_action('admin_post_pretix_eventlister_test_api', array($this, 'handle_admin_test_api'));
		add_action(self::CRON_HOOK, array($this, 'sync_events_to_cpt'));
		add_action('add_meta_boxes', array($this, 'register_event_override_metabox'));
		add_action('save_post_' . self::CPT, array($this, 'save_event_override_metabox'), 10, 2);
		add_action('admin_init', array($this, 'normalize_installation_state'));
		add_action('admin_menu', array($this, 'register_settings_page'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_plugin_admin_assets'));
		add_action('wp_enqueue_scripts', array($this, 'register_assets'));
		add_action('upgrader_process_complete', array($this, 'handle_upgrader_process_complete'), 10, 2);
		add_filter('upgrader_source_selection', array($this, 'prefer_plugin_source_directory'), 1, 4);
		add_filter('upgrader_package_options', array($this, 'force_destination_directory'), 1);
		add_filter('pre_set_site_transient_update_plugins', array($this, 'inject_update_information'));
		add_filter('plugins_api', array($this, 'inject_plugin_information'), 20, 3);
		add_filter('plugin_row_meta', array($this, 'add_plugin_row_meta'), 10, 4);
		add_filter('gettext', array($this, 'translate_inline_messages'), 20, 3);
		add_shortcode('pretix_events', array($this, 'render_shortcode'));
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			'pretix-eventlister',
			false,
			dirname(plugin_basename(__FILE__)) . '/languages'
		);

		$locale = function_exists('determine_locale') ? determine_locale() : get_locale();
		if (! is_string($locale)) {
			$locale = '';
		}

		if (0 !== strpos(strtolower($locale), 'de')) {
			$this->inline_translations = array();
			return;
		}

		$file = plugin_dir_path(__FILE__) . 'languages/pretix-eventlister-de_DE.php';
		if (is_readable($file)) {
			$map = require $file;
			$this->inline_translations = is_array($map) ? $map : array();
		}
	}

	public function translate_inline_messages($translation, $text, $domain) {
		if ('pretix-eventlister' !== $domain) {
			return $translation;
		}

		if (! is_array($this->inline_translations) || empty($this->inline_translations)) {
			return $translation;
		}

		return isset($this->inline_translations[ $text ]) ? $this->inline_translations[ $text ] : $translation;
	}

	public function force_destination_directory($options) {
		if (! is_array($options)) {
			return $options;
		}

		if (empty($options['destination']) || ! is_string($options['destination'])) {
			return $options;
		}

		$is_plugin_operation = isset($options['hook_extra']['type']) && 'plugin' === $options['hook_extra']['type'];
		$is_our_plugin_update = isset($options['hook_extra']['plugin']) && $this->get_plugin_basename() === $options['hook_extra']['plugin'];

		$package = isset($options['package']) && is_string($options['package']) ? $options['package'] : '';
		$is_our_package = $this->is_our_package_reference($package);
		$is_our_uploaded_zip = $this->is_uploaded_our_plugin_zip();

		if (! $is_our_plugin_update && ! ($is_plugin_operation && ($is_our_package || $is_our_uploaded_zip))) {
			return $options;
		}

		// Ensures root-style and nested ZIPs install into /wp-content/plugins/pretix-eventlister/.
		$options['destination_name'] = self::PLUGIN_SLUG;
		$options['abort_if_destination_exists'] = false;

		return $options;
	}

	public function register_blocks() {
		if (! function_exists('register_block_type')) {
			return;
		}

		wp_register_script(
			'pretix-eventlister-block',
			plugin_dir_url(__FILE__) . 'assets/js/block.js',
			array('wp-blocks', 'wp-element', 'wp-i18n', 'wp-components', 'wp-block-editor', 'wp-server-side-render'),
			self::VERSION,
			true
		);

		register_block_type(
			'pretix-eventlister/events',
			array(
				'api_version' => 2,
				'title' => __('Pretix Events', 'pretix-eventlister'),
				'description' => __('Zeigt kommende pretix-Events als moderne Kartenliste.', 'pretix-eventlister'),
				'category' => 'widgets',
				'icon' => 'calendar-alt',
				'attributes' => array(
					'limit' => array('type' => 'string', 'default' => '9'),
					'scope' => array('type' => 'string', 'default' => 'selected'),
					'organizers' => array('type' => 'string', 'default' => ''),
					'style' => array('type' => 'string', 'default' => 'default'),
					'show_description' => array('type' => 'string', 'default' => 'default'),
					'show_organizer' => array('type' => 'string', 'default' => 'default'),
					'show_image' => array('type' => 'string', 'default' => 'default'),
					'show_countdown' => array('type' => 'string', 'default' => 'default'),
					'show_location' => array('type' => 'string', 'default' => 'default'),
					'show_time' => array('type' => 'string', 'default' => 'default'),
					'show_platform_notice' => array('type' => 'string', 'default' => 'default'),
					'filters' => array('type' => 'string', 'default' => 'default'),
					'load_more' => array('type' => 'string', 'default' => 'default'),
					'page_size' => array('type' => 'string', 'default' => ''),
					'badges' => array('type' => 'string', 'default' => 'default'),
					'badges_availability' => array('type' => 'string', 'default' => 'default'),
					'show_available_tickets' => array('type' => 'string', 'default' => 'default'),
					'calendar' => array('type' => 'string', 'default' => 'default'),
					'schema' => array('type' => 'string', 'default' => 'default'),
					'modal' => array('type' => 'string', 'default' => 'default'),
					'tilt' => array('type' => 'string', 'default' => 'default'),
				),
				'editor_script' => 'pretix-eventlister-block',
				'render_callback' => array($this, 'render_block'),
			)
		);
	}

	public function render_block($attributes) {
		return $this->render_shortcode(is_array($attributes) ? $attributes : array());
	}

	public function register_settings_page() {
		add_options_page(
			__('Pretix Eventlister', 'pretix-eventlister'),
			__('Pretix Eventlister', 'pretix-eventlister'),
			'manage_options',
			'pretix-eventlister',
			array($this, 'render_settings_page')
		);
	}

	public function register_settings() {
		register_setting(
			'pretix_eventlister',
			self::OPTION_KEY,
			array($this, 'sanitize_options')
		);

		add_settings_section(
			'pretix_eventlister_api',
			__('Pretix API und Auswahl', 'pretix-eventlister'),
			function () {
				echo '<p>' . esc_html__('Verbinde hier deine pretix-Instanz und definiere optional Standard-Veranstalter.', 'pretix-eventlister') . '</p>';
			},
			'pretix-eventlister'
		);

		add_settings_section(
			'pretix_eventlister_notes',
			__('Hinweise fuer Partner-Events', 'pretix-eventlister'),
			function () {
				echo '<p>' . esc_html__('Fuer bestimmte Veranstalter kann ein Hinweis eingeblendet werden, dass die Plattform nur fuer Ticketing und Infrastruktur bereitgestellt wird.', 'pretix-eventlister') . '</p>';
			},
			'pretix-eventlister'
		);

		add_settings_section(
			'pretix_eventlister_display',
			__('Darstellung und Features', 'pretix-eventlister'),
			function () {
				echo '<p>' . esc_html__('Alle Zusatzfunktionen sind optional. Du kannst sie global aktivieren und pro Shortcode oder Block wieder ueberschreiben.', 'pretix-eventlister') . '</p>';
			},
			'pretix-eventlister'
		);

		add_settings_section(
			'pretix_eventlister_visibility',
			__('Sichtbarkeit steuern', 'pretix-eventlister'),
			function () {
				echo '<p>' . esc_html__('Hier kannst du Veranstalter und einzelne Events gezielt ausblenden, ohne sie in pretix zu loeschen.', 'pretix-eventlister') . '</p>';
			},
			'pretix-eventlister'
		);

		add_settings_section(
			'pretix_eventlister_tools',
			__('Tools', 'pretix-eventlister'),
			function () {
				echo '<p>' . esc_html__('Hilfsfunktionen zum Debuggen und zum Cache-Management.', 'pretix-eventlister') . '</p>';
			},
			'pretix-eventlister'
		);

		add_settings_section(
			'pretix_eventlister_cpt',
			__('Optional: Events als WordPress-Beitraege (CPT)', 'pretix-eventlister'),
			function () {
				echo '<p>' . esc_html__('Optional kannst du Events als Custom Post Type synchronisieren, damit sie besser durchsuchbar und indexierbar sind. Standardmaessig ist das deaktiviert.', 'pretix-eventlister') . '</p>';
			},
			'pretix-eventlister'
		);

		$fields = array(
			array(
				'key' => 'base_url',
				'label' => __('Pretix Basis-URL', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_api',
				'type' => 'url',
				'description' => __('Beispiel: https://tickets.example.de', 'pretix-eventlister'),
			),
			array(
				'key' => 'default_organizers',
				'label' => __('Standard-Veranstalter', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_api',
				'type' => 'textarea',
				'rows' => 3,
				'description' => __('Mehrere Slugs mit Komma oder Zeilenumbruch trennen. Leer lassen, damit der Shortcode alle Veranstalter der Instanz laden kann.', 'pretix-eventlister'),
			),
			array(
				'key' => 'api_token',
				'label' => __('API-Token', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_api',
				'type' => 'password',
				'description' => __('Empfohlen: ein API-Token mit Leserechten auf Organizer und Events.', 'pretix-eventlister'),
			),
			array(
				'key' => 'cache_ttl',
				'label' => __('Cache-Dauer (Minuten)', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_api',
				'type' => 'number',
				'min' => 1,
				'step' => 1,
				'description' => __('Reduziert API-Aufrufe und beschleunigt die Ausgabe.', 'pretix-eventlister'),
			),
			array(
				'key' => 'platform_organizers',
				'label' => __('Organizer mit Plattform-Hinweis', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_notes',
				'type' => 'textarea',
				'rows' => 3,
				'description' => __('Diese Veranstalter erhalten automatisch einen Hinweis auf der Event-Karte.', 'pretix-eventlister'),
			),
			array(
				'key' => 'platform_notice',
				'label' => __('Hinweistext', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_notes',
				'type' => 'textarea',
				'rows' => 4,
				'description' => __('Wird auf Karten der markierten Veranstalter angezeigt.', 'pretix-eventlister'),
			),
			array(
				'key' => 'platform_notice_map',
				'label' => __('Hinweistext pro Organizer (optional)', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_notes',
				'type' => 'textarea',
				'rows' => 6,
				'description' => __('Format: eine Zeile pro Organizer, z.B. `partner-a|Eigener Hinweistext ...`. Wenn gesetzt, ueberschreibt das den globalen Hinweistext.', 'pretix-eventlister'),
			),
			array(
				'key' => 'disabled_organizers',
				'label' => __('Deaktivierte Veranstalter', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_visibility',
				'type' => 'textarea',
				'rows' => 3,
				'description' => __('Slugs mit Komma oder Zeilenumbruch. Diese Veranstalter werden weder im Frontend noch im Sync verarbeitet.', 'pretix-eventlister'),
			),
			array(
				'key' => 'hidden_events',
				'label' => __('Ausgeblendete Events', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_visibility',
				'type' => 'textarea',
				'rows' => 5,
				'description' => __('Eine Zeile pro Event im Format `organizer-slug/event-slug`. Diese Events werden nicht ausgegeben und nicht in den Sync uebernommen.', 'pretix-eventlister'),
			),

			array(
				'key' => 'default_style',
				'label' => __('Standard-Layout', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'select',
				'options' => array(
					'grid' => __('Grid', 'pretix-eventlister'),
					'list' => __('Liste', 'pretix-eventlister'),
					'compact' => __('Kompakt', 'pretix-eventlister'),
				),
				'description' => __('Kann pro Shortcode/Block ueberschrieben werden.', 'pretix-eventlister'),
			),
			array(
				'key' => 'default_show_description',
				'label' => __('Beschreibung standardmaessig anzeigen', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
			),
			array(
				'key' => 'default_show_organizer',
				'label' => __('Veranstalter standardmaessig anzeigen', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
			),
			array(
				'key' => 'show_image',
				'label' => __('Eventbild anzeigen', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
			),
			array(
				'key' => 'show_time',
				'label' => __('Uhrzeit anzeigen', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
			),
			array(
				'key' => 'show_location',
				'label' => __('Ort anzeigen', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
			),
			array(
				'key' => 'show_countdown',
				'label' => __('Countdown "Beginnt in X Tagen" anzeigen', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
			),
			array(
				'key' => 'show_platform_notice',
				'label' => __('Plattform-Hinweis anzeigen', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
			),
			array(
				'key' => 'show_organizer_slug',
				'label' => __('Organizer-Slug in Kartenfuss anzeigen', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
				'description' => __('Hilfreich fuer interne Seiten oder Debugging.', 'pretix-eventlister'),
			),
			array(
				'key' => 'show_ticket_button',
				'label' => __('Ticket-Button anzeigen', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
			),
			array(
				'key' => 'show_ticket_price',
				'label' => __('Ticketpreis im Button anzeigen ("Tickets ab ...")', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
			),
			array(
				'key' => 'feature_filters',
				'label' => __('Frontend-Filter (Veranstalter, Zeitraum, Ort, Suche)', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
			),
			array(
				'key' => 'feature_load_more',
				'label' => __('Pagination / "Mehr laden" aktivieren', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
			),
			array(
				'key' => 'page_size',
				'label' => __('Page-Size fuer "Mehr laden"', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'number',
				'min' => 1,
				'step' => 1,
				'description' => __('Wie viele Karten initial sichtbar sind und pro Klick nachgeladen werden.', 'pretix-eventlister'),
			),
			array(
				'key' => 'feature_badges',
				'label' => __('Badges (kostenlos, online, mehrtägig, demnächst)', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
			),
			array(
				'key' => 'feature_badges_availability',
				'label' => __('Badges für Verfügbarkeit (ausverkauft / wenige Tickets)', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
				'description' => __('Erfordert zusaetzliche API-Abfragen (Quotas).', 'pretix-eventlister'),
			),
			array(
				'key' => 'show_available_tickets',
				'label' => __('Verfügbare Tickets je Event anzeigen', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
				'description' => __('Zeigt pro Event die aktuell verfügbare Ticketanzahl aus den Quotas an.', 'pretix-eventlister'),
			),
			array(
				'key' => 'low_ticket_threshold',
				'label' => __('Schwelle fuer "Wenige Tickets"', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'number',
				'min' => 1,
				'step' => 1,
				'description' => __('Ab dieser verfügbaren Ticketanzahl (oder weniger) wird der Hinweis "Wenige Tickets" gesetzt.', 'pretix-eventlister'),
			),
			array(
				'key' => 'feature_calendar',
				'label' => __('"In Kalender" Links (ICS, Google, Outlook)', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
			),
			array(
				'key' => 'feature_schema',
				'label' => __('schema.org Event-Markup (JSON-LD) ausgeben', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
			),
			array(
				'key' => 'feature_modal',
				'label' => __('Detailansicht als Modal (statt nur Link)', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
			),
			array(
				'key' => 'feature_tilt',
				'label' => __('3D-Tilt Hover-Effekt aktivieren', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'checkbox',
			),
			array(
				'key' => 'pinned_events',
				'label' => __('Hervorgehobene / angepinnte Events', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'textarea',
				'rows' => 4,
				'description' => __('Eine Zeile pro Event im Format `organizer-slug/event-slug`. Diese Events werden in der Liste nach oben sortiert.', 'pretix-eventlister'),
			),
			array(
				'key' => 'accent_color',
				'label' => __('Akzentfarbe (optional)', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_display',
				'type' => 'color',
				'description' => __('Leer lassen fuer die Standardfarbe.', 'pretix-eventlister'),
			),

			array(
				'key' => 'enable_cpt_sync',
				'label' => __('CPT-Synchronisierung aktivieren', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_cpt',
				'type' => 'checkbox',
			),
			array(
				'key' => 'cpt_sync_scope',
				'label' => __('CPT-Scope', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_cpt',
				'type' => 'select',
				'options' => array(
					'selected' => __('Nur ausgewaehlte Veranstalter', 'pretix-eventlister'),
					'all' => __('Alle Veranstalter der Instanz', 'pretix-eventlister'),
				),
			),
			array(
				'key' => 'cpt_sync_organizers',
				'label' => __('CPT-Veranstalter (optional)', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_cpt',
				'type' => 'textarea',
				'rows' => 3,
				'description' => __('Wenn CPT-Scope = "Nur ausgewaehlte Veranstalter": hier Slugs angeben (Komma oder Zeilenumbruch). Leer = Standard-Veranstalter.', 'pretix-eventlister'),
			),
			array(
				'key' => 'cpt_sync_interval',
				'label' => __('CPT-Sync Intervall (Stunden)', 'pretix-eventlister'),
				'section' => 'pretix_eventlister_cpt',
				'type' => 'number',
				'min' => 1,
				'step' => 1,
				'description' => __('Wie oft WordPress automatisch synchronisiert. (Es wird immer ein Cache genutzt.)', 'pretix-eventlister'),
			),
		);

		foreach ($fields as $field) {
			add_settings_field(
				$field['key'],
				$field['label'],
				array($this, 'render_field'),
				'pretix-eventlister',
				$field['section'],
				$field
			);
		}

		add_settings_field(
			'pretix_eventlister_tools_actions',
			__('Aktionen', 'pretix-eventlister'),
			array($this, 'render_tools_field'),
			'pretix-eventlister',
			'pretix_eventlister_tools',
			array()
		);
	}

	public function sanitize_options($options) {
		$current = $this->get_options();
		$present_fields = isset($options['__present_fields']) && is_array($options['__present_fields'])
			? array_values(array_unique(array_map('sanitize_key', $options['__present_fields'])))
			: array();
		$present_lookup = array_fill_keys($present_fields, true);
		$platform_notice = isset($options['platform_notice']) ? sanitize_textarea_field($options['platform_notice']) : '';

		$sanitized = array(
			'base_url' => isset($options['base_url']) ? untrailingslashit(esc_url_raw($options['base_url'])) : '',
			'default_organizers' => isset($options['default_organizers']) ? $this->sanitize_slug_list($options['default_organizers']) : (isset($options['organizer']) ? $this->sanitize_slug_list($options['organizer']) : ''),
			'api_token' => isset($options['api_token']) ? sanitize_text_field($options['api_token']) : '',
			'cache_ttl' => isset($options['cache_ttl']) ? max(1, absint($options['cache_ttl'])) : 15,
			'platform_organizers' => isset($options['platform_organizers']) ? $this->sanitize_slug_list($options['platform_organizers']) : '',
			'platform_notice' => $platform_notice ? $platform_notice : $this->get_default_platform_notice(),
			'platform_notice_map' => isset($options['platform_notice_map']) ? sanitize_textarea_field($options['platform_notice_map']) : '',
			'disabled_organizers' => isset($options['disabled_organizers']) ? $this->sanitize_slug_list($options['disabled_organizers']) : '',
			'hidden_events' => isset($options['hidden_events']) ? $this->sanitize_event_key_list($options['hidden_events']) : '',
			'default_style' => isset($options['default_style']) && in_array($options['default_style'], array('grid', 'list', 'compact'), true) ? $options['default_style'] : 'grid',
			'default_show_description' => ! empty($options['default_show_description']) ? 1 : 0,
			'default_show_organizer' => ! empty($options['default_show_organizer']) ? 1 : 0,
			'show_image' => ! empty($options['show_image']) ? 1 : 0,
			'show_time' => ! empty($options['show_time']) ? 1 : 0,
			'show_location' => ! empty($options['show_location']) ? 1 : 0,
			'show_countdown' => ! empty($options['show_countdown']) ? 1 : 0,
			'show_platform_notice' => ! empty($options['show_platform_notice']) ? 1 : 0,
			'show_organizer_slug' => ! empty($options['show_organizer_slug']) ? 1 : 0,
			'show_ticket_button' => ! empty($options['show_ticket_button']) ? 1 : 0,
			'show_ticket_price' => ! empty($options['show_ticket_price']) ? 1 : 0,
			'feature_filters' => ! empty($options['feature_filters']) ? 1 : 0,
			'feature_load_more' => ! empty($options['feature_load_more']) ? 1 : 0,
			'page_size' => isset($options['page_size']) ? max(1, absint($options['page_size'])) : 9,
			'feature_badges' => ! empty($options['feature_badges']) ? 1 : 0,
			'feature_badges_availability' => ! empty($options['feature_badges_availability']) ? 1 : 0,
			'show_available_tickets' => ! empty($options['show_available_tickets']) ? 1 : 0,
			'low_ticket_threshold' => isset($options['low_ticket_threshold']) ? max(1, absint($options['low_ticket_threshold'])) : 10,
			'feature_calendar' => ! empty($options['feature_calendar']) ? 1 : 0,
			'feature_schema' => ! empty($options['feature_schema']) ? 1 : 0,
			'feature_modal' => ! empty($options['feature_modal']) ? 1 : 0,
			'feature_tilt' => ! empty($options['feature_tilt']) ? 1 : 0,
			'pinned_events' => isset($options['pinned_events']) ? sanitize_textarea_field($options['pinned_events']) : '',
			'accent_color' => isset($options['accent_color']) ? sanitize_hex_color($options['accent_color']) : '',
			'enable_cpt_sync' => ! empty($options['enable_cpt_sync']) ? 1 : 0,
			'cpt_sync_scope' => isset($options['cpt_sync_scope']) && in_array($options['cpt_sync_scope'], array('selected', 'all'), true) ? $options['cpt_sync_scope'] : 'selected',
			'cpt_sync_organizers' => isset($options['cpt_sync_organizers']) ? $this->sanitize_slug_list($options['cpt_sync_organizers']) : '',
			'cpt_sync_interval' => isset($options['cpt_sync_interval']) ? max(1, absint($options['cpt_sync_interval'])) : 12,
			'composer_enabled' => ! empty($options['composer_enabled']) ? 1 : 0,
			'composer_layout_json' => isset($options['composer_layout_json']) ? $this->sanitize_composer_layout_json($options['composer_layout_json']) : '',
			'composer_styles_json' => isset($options['composer_styles_json']) ? $this->sanitize_composer_styles_json($options['composer_styles_json']) : '',
		);

		foreach ($sanitized as $key => $value) {
			if (! isset($present_lookup[ $key ]) && ! array_key_exists($key, $options) && array_key_exists($key, $current)) {
				$sanitized[ $key ] = $current[ $key ];
			}
		}

		if ($current !== $sanitized) {
			$this->flush_cache();
		}

		return $sanitized;
	}

	public function render_field($args) {
		$options = $this->get_options();
		$key = $args['key'];
		$value = isset($options[ $key ]) ? $options[ $key ] : '';
		$type = isset($args['type']) ? $args['type'] : 'text';

		printf(
			'<input type="hidden" name="%1$s[__present_fields][]" value="%2$s" />',
			esc_attr(self::OPTION_KEY),
			esc_attr($key)
		);

		if ('textarea' === $type) {
			printf(
				'<textarea class="large-text" rows="%1$d" name="%2$s[%3$s]">%4$s</textarea>',
				isset($args['rows']) ? absint($args['rows']) : 3,
				esc_attr(self::OPTION_KEY),
				esc_attr($key),
				esc_textarea($value)
			);
		} elseif ('checkbox' === $type) {
			$label = ! empty($args['checkbox_label']) ? (string) $args['checkbox_label'] : '';
			if ('' !== $label) {
				printf(
					'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
					esc_attr(self::OPTION_KEY),
					esc_attr($key),
					checked(! empty($value), true, false),
					esc_html($label)
				);
			} else {
				printf(
					'<input type="checkbox" name="%1$s[%2$s]" value="1" %3$s />',
					esc_attr(self::OPTION_KEY),
					esc_attr($key),
					checked(! empty($value), true, false)
				);
			}
		} elseif ('select' === $type) {
			$options_list = isset($args['options']) && is_array($args['options']) ? $args['options'] : array();
			printf(
				'<select name="%1$s[%2$s]">',
				esc_attr(self::OPTION_KEY),
				esc_attr($key)
			);
			foreach ($options_list as $opt_value => $opt_label) {
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr($opt_value),
					selected((string) $value, (string) $opt_value, false),
					esc_html($opt_label)
				);
			}
			echo '</select>';
		} elseif ('color' === $type) {
			printf(
				'<input type="text" class="regular-text" name="%1$s[%2$s]" value="%3$s" placeholder="#df6d4b" />',
				esc_attr(self::OPTION_KEY),
				esc_attr($key),
				esc_attr($value)
			);
		} else {
			printf(
				'<input type="%1$s" class="regular-text" name="%2$s[%3$s]" value="%4$s" %5$s %6$s />',
				esc_attr($type),
				esc_attr(self::OPTION_KEY),
				esc_attr($key),
				esc_attr($value),
				'number' === $type ? 'min="' . esc_attr(isset($args['min']) ? $args['min'] : 1) . '" step="' . esc_attr(isset($args['step']) ? $args['step'] : 1) . '"' : '',
				'url' === $type ? 'placeholder="https://tickets.example.de"' : ''
			);
		}

		if (! empty($args['description'])) {
			echo '<p class="description">' . esc_html($args['description']) . '</p>';
		}
	}

	public function render_tools_field() {
		$flush_url = wp_nonce_url(admin_url('admin-post.php?action=pretix_eventlister_flush_cache'), 'pretix_eventlister_flush_cache');
		$test_url = wp_nonce_url(admin_url('admin-post.php?action=pretix_eventlister_test_api'), 'pretix_eventlister_test_api');

		echo '<p>';
		printf(
			'<a href="%1$s" class="button">%2$s</a> ',
			esc_url($test_url),
			esc_html__('API-Verbindung testen', 'pretix-eventlister')
		);
		printf(
			'<a href="%1$s" class="button">%2$s</a>',
			esc_url($flush_url),
			esc_html__('Cache leeren', 'pretix-eventlister')
		);
		echo '</p>';
		echo '<p class="description">' . esc_html__('Hinweis: Diese Aktionen beeinflussen nur dieses Plugin. Der Cache wird bei Optionsänderungen automatisch geleert.', 'pretix-eventlister') . '</p>';
		echo $this->render_admin_events_snapshot();
	}

	private function render_admin_events_snapshot() {
		$options = $this->get_options();
		if (empty($options['base_url']) || empty($options['api_token'])) {
			return '<p class="description">' . esc_html__('Event-Preview wird angezeigt, sobald Basis-URL und API-Token gespeichert sind.', 'pretix-eventlister') . '</p>';
		}

		$query = array(
			'scope' => ! empty($options['default_organizers']) ? 'selected' : 'all',
			'organizers' => $this->parse_slug_list(isset($options['default_organizers']) ? $options['default_organizers'] : ''),
			'limit' => 20,
			'style' => 'grid',
			'show_description' => false,
			'show_organizer' => true,
			'show_image' => false,
			'show_time' => true,
			'show_location' => true,
			'show_countdown' => false,
			'show_platform_notice' => false,
			'show_organizer_slug' => false,
			'show_ticket_button' => true,
			'show_ticket_price' => true,
			'feature_filters' => false,
			'feature_load_more' => false,
			'page_size' => 20,
			'feature_badges' => false,
			'feature_badges_availability' => true,
			'show_available_tickets' => true,
			'feature_calendar' => false,
			'feature_schema' => false,
			'feature_modal' => false,
			'feature_tilt' => false,
		);

		$collection = $this->build_collection($query, $options);
		if (is_wp_error($collection)) {
			return '<p class="description">' . esc_html(sprintf(__('Event-Preview Fehler: %s', 'pretix-eventlister'), $collection->get_error_message())) . '</p>';
		}

		$events = isset($collection['events']) && is_array($collection['events']) ? $collection['events'] : array();
		if (empty($events)) {
			return '<p class="description">' . esc_html__('Event-Preview: Keine kommenden Events gefunden.', 'pretix-eventlister') . '</p>';
		}

		ob_start();
		?>
		<div style="margin-top:1rem;overflow:auto;">
			<h3 style="margin:1rem 0 0.5rem;"><?php echo esc_html__('Gefetchte Events (Preview)', 'pretix-eventlister'); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__('Organizer', 'pretix-eventlister'); ?></th>
						<th><?php echo esc_html__('Event', 'pretix-eventlister'); ?></th>
						<th><?php echo esc_html__('Start', 'pretix-eventlister'); ?></th>
						<th><?php echo esc_html__('Ort', 'pretix-eventlister'); ?></th>
						<th><?php echo esc_html__('Verfügbar', 'pretix-eventlister'); ?></th>
						<th><?php echo esc_html__('Preis', 'pretix-eventlister'); ?></th>
						<th><?php echo esc_html__('Status', 'pretix-eventlister'); ?></th>
						<th><?php echo esc_html__('API-Info', 'pretix-eventlister'); ?></th>
						<th><?php echo esc_html__('Link', 'pretix-eventlister'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($events as $event) : ?>
						<?php
						$availability_label = __('n/a', 'pretix-eventlister');
						if (isset($event['available_tickets']) && null !== $event['available_tickets']) {
							$availability_label = number_format_i18n((int) $event['available_tickets']) . ' ' . __('Tickets', 'pretix-eventlister');
						}
						if (! empty($event['sold_out'])) {
							$availability_label = __('Ausverkauft', 'pretix-eventlister');
						}
						$product_lines = array();
						if (! empty($event['product_availability']) && is_array($event['product_availability'])) {
							foreach ($event['product_availability'] as $product_row) {
								if (! is_array($product_row) || empty($product_row['name'])) {
									continue;
								}
								$product_lines[] = sprintf(
									'%s: %s',
									sanitize_text_field((string) $product_row['name']),
									isset($product_row['available_tickets']) && null !== $product_row['available_tickets']
										? number_format_i18n((int) $product_row['available_tickets'])
										: 'n/a'
								);
							}
						}

						$price_label = __('n/a', 'pretix-eventlister');
						if (isset($event['lowest_price']) && null !== $event['lowest_price']) {
							$price_value = $this->format_money((float) $event['lowest_price']);
							$currency = ! empty($event['lowest_price_currency']) ? strtoupper((string) $event['lowest_price_currency']) : 'EUR';
							$price_label = $price_value . ' ' . $currency;
						}

						$status_parts = array();
						$status_parts[] = ! empty($event['is_live']) ? __('live', 'pretix-eventlister') : __('nicht live', 'pretix-eventlister');
						if (isset($event['is_public'])) {
							$status_parts[] = ! empty($event['is_public']) ? __('öffentlich', 'pretix-eventlister') : __('intern', 'pretix-eventlister');
						}
						$status_parts[] = ! empty($event['is_online']) ? __('online', 'pretix-eventlister') : __('vor Ort', 'pretix-eventlister');
						?>
						<tr>
							<td><?php echo esc_html((string) $event['organizer_name']); ?><br><code><?php echo esc_html((string) $event['organizer_slug']); ?></code></td>
							<td><?php echo esc_html((string) $event['name']); ?><br><code><?php echo esc_html((string) $event['slug']); ?></code></td>
							<td><?php echo esc_html((string) $event['date_label']); ?><?php if (! empty($event['time_label'])) : ?><br><?php echo esc_html((string) $event['time_label']); ?><?php endif; ?></td>
							<td><?php echo esc_html(! empty($event['location']) ? $this->resolve_location_text($event['location']) : '-'); ?></td>
							<td>
								<?php echo esc_html($availability_label); ?>
								<?php if (! empty($product_lines)) : ?>
									<br><small><?php echo esc_html(implode(' | ', $product_lines)); ?></small>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html($price_label); ?></td>
							<td><?php echo esc_html(implode(' | ', $status_parts)); ?></td>
							<td><code><?php echo esc_html('id:' . (int) $event['event_id']); ?></code><?php if (! empty($event['timezone'])) : ?><br><code><?php echo esc_html('tz:' . (string) $event['timezone']); ?></code><?php endif; ?></td>
							<td><?php if (! empty($event['url'])) : ?><a href="<?php echo esc_url((string) $event['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('öffnen', 'pretix-eventlister'); ?></a><?php else : ?>-<?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function render_settings_page() {
		$tabs = array(
			'connection' => __('Verbindung', 'pretix-eventlister'),
			'display' => __('Anzeige', 'pretix-eventlister'),
			'generator' => __('Shortcode-Generator', 'pretix-eventlister'),
			'composer' => __('Layout Composer', 'pretix-eventlister'),
			'sync' => __('Sync & Overrides', 'pretix-eventlister'),
			'tools' => __('Tools', 'pretix-eventlister'),
		);
		$active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'connection';
		if (! isset($tabs[ $active_tab ])) {
			$active_tab = 'connection';
		}

		$tab_sections = array(
			'connection' => array('pretix_eventlister_api', 'pretix_eventlister_notes', 'pretix_eventlister_visibility'),
			'display' => array('pretix_eventlister_display'),
			'generator' => array(),
			'composer' => array(),
			'sync' => array('pretix_eventlister_cpt'),
			'tools' => array('pretix_eventlister_tools'),
		);
		$current_sections = isset($tab_sections[ $active_tab ]) ? $tab_sections[ $active_tab ] : array();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Pretix Eventlister', 'pretix-eventlister'); ?></h1>
			<?php
			if (! empty($_GET['pel_notice']) && ! empty($_GET['pel_message'])) {
				$type = sanitize_key(wp_unslash($_GET['pel_notice']));
				$message = sanitize_text_field(wp_unslash($_GET['pel_message']));
				$class = 'notice';
				$class .= 'success' === $type ? ' notice-success' : ('error' === $type ? ' notice-error' : ' notice-info');
				echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
			}
			?>
			<h2 class="nav-tab-wrapper" style="margin-bottom:12px;">
				<?php foreach ($tabs as $tab_key => $tab_label) : ?>
					<?php $tab_url = add_query_arg(array('page' => 'pretix-eventlister', 'tab' => $tab_key), admin_url('options-general.php')); ?>
					<a href="<?php echo esc_url($tab_url); ?>" class="nav-tab<?php echo $active_tab === $tab_key ? ' nav-tab-active' : ''; ?>"><?php echo esc_html($tab_label); ?></a>
				<?php endforeach; ?>
			</h2>
			<?php if (! empty($current_sections)) : ?>
				<form action="options.php" method="post">
					<?php
					settings_fields('pretix_eventlister');
					$this->render_settings_sections_by_ids('pretix-eventlister', $current_sections);
					submit_button();
					?>
				</form>
			<?php endif; ?>

			<?php if ('display' === $active_tab) : ?>
				<h2><?php echo esc_html__('Shortcode-Beispiele', 'pretix-eventlister'); ?></h2>
				<ul>
					<li><code>[pretix_events]</code></li>
					<li><code>[pretix_events scope="all" limit="all"]</code></li>
					<li><code>[pretix_events organizer="organizer-a"]</code></li>
					<li><code>[pretix_events organizers="organizer-a,partner-a,partner-b"]</code></li>
					<li><code>[pretix_events scope="all" style="list" show_description="no"]</code></li>
					<li><code>[pretix_events filters="yes" load_more="yes" page_size="12"]</code></li>
				</ul>
			<?php endif; ?>
			<?php if ('generator' === $active_tab) : ?>
				<?php $this->render_shortcode_generator(); ?>
			<?php endif; ?>
			<?php if ('composer' === $active_tab) : ?>
				<?php $this->render_composer_builder_v2(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_settings_sections_by_ids($page, $section_ids) {
		global $wp_settings_sections;

		if (! is_array($section_ids) || empty($section_ids)) {
			return;
		}

		foreach ($section_ids as $section_id) {
			if (empty($wp_settings_sections[ $page ][ $section_id ])) {
				continue;
			}

			$section = $wp_settings_sections[ $page ][ $section_id ];
			if (! empty($section['title'])) {
				echo '<h2>' . esc_html($section['title']) . '</h2>';
			}

			if (! empty($section['callback']) && is_callable($section['callback'])) {
				call_user_func($section['callback'], $section);
			}

			echo '<table class="form-table" role="presentation">';
			do_settings_fields($page, $section_id);
			echo '</table>';
		}
	}

	private function render_shortcode_generator() {
		$options = $this->get_options();
		$defaults = array(
			'scope' => ! empty($options['default_organizers']) ? 'selected' : 'all',
			'limit' => '9',
			'organizers' => '',
			'style' => 'default',
			'show_description' => 'default',
			'show_organizer' => 'default',
			'show_image' => 'default',
			'show_time' => 'default',
			'show_location' => 'default',
			'show_countdown' => 'default',
			'show_platform_notice' => 'default',
			'show_organizer_slug' => 'default',
			'show_ticket_button' => 'default',
			'show_ticket_price' => 'default',
			'filters' => 'default',
			'load_more' => 'default',
			'page_size' => (string) max(1, absint(isset($options['page_size']) ? $options['page_size'] : 9)),
			'badges' => 'default',
			'badges_availability' => 'default',
			'show_available_tickets' => 'default',
			'calendar' => 'default',
			'schema' => 'default',
			'modal' => 'default',
			'tilt' => 'default',
		);
		?>
		<style>
			.pel-gen{--pel-ink:#0f172a;--pel-muted:#475569;--pel-accent:#0b5fff;--pel-bg:#f8fafc;--pel-border:#dbe3ee;background:linear-gradient(135deg,#f8fbff,#f5f8ff 45%,#eef4ff);border:1px solid var(--pel-border);border-radius:16px;padding:18px;box-shadow:0 8px 30px rgba(11,95,255,.08)}
			.pel-gen h2{margin:0 0 6px;color:var(--pel-ink)}
			.pel-gen p{margin:0;color:var(--pel-muted)}
			.pel-gen__toolbar{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}
			.pel-gen__preset{border:1px solid #bfdbfe;background:#fff;color:#1d4ed8;padding:6px 10px;border-radius:999px;cursor:pointer;font-weight:600}
			.pel-gen__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px 14px}
			.pel-gen label{display:block;color:#1e293b;font-weight:600}
			.pel-gen input,.pel-gen select{width:100%;margin-top:4px}
			.pel-gen__toggles{margin-top:10px;padding:12px;border:1px solid var(--pel-border);border-radius:12px;background:#fff}
			.pel-gen__toggles h3{margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:#334155}
			.pel-gen__chips{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px}
			.pel-gen__output{margin-top:12px;padding:10px;border:1px solid var(--pel-border);border-radius:12px;background:#fff}
			.pel-gen__actions{display:flex;gap:8px;align-items:center;margin-top:8px}
		</style>
		<div class="pel-gen">
			<h2><?php echo esc_html__('Shortcode-Generator', 'pretix-eventlister'); ?></h2>
			<p><?php echo esc_html__('Konfiguriere den Block wie im Builder und kopiere den fertigen Shortcode.', 'pretix-eventlister'); ?></p>
			<div class="pel-gen__toolbar">
				<button type="button" class="pel-gen__preset" data-preset="minimal"><?php echo esc_html__('Preset: Minimal', 'pretix-eventlister'); ?></button>
				<button type="button" class="pel-gen__preset" data-preset="cards"><?php echo esc_html__('Preset: Karten', 'pretix-eventlister'); ?></button>
				<button type="button" class="pel-gen__preset" data-preset="full"><?php echo esc_html__('Preset: Voll', 'pretix-eventlister'); ?></button>
			</div>

			<div id="pretix-shortcode-generator" class="pel-gen__grid">
				<label><?php echo esc_html__('Scope', 'pretix-eventlister'); ?><br>
					<select data-opt="scope">
						<option value="selected" <?php selected('selected', $defaults['scope']); ?>>selected</option>
						<option value="all" <?php selected('all', $defaults['scope']); ?>>all</option>
					</select>
				</label>
				<label><?php echo esc_html__('Limit', 'pretix-eventlister'); ?><br>
					<input type="text" value="<?php echo esc_attr($defaults['limit']); ?>" data-opt="limit" />
				</label>
				<label><?php echo esc_html__('Style', 'pretix-eventlister'); ?><br>
					<select data-opt="style">
						<option value="default">default</option>
						<option value="grid">grid</option>
						<option value="list">list</option>
						<option value="compact">compact</option>
					</select>
				</label>
				<label><?php echo esc_html__('Page size', 'pretix-eventlister'); ?><br>
					<input type="number" min="1" value="<?php echo esc_attr($defaults['page_size']); ?>" data-opt="page_size" />
				</label>
				<label style="grid-column:1/-1;"><?php echo esc_html__('Organizers (comma separated slugs)', 'pretix-eventlister'); ?><br>
					<input type="text" class="regular-text" style="width:100%;max-width:680px;" value="<?php echo esc_attr($defaults['organizers']); ?>" data-opt="organizers" />
				</label>
			</div>
			<div class="pel-gen__toggles">
				<h3><?php echo esc_html__('Optionen', 'pretix-eventlister'); ?></h3>
				<div class="pel-gen__chips">
					<?php
					$toggle_fields = array(
						'show_description', 'show_organizer', 'show_image', 'show_time', 'show_location',
						'show_countdown', 'show_platform_notice', 'show_organizer_slug', 'show_ticket_button',
						'show_ticket_price', 'filters', 'load_more', 'badges', 'badges_availability',
						'show_available_tickets', 'calendar', 'schema', 'modal', 'tilt',
					);
					foreach ($toggle_fields as $field) :
						?>
						<label><?php echo esc_html($field); ?>
							<select data-opt="<?php echo esc_attr($field); ?>">
								<option value="default">default</option>
								<option value="yes">yes</option>
								<option value="no">no</option>
							</select>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
			<div style="margin-top:12px;">
				<label style="display:inline-flex;align-items:center;gap:8px;">
					<input type="checkbox" id="pretix-shortcode-all-opts" checked />
					<?php echo esc_html__('Immer alle Optionen ausgeben', 'pretix-eventlister'); ?>
				</label>
			</div>
			<div class="pel-gen__output">
				<textarea id="pretix-shortcode-output" readonly rows="4" style="width:100%;font-family:ui-monospace,Consolas,monospace;"></textarea>
			</div>
			<div class="pel-gen__actions">
				<button type="button" class="button button-primary" id="pretix-shortcode-copy"><?php echo esc_html__('Shortcode kopieren', 'pretix-eventlister'); ?></button>
			</div>
		</div>
		<script>
			(function(){
				const root=document.getElementById('pretix-shortcode-generator');
				const output=document.getElementById('pretix-shortcode-output');
				const copyBtn=document.getElementById('pretix-shortcode-copy');
				const includeAll=document.getElementById('pretix-shortcode-all-opts');
				const presetButtons=Array.from(document.querySelectorAll('.pel-gen__preset'));
				if(!root||!output||!copyBtn||!includeAll){return;}

				const defaultMap=<?php echo wp_json_encode($defaults); ?>;
				const fields=Array.from(root.querySelectorAll('[data-opt]'));

				function escapeValue(v){
					return String(v).replace(/"/g,'\\"');
				}

				function build(){
					let attrs=[];
					fields.forEach((field)=>{
						const key=field.getAttribute('data-opt');
						let value=(field.value||'').trim();
						if(!key){return;}
						if('organizers'===key && value===''){return;}
						const isDefault = Object.prototype.hasOwnProperty.call(defaultMap,key) && String(defaultMap[key])===value;
						if(!includeAll.checked && isDefault){return;}
						if(''===value){return;}
						attrs.push(key+'="'+escapeValue(value)+'"');
					});
					output.value='[pretix_events'+(attrs.length?' '+attrs.join(' '):'')+']';
				}

				function setPreset(name){
					const map={
						minimal:{show_description:'no',show_image:'no',show_time:'yes',show_location:'yes',badges:'no',modal:'no',calendar:'no',filters:'no'},
						cards:{show_description:'yes',show_image:'yes',show_time:'yes',show_location:'yes',badges:'yes',modal:'yes',calendar:'no',filters:'no'},
						full:{show_description:'yes',show_image:'yes',show_time:'yes',show_location:'yes',badges:'yes',badges_availability:'yes',show_available_tickets:'yes',calendar:'yes',modal:'yes',filters:'yes',load_more:'yes'}
					};
					const preset=map[name]||{};
					fields.forEach((field)=>{
						const key=field.getAttribute('data-opt');
						if(key&&Object.prototype.hasOwnProperty.call(preset,key)){
							field.value=String(preset[key]);
						}
					});
					build();
				}

				fields.forEach((f)=>f.addEventListener('input',build));
				fields.forEach((f)=>f.addEventListener('change',build));
				includeAll.addEventListener('change',build);
				copyBtn.addEventListener('click',async()=>{
					try{
						await navigator.clipboard.writeText(output.value);
						copyBtn.textContent='<?php echo esc_js(__('Kopiert', 'pretix-eventlister')); ?>';
						setTimeout(()=>{copyBtn.textContent='<?php echo esc_js(__('Shortcode kopieren', 'pretix-eventlister')); ?>';},1200);
					}catch(e){
						output.focus();
						output.select();
					}
				});
				presetButtons.forEach((btn)=>{
					btn.addEventListener('click',()=>setPreset(btn.getAttribute('data-preset')));
				});
				build();
			})();
		</script>
		<?php
	}

	private function render_composer_builder_v2() {
		$options = $this->get_options();
		$enabled = ! empty($options['composer_enabled']);
		$layout = $this->get_composer_layout(isset($options['composer_layout_json']) ? $options['composer_layout_json'] : '');
		$layout_json = wp_json_encode($layout, JSON_UNESCAPED_UNICODE);
		$styles = $this->get_composer_styles(isset($options['composer_styles_json']) ? $options['composer_styles_json'] : '');
		$styles_json = wp_json_encode($styles, JSON_UNESCAPED_UNICODE);
		$palette = array(
			'schedule' => array(
				'label' => __('Schedule', 'pretix-eventlister'),
				'description' => __('Datum, Uhrzeit, Ort, Countdown und Verfügbarkeit', 'pretix-eventlister'),
			),
			'body' => array(
				'label' => __('Body', 'pretix-eventlister'),
				'description' => __('Titel und Beschreibung', 'pretix-eventlister'),
			),
			'platform_note' => array(
				'label' => __('Partner Note', 'pretix-eventlister'),
				'description' => __('Optionaler Hinweis zur Plattform', 'pretix-eventlister'),
			),
			'footer' => array(
				'label' => __('Footer', 'pretix-eventlister'),
				'description' => __('Aktionen, Kalender-Links und Details', 'pretix-eventlister'),
			),
		);
		?>
		<style>
			.pel-comp{--pel-border:#cdd9ea;background:radial-gradient(1200px 420px at 0 0,#f8fbff 0,#edf3ff 55%,#e7efff 100%);border:1px solid var(--pel-border);border-radius:18px;padding:18px}
			.pel-comp__head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}
			.pel-comp__head h2{margin:0}
			.pel-comp__head p{margin:4px 0 0;color:#475569}
			.pel-comp__toggle{padding:8px 12px;background:#fff;border:1px solid var(--pel-border);border-radius:10px}
			.pel-comp__wrap{display:grid;grid-template-columns:260px minmax(320px,1fr) minmax(320px,420px);gap:14px}
			.pel-comp__col{background:#fff;border:1px solid var(--pel-border);border-radius:14px;padding:12px;min-height:280px}
			.pel-comp__title{margin:0 0 8px;font-weight:700}
			.pel-comp__sub{margin:0 0 10px;color:#64748b;font-size:12px}
			.pel-comp__item{display:flex;flex-direction:column;gap:4px;padding:10px;border:1px solid #dbeafe;background:#eff6ff;border-radius:10px;margin-bottom:8px;cursor:grab}
			.pel-comp__item strong{font-size:13px}
			.pel-comp__item small{color:#334155}
			.pel-comp__item--canvas{position:relative;padding-right:34px}
			.pel-comp__item--canvas.is-active{border-color:#2563eb;background:#dbeafe;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
			.pel-comp__remove{position:absolute;right:8px;top:8px;border:0;background:transparent;color:#dc2626;cursor:pointer;font-size:16px;line-height:1}
			.pel-comp__drop{min-height:220px;padding:10px;border:1px dashed #93c5fd;border-radius:10px;background:#f8fbff}
			.pel-comp__empty{padding:18px;text-align:center;color:#64748b}
			.pel-comp__hint{margin:8px 0 0;color:#475569}
			.pel-comp__actions{margin-top:12px;display:flex;gap:8px;align-items:center}
			.pel-comp__panel{display:grid;grid-template-columns:1fr 1fr;gap:10px}
			.pel-comp__field{display:flex;flex-direction:column;gap:4px}
			.pel-comp__field label{font-size:12px;color:#334155;font-weight:600}
			.pel-comp__field input,.pel-comp__field select{width:100%}
			.pel-comp__field--full{grid-column:1 / -1}
			.pel-comp__preview{margin-top:10px;padding:12px;border:1px solid #dbe3ee;border-radius:10px;background:#f8fafc}
			.pel-comp__preview-block{padding:10px;border-radius:8px;border:1px dashed #c6d3e6}
			@media (max-width:1200px){.pel-comp__wrap{grid-template-columns:1fr}.pel-comp__panel{grid-template-columns:1fr}}
		</style>
		<div class="pel-comp">
			<form method="post" action="options.php" id="pel-composer-form">
				<?php settings_fields('pretix_eventlister'); ?>
				<input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[__present_fields][]" value="composer_enabled" />
				<input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[__present_fields][]" value="composer_layout_json" />
				<input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[__present_fields][]" value="composer_styles_json" />
				<input type="hidden" id="pel-composer-layout-json" name="<?php echo esc_attr(self::OPTION_KEY); ?>[composer_layout_json]" value="<?php echo esc_attr($layout_json); ?>" />
				<input type="hidden" id="pel-composer-styles-json" name="<?php echo esc_attr(self::OPTION_KEY); ?>[composer_styles_json]" value="<?php echo esc_attr($styles_json); ?>" />
				<div class="pel-comp__head">
					<div>
						<h2><?php echo esc_html__('Layout Composer', 'pretix-eventlister'); ?></h2>
						<p><?php echo esc_html__('Baue dein Kartenlayout per Drag-and-Drop und konfiguriere Design-Optionen pro Baustein.', 'pretix-eventlister'); ?></p>
					</div>
					<div class="pel-comp__toggle">
						<label style="display:inline-flex;align-items:center;gap:8px;">
							<input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[composer_enabled]" value="1" <?php checked($enabled); ?> />
							<?php echo esc_html__('Composer im Frontend aktivieren', 'pretix-eventlister'); ?>
						</label>
					</div>
				</div>
				<div class="pel-comp__wrap">
					<div class="pel-comp__col">
						<p class="pel-comp__title"><?php echo esc_html__('Bausteine', 'pretix-eventlister'); ?></p>
						<p class="pel-comp__sub"><?php echo esc_html__('Ziehe die Bausteine von hier in die Leinwand.', 'pretix-eventlister'); ?></p>
						<div id="pel-composer-palette">
							<?php foreach ($palette as $block_key => $meta) : ?>
								<div class="pel-comp__item" draggable="true" data-block="<?php echo esc_attr($block_key); ?>">
									<strong><?php echo esc_html($meta['label']); ?></strong>
									<small><?php echo esc_html($meta['description']); ?></small>
								</div>
							<?php endforeach; ?>
						</div>
						<p class="pel-comp__hint"><?php echo esc_html__('Tipp: Doppelklick fügt einen Baustein schnell hinzu.', 'pretix-eventlister'); ?></p>
					</div>
					<div class="pel-comp__col">
						<p class="pel-comp__title"><?php echo esc_html__('Leinwand (Frontend-Reihenfolge)', 'pretix-eventlister'); ?></p>
						<p class="pel-comp__sub"><?php echo esc_html__('Klicke auf einen Baustein, um erweiterte Style-Optionen zu bearbeiten.', 'pretix-eventlister'); ?></p>
						<div id="pel-composer-canvas" class="pel-comp__drop"></div>
						<div class="pel-comp__actions">
							<button type="button" class="button" id="pel-composer-reset"><?php echo esc_html__('Reset to default', 'pretix-eventlister'); ?></button>
						</div>
					</div>
					<div class="pel-comp__col">
						<p class="pel-comp__title"><?php echo esc_html__('Design-Panel', 'pretix-eventlister'); ?></p>
						<p class="pel-comp__sub" id="pel-composer-selected-label"><?php echo esc_html__('Kein Baustein ausgewählt', 'pretix-eventlister'); ?></p>
						<div class="pel-comp__panel" id="pel-composer-style-panel">
							<div class="pel-comp__field pel-comp__field--full">
								<label><input type="checkbox" data-style="visible" value="1" /> <?php echo esc_html__('Sichtbar', 'pretix-eventlister'); ?></label>
							</div>
							<div class="pel-comp__field"><label><?php echo esc_html__('Innenabstand', 'pretix-eventlister'); ?></label><input type="text" data-style="padding" placeholder="16px 12px" /></div>
							<div class="pel-comp__field"><label><?php echo esc_html__('Außenabstand', 'pretix-eventlister'); ?></label><input type="text" data-style="margin" placeholder="0 0 12px 0" /></div>
							<div class="pel-comp__field"><label><?php echo esc_html__('Textfarbe', 'pretix-eventlister'); ?></label><input type="color" data-style-color="text_color" /></div>
							<div class="pel-comp__field"><label><?php echo esc_html__('Hintergrund', 'pretix-eventlister'); ?></label><input type="color" data-style-color="background_color" /></div>
							<div class="pel-comp__field"><label><?php echo esc_html__('Rahmenfarbe', 'pretix-eventlister'); ?></label><input type="color" data-style-color="border_color" /></div>
							<div class="pel-comp__field"><label><?php echo esc_html__('Rahmenbreite', 'pretix-eventlister'); ?></label><input type="text" data-style="border_width" placeholder="1px" /></div>
							<div class="pel-comp__field"><label><?php echo esc_html__('Eckenradius', 'pretix-eventlister'); ?></label><input type="text" data-style="border_radius" placeholder="12px" /></div>
							<div class="pel-comp__field"><label><?php echo esc_html__('Schriftfamilie', 'pretix-eventlister'); ?></label><input type="text" data-style="font_family" placeholder="Inter, sans-serif" /></div>
							<div class="pel-comp__field"><label><?php echo esc_html__('Schriftgröße', 'pretix-eventlister'); ?></label><input type="text" data-style="font_size" placeholder="16px" /></div>
							<div class="pel-comp__field"><label><?php echo esc_html__('Schriftstärke', 'pretix-eventlister'); ?></label><select data-style="font_weight"><option value=""><?php echo esc_html__('Standard', 'pretix-eventlister'); ?></option><option value="normal">normal</option><option value="bold">bold</option><option value="300">300</option><option value="400">400</option><option value="500">500</option><option value="600">600</option><option value="700">700</option><option value="800">800</option></select></div>
							<div class="pel-comp__field"><label><?php echo esc_html__('Zeilenhöhe', 'pretix-eventlister'); ?></label><input type="text" data-style="line_height" placeholder="1.5" /></div>
							<div class="pel-comp__field"><label><?php echo esc_html__('Zeichenabstand', 'pretix-eventlister'); ?></label><input type="text" data-style="letter_spacing" placeholder="0.02em" /></div>
							<div class="pel-comp__field"><label><?php echo esc_html__('Textausrichtung', 'pretix-eventlister'); ?></label><select data-style="text_align"><option value=""><?php echo esc_html__('Standard', 'pretix-eventlister'); ?></option><option value="left"><?php echo esc_html__('Links', 'pretix-eventlister'); ?></option><option value="center"><?php echo esc_html__('Zentriert', 'pretix-eventlister'); ?></option><option value="right"><?php echo esc_html__('Rechts', 'pretix-eventlister'); ?></option><option value="justify"><?php echo esc_html__('Blocksatz', 'pretix-eventlister'); ?></option></select></div>
							<div class="pel-comp__field pel-comp__field--full"><label><?php echo esc_html__('Schatten', 'pretix-eventlister'); ?></label><input type="text" data-style="shadow" placeholder="0 8px 20px rgba(0,0,0,.12)" /></div>
						</div>
						<div class="pel-comp__preview">
							<div class="pel-comp__preview-block" id="pel-composer-preview"><?php echo esc_html__('Live-Vorschau des ausgewählten Bausteins', 'pretix-eventlister'); ?></div>
						</div>
					</div>
				</div>
				<?php submit_button(__('Composer speichern', 'pretix-eventlister')); ?>
			</form>
		</div>
		<script>
			(function(){
				const palette=<?php echo wp_json_encode($palette, JSON_UNESCAPED_UNICODE); ?>;
				const defaultLayout=<?php echo wp_json_encode($this->get_default_composer_layout(), JSON_UNESCAPED_UNICODE); ?>;
				const defaultStyles=<?php echo wp_json_encode($this->get_default_composer_styles(), JSON_UNESCAPED_UNICODE); ?>;
				const canvas=document.getElementById('pel-composer-canvas');
				const layoutInput=document.getElementById('pel-composer-layout-json');
				const stylesInput=document.getElementById('pel-composer-styles-json');
				const resetBtn=document.getElementById('pel-composer-reset');
				const paletteEl=document.getElementById('pel-composer-palette');
				const panel=document.getElementById('pel-composer-style-panel');
				const selectedLabel=document.getElementById('pel-composer-selected-label');
				const preview=document.getElementById('pel-composer-preview');
				if(!canvas||!layoutInput||!stylesInput||!paletteEl||!panel){return;}

				let draggedKey='';
				let activeKey='';
				const state={layout:[],styles:{}};
				try{ state.layout=JSON.parse(layoutInput.value||'[]'); }catch(e){ state.layout=[]; }
				try{ state.styles=JSON.parse(stylesInput.value||'{}'); }catch(e){ state.styles={}; }
				if(!Array.isArray(state.layout)||!state.layout.length){ state.layout=defaultLayout.slice(); }
				state.layout=state.layout.filter((k)=>Object.prototype.hasOwnProperty.call(palette,k));
				if(!state.layout.length){ state.layout=defaultLayout.slice(); }

				function deepClone(obj){
					return JSON.parse(JSON.stringify(obj||{}));
				}

				function ensureStyles(blockKey){
					if(!Object.prototype.hasOwnProperty.call(state.styles,blockKey)||typeof state.styles[blockKey]!=='object'||!state.styles[blockKey]){
						state.styles[blockKey]=deepClone(defaultStyles[blockKey]||{visible:1});
						return;
					}
					const fallback=defaultStyles[blockKey]||{};
					Object.keys(fallback).forEach((k)=>{
						if(!Object.prototype.hasOwnProperty.call(state.styles[blockKey],k)){
							state.styles[blockKey][k]=fallback[k];
						}
					});
				}

				Object.keys(defaultStyles).forEach(ensureStyles);

				function blockNode(key){
					const node=document.createElement('div');
					node.className='pel-comp__item pel-comp__item--canvas';
					if(activeKey===key){ node.classList.add('is-active'); }
					node.setAttribute('draggable','true');
					node.setAttribute('data-block',key);
					node.innerHTML='<strong>'+(palette[key]&&palette[key].label?palette[key].label:key)+'</strong><small>'+(palette[key]&&palette[key].description?palette[key].description:'')+'</small><button type="button" class="pel-comp__remove" data-remove="'+key+'" aria-label="remove">×</button>';
					node.addEventListener('dragstart',()=>{draggedKey=key;});
					node.addEventListener('click',(event)=>{
						if(event.target&&event.target.closest('[data-remove]')){return;}
						selectBlock(key);
					});
					return node;
				}

				function syncHidden(){
					layoutInput.value=JSON.stringify(state.layout);
					stylesInput.value=JSON.stringify(state.styles);
				}

				function toColorValue(raw){
					if(typeof raw!=='string'||!raw){ return '#000000'; }
					const value=raw.trim();
					if(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(value)){
						return value.length===4 ? '#'+value[1]+value[1]+value[2]+value[2]+value[3]+value[3] : value;
					}
					return '#000000';
				}

				function applyPreviewStyles(styles){
					if(!preview){return;}
					preview.style.padding=styles.padding||'';
					preview.style.margin=styles.margin||'';
					preview.style.color=styles.text_color||'';
					preview.style.background=styles.background_color||'';
					preview.style.borderColor=styles.border_color||'';
					preview.style.borderWidth=styles.border_width||'';
					preview.style.borderStyle=styles.border_width ? 'solid' : '';
					preview.style.borderRadius=styles.border_radius||'';
					preview.style.fontFamily=styles.font_family||'';
					preview.style.fontSize=styles.font_size||'';
					preview.style.fontWeight=styles.font_weight||'';
					preview.style.lineHeight=styles.line_height||'';
					preview.style.letterSpacing=styles.letter_spacing||'';
					preview.style.textAlign=styles.text_align||'';
					preview.style.boxShadow=styles.shadow||'';
					preview.style.opacity=styles.visible ? '1' : '.45';
				}

				function fillPanelForActive(){
					if(!activeKey||!Object.prototype.hasOwnProperty.call(palette,activeKey)){return;}
					ensureStyles(activeKey);
					const styles=state.styles[activeKey];
					if(selectedLabel){
						selectedLabel.textContent=(palette[activeKey]&&palette[activeKey].label?palette[activeKey].label:activeKey);
					}
					panel.querySelectorAll('[data-style]').forEach((input)=>{
						const key=input.getAttribute('data-style');
						if(!key){return;}
						if(input.type==='checkbox'){
							input.checked=!!styles[key];
						}else{
							input.value=styles[key]||'';
						}
					});
					panel.querySelectorAll('[data-style-color]').forEach((input)=>{
						const key=input.getAttribute('data-style-color');
						if(!key){return;}
						input.value=toColorValue(styles[key]||'');
					});
					applyPreviewStyles(styles);
				}

				function selectBlock(key){
					if(!key||state.layout.indexOf(key)===-1){return;}
					activeKey=key;
					render();
					fillPanelForActive();
				}

				function render(){
					canvas.innerHTML='';
					state.layout.forEach((key)=>canvas.appendChild(blockNode(key)));
					if(!state.layout.length){
						const empty=document.createElement('div');
						empty.className='pel-comp__empty';
						empty.textContent='<?php echo esc_js(__('Füge mindestens einen Baustein aus der Palette hinzu.', 'pretix-eventlister')); ?>';
						canvas.appendChild(empty);
					}
					if(!activeKey||state.layout.indexOf(activeKey)===-1){
						activeKey=state.layout.length?state.layout[0]:'';
					}
					syncHidden();
					fillPanelForActive();
				}

				paletteEl.querySelectorAll('.pel-comp__item').forEach((item)=>{
					item.addEventListener('dragstart',()=>{draggedKey=item.getAttribute('data-block')||'';});
					item.addEventListener('dblclick',()=>{
						const key=item.getAttribute('data-block')||'';
						if(key&&state.layout.indexOf(key)===-1){
							state.layout.push(key);
							activeKey=key;
							render();
						}
					});
				});

				canvas.addEventListener('dragover',(e)=>e.preventDefault());
				canvas.addEventListener('drop',(e)=>{
					e.preventDefault();
					if(!draggedKey||!Object.prototype.hasOwnProperty.call(palette,draggedKey)){return;}
					const target=e.target.closest('[data-block]');
					const oldIdx=state.layout.indexOf(draggedKey);
					if(oldIdx!==-1){ state.layout.splice(oldIdx,1); }
					if(target){
						const key=target.getAttribute('data-block')||'';
						const idx=state.layout.indexOf(key);
						if(idx===-1){ state.layout.push(draggedKey); } else { state.layout.splice(idx,0,draggedKey); }
					}else{
						state.layout.push(draggedKey);
					}
					state.layout=state.layout.filter((k,i)=>state.layout.indexOf(k)===i);
					if(!activeKey){ activeKey=draggedKey; }
					render();
				});

				canvas.addEventListener('click',(e)=>{
					const remove=e.target.closest('[data-remove]');
					if(!remove){return;}
					const key=remove.getAttribute('data-remove')||'';
					state.layout=state.layout.filter((k)=>k!==key);
					render();
				});

				panel.querySelectorAll('[data-style]').forEach((input)=>{
					const handler=()=>{
						if(!activeKey){return;}
						ensureStyles(activeKey);
						const key=input.getAttribute('data-style');
						if(!key){return;}
						state.styles[activeKey][key]=input.type==='checkbox' ? (input.checked ? 1 : 0) : (input.value||'').trim();
						syncHidden();
						applyPreviewStyles(state.styles[activeKey]);
					};
					input.addEventListener('input',handler);
					input.addEventListener('change',handler);
				});

				panel.querySelectorAll('[data-style-color]').forEach((input)=>{
					const handler=()=>{
						if(!activeKey){return;}
						ensureStyles(activeKey);
						const key=input.getAttribute('data-style-color');
						if(!key){return;}
						state.styles[activeKey][key]=(input.value||'').trim();
						syncHidden();
						applyPreviewStyles(state.styles[activeKey]);
					};
					input.addEventListener('input',handler);
					input.addEventListener('change',handler);
				});

				if(resetBtn){
					resetBtn.addEventListener('click',()=>{
						state.layout=defaultLayout.slice();
						state.styles=deepClone(defaultStyles);
						activeKey=state.layout[0]||'';
						render();
					});
				}

				activeKey=state.layout[0]||'';
				render();
			})();
		</script>
		<?php
	}

	public function register_assets() {
		wp_register_style(
			'pretix-eventlister',
			plugin_dir_url(__FILE__) . 'assets/css/style.css',
			array(),
			self::VERSION
		);

		wp_register_script(
			'pretix-eventlister',
			plugin_dir_url(__FILE__) . 'assets/js/script.js',
			array(),
			self::VERSION,
			true
		);
	}

	public function register_cpt() {
		$options = $this->get_options();
		$enabled = ! empty($options['enable_cpt_sync']);

		register_post_type(
			self::CPT,
			array(
				'labels' => array(
					'name' => __('Pretix Events', 'pretix-eventlister'),
					'singular_name' => __('Pretix Event', 'pretix-eventlister'),
				),
				'public' => false,
				'show_ui' => (bool) $enabled,
				'show_in_menu' => (bool) $enabled,
				'show_in_rest' => (bool) $enabled,
				'supports' => array('title', 'editor', 'excerpt', 'thumbnail'),
				'has_archive' => false,
				'rewrite' => false,
				'delete_with_user' => false,
			)
		);
	}

	public function register_event_override_metabox() {
		add_meta_box(
			'pretix-eventlister-overrides',
			__('Manuelle Event-Overrides', 'pretix-eventlister'),
			array($this, 'render_event_override_metabox'),
			self::CPT,
			'normal',
			'high'
		);
	}

	public function render_event_override_metabox($post) {
		if (! $post instanceof WP_Post || self::CPT !== $post->post_type) {
			return;
		}

		wp_nonce_field('pretix_eventlister_override_meta', 'pretix_eventlister_override_nonce');

		$enabled = ! empty(get_post_meta($post->ID, '_pretix_manual_override_enabled', true));
		$hidden = ! empty(get_post_meta($post->ID, '_pretix_manual_hidden', true));
		$name = (string) get_post_meta($post->ID, '_pretix_manual_name', true);
		$description = (string) get_post_meta($post->ID, '_pretix_manual_description', true);
		$image = (string) get_post_meta($post->ID, '_pretix_manual_image', true);
		$location = (string) get_post_meta($post->ID, '_pretix_manual_location', true);
		$url = (string) get_post_meta($post->ID, '_pretix_manual_url', true);
		$manual_date_from = (int) get_post_meta($post->ID, '_pretix_manual_date_from', true);
		$manual_date_to = (int) get_post_meta($post->ID, '_pretix_manual_date_to', true);
		$source_date_from = (int) get_post_meta($post->ID, '_pretix_date_from', true);
		$source_date_to = (int) get_post_meta($post->ID, '_pretix_date_to', true);
		?>
		<style>
			.pretix-metabox-tabs{display:flex;gap:8px;margin:0 0 12px;padding:0;list-style:none}
			.pretix-metabox-tab{padding:6px 10px;border:1px solid #d0d5dd;border-radius:8px;background:#f8fafc;color:#1f2937;font-weight:600;cursor:pointer}
			.pretix-metabox-tab.is-active{background:#111827;color:#fff;border-color:#111827}
			.pretix-metabox-panel{display:none}
			.pretix-metabox-panel.is-active{display:block}
			.pretix-metabox-grid{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
			.pretix-metabox-muted{display:block;color:#667085;font-size:12px;margin-top:4px}
		</style>
		<p>
			<label>
				<input type="checkbox" name="pretix_manual_override_enabled" value="1" <?php checked($enabled); ?> />
				<?php echo esc_html__('Manuelle Overrides aktivieren (API-Sync überschreibt diese Werte nicht)', 'pretix-eventlister'); ?>
			</label>
		</p>
		<p>
			<label>
				<input type="checkbox" name="pretix_manual_hidden" value="1" <?php checked($hidden); ?> />
				<?php echo esc_html__('Event ausblenden (Frontend und Sync-Ausgabe)', 'pretix-eventlister'); ?>
			</label>
		</p>
		<p class="description"><?php echo esc_html__('Leer lassen = Wert aus pretix verwenden.', 'pretix-eventlister'); ?></p>
		<ul class="pretix-metabox-tabs" data-pretix-metabox-tabs>
			<li><button type="button" class="pretix-metabox-tab is-active" data-target="content"><?php echo esc_html__('Inhalt', 'pretix-eventlister'); ?></button></li>
			<li><button type="button" class="pretix-metabox-tab" data-target="schedule"><?php echo esc_html__('Zeit & Ort', 'pretix-eventlister'); ?></button></li>
			<li><button type="button" class="pretix-metabox-tab" data-target="links"><?php echo esc_html__('Link & Sichtbarkeit', 'pretix-eventlister'); ?></button></li>
		</ul>

		<div class="pretix-metabox-panel is-active" data-panel="content">
			<p>
				<label for="pretix_manual_name"><strong><?php echo esc_html__('Titel', 'pretix-eventlister'); ?></strong></label><br />
				<input type="text" id="pretix_manual_name" name="pretix_manual_name" class="widefat" value="<?php echo esc_attr($name); ?>" />
			</p>
			<p>
				<label for="pretix_manual_description"><strong><?php echo esc_html__('Beschreibung (HTML oder Markdown)', 'pretix-eventlister'); ?></strong></label><br />
				<textarea id="pretix_manual_description" name="pretix_manual_description" rows="6" class="widefat"><?php echo esc_textarea($description); ?></textarea>
			</p>
			<p>
				<label for="pretix_manual_image"><strong><?php echo esc_html__('Bild-URL', 'pretix-eventlister'); ?></strong></label><br />
				<input type="url" id="pretix_manual_image" name="pretix_manual_image" class="widefat" value="<?php echo esc_attr($image); ?>" placeholder="https://example.com/image.jpg" />
			</p>
		</div>

		<div class="pretix-metabox-panel" data-panel="schedule">
			<div class="pretix-metabox-grid">
				<p>
					<label for="pretix_manual_date_from"><strong><?php echo esc_html__('Start (manuell)', 'pretix-eventlister'); ?></strong></label><br />
					<input type="datetime-local" id="pretix_manual_date_from" name="pretix_manual_date_from" value="<?php echo esc_attr($this->format_timestamp_for_datetime_local($manual_date_from)); ?>" />
					<span class="pretix-metabox-muted"><?php echo esc_html(sprintf(__('Original: %s', 'pretix-eventlister'), $source_date_from ? wp_date('d.m.Y H:i', $source_date_from) : '-')); ?></span>
				</p>
				<p>
					<label for="pretix_manual_date_to"><strong><?php echo esc_html__('Ende (manuell)', 'pretix-eventlister'); ?></strong></label><br />
					<input type="datetime-local" id="pretix_manual_date_to" name="pretix_manual_date_to" value="<?php echo esc_attr($this->format_timestamp_for_datetime_local($manual_date_to)); ?>" />
					<span class="pretix-metabox-muted"><?php echo esc_html(sprintf(__('Original: %s', 'pretix-eventlister'), $source_date_to ? wp_date('d.m.Y H:i', $source_date_to) : '-')); ?></span>
				</p>
			</div>
			<p>
				<label for="pretix_manual_location"><strong><?php echo esc_html__('Ort', 'pretix-eventlister'); ?></strong></label><br />
				<input type="text" id="pretix_manual_location" name="pretix_manual_location" class="widefat" value="<?php echo esc_attr($location); ?>" />
			</p>
		</div>

		<div class="pretix-metabox-panel" data-panel="links">
			<p>
				<label for="pretix_manual_url"><strong><?php echo esc_html__('Ticket-Shop-URL', 'pretix-eventlister'); ?></strong></label><br />
				<input type="url" id="pretix_manual_url" name="pretix_manual_url" class="widefat" value="<?php echo esc_attr($url); ?>" placeholder="https://tickets.example.com/event" />
			</p>
		</div>

		<script>
			(function(){
				const root=document.currentScript.closest('.inside');
				if(!root){return;}
				const tabs=root.querySelectorAll('[data-pretix-metabox-tabs] .pretix-metabox-tab');
				const panels=root.querySelectorAll('.pretix-metabox-panel');
				tabs.forEach((tab)=>{
					tab.addEventListener('click',()=>{
						const target=tab.getAttribute('data-target');
						tabs.forEach((t)=>t.classList.remove('is-active'));
						panels.forEach((p)=>p.classList.remove('is-active'));
						tab.classList.add('is-active');
						const panel=root.querySelector('.pretix-metabox-panel[data-panel=\"'+target+'\"]');
						if(panel){panel.classList.add('is-active');}
					});
				});
			})();
		</script>
		<?php
	}

	public function save_event_override_metabox($post_id, $post) {
		if (! $post instanceof WP_Post || self::CPT !== $post->post_type) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (! isset($_POST['pretix_eventlister_override_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pretix_eventlister_override_nonce'])), 'pretix_eventlister_override_meta')) {
			return;
		}

		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$enabled = isset($_POST['pretix_manual_override_enabled']) ? 1 : 0;
		$hidden = isset($_POST['pretix_manual_hidden']) ? 1 : 0;
		update_post_meta($post_id, '_pretix_manual_override_enabled', $enabled);
		update_post_meta($post_id, '_pretix_manual_hidden', $hidden);

		$fields = array(
			'_pretix_manual_name' => isset($_POST['pretix_manual_name']) ? sanitize_text_field(wp_unslash($_POST['pretix_manual_name'])) : '',
			'_pretix_manual_description' => isset($_POST['pretix_manual_description']) ? wp_kses_post(wp_unslash($_POST['pretix_manual_description'])) : '',
			'_pretix_manual_image' => isset($_POST['pretix_manual_image']) ? esc_url_raw(wp_unslash($_POST['pretix_manual_image'])) : '',
			'_pretix_manual_location' => isset($_POST['pretix_manual_location']) ? sanitize_text_field(wp_unslash($_POST['pretix_manual_location'])) : '',
			'_pretix_manual_url' => isset($_POST['pretix_manual_url']) ? esc_url_raw(wp_unslash($_POST['pretix_manual_url'])) : '',
		);

		foreach ($fields as $meta_key => $meta_value) {
			if ('' === $meta_value) {
				delete_post_meta($post_id, $meta_key);
				continue;
			}
			update_post_meta($post_id, $meta_key, $meta_value);
		}

		$manual_date_from = isset($_POST['pretix_manual_date_from']) ? $this->parse_datetime_local_input(wp_unslash($_POST['pretix_manual_date_from'])) : 0;
		$manual_date_to = isset($_POST['pretix_manual_date_to']) ? $this->parse_datetime_local_input(wp_unslash($_POST['pretix_manual_date_to'])) : 0;

		if ($manual_date_from > 0) {
			update_post_meta($post_id, '_pretix_manual_date_from', $manual_date_from);
		} else {
			delete_post_meta($post_id, '_pretix_manual_date_from');
		}

		if ($manual_date_to > 0) {
			update_post_meta($post_id, '_pretix_manual_date_to', $manual_date_to);
		} else {
			delete_post_meta($post_id, '_pretix_manual_date_to');
		}

		$this->manual_override_cache = array();
	}

	private function format_timestamp_for_datetime_local($timestamp) {
		$timestamp = absint($timestamp);
		if ($timestamp <= 0) {
			return '';
		}

		return wp_date('Y-m-d\\TH:i', $timestamp, wp_timezone());
	}

	private function parse_datetime_local_input($value) {
		$value = trim((string) $value);
		if ('' === $value) {
			return 0;
		}

		$datetime = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $value, wp_timezone());
		if (! $datetime instanceof DateTimeImmutable) {
			return 0;
		}

		return $datetime->getTimestamp();
	}

	public function register_cron() {
		$options = $this->get_options();
		$enabled = ! empty($options['enable_cpt_sync']);
		$timestamp = wp_next_scheduled(self::CRON_HOOK);

		if (! $enabled && $timestamp) {
			wp_unschedule_event($timestamp, self::CRON_HOOK);
			return;
		}

		if ($enabled && ! $timestamp) {
			wp_schedule_event(time() + 60, 'hourly', self::CRON_HOOK);
		}
	}

	public function sync_events_to_cpt() {
		$options = $this->get_options();
		if (empty($options['enable_cpt_sync'])) {
			return;
		}

		$interval_hours = max(1, absint($options['cpt_sync_interval']));
		$last_sync = (int) get_option(self::CACHE_PREFIX . 'cpt_last_sync', 0);
		if ($last_sync > 0 && (time() - $last_sync) < ($interval_hours * HOUR_IN_SECONDS)) {
			return;
		}

		$scope = ! empty($options['cpt_sync_scope']) ? (string) $options['cpt_sync_scope'] : 'selected';
		$sync_organizers = $this->parse_slug_list($options['cpt_sync_organizers']);
		if (empty($sync_organizers)) {
			$sync_organizers = $this->parse_slug_list($options['default_organizers']);
		}

		$query = array(
			'scope' => 'all' === $scope ? 'all' : 'selected',
			'organizers' => $sync_organizers,
			'limit' => null,
			'style' => 'grid',
			'show_description' => true,
			'show_organizer' => true,
			'show_image' => true,
			'show_time' => true,
			'show_location' => true,
			'show_countdown' => false,
			'show_platform_notice' => false,
			'show_organizer_slug' => false,
			'show_ticket_button' => false,
			'show_ticket_price' => false,
			'feature_filters' => false,
			'feature_load_more' => false,
			'page_size' => 50,
			'feature_badges' => false,
			'feature_badges_availability' => false,
			'show_available_tickets' => false,
			'feature_calendar' => false,
			'feature_schema' => false,
			'feature_modal' => false,
			'feature_tilt' => false,
		);

		$collection = $this->build_collection($query, $options);
		if (is_wp_error($collection) || empty($collection['events'])) {
			return;
		}

		$existing = get_posts(
			array(
				'post_type' => self::CPT,
				'post_status' => array('publish', 'draft', 'pending', 'private'),
				'posts_per_page' => -1,
				'fields' => 'ids',
				'meta_key' => '_pretix_slug',
			)
		);

		$index = array();
		foreach ($existing as $post_id) {
			$org = (string) get_post_meta($post_id, '_pretix_org', true);
			$slug = (string) get_post_meta($post_id, '_pretix_slug', true);
			if ($org && $slug) {
				$index[ strtolower($org . '/' . $slug) ] = (int) $post_id;
			}
		}

		foreach ($collection['events'] as $event) {
			if (empty($event['organizer_slug']) || empty($event['slug'])) {
				continue;
			}

			$key = strtolower($event['organizer_slug'] . '/' . $event['slug']);
			$post_id = isset($index[ $key ]) ? $index[ $key ] : 0;
			$manual_override_active = $post_id ? ! empty(get_post_meta($post_id, '_pretix_manual_override_enabled', true)) : false;

			$content = '';
			if (! empty($event['description'])) {
				$content = wp_kses_post($event['description']);
			}

			$new_id = $post_id;
			if (! $post_id || ! $manual_override_active) {
				$postarr = array(
					'ID' => $post_id,
					'post_type' => self::CPT,
					'post_status' => 'publish',
					'post_title' => wp_strip_all_tags((string) $event['name']),
					'post_content' => $content,
					'post_excerpt' => wp_strip_all_tags(wp_trim_words($content, 35)),
				);

				$new_id = wp_insert_post($postarr, true);
				if (is_wp_error($new_id) || ! $new_id) {
					continue;
				}
			}

			update_post_meta($new_id, '_pretix_org', (string) $event['organizer_slug']);
			update_post_meta($new_id, '_pretix_slug', (string) $event['slug']);
			update_post_meta($new_id, '_pretix_source_url', (string) $event['url']);
			update_post_meta($new_id, '_pretix_url', (string) $event['url']);
			update_post_meta($new_id, '_pretix_date_from', ! empty($event['date_from']) ? (int) $event['date_from'] : 0);
			update_post_meta($new_id, '_pretix_date_to', ! empty($event['date_to']) ? (int) $event['date_to'] : 0);
		}

		update_option(self::CACHE_PREFIX . 'cpt_last_sync', time(), false);
	}

	public function download_ics() {
		if (! $this->allow_public_ics_request()) {
			status_header(429);
			echo esc_html__('Zu viele Anfragen. Bitte spaeter erneut versuchen.', 'pretix-eventlister');
			exit;
		}

		$options = $this->get_options();
		$base_url = isset($options['base_url']) ? (string) $options['base_url'] : '';
		$api_token = isset($options['api_token']) ? (string) $options['api_token'] : '';

		$organizer_slug = isset($_GET['org']) ? sanitize_title(wp_unslash($_GET['org'])) : '';
		$event_slug = isset($_GET['event']) ? sanitize_title(wp_unslash($_GET['event'])) : '';

		if (strlen($organizer_slug) > 120 || strlen($event_slug) > 120) {
			status_header(400);
			echo esc_html__('Ungueltige Anfrage.', 'pretix-eventlister');
			exit;
		}

		if (! $base_url || ! $api_token || ! $organizer_slug || ! $event_slug) {
			status_header(400);
			echo esc_html__('Ungueltige Anfrage.', 'pretix-eventlister');
			exit;
		}

		$cache_key = self::CACHE_PREFIX . 'ics_' . md5($base_url . '|' . $organizer_slug . '|' . $event_slug);
		$cached = get_transient($cache_key);
		if (is_string($cached) && '' !== $cached) {
			$this->send_ics($cached, $event_slug);
		}

		$event = $this->get_api_object(
			$this->build_api_url(
				$base_url,
				sprintf(
					'api/v1/organizers/%1$s/events/%2$s/',
					rawurlencode($organizer_slug),
					rawurlencode($event_slug)
				)
			),
			$api_token
		);

		if (is_wp_error($event) || ! is_array($event)) {
			status_header(404);
			echo esc_html__('Event nicht gefunden.', 'pretix-eventlister');
			exit;
		}

		$settings = $this->get_event_settings($base_url, $api_token, $organizer_slug, $event_slug, absint($options['cache_ttl']));
		if (is_wp_error($settings)) {
			$settings = array();
		}

		$name = $this->resolve_event_name($event);
		$description_html = $this->resolve_event_description($event, $settings);
		$description = trim(preg_replace('/\\s+/', ' ', wp_strip_all_tags((string) $description_html)));
		$location = ! empty($event['location']) ? $this->resolve_location_text($event['location']) : '';
		$start = ! empty($event['date_from']) ? strtotime((string) $event['date_from']) : 0;
		$end = ! empty($event['date_to']) ? strtotime((string) $event['date_to']) : 0;
		$url = $this->resolve_public_url($event, $base_url, $organizer_slug);

		if (! $start) {
			status_header(404);
			echo esc_html__('Event hat kein Datum.', 'pretix-eventlister');
			exit;
		}

		if (! $end) {
			$end = $start + HOUR_IN_SECONDS;
		}

		$uid = 'pretix-' . $organizer_slug . '-' . $event_slug . '@' . wp_parse_url(home_url('/'), PHP_URL_HOST);
		$ics = $this->build_ics(
			array(
				'uid' => $uid,
				'summary' => $name,
				'description' => $description,
				'location' => $location,
				'url' => $url,
				'start' => $start,
				'end' => $end,
			)
		);

		set_transient($cache_key, $ics, MINUTE_IN_SECONDS * max(1, absint($options['cache_ttl'])));
		$this->send_ics($ics, $event_slug);
	}

	private function send_ics($ics, $filename_slug) {
		nocache_headers();
		header('Content-Type: text/calendar; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename_slug . '.ics') . '"');
		echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private function allow_public_ics_request() {
		$limit = 40;
		$window = MINUTE_IN_SECONDS;
		$client = $this->get_public_client_key();
		$key = self::CACHE_PREFIX . 'ics_rl_' . md5($client);
		$count = (int) get_transient($key);
		if ($count >= $limit) {
			return false;
		}

		set_transient($key, $count + 1, $window);
		return true;
	}

	private function get_public_client_key() {
		$ip = '';
		if (! empty($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
			$ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
		}

		$ua = '';
		if (! empty($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])) {
			$ua = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));
		}

		return $ip . '|' . substr($ua, 0, 120);
	}

	private function build_ics($data) {
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Pretix Eventlister//DE',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:' . $this->ics_escape(isset($data['uid']) ? $data['uid'] : ''),
			'DTSTAMP:' . gmdate('Ymd\\THis\\Z'),
			'DTSTART:' . gmdate('Ymd\\THis\\Z', (int) $data['start']),
			'DTEND:' . gmdate('Ymd\\THis\\Z', (int) $data['end']),
			'SUMMARY:' . $this->ics_escape(isset($data['summary']) ? $data['summary'] : ''),
		);

		if (! empty($data['location'])) {
			$lines[] = 'LOCATION:' . $this->ics_escape($data['location']);
		}

		if (! empty($data['description'])) {
			$lines[] = 'DESCRIPTION:' . $this->ics_escape($data['description']);
		}

		if (! empty($data['url'])) {
			$lines[] = 'URL:' . $this->ics_escape($data['url']);
		}

		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode("\r\n", array_map(array($this, 'ics_fold_line'), $lines)) . "\r\n";
	}

	private function ics_escape($value) {
		$value = (string) $value;
		$value = str_replace(array("\\", ";", ",", "\n", "\r"), array("\\\\", "\\;", "\\,", "\\n", ""), $value);
		return $value;
	}

	private function ics_fold_line($line) {
		$line = (string) $line;
		if (strlen($line) <= 75) {
			return $line;
		}

		$result = '';
		while (strlen($line) > 75) {
			$result .= substr($line, 0, 75) . "\r\n" . ' ';
			$line = substr($line, 75);
		}

		return $result . $line;
	}

	public function handle_admin_flush_cache() {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Keine Berechtigung.', 'pretix-eventlister'));
		}

		check_admin_referer('pretix_eventlister_flush_cache');
		$this->flush_cache();
		$this->admin_redirect_notice('success', __('Cache wurde geleert.', 'pretix-eventlister'));
	}

	public function handle_admin_test_api() {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Keine Berechtigung.', 'pretix-eventlister'));
		}

		check_admin_referer('pretix_eventlister_test_api');

		$options = $this->get_options();
		$base_url = isset($options['base_url']) ? (string) $options['base_url'] : '';
		$api_token = isset($options['api_token']) ? (string) $options['api_token'] : '';

		if (! $base_url || ! $api_token) {
			$this->admin_redirect_notice('error', __('Bitte Basis-URL und API-Token speichern, bevor du testest.', 'pretix-eventlister'));
		}

		$organizers = $this->get_paginated_results($this->build_api_url($base_url, 'api/v1/organizers/'), $api_token);
		if (is_wp_error($organizers)) {
			$this->admin_redirect_notice('error', $organizers->get_error_message());
		}

		$count = is_array($organizers) ? count($organizers) : 0;
		$this->admin_redirect_notice('success', sprintf(
			/* translators: %d: number of organizers */
			__('API ok. %d Veranstalter gefunden.', 'pretix-eventlister'),
			$count
		));
	}

	private function admin_redirect_notice($type, $message) {
		$url = add_query_arg(
			array(
				'page' => 'pretix-eventlister',
				'pel_notice' => sanitize_key((string) $type),
				'pel_message' => rawurlencode((string) $message),
			),
			admin_url('options-general.php')
		);

		wp_safe_redirect($url);
		exit;
	}

	public function enqueue_plugin_admin_assets($hook_suffix) {
		if ('plugins.php' !== $hook_suffix) {
			return;
		}

		$icon_url = esc_url($this->get_plugin_icon_url());
		$plugin_selector = '#the-list tr[data-plugin="' . esc_attr($this->get_plugin_basename()) . '"] .plugin-title strong';

		wp_register_style('pretix-eventlister-admin', false, array(), self::VERSION);
		wp_enqueue_style('pretix-eventlister-admin');
		wp_add_inline_style(
			'pretix-eventlister-admin',
			$plugin_selector . '{display:inline-flex;align-items:center;gap:10px;}' .
			$plugin_selector . "::before{content:'';width:26px;height:26px;display:inline-block;flex:0 0 26px;border-radius:8px;background:#111827 url('" . $icon_url . "') center/18px 18px no-repeat;box-shadow:0 8px 18px rgba(15,23,42,.14);}"
		);
	}

	public function add_plugin_row_meta($plugin_meta, $plugin_file, $plugin_data, $status) {
		if ($this->get_plugin_basename() !== $plugin_file) {
			return $plugin_meta;
		}

		$plugin_meta[] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url(self::GITHUB_REPOSITORY_URL),
			esc_html__('GitHub-Repository', 'pretix-eventlister')
		);

		$plugin_meta[] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url($this->get_changelog_url()),
			esc_html__('Changelog', 'pretix-eventlister')
		);

		return $plugin_meta;
	}

	public function render_shortcode($atts) {
		$options = $this->get_options();
		$query = $this->normalize_shortcode_atts($atts, $options);

		wp_enqueue_style('pretix-eventlister');
		wp_enqueue_script('pretix-eventlister');

		$collection = $this->build_collection($query, $options);
		if (is_wp_error($collection)) {
			return sprintf(
				'<div class="pretix-eventlister__notice pretix-eventlister__notice--error">%s</div>',
				esc_html($collection->get_error_message())
			);
		}

		if (empty($collection['events'])) {
			return sprintf(
				'<div class="pretix-eventlister__notice">%s</div>',
				esc_html__('Aktuell sind keine passenden Events verfügbar.', 'pretix-eventlister')
			);
		}

		$events = $collection['events'];
		$collection_meta = $collection['meta'];
		$show_description = $query['show_description'];
		$show_organizer = $query['show_organizer'];
		$show_image = $query['show_image'];
		$show_time = $query['show_time'];
		$show_location = $query['show_location'];
		$show_countdown = $query['show_countdown'];
		$show_platform_notice = $query['show_platform_notice'];
		$show_organizer_slug = $query['show_organizer_slug'];
		$show_ticket_button = $query['show_ticket_button'];
		$feature_filters = $query['feature_filters'];
		$feature_load_more = $query['feature_load_more'];
		$page_size = $query['page_size'];
		$feature_badges = $query['feature_badges'];
		$show_available_tickets = $query['show_available_tickets'];
		$feature_calendar = $query['feature_calendar'];
		$feature_schema = $query['feature_schema'];
		$feature_modal = $query['feature_modal'];
		$feature_tilt = $query['feature_tilt'];
		$accent_color = ! empty($options['accent_color']) ? (string) $options['accent_color'] : '';
		$composer_enabled = ! empty($options['composer_enabled']);
		$composer_config = array(
			'enabled' => $composer_enabled,
			'layout' => $this->get_composer_layout(isset($options['composer_layout_json']) ? $options['composer_layout_json'] : ''),
			'styles' => $this->get_composer_styles(isset($options['composer_styles_json']) ? $options['composer_styles_json'] : ''),
		);
		$composer_config_json = wp_json_encode($composer_config);
		$instance_id = 'pretix-' . substr(md5(uniqid('', true)), 0, 10);

		$layout_class = 'list' === $query['style']
			? 'pretix-eventlister--list'
			: ('compact' === $query['style'] ? 'pretix-eventlister--compact' : 'pretix-eventlister--grid');

		ob_start();
		include plugin_dir_path(__FILE__) . 'templates/events-list.php';
		return ob_get_clean();
	}

	public function inject_update_information($transient) {
		if (! is_object($transient) || empty($transient->checked)) {
			return $transient;
		}

		$plugin_basename = $this->get_plugin_basename();
		$current_version = isset($transient->checked[ $plugin_basename ]) ? $transient->checked[ $plugin_basename ] : self::VERSION;
		$release = $this->get_latest_github_release();

		if (is_wp_error($release) || empty($release['version']) || empty($release['package'])) {
			return $transient;
		}

		$plugin_data = (object) array(
			'id' => self::GITHUB_REPOSITORY_URL,
			'slug' => self::PLUGIN_SLUG,
			'plugin' => $plugin_basename,
			'new_version' => $release['version'],
			'url' => $release['html_url'],
			'package' => $release['package'],
			'tested' => get_bloginfo('version'),
			'icons' => $this->get_plugin_icons(),
			'banners' => array(),
			'banners_rtl' => array(),
			'requires_php' => self::MINIMUM_PHP,
		);

		if (version_compare($release['version'], $current_version, '>')) {
			$transient->response[ $plugin_basename ] = $plugin_data;
		} else {
			$transient->no_update[ $plugin_basename ] = $plugin_data;
		}

		return $transient;
	}

	public function inject_plugin_information($result, $action, $args) {
		if ('plugin_information' !== $action || empty($args->slug) || self::PLUGIN_SLUG !== $args->slug) {
			return $result;
		}

		$release = $this->get_latest_github_release();
		if (is_wp_error($release)) {
			return $result;
		}

		return (object) array(
			'name' => __('Pretix Eventlister', 'pretix-eventlister'),
			'slug' => self::PLUGIN_SLUG,
			'version' => ! empty($release['version']) ? $release['version'] : self::VERSION,
			'author' => '<a href="' . esc_url(self::GITHUB_REPOSITORY_URL) . '">bright color</a>',
			'author_profile' => esc_url(self::GITHUB_REPOSITORY_URL),
			'homepage' => esc_url(self::GITHUB_REPOSITORY_URL),
			'download_link' => ! empty($release['package']) ? $release['package'] : '',
			'trunk' => ! empty($release['package']) ? $release['package'] : '',
			'requires' => '5.8',
			'requires_php' => self::MINIMUM_PHP,
			'last_updated' => ! empty($release['published_at']) ? $release['published_at'] : '',
			'external' => true,
			'sections' => array(
				'description' => wp_kses_post(
					'<p>' . __('Modern WordPress plugin for pretix events with multi-organizer support, responsive card layouts, and optional partner platform notices.', 'pretix-eventlister') . '</p>' .
					'<p>' . __('Updates are delivered directly from this plugin\'s GitHub releases.', 'pretix-eventlister') . '</p>'
				),
				'installation' => wp_kses_post(
					'<ol>' .
					'<li>' . __('Install or update the plugin in WordPress.', 'pretix-eventlister') . '</li>' .
					'<li>' . __('Configure your pretix connection under Settings > Pretix Eventlister.', 'pretix-eventlister') . '</li>' .
					'<li>' . __('New versions are detected automatically once a GitHub release with a ZIP asset is published.', 'pretix-eventlister') . '</li>' .
					'</ol>'
				),
				'changelog' => $this->format_release_notes_for_modal(isset($release['body']) ? $release['body'] : ''),
			),
			'banners' => array(),
			'icons' => $this->get_plugin_icons(),
			'versions' => ! empty($release['package']) ? array($release['version'] => $release['package']) : array(),
		);
	}

	public function handle_upgrader_process_complete($upgrader_object, $options) {
		if (empty($options['action']) || 'update' !== $options['action']) {
			return;
		}

		if (empty($options['type']) || 'plugin' !== $options['type']) {
			return;
		}

		if (empty($options['plugins']) || ! is_array($options['plugins'])) {
			return;
		}

		if (in_array($this->get_plugin_basename(), $options['plugins'], true)) {
			delete_site_transient(self::GITHUB_RELEASE_CACHE_KEY);
		}
	}

	public function normalize_installation_state() {
		if (! is_admin()) {
			return;
		}

		if (! current_user_can('activate_plugins')) {
			return;
		}

		$canonical_basename = self::PLUGIN_SLUG . '/' . self::PLUGIN_SLUG . '.php';
		$canonical_file = WP_PLUGIN_DIR . '/' . $canonical_basename;

		if (! is_file($canonical_file)) {
			return;
		}

		$active_plugins = get_option('active_plugins', array());
		if (is_array($active_plugins) && ! empty($active_plugins)) {
			$changed = false;

			foreach ($active_plugins as $index => $plugin_basename) {
				if (! is_string($plugin_basename) || ! $this->is_legacy_plugin_basename($plugin_basename)) {
					continue;
				}
				$active_plugins[ $index ] = $canonical_basename;
				$changed = true;
			}

			if ($changed) {
				$active_plugins = array_values(array_unique($active_plugins));
				update_option('active_plugins', $active_plugins, false);
			}
		}

		$update_plugins = get_site_transient('update_plugins');
		if (! is_object($update_plugins)) {
			return;
		}

		$map_sets = array('checked', 'response', 'no_update');
		$updated = false;
		foreach ($map_sets as $set_key) {
			if (empty($update_plugins->{$set_key}) || ! is_array($update_plugins->{$set_key})) {
				continue;
			}

			foreach ($update_plugins->{$set_key} as $plugin_basename => $payload) {
				if (! is_string($plugin_basename) || ! $this->is_legacy_plugin_basename($plugin_basename)) {
					continue;
				}

				unset($update_plugins->{$set_key}[ $plugin_basename ]);
				$update_plugins->{$set_key}[ $canonical_basename ] = $payload;
				$updated = true;
			}
		}

		if ($updated) {
			set_site_transient('update_plugins', $update_plugins);
		}
	}

	public function prefer_plugin_source_directory($source, $remote_source, $upgrader, $hook_extra) {
		if (is_wp_error($source)) {
			return $source;
		}

		$source = untrailingslashit((string) $source);
		if (! $source || ! is_dir($source)) {
			return $source;
		}

		if ($this->directory_contains_plugin_file($source)) {
			return $source;
		}

		$direct_candidate = untrailingslashit($source . '/' . self::PLUGIN_SLUG);
		if ($this->directory_contains_plugin_file($direct_candidate)) {
			return $direct_candidate;
		}

		$candidates = glob($source . '/*', GLOB_ONLYDIR);
		if (is_array($candidates) && 1 === count($candidates)) {
			$candidate = untrailingslashit($candidates[0]);
			if ($this->directory_contains_plugin_file($candidate)) {
				return $candidate;
			}
		}

		$deep_candidate = $this->find_single_plugin_directory($source, 4);
		return $deep_candidate ? $deep_candidate : $source;
	}

	private function find_single_plugin_directory($root, $max_depth = 4) {
		$root = untrailingslashit((string) $root);
		if (! $root || ! is_dir($root)) {
			return '';
		}

		$matches = array();

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ($iterator as $item) {
				if (! $item->isDir()) {
					continue;
				}

				$path = untrailingslashit($item->getPathname());
				$relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
				$depth = '' === $relative ? 0 : substr_count($relative, DIRECTORY_SEPARATOR) + 1;
				if ($depth > $max_depth) {
					continue;
				}

				if ($this->directory_contains_plugin_file($path)) {
					$matches[] = $path;
					if (count($matches) > 1) {
						break;
					}
				}
			}
		} catch (Exception $e) {
			return '';
		}

		return 1 === count($matches) ? (string) $matches[0] : '';
	}

	private function normalize_shortcode_atts($atts, $options) {
		$atts = shortcode_atts(
			array(
				'limit' => '9',
				'scope' => 'selected',
				'organizer' => '',
				'organizers' => '',
				'style' => 'default',
				'show_description' => 'default',
				'show_organizer' => 'default',
				'show_image' => 'default',
				'show_time' => 'default',
				'show_location' => 'default',
				'show_countdown' => 'default',
				'show_platform_notice' => 'default',
				'show_organizer_slug' => 'default',
				'show_ticket_button' => 'default',
				'show_ticket_price' => 'default',
				'filters' => 'default',
				'load_more' => 'default',
				'page_size' => '',
				'badges' => 'default',
				'badges_availability' => 'default',
				'show_available_tickets' => 'default',
				'calendar' => 'default',
				'schema' => 'default',
				'modal' => 'default',
				'tilt' => 'default',
			),
			$atts,
			'pretix_events'
		);

		$selected_organizers = array_merge(
			$this->parse_slug_list($atts['organizers']),
			$this->parse_slug_list($atts['organizer'])
		);

		if (empty($selected_organizers)) {
			$selected_organizers = $this->parse_slug_list($options['default_organizers']);
		}

		$scope = sanitize_key($atts['scope']);
		if (! in_array($scope, array('all', 'selected'), true)) {
			$scope = 'selected';
		}

		if (empty($selected_organizers) && 'selected' === $scope) {
			$scope = 'all';
		}

		$limit_raw = is_scalar($atts['limit']) ? trim((string) $atts['limit']) : '9';
		$limit_all = in_array(strtolower($limit_raw), array('all', '-1', '0'), true);
		$limit = $limit_all ? null : max(1, absint($limit_raw));

		$resolved_style = $this->resolve_style_from_shortcode(
			$atts['style'],
			isset($options['default_style']) ? (string) $options['default_style'] : 'grid'
		);

		$page_size = $this->resolve_int_from_shortcode(
			$atts['page_size'],
			isset($options['page_size']) ? absint($options['page_size']) : 9
		);

		return array(
			'scope' => $scope,
			'organizers' => array_values(array_unique($selected_organizers)),
			'limit' => $limit,
			'style' => $resolved_style,
			'show_description' => $this->resolve_toggle_from_shortcode($atts['show_description'], ! empty($options['default_show_description'])),
			'show_organizer' => $this->resolve_toggle_from_shortcode($atts['show_organizer'], ! empty($options['default_show_organizer'])),
			'show_image' => $this->resolve_toggle_from_shortcode($atts['show_image'], ! empty($options['show_image'])),
			'show_time' => $this->resolve_toggle_from_shortcode($atts['show_time'], ! empty($options['show_time'])),
			'show_location' => $this->resolve_toggle_from_shortcode($atts['show_location'], ! empty($options['show_location'])),
			'show_countdown' => $this->resolve_toggle_from_shortcode($atts['show_countdown'], ! empty($options['show_countdown'])),
			'show_platform_notice' => $this->resolve_toggle_from_shortcode($atts['show_platform_notice'], ! empty($options['show_platform_notice'])),
			'show_organizer_slug' => $this->resolve_toggle_from_shortcode($atts['show_organizer_slug'], ! empty($options['show_organizer_slug'])),
			'show_ticket_button' => $this->resolve_toggle_from_shortcode($atts['show_ticket_button'], ! empty($options['show_ticket_button'])),
			'show_ticket_price' => $this->resolve_toggle_from_shortcode($atts['show_ticket_price'], ! empty($options['show_ticket_price'])),
			'feature_filters' => $this->resolve_toggle_from_shortcode($atts['filters'], ! empty($options['feature_filters'])),
			'feature_load_more' => $this->resolve_toggle_from_shortcode($atts['load_more'], ! empty($options['feature_load_more'])),
			'page_size' => $page_size,
			'feature_badges' => $this->resolve_toggle_from_shortcode($atts['badges'], ! empty($options['feature_badges'])),
			'feature_badges_availability' => $this->resolve_toggle_from_shortcode($atts['badges_availability'], ! empty($options['feature_badges_availability'])),
			'show_available_tickets' => $this->resolve_toggle_from_shortcode($atts['show_available_tickets'], ! empty($options['show_available_tickets'])),
			'feature_calendar' => $this->resolve_toggle_from_shortcode($atts['calendar'], ! empty($options['feature_calendar'])),
			'feature_schema' => $this->resolve_toggle_from_shortcode($atts['schema'], ! empty($options['feature_schema'])),
			'feature_modal' => $this->resolve_toggle_from_shortcode($atts['modal'], ! empty($options['feature_modal'])),
			'feature_tilt' => $this->resolve_toggle_from_shortcode($atts['tilt'], ! empty($options['feature_tilt'])),
		);
	}

	private function build_collection($query, $options) {
		$base_url = $options['base_url'];
		$api_token = $options['api_token'];

		if (! $base_url || ! $api_token) {
			return new WP_Error(
				'pretix_eventlister_missing_config',
				__('Bitte hinterlege zuerst Basis-URL und API-Token in den Plugin-Einstellungen.', 'pretix-eventlister')
			);
		}

		$organizer_index = array();
		if ('all' === $query['scope'] || $query['show_organizer']) {
			$organizer_index = $this->get_organizer_index($base_url, $api_token, absint($options['cache_ttl']));
			if (is_wp_error($organizer_index)) {
				if ('all' === $query['scope']) {
					return $organizer_index;
				}

				$organizer_index = array();
			}
		}

		$organizer_slugs = 'all' === $query['scope'] ? array_keys($organizer_index) : $query['organizers'];
		$disabled_organizers = $this->parse_slug_list(isset($options['disabled_organizers']) ? $options['disabled_organizers'] : '');
		if (! empty($disabled_organizers)) {
			$organizer_slugs = array_values(array_diff($organizer_slugs, $disabled_organizers));
		}

		if (empty($organizer_slugs)) {
			return new WP_Error(
				'pretix_eventlister_missing_organizers',
				__('Es konnten keine Veranstalter fuer die Abfrage ermittelt werden.', 'pretix-eventlister')
			);
		}

		$cache_key = self::CACHE_PREFIX . md5(
			wp_json_encode(
				array(
					$base_url,
					$query,
					$organizer_slugs,
					$options['platform_organizers'],
					$options['platform_notice'],
					$options['disabled_organizers'],
					$options['hidden_events'],
				)
			)
		);
		$cached = get_transient($cache_key);
		if (false !== $cached) {
			return $cached;
		}

		$platform_organizers = $this->parse_slug_list($options['platform_organizers']);
		$platform_notice_map = $this->parse_notice_map(isset($options['platform_notice_map']) ? $options['platform_notice_map'] : '');
		$pinned_events = $this->parse_pinned_events(isset($options['pinned_events']) ? $options['pinned_events'] : '');
		$hidden_events = $this->parse_pinned_events(isset($options['hidden_events']) ? $options['hidden_events'] : '');
		$events = array();

		foreach ($organizer_slugs as $organizer_slug) {
			$organizer_name = isset($organizer_index[ $organizer_slug ]['name']) ? $organizer_index[ $organizer_slug ]['name'] : $this->beautify_slug($organizer_slug);
			$organizer_events = $this->get_paginated_results(
				$this->build_api_url($base_url, sprintf('api/v1/organizers/%s/events/?ordering=date_from', rawurlencode($organizer_slug))),
				$api_token
			);

			if (is_wp_error($organizer_events)) {
				return $organizer_events;
			}

			foreach ($organizer_events as $event) {
				$raw_slug = ! empty($event['slug']) ? sanitize_title((string) $event['slug']) : '';
				$event_key = $raw_slug ? strtolower($organizer_slug . '/' . $raw_slug) : '';
				if ($event_key && isset($hidden_events[ $event_key ])) {
					continue;
				}

				$normalized_event = $this->normalize_event(
					$event,
					$organizer_slug,
					$organizer_name,
					$platform_organizers,
					$options['platform_notice'],
					$platform_notice_map,
					$pinned_events,
					$base_url,
					$api_token,
					absint($options['cache_ttl']),
					max(1, absint($options['low_ticket_threshold'])),
					$query
				);

				if ($normalized_event) {
					$events[] = $normalized_event;
				}
			}
		}

		usort(
			$events,
			function ($left, $right) {
				if (! empty($left['is_pinned']) && empty($right['is_pinned'])) {
					return -1;
				}

				if (empty($left['is_pinned']) && ! empty($right['is_pinned'])) {
					return 1;
				}

				if ($left['sort_timestamp'] === $right['sort_timestamp']) {
					return strcmp($left['name'], $right['name']);
				}

				return $left['sort_timestamp'] <=> $right['sort_timestamp'];
			}
		);

		if (null !== $query['limit']) {
			$events = array_slice($events, 0, $query['limit']);
		}

		$collection = array(
			'events' => $events,
			'meta' => $this->build_collection_meta($query, $organizer_slugs, $organizer_index, $events),
		);

		set_transient($cache_key, $collection, MINUTE_IN_SECONDS * max(1, absint($options['cache_ttl'])));

		return $collection;
	}

	private function get_organizer_index($base_url, $api_token, $cache_ttl) {
		$cache_key = self::CACHE_PREFIX . 'organizers_' . md5($base_url);
		$cached = get_transient($cache_key);
		if (false !== $cached) {
			return $cached;
		}

		$organizers = $this->get_paginated_results(
			$this->build_api_url($base_url, 'api/v1/organizers/'),
			$api_token
		);

		if (is_wp_error($organizers)) {
			return $organizers;
		}

		$index = array();
		foreach ($organizers as $organizer) {
			if (empty($organizer['slug'])) {
				continue;
			}

			$slug = sanitize_title($organizer['slug']);
			$index[ $slug ] = array(
				'slug' => $slug,
				'name' => $this->resolve_text_value(isset($organizer['name']) ? $organizer['name'] : $slug),
			);
		}

		set_transient($cache_key, $index, MINUTE_IN_SECONDS * max(1, $cache_ttl));

		return $index;
	}

	private function get_paginated_results($url, $api_token) {
		$results = array();
		$next_url = $url;
		$page_counter = 0;

		while ($next_url && $page_counter < 40) {
			$page_counter++;
			$response = wp_remote_get(
				$next_url,
				array(
					'timeout' => 20,
					'headers' => array(
						'Authorization' => 'Token ' . $api_token,
						'Accept' => 'application/json',
					),
				)
			);

			if (is_wp_error($response)) {
				return new WP_Error(
					'pretix_eventlister_request_failed',
					__('Die pretix-Instanz konnte nicht erreicht werden.', 'pretix-eventlister')
				);
			}

			$status_code = wp_remote_retrieve_response_code($response);
			$body = json_decode(wp_remote_retrieve_body($response), true);

			if (200 !== $status_code || ! is_array($body)) {
				return new WP_Error(
					'pretix_eventlister_invalid_response',
					__('Die Antwort der pretix-API war ungueltig.', 'pretix-eventlister')
				);
			}

			if (isset($body['results']) && is_array($body['results'])) {
				$results = array_merge($results, $body['results']);
				$next_url = ! empty($body['next']) ? $this->resolve_next_url($next_url, $body['next']) : '';
				continue;
			}

			if (array_values($body) === $body) {
				$results = array_merge($results, $body);
				$next_url = '';
				continue;
			}

			return new WP_Error(
				'pretix_eventlister_invalid_structure',
				__('Die API hat keine erwarteten Ergebnisdaten geliefert.', 'pretix-eventlister')
			);
		}

		if ($next_url) {
			return new WP_Error(
				'pretix_eventlister_pagination_limit',
				__('Die API-Antwort war zu umfangreich und wurde aus Sicherheitsgruenden abgebrochen.', 'pretix-eventlister')
			);
		}

		return $results;
	}

	private function normalize_event($event, $organizer_slug, $organizer_name, $platform_organizers, $platform_notice, $platform_notice_map, $pinned_events, $base_url, $api_token, $cache_ttl, $low_ticket_threshold, $query) {
		if (isset($event['live']) && ! $event['live']) {
			return null;
		}

		$event_slug = ! empty($event['slug']) ? sanitize_title($event['slug']) : '';
		$manual_override = $this->get_event_manual_overrides($organizer_slug, $event_slug);
		if (! empty($manual_override['hidden'])) {
			return null;
		}

		$date_from = ! empty($event['date_from']) ? strtotime($event['date_from']) : null;
		$date_to = ! empty($event['date_to']) ? strtotime($event['date_to']) : null;

		if (! empty($manual_override['date_from'])) {
			$date_from = (int) $manual_override['date_from'];
		}
		if (! empty($manual_override['date_to'])) {
			$date_to = (int) $manual_override['date_to'];
		}

		$now = current_time('timestamp');
		$site_midnight = $this->get_site_midnight_timestamp();

		if ($date_to && $date_to < $now) {
			return null;
		}

		if (! $date_to && $date_from && $date_from < $site_midnight) {
			return null;
		}

		$schedule = $this->format_schedule($date_from, $date_to);
		$is_platform_event = in_array($organizer_slug, $platform_organizers, true);
		$pinned_key = $event_slug ? strtolower($organizer_slug . '/' . $event_slug) : '';
		$is_pinned = $pinned_key && isset($pinned_events[ $pinned_key ]);

		$needs_description = ! empty($query['show_description']) || ! empty($query['feature_schema']) || ! empty($query['feature_modal']) || ! empty($query['feature_calendar']);
		$needs_image = ! empty($query['show_image']) || ! empty($query['feature_schema']) || ! empty($query['feature_modal']);
		$manual_has_description = ! empty($manual_override['description']);
		$manual_has_image = ! empty($manual_override['image']);
		$needs_settings = $event_slug && (($needs_description && ! $manual_has_description) || ($needs_image && ! $manual_has_image));
		$needs_ticket_stats = $event_slug && ! empty($query['show_ticket_button']) && (! empty($query['show_ticket_price']) || ! empty($query['feature_badges']));
		$settings = array();

		if ($needs_settings) {
			$settings = $this->get_event_settings(
				$base_url,
				$api_token,
				$organizer_slug,
				$event_slug,
				$cache_ttl
			);

			if (is_wp_error($settings)) {
				$settings = array();
			}
		}

		$ticket_stats = array(
			'label' => __('Tickets', 'pretix-eventlister'),
			'lowest_price' => null,
			'currency' => '',
			'is_free' => false,
		);

		if ($needs_ticket_stats) {
			$ticket_stats = $this->get_ticket_stats(
				$base_url,
				$api_token,
				$organizer_slug,
				$event_slug,
				$cache_ttl
			);
		}

		$platform_notice_text = '';
		if ($is_platform_event) {
			$platform_notice_text = isset($platform_notice_map[ strtolower($organizer_slug) ]) && '' !== $platform_notice_map[ strtolower($organizer_slug) ]
				? $platform_notice_map[ strtolower($organizer_slug) ]
				: $platform_notice;
		}

		$days_until = $this->calculate_days_until($date_from);

		$badges = array();
		$is_multi_day = $date_from && $date_to && wp_date('Ymd', $date_to) !== wp_date('Ymd', $date_from);
		$is_online = $this->is_likely_online_event($event);

		if (! empty($query['feature_badges'])) {
			if (! empty($ticket_stats['is_free'])) {
				$badges[] = array('key' => 'free', 'label' => __('Kostenlos', 'pretix-eventlister'));
			}

			if ($is_online) {
				$badges[] = array('key' => 'online', 'label' => __('Online', 'pretix-eventlister'));
			}

			if ($is_multi_day) {
				$badges[] = array('key' => 'multi', 'label' => __('Mehrtägig', 'pretix-eventlister'));
			}

			if (is_int($days_until) && $days_until >= 0 && $days_until <= 7) {
				$badges[] = array('key' => 'soon', 'label' => 0 === $days_until ? __('Heute', 'pretix-eventlister') : (1 === $days_until ? __('Morgen', 'pretix-eventlister') : __('Demnächst', 'pretix-eventlister')));
			}

			if ($is_pinned) {
				$badges[] = array('key' => 'featured', 'label' => __('Featured', 'pretix-eventlister'));
			}
		}

		$needs_availability = $event_slug && (! empty($query['feature_badges_availability']) || ! empty($query['show_available_tickets']));
		$availability_summary = array(
			'available_tickets' => null,
			'is_sold_out' => false,
			'badge' => null,
			'products' => array(),
		);

		if ($needs_availability) {
			$availability_summary = $this->get_event_availability_summary(
				$base_url,
				$api_token,
				$organizer_slug,
				$event_slug,
				$cache_ttl,
				max(1, absint($low_ticket_threshold))
			);
		}

		if (! empty($query['feature_badges_availability']) && ! empty($availability_summary['badge'])) {
			$badges[] = $availability_summary['badge'];
		}

		$normalized = array(
			'name' => $this->resolve_event_name($event),
			'slug' => $event_slug,
			'organizer_slug' => $organizer_slug,
			'organizer_name' => $organizer_name,
			'location' => (! empty($query['show_location']) && ! empty($event['location'])) ? $this->resolve_location_text($event['location']) : '',
			'url' => $this->resolve_public_url($event, $base_url, $organizer_slug),
			'description' => $needs_description ? $this->resolve_event_description($event, $settings) : '',
			'image' => $needs_image ? $this->extract_image_url($event, $settings, $base_url) : '',
			'button_label' => ! empty($query['show_ticket_button'])
				? (! empty($query['show_ticket_price']) ? (string) $ticket_stats['label'] : __('Tickets', 'pretix-eventlister'))
				: '',
			'lowest_price' => isset($ticket_stats['lowest_price']) ? $ticket_stats['lowest_price'] : null,
			'lowest_price_currency' => isset($ticket_stats['currency']) ? $ticket_stats['currency'] : '',
			'is_free' => ! empty($ticket_stats['is_free']),
			'available_tickets' => isset($availability_summary['available_tickets']) ? $availability_summary['available_tickets'] : null,
			'sold_out' => ! empty($availability_summary['is_sold_out']),
			'product_availability' => ! empty($availability_summary['products']) && is_array($availability_summary['products']) ? $availability_summary['products'] : array(),
			'date_from' => $date_from,
			'date_to' => $date_to,
			'sort_timestamp' => $date_from ? $date_from : ($date_to ? $date_to : PHP_INT_MAX),
			'day_label' => $schedule['day_label'],
			'month_label' => $schedule['month_label'],
			'date_label' => $schedule['date_label'],
			'time_label' => ! empty($query['show_time']) ? $schedule['time_label'] : '',
			'countdown_label' => ! empty($query['show_countdown']) ? $this->build_countdown_label($date_from) : '',
			'days_until' => $days_until,
			'is_multi_day' => $is_multi_day,
			'is_online' => $is_online,
			'badges' => $badges,
			'is_pinned' => $is_pinned,
			'is_platform_event' => $is_platform_event,
			'platform_notice' => ($is_platform_event && ! empty($query['show_platform_notice'])) ? $platform_notice_text : '',
			'event_id' => isset($event['id']) ? absint($event['id']) : 0,
			'is_public' => isset($event['is_public']) ? (bool) $event['is_public'] : null,
			'is_live' => isset($event['live']) ? (bool) $event['live'] : true,
			'timezone' => ! empty($event['timezone']) ? sanitize_text_field((string) $event['timezone']) : '',
		);

		if (! empty($manual_override)) {
			if (! empty($manual_override['name'])) {
				$normalized['name'] = $manual_override['name'];
			}

			if (array_key_exists('description', $manual_override) && '' !== $manual_override['description']) {
				$normalized['description'] = $this->render_event_description_html($manual_override['description']);
			}

			if (! empty($manual_override['image'])) {
				$normalized['image'] = $manual_override['image'];
			}

			if (! empty($manual_override['location'])) {
				$normalized['location'] = $manual_override['location'];
			}

			if (! empty($manual_override['url'])) {
				$normalized['url'] = $manual_override['url'];
			}

			$normalized['is_manual_override'] = true;
		}

		return $normalized;
	}

	private function get_event_settings($base_url, $api_token, $organizer_slug, $event_slug, $cache_ttl) {
		$cache_key = self::CACHE_PREFIX . 'settings_' . md5($base_url . '|' . $organizer_slug . '|' . $event_slug);
		$cached = get_transient($cache_key);
		if (false !== $cached) {
			return $cached;
		}

		$settings = $this->get_api_object(
			$this->build_api_url(
				$base_url,
				sprintf(
					'api/v1/organizers/%1$s/events/%2$s/settings/',
					rawurlencode($organizer_slug),
					rawurlencode($event_slug)
				)
			),
			$api_token
		);

		if (is_wp_error($settings)) {
			return $settings;
		}

		set_transient($cache_key, $settings, MINUTE_IN_SECONDS * max(1, $cache_ttl));

		return is_array($settings) ? $settings : array();
	}

	private function get_event_items($base_url, $api_token, $organizer_slug, $event_slug, $cache_ttl) {
		$cache_key = self::CACHE_PREFIX . 'items_' . md5($base_url . '|' . $organizer_slug . '|' . $event_slug);
		$cached = get_transient($cache_key);
		if (false !== $cached) {
			return $cached;
		}

		$items = $this->get_paginated_results(
			$this->build_api_url(
				$base_url,
				sprintf(
					'api/v1/organizers/%1$s/events/%2$s/items/?active=true',
					rawurlencode($organizer_slug),
					rawurlencode($event_slug)
				)
			),
			$api_token
		);

		if (is_wp_error($items)) {
			return $items;
		}

		set_transient($cache_key, $items, MINUTE_IN_SECONDS * max(1, $cache_ttl));

		return is_array($items) ? $items : array();
	}

	private function get_event_quotas($base_url, $api_token, $organizer_slug, $event_slug, $cache_ttl) {
		$cache_key = self::CACHE_PREFIX . 'quotas_' . md5($base_url . '|' . $organizer_slug . '|' . $event_slug);
		$cached = get_transient($cache_key);
		if (false !== $cached) {
			return $cached;
		}

		$quotas = $this->get_paginated_results(
			$this->build_api_url(
				$base_url,
				sprintf(
					'api/v1/organizers/%1$s/events/%2$s/quotas/?with_availability=true',
					rawurlencode($organizer_slug),
					rawurlencode($event_slug)
				)
			),
			$api_token
		);

		if (is_wp_error($quotas)) {
			return $quotas;
		}

		set_transient($cache_key, $quotas, MINUTE_IN_SECONDS * max(1, $cache_ttl));
		return is_array($quotas) ? $quotas : array();
	}

	private function get_event_availability_summary($base_url, $api_token, $organizer_slug, $event_slug, $cache_ttl, $low_ticket_threshold = 10) {
		$items = $this->get_event_items($base_url, $api_token, $organizer_slug, $event_slug, $cache_ttl);
		$quotas = $this->get_event_quotas($base_url, $api_token, $organizer_slug, $event_slug, $cache_ttl);
		if (is_wp_error($items) || is_wp_error($quotas) || empty($quotas) || ! is_array($quotas)) {
			return array(
				'available_tickets' => null,
				'is_sold_out' => false,
				'badge' => null,
				'products' => array(),
			);
		}

		$item_names = array();
		$item_availability = array();
		if (is_array($items)) {
			foreach ($items as $item) {
				if (! is_array($item)) {
					continue;
				}

				if (! $this->is_sellable_item($item)) {
					continue;
				}

				$item_id = isset($item['id']) ? absint($item['id']) : 0;
				if ($item_id <= 0) {
					continue;
				}

				$item_names[ $item_id ] = $this->resolve_item_name($item);
				$item_availability[ $item_id ] = null;
			}
		}

		foreach ($quotas as $quota) {
			if (! is_array($quota)) {
				continue;
			}

			$quota_item_ids = array();
			if (! empty($quota['items']) && is_array($quota['items'])) {
				foreach ($quota['items'] as $item_id) {
					$item_id = absint($item_id);
					if ($item_id > 0) {
						$quota_item_ids[] = $item_id;
					}
				}
			}

			if (empty($quota_item_ids)) {
				continue;
			}

			$quota_available = $this->extract_quota_available_tickets($quota);
			$quota_is_sold_out = $this->is_quota_sold_out($quota, $quota_available);

			foreach ($quota_item_ids as $item_id) {
				if (! array_key_exists($item_id, $item_availability)) {
					continue;
				}

				if ($quota_is_sold_out) {
					$item_availability[ $item_id ] = 0;
					continue;
				}

				if (null === $quota_available) {
					continue;
				}

				$current = $item_availability[ $item_id ];
				if (null === $current) {
					$item_availability[ $item_id ] = $quota_available;
				} else {
					$item_availability[ $item_id ] = min($current, $quota_available);
				}
			}
		}

		if (empty($item_availability)) {
			return array(
				'available_tickets' => null,
				'is_sold_out' => false,
				'badge' => null,
				'products' => array(),
			);
		}

		$product_availability = array();
		$total_available = 0;
		$has_known_values = false;

		foreach ($item_availability as $item_id => $available_tickets) {
			if (null !== $available_tickets && $available_tickets < 0) {
				continue;
			}

			if (null !== $available_tickets) {
				$has_known_values = true;
				$total_available += (int) $available_tickets;
			}

			$product_availability[] = array(
				'item_id' => (int) $item_id,
				'name' => isset($item_names[ $item_id ]) ? $item_names[ $item_id ] : sprintf(__('Produkt %d', 'pretix-eventlister'), (int) $item_id),
				'available_tickets' => null !== $available_tickets ? (int) $available_tickets : null,
				'sold_out' => null !== $available_tickets ? (0 === (int) $available_tickets) : false,
			);
		}

		if (! $has_known_values) {
			return array(
				'available_tickets' => null,
				'is_sold_out' => false,
				'badge' => null,
				'products' => $product_availability,
			);
		}

		if (0 === $total_available) {
			return array(
				'available_tickets' => 0,
				'is_sold_out' => true,
				'badge' => array('key' => 'soldout', 'label' => __('Ausverkauft', 'pretix-eventlister')),
				'products' => $product_availability,
			);
		}

		$badge = null;
		if ($total_available <= max(1, absint($low_ticket_threshold))) {
			$badge = array('key' => 'low', 'label' => __('Wenige Tickets', 'pretix-eventlister'));
		}

		return array(
			'available_tickets' => $total_available,
			'is_sold_out' => false,
			'badge' => $badge,
			'products' => $product_availability,
		);
	}

	private function is_sellable_item($item) {
		if (! is_array($item)) {
			return false;
		}

		if (isset($item['active']) && ! $item['active']) {
			return false;
		}

		if (isset($item['admission']) && ! $item['admission']) {
			return false;
		}

		return true;
	}

	private function resolve_item_name($item) {
		if (! is_array($item)) {
			return '';
		}

		if (! empty($item['name']) && is_array($item['name'])) {
			return $this->resolve_text_value($item['name']);
		}

		if (! empty($item['name']) && is_string($item['name'])) {
			return sanitize_text_field($item['name']);
		}

		return ! empty($item['id']) ? sprintf(__('Produkt %d', 'pretix-eventlister'), absint($item['id'])) : __('Produkt', 'pretix-eventlister');
	}

	private function extract_quota_available_tickets($quota) {
		if (! is_array($quota)) {
			return null;
		}

		$availability = isset($quota['availability']) ? $quota['availability'] : null;
		if (is_array($availability)) {
			foreach (array('available_number', 'available', 'available_count', 'available_total') as $key) {
				if (isset($availability[ $key ]) && is_numeric($availability[ $key ])) {
					return max(0, (int) $availability[ $key ]);
				}
			}
		}

		foreach (array('available_number', 'available', 'available_count') as $key) {
			if (isset($quota[ $key ]) && is_numeric($quota[ $key ])) {
				return max(0, (int) $quota[ $key ]);
			}
		}

		return null;
	}

	private function is_quota_sold_out($quota, $quota_available = null) {
		if (! is_array($quota)) {
			return false;
		}

		if (null !== $quota_available && 0 === (int) $quota_available) {
			return true;
		}

		$availability = isset($quota['availability']) ? $quota['availability'] : null;
		if (is_string($availability)) {
			return in_array(strtolower($availability), array('sold_out', 'soldout', 'unavailable', 'none'), true);
		}

		if (is_array($availability)) {
			foreach (array('is_sold_out', 'sold_out') as $key) {
				if (array_key_exists($key, $availability)) {
					return (bool) $availability[ $key ];
				}
			}
			if (array_key_exists('available', $availability) && is_bool($availability['available'])) {
				return ! $availability['available'];
			}
		}

		return false;
	}

	private function get_api_object($url, $api_token) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Token ' . $api_token,
					'Accept' => 'application/json',
				),
			)
		);

		if (is_wp_error($response)) {
			return new WP_Error(
				'pretix_eventlister_request_failed',
				__('Die pretix-Instanz konnte nicht erreicht werden.', 'pretix-eventlister')
			);
		}

		$status_code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (200 !== $status_code || ! is_array($body)) {
			return new WP_Error(
				'pretix_eventlister_invalid_response',
				__('Die Antwort der pretix-API war ungueltig.', 'pretix-eventlister')
			);
		}

		return $body;
	}

	private function build_collection_meta($query, $organizer_slugs, $organizer_index, $events) {
		$organizer_labels = array();

		foreach ($organizer_slugs as $organizer_slug) {
			$organizer_labels[] = isset($organizer_index[ $organizer_slug ]['name']) ? $organizer_index[ $organizer_slug ]['name'] : $this->beautify_slug($organizer_slug);
		}

		$selection_label = $this->build_selection_label($query['scope'], $organizer_labels);
		$title = 'all' === $query['scope']
			? __('Alle kommenden Events der pretix-Instanz', 'pretix-eventlister')
			: sprintf(
				/* translators: %s: organizer label */
				__('Events von %s', 'pretix-eventlister'),
				$selection_label
			);

		$has_platform_notes = false;
		foreach ($events as $event) {
			if (! empty($event['platform_notice'])) {
				$has_platform_notes = true;
				break;
			}
		}

		$summary_items = array(
			array(
				'label' => __('Auswahl', 'pretix-eventlister'),
				'value' => $selection_label,
			),
			array(
				'label' => __('Veranstalter', 'pretix-eventlister'),
				'value' => number_format_i18n(count($organizer_slugs)),
			),
			array(
				'label' => __('Events', 'pretix-eventlister'),
				'value' => number_format_i18n(count($events)),
			),
		);

		if ($has_platform_notes) {
			$summary_items[] = array(
				'label' => __('Hinweis', 'pretix-eventlister'),
				'value' => __('Plattform-Hinweis bei Partner-Events', 'pretix-eventlister'),
			);
		}

		return array(
			'eyebrow' => __('pretix Eventfeed', 'pretix-eventlister'),
			'title' => $title,
			'intro' => __('Kommende Veranstaltungen werden automatisch aus pretix geladen, sortiert und in einer klaren, modernen Kartenansicht dargestellt.', 'pretix-eventlister'),
			'summary_items' => $summary_items,
		);
	}

	private function build_selection_label($scope, $organizer_labels) {
		if ('all' === $scope) {
			return __('Alle Veranstalter', 'pretix-eventlister');
		}

		$count = count($organizer_labels);
		if (0 === $count) {
			return __('Keine Veranstalter', 'pretix-eventlister');
		}

		if (1 === $count) {
			return $organizer_labels[0];
		}

		if (2 === $count) {
			return $organizer_labels[0] . ' + ' . $organizer_labels[1];
		}

		return sprintf(
			/* translators: 1: first organizer label, 2: second organizer label, 3: number of additional organizers */
			__('%1$s, %2$s + %3$d weitere', 'pretix-eventlister'),
			$organizer_labels[0],
			$organizer_labels[1],
			$count - 2
		);
	}

	private function format_schedule($date_from, $date_to) {
		if (! $date_from) {
			return array(
				'day_label' => '--',
				'month_label' => 'TBA',
				'date_label' => __('Termin folgt', 'pretix-eventlister'),
				'time_label' => '',
			);
		}

		$date_label = wp_date(get_option('date_format'), $date_from);
		if ($date_to && wp_date('Ymd', $date_to) !== wp_date('Ymd', $date_from)) {
			$date_label = sprintf(
				/* translators: 1: start date, 2: end date */
				__('%1$s bis %2$s', 'pretix-eventlister'),
				wp_date(get_option('date_format'), $date_from),
				wp_date(get_option('date_format'), $date_to)
			);
		}

		$time_label = '';
		if ($this->has_time_component($date_from)) {
			$time_label = sprintf(
				/* translators: %s: start time */
				__('ab %s Uhr', 'pretix-eventlister'),
				wp_date('H:i', $date_from)
			);
		}

		if ($date_to && wp_date('Ymd', $date_to) === wp_date('Ymd', $date_from) && $this->has_time_component($date_from) && $this->has_time_component($date_to)) {
			$time_label = sprintf(
				/* translators: 1: start time, 2: end time */
				__('%1$s bis %2$s Uhr', 'pretix-eventlister'),
				wp_date('H:i', $date_from),
				wp_date('H:i', $date_to)
			);
		}

		return array(
			'day_label' => wp_date('d', $date_from),
			'month_label' => strtoupper(wp_date('M', $date_from)),
			'date_label' => $date_label,
			'time_label' => $time_label,
		);
	}

	private function has_time_component($timestamp) {
		return '00:00' !== wp_date('H:i', $timestamp);
	}

	private function get_site_midnight_timestamp() {
		$midnight = new DateTimeImmutable('now', wp_timezone());
		$midnight = $midnight->setTime(0, 0);

		return $midnight->getTimestamp();
	}

	private function build_countdown_label($date_from) {
		if (! $date_from) {
			return '';
		}

		$today = new DateTimeImmutable('now', wp_timezone());
		$today = $today->setTime(0, 0);
		$event_day = (new DateTimeImmutable('@' . $date_from))->setTimezone(wp_timezone())->setTime(0, 0);
		$days_until = (int) $today->diff($event_day)->format('%r%a');

		if ($days_until < 0) {
			return '';
		}

		if (0 === $days_until) {
			return __('Beginnt heute', 'pretix-eventlister');
		}

		if (1 === $days_until) {
			return __('Beginnt morgen', 'pretix-eventlister');
		}

		return sprintf(
			/* translators: %d: days until the event starts */
			__('Beginnt in %d Tagen', 'pretix-eventlister'),
			$days_until
		);
	}

	private function calculate_days_until($date_from) {
		if (! $date_from) {
			return null;
		}

		$today = new DateTimeImmutable('now', wp_timezone());
		$today = $today->setTime(0, 0);
		$event_day = (new DateTimeImmutable('@' . $date_from))->setTimezone(wp_timezone())->setTime(0, 0);
		return (int) $today->diff($event_day)->format('%r%a');
	}

	private function is_likely_online_event($event) {
		$location = ! empty($event['location']) ? strtolower($this->resolve_location_text($event['location'])) : '';
		if ($location && preg_match('/\\b(online|livestream|stream|webinar|zoom|teams|virtuell|virtual)\\b/i', $location)) {
			return true;
		}

		if (! empty($event['is_virtual']) || ! empty($event['online'])) {
			return true;
		}

		if (! empty($event['meta_data']) && is_array($event['meta_data'])) {
			foreach (array('online', 'is_online', 'virtual', 'is_virtual', 'stream') as $key) {
				if (! empty($event['meta_data'][ $key ])) {
					return true;
				}
			}
		}

		return false;
	}

	private function extract_image_url($event, $settings = array(), $base_url = '') {
		if (! empty($settings['logo_image']) && is_string($settings['logo_image'])) {
			return $this->normalize_media_url($settings['logo_image'], $base_url);
		}

		if (! empty($event['picture']) && is_string($event['picture'])) {
			return $this->normalize_media_url($event['picture'], $base_url);
		}

		if (! empty($event['image']) && is_string($event['image']) && filter_var($event['image'], FILTER_VALIDATE_URL)) {
			return esc_url_raw($event['image']);
		}

		if (! empty($event['image_url']) && is_string($event['image_url']) && filter_var($event['image_url'], FILTER_VALIDATE_URL)) {
			return esc_url_raw($event['image_url']);
		}

		if (! empty($event['media']) && is_array($event['media'])) {
			foreach ($event['media'] as $item) {
				if (! empty($item['url'])) {
					return esc_url_raw($item['url']);
				}
			}
		}

		if (! empty($event['images']) && is_array($event['images'])) {
			foreach ($event['images'] as $item) {
				if (! empty($item['url'])) {
					return $this->normalize_media_url($item['url'], $base_url);
				}

				if (! empty($item['image'])) {
					return $this->normalize_media_url($item['image'], $base_url);
				}
			}
		}

		if (! empty($event['meta_data']) && is_array($event['meta_data'])) {
			foreach (array('image', 'image_url', 'featured_image', 'header_image', 'thumbnail') as $key) {
				if (! empty($event['meta_data'][ $key ]) && is_string($event['meta_data'][ $key ]) && filter_var($event['meta_data'][ $key ], FILTER_VALIDATE_URL)) {
					return $this->normalize_media_url($event['meta_data'][ $key ], $base_url);
				}
			}
		}

		if (! empty($event['item_meta_properties']) && is_array($event['item_meta_properties'])) {
			foreach ($event['item_meta_properties'] as $property) {
				if (! empty($property['value']) && filter_var($property['value'], FILTER_VALIDATE_URL)) {
					return $this->normalize_media_url($property['value'], $base_url);
				}
			}
		}

		return '';
	}

	private function get_event_manual_overrides($organizer_slug, $event_slug) {
		$key = strtolower(sanitize_title($organizer_slug) . '/' . sanitize_title($event_slug));
		if (isset($this->manual_override_cache[ $key ])) {
			return $this->manual_override_cache[ $key ];
		}

		if (! $organizer_slug || ! $event_slug || ! post_type_exists(self::CPT)) {
			$this->manual_override_cache[ $key ] = array();
			return array();
		}

		$post_ids = get_posts(
			array(
				'post_type' => self::CPT,
				'post_status' => array('publish', 'draft', 'pending', 'private'),
				'posts_per_page' => 1,
				'fields' => 'ids',
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key' => '_pretix_org',
						'value' => (string) $organizer_slug,
					),
					array(
						'key' => '_pretix_slug',
						'value' => (string) $event_slug,
					),
					array(
						'key' => '_pretix_manual_override_enabled',
						'value' => '1',
					),
				),
			)
		);

		if (empty($post_ids)) {
			$this->manual_override_cache[ $key ] = array();
			return array();
		}

		$post_id = (int) $post_ids[0];
		$post = get_post($post_id);
		if (! $post instanceof WP_Post) {
			$this->manual_override_cache[ $key ] = array();
			return array();
		}

		$manual_image = (string) get_post_meta($post_id, '_pretix_manual_image', true);
		if ('' === $manual_image && has_post_thumbnail($post_id)) {
			$thumbnail = wp_get_attachment_image_url(get_post_thumbnail_id($post_id), 'full');
			if (is_string($thumbnail) && '' !== $thumbnail) {
				$manual_image = $thumbnail;
			}
		}

		$overrides = array(
			'name' => (string) get_post_meta($post_id, '_pretix_manual_name', true),
			'description' => (string) get_post_meta($post_id, '_pretix_manual_description', true),
			'image' => $manual_image,
			'location' => (string) get_post_meta($post_id, '_pretix_manual_location', true),
			'url' => (string) get_post_meta($post_id, '_pretix_manual_url', true),
			'date_from' => absint(get_post_meta($post_id, '_pretix_manual_date_from', true)),
			'date_to' => absint(get_post_meta($post_id, '_pretix_manual_date_to', true)),
			'hidden' => ! empty(get_post_meta($post_id, '_pretix_manual_hidden', true)),
		);

		if ('' === $overrides['name'] && ! empty($post->post_title)) {
			$overrides['name'] = sanitize_text_field($post->post_title);
		}

		if ('' === $overrides['description'] && ! empty($post->post_content)) {
			$overrides['description'] = (string) $post->post_content;
		}

		$this->manual_override_cache[ $key ] = $overrides;
		return $overrides;
	}

	private function resolve_location_text($value) {
		if (is_string($value)) {
			return sanitize_text_field($value);
		}

		if (is_array($value)) {
			foreach ($this->get_locale_preferences() as $locale) {
				if (isset($value[ $locale ]) && is_scalar($value[ $locale ])) {
					return sanitize_text_field((string) $value[ $locale ]);
				}
			}

			foreach ($value as $entry) {
				if (is_scalar($entry) && '' !== trim((string) $entry)) {
					return sanitize_text_field((string) $entry);
				}
			}
		}

		if (is_scalar($value)) {
			return sanitize_text_field((string) $value);
		}

		return '';
	}

	private function resolve_public_url($event, $base_url, $organizer_slug) {
		if (! empty($event['public_url'])) {
			return esc_url_raw($event['public_url']);
		}

		if (! empty($event['slug'])) {
			return esc_url_raw(trailingslashit($base_url) . rawurlencode($organizer_slug) . '/' . rawurlencode($event['slug']) . '/');
		}

		return '';
	}

	private function resolve_event_name($event) {
		if (! empty($event['name']) && is_array($event['name'])) {
			return $this->resolve_text_value($event['name']);
		}

		if (! empty($event['name']) && is_string($event['name'])) {
			return sanitize_text_field($event['name']);
		}

		return ! empty($event['slug']) ? sanitize_text_field($event['slug']) : __('Unbenanntes Event', 'pretix-eventlister');
	}

	private function resolve_event_description($event, $settings = array()) {
		$frontpage_text = $this->resolve_rich_text_value(isset($settings['frontpage_text']) ? $settings['frontpage_text'] : '');
		if ('' !== $frontpage_text) {
			return $this->render_event_description_html($frontpage_text);
		}

		$candidates = array(
			isset($settings['description']) ? $settings['description'] : '',
			isset($event['meta_data']['description']) ? $event['meta_data']['description'] : '',
			isset($event['meta_data']['event_description']) ? $event['meta_data']['event_description'] : '',
			isset($event['description']) ? $event['description'] : '',
			isset($event['body_text']) ? $event['body_text'] : '',
			isset($event['text']) ? $event['text'] : '',
			isset($event['content']) ? $event['content'] : '',
		);

		foreach ($candidates as $candidate) {
			$resolved = $this->resolve_rich_text_value($candidate);
			if ('' !== $resolved) {
				return $this->render_event_description_html($resolved);
			}
		}

		return '';
	}

	private function build_ticket_button_label($base_url, $api_token, $organizer_slug, $event_slug, $cache_ttl) {
		$stats = $this->get_ticket_stats($base_url, $api_token, $organizer_slug, $event_slug, $cache_ttl);
		return ! empty($stats['label']) ? (string) $stats['label'] : __('Tickets', 'pretix-eventlister');
	}

	private function get_ticket_stats($base_url, $api_token, $organizer_slug, $event_slug, $cache_ttl) {
		$base = array(
			'label' => __('Tickets', 'pretix-eventlister'),
			'lowest_price' => null,
			'currency' => '',
			'is_free' => false,
		);

		if (! $event_slug) {
			return $base;
		}

		$items = $this->get_event_items($base_url, $api_token, $organizer_slug, $event_slug, $cache_ttl);
		if (is_wp_error($items) || empty($items) || ! is_array($items)) {
			return $base;
		}

		$lowest_price = null;
		$currency = '';

		foreach ($items as $item) {
			if (isset($item['active']) && ! $item['active']) {
				continue;
			}

			if (isset($item['admission']) && ! $item['admission']) {
				continue;
			}

			$price = isset($item['default_price']) ? $this->normalize_money_value($item['default_price']) : null;
			if (null === $price) {
				continue;
			}

			if (null === $lowest_price || $price < $lowest_price) {
				$lowest_price = $price;
				$currency = ! empty($item['default_price_currency']) ? sanitize_text_field($item['default_price_currency']) : '';
			}
		}

		if (null === $lowest_price) {
			return $base;
		}

		if ((float) $lowest_price <= 0.0) {
			$base['label'] = __('Tickets kostenlos', 'pretix-eventlister');
			$base['lowest_price'] = 0.0;
			$base['currency'] = $currency;
			$base['is_free'] = true;
			return $base;
		}

		$formatted_price = $this->format_money($lowest_price);
		$label = '';

		if ('EUR' === strtoupper($currency) || '' === $currency) {
			$label = sprintf(
				/* translators: %s: lowest ticket price */
				__('Tickets ab %s EUR', 'pretix-eventlister'),
				$formatted_price
			);
		} else {
			$label = sprintf(
				/* translators: 1: lowest ticket price, 2: currency */
				__('Tickets ab %1$s %2$s', 'pretix-eventlister'),
				$formatted_price,
				strtoupper($currency)
			);
		}

		$base['label'] = $label;
		$base['lowest_price'] = (float) $lowest_price;
		$base['currency'] = $currency;
		$base['is_free'] = false;

		return $base;
	}

	private function normalize_media_url($value, $base_url) {
		$value = trim((string) $value);
		if ('' === $value) {
			return '';
		}

		if (preg_match('#^https?://#i', $value)) {
			return esc_url_raw($value);
		}

		return esc_url_raw(untrailingslashit($base_url) . '/' . ltrim($value, '/'));
	}

	private function normalize_money_value($value) {
		if (! is_scalar($value) || '' === trim((string) $value)) {
			return null;
		}

		return (float) str_replace(',', '.', (string) $value);
	}

	private function format_money($amount) {
		$formatted = number_format_i18n((float) $amount, 2);
		$formatted = preg_replace('/([,.]00)$/', '', $formatted);
		return $formatted ? $formatted : '0';
	}

	private function get_locale_preferences() {
		$preferences = array();
		$locale = function_exists('determine_locale') ? determine_locale() : get_locale();

		if (is_string($locale) && '' !== $locale) {
			$preferences[] = strtolower(str_replace('-', '_', $locale));

			if (false !== strpos($locale, '_')) {
				$preferences[] = strtolower(strtok($locale, '_'));
			}

			if (false !== strpos($locale, '-')) {
				$preferences[] = strtolower(strtok($locale, '-'));
			}
		}

		$preferences[] = 'de';
		$preferences[] = 'en';

		return array_values(array_unique(array_filter($preferences)));
	}

	private function resolve_rich_text_value($value) {
		if (is_string($value)) {
			return trim($value);
		}

		if (is_array($value)) {
			foreach ($this->get_locale_preferences() as $locale) {
				if (! empty($value[ $locale ]) && is_string($value[ $locale ])) {
					return trim($value[ $locale ]);
				}
			}

			foreach ($value as $item) {
				if (is_string($item) && '' !== trim($item)) {
					return trim($item);
				}
			}
		}

		return '';
	}

	private function render_event_description_html($content) {
		$content = trim((string) $content);
		if ('' === $content) {
			return '';
		}

		if ($this->contains_html_markup($content)) {
			return wp_kses_post(wpautop($content));
		}

		return wp_kses_post($this->convert_markdown_to_html($content));
	}

	private function contains_html_markup($content) {
		return (bool) preg_match('/<[^>]+>/', (string) $content);
	}

	private function convert_markdown_to_html($markdown) {
		$markdown = str_replace(array("\r\n", "\r"), "\n", trim((string) $markdown));
		if ('' === $markdown) {
			return '';
		}

		$blocks = preg_split("/\n{2,}/", $markdown);
		$html_blocks = array();

		foreach ($blocks as $block) {
			$block = trim($block);
			if ('' === $block) {
				continue;
			}

			$lines = preg_split("/\n/", $block);
			$first_line = isset($lines[0]) ? trim($lines[0]) : '';

			if (preg_match('/^(#{1,6})\s+(.+)$/', $first_line, $matches)) {
				$level = min(6, strlen($matches[1]));
				$html_blocks[] = sprintf('<h%d>%s</h%d>', $level, $this->convert_markdown_inline($matches[2]), $level);
				continue;
			}

			if ($this->is_markdown_list($lines)) {
				$html_blocks[] = $this->convert_markdown_list($lines);
				continue;
			}

			if ($this->is_markdown_quote($lines)) {
				$quote_lines = array();
				foreach ($lines as $line) {
					$quote_lines[] = preg_replace('/^\s*>\s?/', '', $line);
				}

				$html_blocks[] = '<blockquote><p>' . implode('<br>', array_map(array($this, 'convert_markdown_inline'), $quote_lines)) . '</p></blockquote>';
				continue;
			}

			$html_blocks[] = '<p>' . implode('<br>', array_map(array($this, 'convert_markdown_inline'), $lines)) . '</p>';
		}

		return implode("\n", $html_blocks);
	}

	private function is_markdown_list($lines) {
		if (empty($lines)) {
			return false;
		}

		foreach ($lines as $line) {
			if (! preg_match('/^\s*(?:[-*+]\s+|\d+\.\s+)/', $line)) {
				return false;
			}
		}

		return true;
	}

	private function convert_markdown_list($lines) {
		$is_ordered = preg_match('/^\s*\d+\.\s+/', isset($lines[0]) ? $lines[0] : '');
		$tag = $is_ordered ? 'ol' : 'ul';
		$items = array();

		foreach ($lines as $line) {
			$item = preg_replace('/^\s*(?:[-*+]\s+|\d+\.\s+)/', '', trim($line));
			$items[] = '<li>' . $this->convert_markdown_inline($item) . '</li>';
		}

		return '<' . $tag . '>' . implode('', $items) . '</' . $tag . '>';
	}

	private function is_markdown_quote($lines) {
		if (empty($lines)) {
			return false;
		}

		foreach ($lines as $line) {
			if (! preg_match('/^\s*>/', $line)) {
				return false;
			}
		}

		return true;
	}

	private function convert_markdown_inline($text) {
		$text = esc_html((string) $text);
		$code_tokens = array();

		$text = preg_replace_callback(
			'/`([^`]+)`/',
			function ($matches) use (&$code_tokens) {
				$token = '%%PRETIX_CODE_' . count($code_tokens) . '%%';
				$code_tokens[ $token ] = '<code>' . $matches[1] . '</code>';
				return $token;
			},
			$text
		);

		$patterns = array(
			'/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/' => '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>',
			'/\*\*(.+?)\*\*/s' => '<strong>$1</strong>',
			'/__(.+?)__/s' => '<strong>$1</strong>',
			'/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s' => '<em>$1</em>',
			'/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/s' => '<em>$1</em>',
		);

		foreach ($patterns as $pattern => $replacement) {
			$text = preg_replace($pattern, $replacement, $text);
		}

		if (! empty($code_tokens)) {
			$text = strtr($text, $code_tokens);
		}

		return $text;
	}

	private function resolve_text_value($value) {
		if (is_string($value)) {
			return sanitize_text_field($value);
		}

		if (is_array($value)) {
			foreach (array('de', 'en') as $locale) {
				if (! empty($value[ $locale ])) {
					return sanitize_text_field($value[ $locale ]);
				}
			}

			$fallback = reset($value);
			if (is_string($fallback)) {
				return sanitize_text_field($fallback);
			}
		}

		return '';
	}

	private function parse_slug_list($value) {
		if (is_array($value)) {
			$value = implode("\n", $value);
		}

		if (! is_string($value) || '' === trim($value)) {
			return array();
		}

		$parts = preg_split('/[\s,;]+/', $value);
		$slugs = array();

		foreach ($parts as $part) {
			$slug = sanitize_title($part);
			if ($slug) {
				$slugs[] = $slug;
			}
		}

		return array_values(array_unique($slugs));
	}

	private function parse_notice_map($value) {
		if (! is_string($value) || '' === trim($value)) {
			return array();
		}

		$lines = preg_split("/\r\n|\r|\n/", (string) $value);
		$map = array();

		foreach ($lines as $line) {
			$line = trim($line);
			if ('' === $line || false === strpos($line, '|')) {
				continue;
			}

			list($slug, $text) = array_map('trim', explode('|', $line, 2));
			$slug = sanitize_title($slug);
			$text = sanitize_text_field($text);

			if ($slug && '' !== $text) {
				$map[ strtolower($slug) ] = $text;
			}
		}

		return $map;
	}

	private function parse_pinned_events($value) {
		if (! is_string($value) || '' === trim($value)) {
			return array();
		}

		$lines = preg_split("/\r\n|\r|\n/", (string) $value);
		$map = array();

		foreach ($lines as $line) {
			$line = strtolower(trim($line));
			if ('' === $line || false === strpos($line, '/')) {
				continue;
			}

			list($org, $slug) = array_map('trim', explode('/', $line, 2));
			$org = sanitize_title($org);
			$slug = sanitize_title($slug);
			if ($org && $slug) {
				$map[ strtolower($org . '/' . $slug) ] = true;
			}
		}

		return $map;
	}

	private function get_latest_github_release() {
		$cached = get_site_transient(self::GITHUB_RELEASE_CACHE_KEY);
		if (false !== $cached) {
			return $cached;
		}

		$response = wp_remote_get(
			self::GITHUB_RELEASES_API,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept' => 'application/vnd.github+json',
					'User-Agent' => 'Pretix-Eventlister/' . self::VERSION . '; ' . home_url('/'),
				),
			)
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (200 !== $status_code || ! is_array($body) || empty($body['tag_name'])) {
			return new WP_Error(
				'pretix_eventlister_github_release_invalid',
				__('Das neueste GitHub-Release konnte nicht geladen werden.', 'pretix-eventlister')
			);
		}

		$release = array(
			'version' => ltrim(sanitize_text_field($body['tag_name']), 'vV'),
			'tag_name' => sanitize_text_field($body['tag_name']),
			'package' => $this->find_release_package($body),
			'body' => ! empty($body['body']) ? sanitize_textarea_field($body['body']) : '',
			'html_url' => ! empty($body['html_url']) ? esc_url_raw($body['html_url']) : self::GITHUB_REPOSITORY_URL . '/releases',
			'published_at' => ! empty($body['published_at']) ? sanitize_text_field($body['published_at']) : '',
		);

		set_site_transient(self::GITHUB_RELEASE_CACHE_KEY, $release, self::GITHUB_RELEASE_CACHE_TTL);

		return $release;
	}

	private function find_release_package($release) {
		if (empty($release['assets']) || ! is_array($release['assets'])) {
			return '';
		}

		$first_zip = '';

		foreach ($release['assets'] as $asset) {
			if (empty($asset['browser_download_url']) || empty($asset['name'])) {
				continue;
			}

			$name = (string) $asset['name'];
			if ('.zip' !== strtolower(substr($name, -4))) {
				continue;
			}

			if (! $first_zip) {
				$first_zip = esc_url_raw($asset['browser_download_url']);
			}

			if (0 === strpos($name, self::PLUGIN_SLUG . '-')) {
				return esc_url_raw($asset['browser_download_url']);
			}
		}

		return $first_zip;
	}

	private function format_release_notes_for_modal($notes) {
		if (! $notes) {
			return wp_kses_post(
				'<p>' . __('Details zur aktuellen Version findest du im CHANGELOG und in den GitHub-Releases des Plugins.', 'pretix-eventlister') . '</p>'
			);
		}

		$notes = trim((string) $notes);
		// Some release notes arrive with escaped newline sequences like "\n".
		$notes = preg_replace('/\\\\r\\\\n|\\\\n|\\\\r/', "\n", $notes);
		$notes = str_replace(array('\\"', "\\'"), array('"', "'"), $notes);

		if ($this->contains_html_markup($notes)) {
			return wp_kses_post(wpautop($notes));
		}

		return wp_kses_post($this->convert_markdown_to_html($notes));
	}

	private function sanitize_slug_list($value) {
		return implode(",\n", $this->parse_slug_list($value));
	}

	private function sanitize_event_key_list($value) {
		$map = $this->parse_pinned_events($value);
		if (empty($map)) {
			return '';
		}

		return implode("\n", array_keys($map));
	}

	private function to_bool($value) {
		return ! in_array(strtolower((string) $value), array('0', 'false', 'no', 'off'), true);
	}

	private function resolve_toggle_from_shortcode($value, $default) {
		if (! is_scalar($value)) {
			return (bool) $default;
		}

		$raw = strtolower(trim((string) $value));
		if ('' === $raw || 'default' === $raw) {
			return (bool) $default;
		}

		return $this->to_bool($raw);
	}

	private function resolve_int_from_shortcode($value, $default) {
		if (! is_scalar($value)) {
			return max(1, absint($default));
		}

		$raw = trim((string) $value);
		if ('' === $raw || 'default' === strtolower($raw)) {
			return max(1, absint($default));
		}

		return max(1, absint($raw));
	}

	private function resolve_style_from_shortcode($value, $default) {
		$allowed = array('grid', 'list', 'compact');

		if (is_scalar($value)) {
			$raw = sanitize_key((string) $value);
			if ('default' !== $raw && '' !== $raw && in_array($raw, $allowed, true)) {
				return $raw;
			}
		}

		$default = sanitize_key((string) $default);
		return in_array($default, $allowed, true) ? $default : 'grid';
	}

	private function build_api_url($base_url, $path) {
		if (preg_match('#^https?://#i', $path)) {
			return $path;
		}

		return trailingslashit($base_url) . ltrim($path, '/');
	}

	private function resolve_next_url($current_url, $next_path) {
		if (preg_match('#^https?://#i', $next_path)) {
			$current_parts = wp_parse_url($current_url);
			$next_parts = wp_parse_url($next_path);
			if (empty($current_parts['host']) || empty($next_parts['host'])) {
				return '';
			}

			if (strtolower((string) $current_parts['host']) !== strtolower((string) $next_parts['host'])) {
				return '';
			}

			return $next_path;
		}

		$url_parts = wp_parse_url($current_url);
		if (empty($url_parts['scheme']) || empty($url_parts['host'])) {
			return $next_path;
		}

		$origin = $url_parts['scheme'] . '://' . $url_parts['host'];
		if (! empty($url_parts['port'])) {
			$origin .= ':' . $url_parts['port'];
		}

		if (0 === strpos($next_path, '?')) {
			$current_path = isset($url_parts['path']) ? $url_parts['path'] : '/';

			return $origin . $current_path . $next_path;
		}

		if (0 !== strpos($next_path, '/')) {
			$current_directory = isset($url_parts['path']) ? trailingslashit(dirname($url_parts['path'])) : '/';

			return $origin . $current_directory . ltrim($next_path, '/');
		}

		return $origin . $next_path;
	}

	private function beautify_slug($slug) {
		return ucwords(str_replace(array('-', '_'), ' ', $slug));
	}

	private function get_default_platform_notice() {
		return __('Die Plattform stellt fuer dieses Event ausschliesslich die Ticket- und Infrastrukturfunktionen bereit. Veranstalter und Inhalte liegen beim jeweils genannten Anbieter.', 'pretix-eventlister');
	}

	private function get_plugin_icon_url() {
		return plugin_dir_url(__FILE__) . 'assets/icon.svg';
	}

	private function get_plugin_icons() {
		$icon_url = $this->get_plugin_icon_url();

		return array(
			'default' => $icon_url,
			'1x' => $icon_url,
			'2x' => $icon_url,
		);
	}

	private function get_changelog_url() {
		return trailingslashit(self::GITHUB_REPOSITORY_URL) . 'releases';
	}

	private function is_uploaded_our_plugin_zip() {
		if (empty($_FILES['pluginzip']['name'])) {
			return false;
		}

		$uploaded_name = sanitize_file_name(wp_unslash($_FILES['pluginzip']['name']));
		return $this->is_our_package_reference($uploaded_name);
	}

	private function is_legacy_plugin_basename($plugin_basename) {
		if (! is_string($plugin_basename)) {
			return false;
		}

		$plugin_basename = trim($plugin_basename);
		if ('' === $plugin_basename || self::PLUGIN_SLUG . '/' . self::PLUGIN_SLUG . '.php' === $plugin_basename) {
			return false;
		}

		return (bool) preg_match(
			'#^' . preg_quote(self::PLUGIN_SLUG, '#') . '(?:-[^/]+)/(?:' . preg_quote(self::PLUGIN_SLUG, '#') . '/)?' . preg_quote(self::PLUGIN_SLUG . '.php', '#') . '$#i',
			$plugin_basename
		);
	}

	private function is_our_package_reference($value) {
		if (! is_string($value) || '' === trim($value)) {
			return false;
		}

		$value = strtolower(trim($value));
		if (false !== strpos($value, self::GITHUB_REPOSITORY)) {
			return true;
		}

		return (bool) preg_match(
			'#(?:^|/)' . preg_quote(self::PLUGIN_SLUG, '#') . '(?:[-_.]?\d+(?:\.\d+)*)?\.zip(?:$|\?)#i',
			$value
		);
	}

	private function directory_contains_plugin_file($directory) {
		return is_file(trailingslashit($directory) . self::PLUGIN_SLUG . '.php');
	}

	private function get_plugin_basename() {
		return plugin_basename(__FILE__);
	}

	private function get_composer_blocks() {
		return array(
			'schedule',
			'body',
			'platform_note',
			'footer',
		);
	}

	private function get_default_composer_layout() {
		return $this->get_composer_blocks();
	}

	private function get_default_composer_styles() {
		$defaults = array(
			'visible' => 1,
			'padding' => '',
			'margin' => '',
			'text_color' => '',
			'background_color' => '',
			'border_color' => '',
			'border_width' => '',
			'border_radius' => '',
			'font_family' => '',
			'font_size' => '',
			'font_weight' => '',
			'line_height' => '',
			'letter_spacing' => '',
			'text_align' => '',
			'shadow' => '',
		);

		$styles = array();
		foreach ($this->get_composer_blocks() as $block) {
			$styles[ $block ] = $defaults;
		}

		return $styles;
	}

	private function get_composer_layout($raw_json) {
		$decoded = json_decode(is_string($raw_json) ? $raw_json : '', true);
		$allowed = $this->get_composer_blocks();
		$allowed_lookup = array_fill_keys($allowed, true);
		$layout = array();

		if (is_array($decoded)) {
			foreach ($decoded as $item) {
				$key = sanitize_key((string) $item);
				if (! isset($allowed_lookup[ $key ])) {
					continue;
				}
				if (! in_array($key, $layout, true)) {
					$layout[] = $key;
				}
			}
		}

		if (empty($layout)) {
			return $this->get_default_composer_layout();
		}

		return $layout;
	}

	private function get_composer_styles($raw_json) {
		$decoded = json_decode(is_string($raw_json) ? $raw_json : '', true);
		$defaults = $this->get_default_composer_styles();
		$styles = array();

		foreach ($defaults as $block => $default_style) {
			$source = array();
			if (is_array($decoded) && isset($decoded[ $block ]) && is_array($decoded[ $block ])) {
				$source = $decoded[ $block ];
			}
			$styles[ $block ] = $this->sanitize_composer_style_group($source, $default_style);
		}

		return $styles;
	}

	private function sanitize_composer_layout_json($raw_json) {
		$layout = $this->get_composer_layout($raw_json);
		return wp_json_encode($layout);
	}

	private function sanitize_composer_styles_json($raw_json) {
		$styles = $this->get_composer_styles($raw_json);
		return wp_json_encode($styles);
	}

	private function sanitize_composer_style_group($source, $defaults) {
		$style = is_array($source) ? $source : array();

		$visible = isset($style['visible']) ? $style['visible'] : $defaults['visible'];
		$visible = (is_numeric($visible) && (int) $visible < 1) ? 0 : (! empty($visible) ? 1 : 0);

		$font_weight = isset($style['font_weight']) ? trim((string) $style['font_weight']) : '';
		if ('' !== $font_weight && ! preg_match('/^(normal|bold|[1-9]00)$/', $font_weight)) {
			$font_weight = '';
		}

		$text_align = isset($style['text_align']) ? sanitize_key((string) $style['text_align']) : '';
		if (! in_array($text_align, array('', 'left', 'center', 'right', 'justify'), true)) {
			$text_align = '';
		}

		return array(
			'visible' => $visible,
			'padding' => $this->sanitize_css_spacing(isset($style['padding']) ? $style['padding'] : ''),
			'margin' => $this->sanitize_css_spacing(isset($style['margin']) ? $style['margin'] : ''),
			'text_color' => $this->sanitize_css_color(isset($style['text_color']) ? $style['text_color'] : ''),
			'background_color' => $this->sanitize_css_color(isset($style['background_color']) ? $style['background_color'] : ''),
			'border_color' => $this->sanitize_css_color(isset($style['border_color']) ? $style['border_color'] : ''),
			'border_width' => $this->sanitize_css_length(isset($style['border_width']) ? $style['border_width'] : ''),
			'border_radius' => $this->sanitize_css_length(isset($style['border_radius']) ? $style['border_radius'] : ''),
			'font_family' => $this->sanitize_css_font_family(isset($style['font_family']) ? $style['font_family'] : ''),
			'font_size' => $this->sanitize_css_length(isset($style['font_size']) ? $style['font_size'] : ''),
			'font_weight' => $font_weight,
			'line_height' => $this->sanitize_css_length(isset($style['line_height']) ? $style['line_height'] : '', true),
			'letter_spacing' => $this->sanitize_css_length(isset($style['letter_spacing']) ? $style['letter_spacing'] : '', true),
			'text_align' => $text_align,
			'shadow' => $this->sanitize_css_shadow(isset($style['shadow']) ? $style['shadow'] : ''),
		);
	}

	private function sanitize_css_length($value, $allow_unitless = false) {
		$value = trim((string) $value);
		if ('' === $value) {
			return '';
		}

		if ('0' === $value || 'auto' === strtolower($value)) {
			return strtolower($value);
		}

		$pattern = $allow_unitless
			? '/^-?\d+(?:\.\d+)?(?:px|em|rem|%|vh|vw)?$/'
			: '/^-?\d+(?:\.\d+)?(?:px|em|rem|%|vh|vw)$/';

		if (! preg_match($pattern, $value)) {
			return '';
		}

		return $value;
	}

	private function sanitize_css_spacing($value) {
		$value = trim((string) $value);
		if ('' === $value) {
			return '';
		}

		$parts = preg_split('/\s+/', $value);
		if (! is_array($parts) || empty($parts) || count($parts) > 4) {
			return '';
		}

		$sanitized = array();
		foreach ($parts as $part) {
			$clean = $this->sanitize_css_length($part, true);
			if ('' === $clean) {
				return '';
			}
			$sanitized[] = $clean;
		}

		return implode(' ', $sanitized);
	}

	private function sanitize_css_color($value) {
		$value = trim((string) $value);
		if ('' === $value) {
			return '';
		}

		$hex = sanitize_hex_color($value);
		if ($hex) {
			return $hex;
		}

		if (preg_match('/^(rgba?|hsla?)\([0-9\.\,\s%]+\)$/i', $value)) {
			return $value;
		}

		return '';
	}

	private function sanitize_css_font_family($value) {
		$value = trim((string) $value);
		if ('' === $value) {
			return '';
		}

		if (! preg_match('/^[a-zA-Z0-9,\-\s"\']+$/', $value)) {
			return '';
		}

		return $value;
	}

	private function sanitize_css_shadow($value) {
		$value = trim((string) $value);
		if ('' === $value) {
			return '';
		}

		if (strlen($value) > 120) {
			return '';
		}

		if (! preg_match('/^[a-zA-Z0-9#(),.\-\s%]+$/', $value)) {
			return '';
		}

		return $value;
	}

	private function get_options() {
		$stored = get_option(self::OPTION_KEY, array());
		$legacy_organizer = ! empty($stored['organizer']) ? $this->sanitize_slug_list($stored['organizer']) : '';

		$defaults = array(
			'base_url' => '',
			'default_organizers' => $legacy_organizer,
			'api_token' => '',
			'cache_ttl' => 15,
			'platform_organizers' => '',
			'platform_notice' => $this->get_default_platform_notice(),
			'platform_notice_map' => '',
			'disabled_organizers' => '',
			'hidden_events' => '',
			'default_style' => 'grid',
			'default_show_description' => 1,
			'default_show_organizer' => 1,
			'show_image' => 1,
			'show_time' => 1,
			'show_location' => 1,
			'show_countdown' => 1,
			'show_platform_notice' => 1,
			'show_organizer_slug' => 0,
			'show_ticket_button' => 1,
			'show_ticket_price' => 1,
			'feature_filters' => 0,
			'feature_load_more' => 0,
			'page_size' => 9,
			'feature_badges' => 0,
			'feature_badges_availability' => 0,
			'show_available_tickets' => 0,
			'low_ticket_threshold' => 10,
			'feature_calendar' => 0,
			'feature_schema' => 0,
			'feature_modal' => 0,
			'feature_tilt' => 1,
			'pinned_events' => '',
			'accent_color' => '',
			'enable_cpt_sync' => 0,
			'cpt_sync_scope' => 'selected',
			'cpt_sync_organizers' => '',
			'cpt_sync_interval' => 12,
			'composer_enabled' => 0,
			'composer_layout_json' => wp_json_encode($this->get_default_composer_layout()),
			'composer_styles_json' => wp_json_encode($this->get_default_composer_styles()),
		);

		$options = wp_parse_args($stored, $defaults);

		if (empty($options['default_organizers']) && $legacy_organizer) {
			$options['default_organizers'] = $legacy_organizer;
		}

		if (empty($options['platform_notice'])) {
			$options['platform_notice'] = $this->get_default_platform_notice();
		}

		return $options;
	}

	private function flush_cache() {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . self::CACHE_PREFIX . '%',
				'_transient_timeout_' . self::CACHE_PREFIX . '%'
			)
		);
	}
}

new Pretix_Eventlister();
