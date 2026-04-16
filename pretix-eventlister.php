<?php
/**
 * Plugin Name: Pretix Eventlister
 * Description: Listet Events einer pretix-Instanz modern und responsiv in WordPress auf.
 * Version: 1.2.4
 * Author: Bright Color
 * Author URI: https://github.com/brightcolor/pretix-eventlister
 * Text Domain: pretix-eventlister
 * Update URI: https://github.com/brightcolor/pretix-eventlister
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Pretix_Eventlister {
	const VERSION = '1.2.4';
	const PLUGIN_SLUG = 'pretix-eventlister';
	const OPTION_KEY = 'pretix_eventlister_options';
	const CACHE_PREFIX = 'pretix_eventlister_';
	const GITHUB_REPOSITORY = 'brightcolor/pretix-eventlister';
	const GITHUB_REPOSITORY_URL = 'https://github.com/brightcolor/pretix-eventlister';
	const GITHUB_RELEASES_API = 'https://api.github.com/repos/brightcolor/pretix-eventlister/releases/latest';
	const GITHUB_RELEASE_CACHE_KEY = 'pretix_eventlister_github_release';
	const GITHUB_RELEASE_CACHE_TTL = 21600;
	const MINIMUM_PHP = '7.4';

	public function __construct() {
		add_action('admin_menu', array($this, 'register_settings_page'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_plugin_admin_assets'));
		add_action('wp_enqueue_scripts', array($this, 'register_assets'));
		add_action('upgrader_process_complete', array($this, 'handle_upgrader_process_complete'), 10, 2);
		add_filter('upgrader_source_selection', array($this, 'normalize_package_source'), 10, 4);
		add_filter('pre_set_site_transient_update_plugins', array($this, 'inject_update_information'));
		add_filter('plugins_api', array($this, 'inject_plugin_information'), 20, 3);
		add_filter('plugin_row_meta', array($this, 'add_plugin_row_meta'), 10, 4);
		add_shortcode('pretix_events', array($this, 'render_shortcode'));
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
				echo '<p>' . esc_html__('Fuer bestimmte Veranstalter kann ein Hinweis eingeblendet werden, dass HSP-Events nur die Plattform bereitstellt.', 'pretix-eventlister') . '</p>';
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
				'label' => __('Organizer mit HSP-Hinweis', 'pretix-eventlister'),
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
	}

	public function sanitize_options($options) {
		$current = $this->get_options();
		$platform_notice = isset($options['platform_notice']) ? sanitize_textarea_field($options['platform_notice']) : '';

		$sanitized = array(
			'base_url' => isset($options['base_url']) ? untrailingslashit(esc_url_raw($options['base_url'])) : '',
			'default_organizers' => isset($options['default_organizers']) ? $this->sanitize_slug_list($options['default_organizers']) : (isset($options['organizer']) ? $this->sanitize_slug_list($options['organizer']) : ''),
			'api_token' => isset($options['api_token']) ? sanitize_text_field($options['api_token']) : '',
			'cache_ttl' => isset($options['cache_ttl']) ? max(1, absint($options['cache_ttl'])) : 15,
			'platform_organizers' => isset($options['platform_organizers']) ? $this->sanitize_slug_list($options['platform_organizers']) : '',
			'platform_notice' => $platform_notice ? $platform_notice : $this->get_default_platform_notice(),
		);

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

		if ('textarea' === $type) {
			printf(
				'<textarea class="large-text" rows="%1$d" name="%2$s[%3$s]">%4$s</textarea>',
				isset($args['rows']) ? absint($args['rows']) : 3,
				esc_attr(self::OPTION_KEY),
				esc_attr($key),
				esc_textarea($value)
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

	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Pretix Eventlister', 'pretix-eventlister'); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields('pretix_eventlister');
				do_settings_sections('pretix-eventlister');
				submit_button();
				?>
			</form>

			<h2><?php echo esc_html__('Shortcode-Beispiele', 'pretix-eventlister'); ?></h2>
			<ul>
				<li><code>[pretix_events]</code></li>
				<li><code>[pretix_events scope="all" limit="all"]</code></li>
				<li><code>[pretix_events organizer="hsp-events"]</code></li>
				<li><code>[pretix_events organizers="hsp-events,partner-a,partner-b"]</code></li>
				<li><code>[pretix_events scope="all" style="list" show_description="no"]</code></li>
			</ul>
		</div>
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
				esc_html__('Aktuell sind keine passenden Events verfuegbar.', 'pretix-eventlister')
			);
		}

		$events = $collection['events'];
		$collection_meta = $collection['meta'];
		$show_description = $query['show_description'];
		$show_organizer = $query['show_organizer'];
		$layout_class = 'list' === $query['style'] ? 'pretix-eventlister--list' : 'pretix-eventlister--grid';

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
			'author' => '<a href="' . esc_url(self::GITHUB_REPOSITORY_URL) . '">Bright Color</a>',
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
					'<p>' . __('Modernes WordPress-Plugin fuer pretix-Events mit Multi-Organizer-Support, responsiver Kartenansicht und optionalen HSP-Plattform-Hinweisen.', 'pretix-eventlister') . '</p>' .
					'<p>' . __('Aktualisierungen werden direkt aus den GitHub-Releases dieses Plugins geladen.', 'pretix-eventlister') . '</p>'
				),
				'installation' => wp_kses_post(
					'<ol>' .
					'<li>' . __('Plugin in WordPress installieren oder aktualisieren.', 'pretix-eventlister') . '</li>' .
					'<li>' . __('Unter Einstellungen > Pretix Eventlister die pretix-Zugangsdaten hinterlegen.', 'pretix-eventlister') . '</li>' .
					'<li>' . __('Neue Versionen werden automatisch erkannt, sobald ein GitHub-Release mit ZIP-Datei veroeffentlicht wird.', 'pretix-eventlister') . '</li>' .
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

	public function normalize_package_source($source, $remote_source, $upgrader, $hook_extra) {
		$plugin_source = $this->locate_plugin_source($source);

		if (! $plugin_source || ! $this->is_target_plugin_upgrade($hook_extra, $plugin_source)) {
			return $source;
		}

		if (self::PLUGIN_SLUG === basename(untrailingslashit($plugin_source))) {
			return $plugin_source;
		}

		$remote_root = untrailingslashit((string) $remote_source);
		$plugin_parent = untrailingslashit(dirname($plugin_source));

		if ($plugin_parent !== $remote_root) {
			return $plugin_source;
		}

		$normalized_source = trailingslashit($remote_source) . self::PLUGIN_SLUG;

		if (wp_normalize_path($plugin_source) === wp_normalize_path($normalized_source)) {
			return $plugin_source;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		global $wp_filesystem;

		if (! WP_Filesystem()) {
			return new WP_Error(
				'pretix_eventlister_upgrade_filesystem',
				__('Das Dateisystem konnte fuer die Plugin-Aktualisierung nicht initialisiert werden.', 'pretix-eventlister')
			);
		}

		if ($wp_filesystem->exists($normalized_source)) {
			$wp_filesystem->delete($normalized_source, true);
		}

		if (! $wp_filesystem->move($plugin_source, $normalized_source, true)) {
			return new WP_Error(
				'pretix_eventlister_upgrade_source',
				__('Der Plugin-Ordner konnte waehrend der Aktualisierung nicht in den erwarteten Zielnamen verschoben werden.', 'pretix-eventlister')
			);
		}

		return $normalized_source;
	}

	private function normalize_shortcode_atts($atts, $options) {
		$atts = shortcode_atts(
			array(
				'limit' => '9',
				'scope' => 'selected',
				'organizer' => '',
				'organizers' => '',
				'style' => 'grid',
				'show_description' => 'yes',
				'show_organizer' => 'yes',
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

		return array(
			'scope' => $scope,
			'organizers' => array_values(array_unique($selected_organizers)),
			'limit' => $limit,
			'style' => 'list' === sanitize_key($atts['style']) ? 'list' : 'grid',
			'show_description' => $this->to_bool($atts['show_description']),
			'show_organizer' => $this->to_bool($atts['show_organizer']),
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
				)
			)
		);
		$cached = get_transient($cache_key);
		if (false !== $cached) {
			return $cached;
		}

		$platform_organizers = $this->parse_slug_list($options['platform_organizers']);
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
				$normalized_event = $this->normalize_event(
					$event,
					$organizer_slug,
					$organizer_name,
					$platform_organizers,
					$options['platform_notice'],
					$base_url
				);

				if ($normalized_event) {
					$events[] = $normalized_event;
				}
			}
		}

		usort(
			$events,
			function ($left, $right) {
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

	private function normalize_event($event, $organizer_slug, $organizer_name, $platform_organizers, $platform_notice, $base_url) {
		if (isset($event['live']) && ! $event['live']) {
			return null;
		}

		$date_from = ! empty($event['date_from']) ? strtotime($event['date_from']) : null;
		$date_to = ! empty($event['date_to']) ? strtotime($event['date_to']) : null;
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

		return array(
			'name' => $this->resolve_event_name($event),
			'slug' => ! empty($event['slug']) ? sanitize_title($event['slug']) : '',
			'organizer_slug' => $organizer_slug,
			'organizer_name' => $organizer_name,
			'location' => ! empty($event['location']) ? wp_strip_all_tags($event['location']) : '',
			'url' => $this->resolve_public_url($event, $base_url, $organizer_slug),
			'description' => $this->resolve_event_description($event),
			'image' => $this->extract_image_url($event),
			'date_from' => $date_from,
			'date_to' => $date_to,
			'sort_timestamp' => $date_from ? $date_from : ($date_to ? $date_to : PHP_INT_MAX),
			'day_label' => $schedule['day_label'],
			'month_label' => $schedule['month_label'],
			'date_label' => $schedule['date_label'],
			'time_label' => $schedule['time_label'],
			'is_platform_event' => $is_platform_event,
			'platform_notice' => $is_platform_event ? $platform_notice : '',
		);
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
				'value' => __('HSP-Plattform bei Partner-Events', 'pretix-eventlister'),
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

	private function extract_image_url($event) {
		if (! empty($event['media']) && is_array($event['media'])) {
			foreach ($event['media'] as $item) {
				if (! empty($item['url'])) {
					return esc_url_raw($item['url']);
				}
			}
		}

		if (! empty($event['images']) && is_array($event['images'])) {
			foreach ($event['images'] as $item) {
				if (! empty($item['image'])) {
					return esc_url_raw($item['image']);
				}
			}
		}

		if (! empty($event['item_meta_properties']) && is_array($event['item_meta_properties'])) {
			foreach ($event['item_meta_properties'] as $property) {
				if (! empty($property['value']) && filter_var($property['value'], FILTER_VALIDATE_URL)) {
					return esc_url_raw($property['value']);
				}
			}
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

	private function resolve_event_description($event) {
		if (! empty($event['meta_data']['description']) && is_string($event['meta_data']['description'])) {
			return wp_kses_post($event['meta_data']['description']);
		}

		if (! empty($event['description']) && is_string($event['description'])) {
			return wp_kses_post($event['description']);
		}

		return '';
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

		return wp_kses_post(wpautop(esc_html($notes)));
	}

	private function sanitize_slug_list($value) {
		return implode(",\n", $this->parse_slug_list($value));
	}

	private function to_bool($value) {
		return ! in_array(strtolower((string) $value), array('0', 'false', 'no', 'off'), true);
	}

	private function build_api_url($base_url, $path) {
		if (preg_match('#^https?://#i', $path)) {
			return $path;
		}

		return trailingslashit($base_url) . ltrim($path, '/');
	}

	private function resolve_next_url($current_url, $next_path) {
		if (preg_match('#^https?://#i', $next_path)) {
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
		return __('HSP-Events stellt fuer dieses Event ausschliesslich die Ticket- und Plattforminfrastruktur bereit. Veranstalter und Inhalte liegen beim jeweils genannten Anbieter.', 'pretix-eventlister');
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

	private function is_target_plugin_upgrade($hook_extra, $plugin_source) {
		if (! empty($hook_extra['plugin']) && $this->matches_plugin_basename($hook_extra['plugin'])) {
			return true;
		}

		if (! empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
			foreach ($hook_extra['plugins'] as $plugin) {
				if ($this->matches_plugin_basename($plugin)) {
					return true;
				}
			}
		}

		return $this->source_contains_main_file($plugin_source);
	}

	private function matches_plugin_basename($plugin) {
		$plugin = ltrim(wp_normalize_path((string) $plugin), '/');

		return self::PLUGIN_SLUG . '/' . self::PLUGIN_SLUG . '.php' === $plugin
			|| self::PLUGIN_SLUG . '.php' === $plugin
			|| '/' . self::PLUGIN_SLUG . '.php' === substr($plugin, -strlen('/' . self::PLUGIN_SLUG . '.php'));
	}

	private function locate_plugin_source($source) {
		$source = untrailingslashit((string) $source);

		if ($this->source_contains_main_file($source)) {
			return $source;
		}

		$candidates = glob($source . '/*', GLOB_ONLYDIR);
		if (! is_array($candidates)) {
			return '';
		}

		foreach ($candidates as $candidate) {
			if ($this->source_contains_main_file($candidate)) {
				return untrailingslashit($candidate);
			}
		}

		return '';
	}

	private function source_contains_main_file($path) {
		return file_exists(trailingslashit($path) . self::PLUGIN_SLUG . '.php');
	}

	private function get_plugin_basename() {
		return plugin_basename(__FILE__);
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
