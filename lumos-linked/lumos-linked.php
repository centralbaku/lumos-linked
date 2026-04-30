<?php
/**
 * Plugin Name: Lumos Linker
 * Description: Scan posts and pages and add internal links based on admin-defined keywords.
 * Version: 0.4.6
 * Author: Orkhan Hasanov
 * Update URI: https://github.com/centralbaku/lumos-linked
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
	exit;
}

class Lumos_Linked_GitHub_Updater {
	const GITHUB_REPO = 'centralbaku/lumos-linked';
	const RELEASE_CACHE_KEY = 'lumos_linked_latest_release';

	/**
	 * @var string
	 */
	private $plugin_file;

	/**
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * @var string
	 */
	private $plugin_version;

	public function __construct($plugin_file, $plugin_version) {
		$this->plugin_file    = $plugin_file;
		$this->plugin_slug    = plugin_basename($plugin_file);
		$this->plugin_version = $plugin_version;

		add_filter('pre_set_site_transient_update_plugins', array($this, 'inject_update_info'));
		add_filter('plugins_api', array($this, 'inject_plugin_info'), 20, 3);
		add_filter('plugin_action_links_' . $this->plugin_slug, array($this, 'add_check_updates_link'));
		add_action('admin_post_lumos_linked_check_updates', array($this, 'handle_manual_check_updates'));
		add_action('admin_notices', array($this, 'render_checked_notice'));
	}

	public function add_check_updates_link($links) {
		if (!current_user_can('update_plugins')) {
			return $links;
		}

		$url = wp_nonce_url(
			admin_url('admin-post.php?action=lumos_linked_check_updates'),
			'lumos_linked_check_updates'
		);

		$links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Check for updates', 'lumos-linked') . '</a>';
		return $links;
	}

	public function handle_manual_check_updates() {
		if (!current_user_can('update_plugins')) {
			wp_die(esc_html__('Unauthorized request.', 'lumos-linked'));
		}

		check_admin_referer('lumos_linked_check_updates');
		delete_transient(self::RELEASE_CACHE_KEY);
		delete_site_transient('update_plugins');
		wp_clean_plugins_cache(true);
		wp_update_plugins();

		$redirect = add_query_arg(
			array(
				'lumos_linked_checked' => '1',
			),
			admin_url('plugins.php')
		);
		wp_safe_redirect($redirect);
		exit;
	}

	public function render_checked_notice() {
		if (!is_admin() || !current_user_can('update_plugins')) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || 'plugins' !== $screen->id) {
			return;
		}

		if (!isset($_GET['lumos_linked_checked']) || '1' !== (string) $_GET['lumos_linked_checked']) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Lumos Linker update check completed.', 'lumos-linked') . '</p></div>';
	}

	public function inject_update_info($transient) {
		if (empty($transient->checked) || !is_object($transient)) {
			return $transient;
		}

		if (!isset($transient->response) || !is_array($transient->response)) {
			$transient->response = array();
		}

		$release = $this->get_latest_release();
		if (empty($release)) {
			unset($transient->response[ $this->plugin_slug ]);
			return $transient;
		}

		$latest_version = isset($release['version']) ? $release['version'] : '';
		$package_url    = isset($release['package']) ? $release['package'] : '';
		if ('' === $latest_version || '' === $package_url) {
			unset($transient->response[ $this->plugin_slug ]);
			return $transient;
		}

		if (version_compare($this->plugin_version, $latest_version, '>=')) {
			unset($transient->response[ $this->plugin_slug ]);
			return $transient;
		}

		$update = (object) array(
			'slug'        => dirname($this->plugin_slug),
			'plugin'      => $this->plugin_slug,
			'new_version' => $latest_version,
			'url'         => $this->get_repository_url(),
			'package'     => $package_url,
		);

		$transient->response[ $this->plugin_slug ] = $update;
		return $transient;
	}

	public function inject_plugin_info($result, $action, $args) {
		if ('plugin_information' !== $action || empty($args->slug)) {
			return $result;
		}

		$expected_slug = dirname($this->plugin_slug);
		if ($args->slug !== $expected_slug) {
			return $result;
		}

		$release = $this->get_latest_release();
		if (empty($release)) {
			return $result;
		}

		return (object) array(
			'name'          => 'Lumos-linked',
			'slug'          => $expected_slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/centralbaku">Orkhan Hasanov</a>',
			'homepage'      => $this->get_repository_url(),
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => 'Automatically creates internal links in posts and pages using keyword rules from the admin panel.',
				'changelog'   => nl2br(esc_html(isset($release['body']) ? $release['body'] : 'No changelog provided.')),
			),
		);
	}

	private function get_latest_release() {
		$cache_key = self::RELEASE_CACHE_KEY;
		$cached    = get_transient($cache_key);
		if (is_array($cached) && !empty($cached['version']) && !empty($cached['package'])) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => $this->build_request_headers(),
			)
		);

		if (is_wp_error($response)) {
			return array();
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		if (200 !== (int) $code || '' === $body) {
			return array();
		}

		$data = json_decode($body, true);
		if (!is_array($data) || empty($data['tag_name'])) {
			return array();
		}

		$version = ltrim((string) $data['tag_name'], 'v');
		$package = $this->find_zip_asset_url($data);
		if ('' === $version || '' === $package) {
			return array();
		}

		$release = array(
			'version' => $version,
			'package' => $package,
			'body'    => isset($data['body']) ? (string) $data['body'] : '',
		);

		set_transient($cache_key, $release, 6 * HOUR_IN_SECONDS);
		return $release;
	}

	private function find_zip_asset_url($release_data) {
		if (!empty($release_data['assets']) && is_array($release_data['assets'])) {
			foreach ($release_data['assets'] as $asset) {
				if (empty($asset['browser_download_url'])) {
					continue;
				}

				$name = isset($asset['name']) ? (string) $asset['name'] : '';
				$url  = (string) $asset['browser_download_url'];
				if (substr($name, -4) === '.zip' || substr($url, -4) === '.zip') {
					return esc_url_raw($url);
				}
			}
		}

		return '';
	}

	private function build_request_headers() {
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'WordPress/Lumos-linked',
		);

		if (defined('LUMOS_LINKED_GITHUB_TOKEN') && LUMOS_LINKED_GITHUB_TOKEN) {
			$headers['Authorization'] = 'Bearer ' . LUMOS_LINKED_GITHUB_TOKEN;
		}

		return $headers;
	}

	private function get_repository_url() {
		return 'https://github.com/' . self::GITHUB_REPO;
	}
}

class AIL_Auto_Internal_Linker {
	const MENU_SLUG  = 'ail-internal-linker';
	const LINKS_SLUG = 'ail-links';
	const SETTINGS_SLUG = 'ail-settings';
	const MAPS_FILE  = 'mappings.json';
	const STATS_FILE = 'click-stats.json';
	const SCAN_FILE  = 'scan-summary.json';
	const SETTINGS_FILE = 'settings.json';
	const MAPS_OPTION = 'lumos_linked_mappings_backup';
	const STATS_OPTION = 'lumos_linked_stats_backup';
	const SCAN_OPTION = 'lumos_linked_scan_summary_backup';
	const SETTINGS_OPTION = 'lumos_linked_settings_backup';

	public function __construct() {
		add_action('admin_menu', array($this, 'register_admin_page'));
		add_action('admin_post_ail_add_mapping', array($this, 'handle_add_mapping'));
		add_action('admin_post_ail_delete_mapping', array($this, 'handle_delete_mapping'));
		add_action('admin_post_ail_update_mapping', array($this, 'handle_update_mapping'));
		add_action('admin_post_ail_scan_content', array($this, 'handle_scan_content'));
		add_action('admin_post_ail_migrate_legacy_links', array($this, 'handle_migrate_legacy_links'));
		add_action('admin_post_ail_save_settings', array($this, 'handle_save_settings'));
		add_action('save_post', array($this, 'auto_link_on_save'), 20, 3);
		add_action('template_redirect', array($this, 'handle_track_click'));
		add_action('wp_ajax_lumos_linked_track_click', array($this, 'handle_track_click_ajax'));
		add_action('wp_ajax_nopriv_lumos_linked_track_click', array($this, 'handle_track_click_ajax'));
		add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_autolinker'));
	}

	public function enqueue_frontend_autolinker() {
		if (is_admin()) {
			return;
		}

		$mappings = $this->get_mappings();
		if (empty($mappings)) {
			return;
		}

		$settings = $this->get_settings();
		$public_mappings = array();
		foreach ($mappings as $mapping) {
			if (empty($mapping['id']) || empty($mapping['keyword']) || empty($mapping['target_url'])) {
				continue;
			}
			$public_mappings[] = array(
				'id'             => (string) $mapping['id'],
				'keyword'        => (string) $mapping['keyword'],
				'target_url'     => (string) $mapping['target_url'],
				'case_sensitive' => !empty($mapping['case_sensitive']),
				'exclude_from'   => isset($mapping['exclude_from']) ? (array) $mapping['exclude_from'] : array(),
				'exclude_target_url_page' => !empty($mapping['exclude_target_url_page']),
			);
		}

		if (empty($public_mappings)) {
			return;
		}

		wp_enqueue_script(
			'lumos-linked-frontend-autolinker',
			plugins_url('assets/frontend-autolink.js', __FILE__),
			array(),
			'0.4.5',
			true
		);

		$hover_style = isset($settings['hover_style']) ? $settings['hover_style'] : 'underline';
		$hover_color = isset($settings['hover_color']) ? $settings['hover_color'] : '#2a7cc7';
		$link_color  = isset($settings['link_color']) ? $settings['link_color'] : '#2a7cc7';

		wp_localize_script(
			'lumos-linked-frontend-autolinker',
			'LumosLinkedData',
			array(
				'mappings'    => $public_mappings,
				'hover_style' => $hover_style,
				'ajax_url'    => admin_url('admin-ajax.php'),
			)
		);
		$css = '.lumos_link{color:' . esc_attr($link_color) . ' !important;}';
		$css .= '.lumos_linked_hover{position:relative;display:inline-block;transition:all .15s ease;}';
		$css .= '.lumos_linked_hover:hover{color:' . esc_attr($hover_color) . ' !important;';
		if ('underline' === $hover_style) {
			$css .= 'text-decoration:underline;';
		} elseif ('none' === $hover_style) {
			$css .= 'text-decoration:none;';
		} elseif ('bold' === $hover_style) {
			$css .= 'font-weight:700;';
		} elseif ('italic' === $hover_style) {
			$css .= 'font-style:italic;';
		}
		$css .= '}';
		// Elara-style line animation inspired by Codrops LineHoverStyles.
		$css .= '.lumos_linked_hover--elara{text-decoration:none !important;padding:0 .14em;}';
		$css .= '.lumos_linked_hover--elara:hover{text-decoration:none !important;}';
		$css .= '.lumos_linked_hover--elara > span{position:relative;display:inline-block;}';
		$css .= '.lumos_linked_hover--elara > span::before,.lumos_linked_hover--elara > span::after{content:"";position:absolute;left:0;bottom:-.07em;width:100%;height:2px;background:currentColor;transform:scaleX(0);transform-origin:left center;transition:transform .35s ease;}';
		$css .= '.lumos_linked_hover--elara > span::before{opacity:.35;transition-delay:.14s;}';
		$css .= '.lumos_linked_hover--elara > span::after{opacity:1;transition-delay:0s;}';
		$css .= '.lumos_linked_hover--elara:hover > span::before,.lumos_linked_hover--elara:hover > span::after{transform:scaleX(1);}';
		$css .= '.lumos_linked_hover--elara:hover > span::before{transition-delay:0s;}';
		$css .= '.lumos_linked_hover--elara:hover > span::after{transition-delay:.14s;}';
		wp_register_style('lumos-linked-inline-style', false, array(), '0.4.5');
		wp_enqueue_style('lumos-linked-inline-style');
		wp_add_inline_style('lumos-linked-inline-style', $css);
	}

	public function register_admin_page() {
		add_menu_page(
			__('Lumos Linker', 'lumos-linked'),
			__('Lumos Linker', 'lumos-linked'),
			'manage_options',
			self::MENU_SLUG,
			array($this, 'render_admin_page'),
			$this->menu_icon_data_uri(),
			58
		);
		add_submenu_page(
			self::MENU_SLUG,
			__('Dashboard', 'lumos-linked'),
			__('Dashboard', 'lumos-linked'),
			'manage_options',
			self::MENU_SLUG,
			array($this, 'render_admin_page')
		);
		add_submenu_page(
			self::MENU_SLUG,
			__('Links', 'lumos-linked'),
			__('Links', 'lumos-linked'),
			'manage_options',
			self::LINKS_SLUG,
			array($this, 'render_admin_page')
		);
		add_submenu_page(
			self::MENU_SLUG,
			__('Settings', 'lumos-linked'),
			__('Settings', 'lumos-linked'),
			'manage_options',
			self::SETTINGS_SLUG,
			array($this, 'render_admin_page')
		);
	}

	private function menu_icon_data_uri() {
		$icon_path = plugin_dir_path(__FILE__) . 'assets/icon.svg';
		$svg       = file_exists($icon_path) ? file_get_contents($icon_path) : '';
		if (!$svg) {
			$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><circle cx="10" cy="10" r="7" fill="#a7aaad"/></svg>';
		}

		return 'data:image/svg+xml;base64,' . base64_encode($svg);
	}

	public function render_admin_page() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$mappings = $this->get_mappings();
		$stats    = $this->get_stats();
		$scan_summary = $this->get_scan_summary();
		$settings = $this->get_settings();
		$report_page = isset($_GET['ail_report_page']) ? max(1, absint($_GET['ail_report_page'])) : 1;
		$pages_report = $this->get_pages_keywords_report($report_page, 10);
		$current_page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : self::MENU_SLUG;
		$is_dashboard = (self::MENU_SLUG === $current_page);
		$is_links     = (self::LINKS_SLUG === $current_page);
		$is_settings  = (self::SETTINGS_SLUG === $current_page);
		$total_clicks = 0;
		$top_keyword = '';
		$top_keyword_clicks = 0;
		if (isset($stats['by_mapping']) && is_array($stats['by_mapping'])) {
			$keyword_name_by_id = array();
			foreach ($mappings as $mapping) {
				if (!empty($mapping['id']) && !empty($mapping['keyword'])) {
					$keyword_name_by_id[ (string) $mapping['id'] ] = (string) $mapping['keyword'];
				}
			}
			foreach ($stats['by_mapping'] as $map_id => $mapping_stats) {
				$clicks = isset($mapping_stats['clicks']) ? (int) $mapping_stats['clicks'] : 0;
				$total_clicks += $clicks;
				if ($clicks > $top_keyword_clicks) {
					$top_keyword_clicks = $clicks;
					$top_keyword = isset($keyword_name_by_id[ (string) $map_id ]) ? $keyword_name_by_id[ (string) $map_id ] : '';
				}
			}
		}
		$total_keyword_links = isset($pages_report['total_keyword_links']) ? (int) $pages_report['total_keyword_links'] : 0;
		$avg_links_per_page = ($pages_report['total_rows'] > 0) ? round((float) $total_keyword_links / (float) $pages_report['total_rows'], 2) : 0;
		$scan_updated_at = isset($scan_summary['updated_at']) ? (int) $scan_summary['updated_at'] : 0;
		$notice   = isset($_GET['ail_notice']) ? sanitize_text_field(wp_unslash($_GET['ail_notice'])) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Lumos Linker', 'lumos-linked'); ?></h1>
			<?php if ($notice === 'added') : ?>
				<div class="notice notice-success"><p><?php esc_html_e('Keyword mapping added.', 'lumos-linked'); ?></p></div>
			<?php elseif ($notice === 'deleted') : ?>
				<div class="notice notice-success"><p><?php esc_html_e('Keyword mapping deleted.', 'lumos-linked'); ?></p></div>
			<?php elseif (strpos($notice, 'scanned') === 0) : ?>
				<?php
				$updated_count = 0;
				if (preg_match('/^scanned_(\d+)$/', $notice, $matches)) {
					$updated_count = (int) $matches[1];
				}
				?>
				<div class="notice notice-success"><p><?php echo esc_html(sprintf(__('Scan completed. Updated posts/pages: %d', 'lumos-linked'), $updated_count)); ?></p></div>
			<?php elseif (strpos($notice, 'migrated') === 0) : ?>
				<?php
				$migrated_count = 0;
				if (preg_match('/^migrated_(\d+)$/', $notice, $matches)) {
					$migrated_count = (int) $matches[1];
				}
				?>
				<div class="notice notice-success"><p><?php echo esc_html(sprintf(__('Legacy tracking links migrated. Updated posts/pages: %d', 'lumos-linked'), $migrated_count)); ?></p></div>
			<?php elseif ($notice === 'invalid') : ?>
				<div class="notice notice-error"><p><?php esc_html_e('Please provide a valid keyword and target URL.', 'lumos-linked'); ?></p></div>
			<?php elseif ($notice === 'settings_saved') : ?>
				<div class="notice notice-success"><p><?php esc_html_e('Appearance settings saved.', 'lumos-linked'); ?></p></div>
			<?php endif; ?>

			<?php if ($is_dashboard) : ?>
				<h2><?php esc_html_e('Dashboard', 'lumos-linked'); ?></h2>
				<div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; margin:12px 0 18px;">
					<div style="background:#fff; border:1px solid #dcdcde; padding:16px; border-radius:8px;">
						<div style="font-size:12px; color:#646970; margin-bottom:8px;"><?php esc_html_e('Total mappings', 'lumos-linked'); ?></div>
						<div style="font-size:30px; line-height:1.1; font-weight:700;"><?php echo esc_html((string) count($mappings)); ?></div>
					</div>
					<div style="background:#fff; border:1px solid #dcdcde; padding:16px; border-radius:8px;">
						<div style="font-size:12px; color:#646970; margin-bottom:8px;"><?php esc_html_e('Linked pages/posts', 'lumos-linked'); ?></div>
						<div style="font-size:30px; line-height:1.1; font-weight:700;"><?php echo esc_html((string) $pages_report['total_rows']); ?></div>
					</div>
					<div style="background:#fff; border:1px solid #dcdcde; padding:16px; border-radius:8px;">
						<div style="font-size:12px; color:#646970; margin-bottom:8px;"><?php esc_html_e('Total keyword links', 'lumos-linked'); ?></div>
						<div style="font-size:30px; line-height:1.1; font-weight:700;"><?php echo esc_html((string) $total_keyword_links); ?></div>
					</div>
					<div style="background:#fff; border:1px solid #dcdcde; padding:16px; border-radius:8px;">
						<div style="font-size:12px; color:#646970; margin-bottom:8px;"><?php esc_html_e('Total clicks', 'lumos-linked'); ?></div>
						<div style="font-size:30px; line-height:1.1; font-weight:700;"><?php echo esc_html((string) $total_clicks); ?></div>
					</div>
					<div style="background:#fff; border:1px solid #dcdcde; padding:16px; border-radius:8px;">
						<div style="font-size:12px; color:#646970; margin-bottom:8px;"><?php esc_html_e('Avg links per page', 'lumos-linked'); ?></div>
						<div style="font-size:30px; line-height:1.1; font-weight:700;"><?php echo esc_html((string) $avg_links_per_page); ?></div>
					</div>
					<div style="background:#fff; border:1px solid #dcdcde; padding:16px; border-radius:8px;">
						<div style="font-size:12px; color:#646970; margin-bottom:8px;"><?php esc_html_e('Top keyword by clicks', 'lumos-linked'); ?></div>
						<div style="font-size:18px; line-height:1.2; font-weight:700;"><?php echo esc_html($top_keyword ? $top_keyword : __('N/A', 'lumos-linked')); ?></div>
						<div style="font-size:12px; color:#646970; margin-top:6px;"><?php echo esc_html(sprintf(__('Clicks: %d', 'lumos-linked'), $top_keyword_clicks)); ?></div>
					</div>
				</div>
				<div style="margin-top:-6px; margin-bottom:12px; color:#646970;">
					<?php
					echo esc_html(
						$scan_updated_at > 0
							? sprintf(__('Last scan: %s', 'lumos-linked'), wp_date('Y-m-d H:i', $scan_updated_at))
							: __('Last scan: not run yet', 'lumos-linked')
					);
					?>
				</div>
			<?php endif; ?>

			<?php if ($is_links) : ?>
			<h2><?php esc_html_e('Add keyword mappings', 'lumos-linked'); ?></h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('ail_add_mapping'); ?>
				<input type="hidden" name="action" value="ail_add_mapping" />
				<table class="widefat striped" id="ail-mapping-builder">
					<thead>
						<tr>
							<th><?php esc_html_e('Keyword / phrase', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Target URL', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Exclude from URLs', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Action', 'lumos-linked'); ?></th>
						</tr>
					</thead>
					<tbody id="ail-builder-body">
						<tr>
							<td><input name="keywords[]" type="text" class="regular-text ail-keyword-input" placeholder="middle corridor" /></td>
							<td><input name="target_urls[]" type="text" class="regular-text ail-target-input" placeholder="/route-page or https://example.com/route-page" /></td>
							<td>
								<textarea name="exclude_from[]" class="regular-text ail-exclude-input" rows="2" placeholder="/services/rail-freight, /about"></textarea>
								<p class="description"><?php esc_html_e('Multiple values supported: comma or new line.', 'lumos-linked'); ?></p>
							</td>
							<td><button type="button" class="button ail-remove-row"><?php esc_html_e('Remove', 'lumos-linked'); ?></button></td>
						</tr>
					</tbody>
				</table>
				<p style="margin-top:10px;">
					<button type="button" id="ail-add-row" class="button"><?php esc_html_e('Add another row', 'lumos-linked'); ?></button>
				</p>
				<p>
					<label>
						<input name="case_sensitive_global" type="checkbox" value="1" />
						<?php esc_html_e('Case-sensitive keyword matching for rows in this save', 'lumos-linked'); ?>
					</label>
				</p>
				<p>
					<label>
						<input name="exclude_target_url_global" type="checkbox" value="1" />
						<?php esc_html_e('Exclude from targeted URL page for rows in this save', 'lumos-linked'); ?>
					</label>
				</p>
				<?php submit_button(__('Save mapping', 'lumos-linked')); ?>
			</form>
			<?php endif; ?>

			<?php if ($is_settings) : ?>
			<h2><?php esc_html_e('Link hover settings', 'lumos-linked'); ?></h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('ail_save_settings'); ?>
				<input type="hidden" name="action" value="ail_save_settings" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ail_link_color"><?php esc_html_e('Link color', 'lumos-linked'); ?></label></th>
						<td><input id="ail_link_color" name="link_color" type="color" value="<?php echo esc_attr($settings['link_color']); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ail_hover_color"><?php esc_html_e('Hover color', 'lumos-linked'); ?></label></th>
						<td><input id="ail_hover_color" name="hover_color" type="color" value="<?php echo esc_attr($settings['hover_color']); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ail_hover_style"><?php esc_html_e('Hover style', 'lumos-linked'); ?></label></th>
						<td>
							<select id="ail_hover_style" name="hover_style">
								<option value="underline" <?php selected($settings['hover_style'], 'underline'); ?>><?php esc_html_e('Underline', 'lumos-linked'); ?></option>
								<option value="none" <?php selected($settings['hover_style'], 'none'); ?>><?php esc_html_e('No decoration', 'lumos-linked'); ?></option>
								<option value="bold" <?php selected($settings['hover_style'], 'bold'); ?>><?php esc_html_e('Bold', 'lumos-linked'); ?></option>
								<option value="italic" <?php selected($settings['hover_style'], 'italic'); ?>><?php esc_html_e('Italic', 'lumos-linked'); ?></option>
								<option value="elara" <?php selected($settings['hover_style'], 'elara'); ?>><?php esc_html_e('Elara line animation', 'lumos-linked'); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button(__('Save hover settings', 'lumos-linked')); ?>
			</form>
			<?php endif; ?>

			<?php if ($is_links) : ?>
			<hr />

			<h2><?php esc_html_e('Keyword mappings', 'lumos-linked'); ?></h2>
			<?php if (empty($mappings)) : ?>
				<p><?php esc_html_e('No mappings yet.', 'lumos-linked'); ?></p>
			<?php else : ?>
				<script src="https://unpkg.com/active-table@1.1.8/dist/activeTable.bundle.js"></script>
				<p><?php esc_html_e('Interactive table view:', 'lumos-linked'); ?></p>
				<active-table id="ail-active-table" style="height:320px; width:100%; margin-bottom:12px;"></active-table>
				<table class="widefat striped" id="ail-mappings-fallback" style="margin-top:10px;">
					<thead>
						<tr>
							<th><?php esc_html_e('Keyword', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Target URL', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Exclude from URLs', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Exclude target URL page', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Case', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Linked pages', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Clicks', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Sources', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Action', 'lumos-linked'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($mappings as $mapping) : ?>
							<?php
							$map_id      = isset($mapping['id']) ? $mapping['id'] : '';
							$map_stats   = isset($stats['by_mapping'][ $map_id ]) ? $stats['by_mapping'][ $map_id ] : array();
							$map_clicks  = isset($map_stats['clicks']) ? (int) $map_stats['clicks'] : 0;
							$map_sources = isset($map_stats['sources']) && is_array($map_stats['sources']) ? $map_stats['sources'] : array();
							$source_count = count($map_sources);
							$linked_pages = $this->get_linked_pages_count($map_id);
							$exclude_from = isset($mapping['exclude_from']) && is_array($mapping['exclude_from']) ? implode(', ', $mapping['exclude_from']) : '';
							$exclude_target = !empty($mapping['exclude_target_url_page']);
							?>
							<tr>
								<td>
									<a href="#" class="ail-open-stats" data-keyword="<?php echo esc_attr($mapping['keyword']); ?>" data-clicks="<?php echo esc_attr((string) $map_clicks); ?>" data-sources="<?php echo esc_attr(wp_json_encode($map_sources)); ?>">
										<?php echo esc_html($mapping['keyword']); ?>
									</a>
								</td>
								<td><a href="<?php echo esc_url($mapping['target_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($mapping['target_url']); ?></a></td>
								<td><?php echo esc_html($exclude_from); ?></td>
								<td><?php echo $exclude_target ? esc_html__('Yes', 'lumos-linked') : esc_html__('No', 'lumos-linked'); ?></td>
								<td><?php echo !empty($mapping['case_sensitive']) ? esc_html__('Sensitive', 'lumos-linked') : esc_html__('Insensitive', 'lumos-linked'); ?></td>
								<td><?php echo esc_html((string) $linked_pages); ?></td>
								<td><?php echo esc_html((string) $map_clicks); ?></td>
								<td><?php echo esc_html((string) $source_count); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
										<?php wp_nonce_field('ail_delete_mapping_' . $map_id); ?>
										<input type="hidden" name="action" value="ail_delete_mapping" />
										<input type="hidden" name="mapping_id" value="<?php echo esc_attr($map_id); ?>" />
										<?php submit_button(__('Delete', 'lumos-linked'), 'delete', 'submit', false); ?>
									</form>
									<button type="button" class="button ail-open-edit" data-map-id="<?php echo esc_attr($map_id); ?>" data-keyword="<?php echo esc_attr($mapping['keyword']); ?>" data-target="<?php echo esc_attr($mapping['target_url']); ?>" data-exclude="<?php echo esc_attr($exclude_from); ?>" data-case="<?php echo !empty($mapping['case_sensitive']) ? '1' : '0'; ?>" data-exclude-target="<?php echo $exclude_target ? '1' : '0'; ?>" style="margin-left:6px;"><?php esc_html_e('Edit', 'lumos-linked'); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php endif; ?>

			<?php if ($is_dashboard) : ?>
			<hr />

			<h2><?php esc_html_e('Scan all posts/pages', 'lumos-linked'); ?></h2>
			<p><?php esc_html_e('This will scan all published posts and pages and add internal links for your keywords.', 'lumos-linked'); ?></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('ail_scan_content'); ?>
				<input type="hidden" name="action" value="ail_scan_content" />
				<?php submit_button(__('Run scan now', 'lumos-linked'), 'primary'); ?>
			</form>
			<h2><?php esc_html_e('Migrate legacy tracked links', 'lumos-linked'); ?></h2>
			<p><?php esc_html_e('This converts old redirect-style Lumos tracking URLs in saved content (including Elementor data) to direct destination links.', 'lumos-linked'); ?></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('ail_migrate_legacy_links'); ?>
				<input type="hidden" name="action" value="ail_migrate_legacy_links" />
				<?php submit_button(__('Migrate legacy links now', 'lumos-linked'), 'secondary'); ?>
			</form>
			<?php if (!empty($scan_summary['rows'])) : ?>
				<h3><?php esc_html_e('Last scan result', 'lumos-linked'); ?></h3>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e('Keyword', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Pages', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Page keyword links', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Posts', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Post keyword links', 'lumos-linked'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($scan_summary['rows'] as $row) : ?>
							<tr>
								<td><?php echo esc_html($row['keyword']); ?></td>
								<td><?php echo esc_html((string) $row['pages']); ?></td>
								<td><?php echo esc_html((string) $row['page_keywords']); ?></td>
								<td><?php echo esc_html((string) $row['posts']); ?></td>
								<td><?php echo esc_html((string) $row['post_keywords']); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php endif; ?>

			<?php if ($is_links) : ?>
			<?php if (!empty($pages_report['rows'])) : ?>
				<h3><?php esc_html_e('Pages where linked', 'lumos-linked'); ?></h3>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e('URL', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Keyword links count', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Keywords on this page', 'lumos-linked'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($pages_report['rows'] as $row) : ?>
							<tr>
								<td><a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($row['url']); ?></a></td>
								<td><?php echo esc_html((string) $row['keywords_count']); ?></td>
								<td><?php echo esc_html($row['keyword_breakdown']); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ($pages_report['total_pages'] > 1) : ?>
					<div style="margin-top:10px;">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg(
										array(
											'page'            => self::LINKS_SLUG,
											'ail_report_page' => '%#%',
											'ail_notice'      => $notice,
										),
										admin_url('admin.php')
									),
									'format'    => '',
									'current'   => $pages_report['current_page'],
									'total'     => $pages_report['total_pages'],
									'type'      => 'plain',
								)
							)
						);
						?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php if ($is_links) : ?>
		<div id="ail-edit-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9999;">
			<div style="background:#fff; max-width:760px; margin:5% auto; padding:20px; border-radius:8px;">
				<h2><?php esc_html_e('Edit mapping', 'lumos-linked'); ?></h2>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('ail_update_mapping'); ?>
					<input type="hidden" name="action" value="ail_update_mapping" />
					<input type="hidden" id="ail_edit_mapping_id" name="mapping_id" value="" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="ail_edit_keyword"><?php esc_html_e('Keyword', 'lumos-linked'); ?></label></th>
							<td><input id="ail_edit_keyword" name="keyword" type="text" class="regular-text" required /></td>
						</tr>
						<tr>
							<th scope="row"><label for="ail_edit_target"><?php esc_html_e('Target URL', 'lumos-linked'); ?></label></th>
							<td><input id="ail_edit_target" name="target_url" type="text" class="regular-text" required /></td>
						</tr>
						<tr>
							<th scope="row"><label for="ail_edit_exclude"><?php esc_html_e('Exclude from URLs', 'lumos-linked'); ?></label></th>
							<td>
								<textarea id="ail_edit_exclude" name="exclude_from" rows="3" class="large-text"></textarea>
								<p class="description"><?php esc_html_e('Multiple values supported: comma or new line.', 'lumos-linked'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Case-sensitive', 'lumos-linked'); ?></th>
							<td><label><input type="checkbox" id="ail_edit_case" name="case_sensitive" value="1" /> <?php esc_html_e('Match exact case', 'lumos-linked'); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Exclude target URL page', 'lumos-linked'); ?></th>
							<td><label><input type="checkbox" id="ail_edit_exclude_target" name="exclude_target_url_page" value="1" /> <?php esc_html_e('Do not link this keyword on its own target URL page', 'lumos-linked'); ?></label></td>
						</tr>
					</table>
					<?php submit_button(__('Save changes', 'lumos-linked'), 'primary', 'submit', false); ?>
					<button type="button" class="button" id="ail-close-edit" style="margin-left:8px;"><?php esc_html_e('Cancel', 'lumos-linked'); ?></button>
				</form>
			</div>
		</div>
		<div id="ail-stats-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9999;">
			<div style="background:#fff; max-width:700px; margin:6% auto; padding:20px; border-radius:8px;">
				<h2 id="ail-modal-title"><?php esc_html_e('Keyword stats', 'lumos-linked'); ?></h2>
				<p><strong><?php esc_html_e('Total clicks:', 'lumos-linked'); ?></strong> <span id="ail-modal-clicks">0</span></p>
				<h3><?php esc_html_e('Clicked from pages', 'lumos-linked'); ?></h3>
				<ul id="ail-modal-sources"></ul>
				<p style="margin-top:16px;">
					<button type="button" class="button button-primary" id="ail-close-modal"><?php esc_html_e('Close', 'lumos-linked'); ?></button>
				</p>
			</div>
		</div>
		<script>
		(function() {
			function initActiveTable() {
				const tableEl = document.getElementById('ail-active-table');
				const fallback = document.getElementById('ail-mappings-fallback');
				if (!tableEl || !fallback || typeof tableEl !== 'object') {
					return;
				}

				const data = [['Keyword', 'Target URL', 'Exclude from URLs', 'Exclude target URL page', 'Case', 'Linked pages', 'Clicks', 'Sources']];
				fallback.querySelectorAll('tbody tr').forEach(function(row) {
					const cells = row.querySelectorAll('td');
					if (cells.length >= 8) {
						const keyword = (cells[0].innerText || '').trim();
						const target = (cells[1].innerText || '').trim();
						const exclude = (cells[2].innerText || '').trim();
						const excludeTarget = (cells[3].innerText || '').trim();
						const matchCase = (cells[4].innerText || '').trim();
						const linkedPages = (cells[5].innerText || '').trim();
						const clicks = (cells[6].innerText || '').trim();
						const sources = (cells[7].innerText || '').trim();
						data.push([keyword, target, exclude, excludeTarget, matchCase, linkedPages, clicks, sources]);
					}
				});

				tableEl.data = data;
				tableEl.readOnly = true;
			}

			function addRow() {
				const body = document.getElementById('ail-builder-body');
				const row = document.createElement('tr');
				row.innerHTML =
					'<td><input name="keywords[]" type="text" class="regular-text ail-keyword-input" placeholder="middle corridor" /></td>' +
					'<td><input name="target_urls[]" type="text" class="regular-text ail-target-input" placeholder="/route-page or https://example.com/route-page" /></td>' +
					'<td><textarea name="exclude_from[]" class="regular-text ail-exclude-input" rows="2" placeholder="/services/rail-freight, /about"></textarea><p class="description">Multiple values supported: comma or new line.</p></td>' +
					'<td><button type="button" class="button ail-remove-row">Remove</button></td>';
				body.appendChild(row);
			}

			document.getElementById('ail-add-row').addEventListener('click', function(e) {
				e.preventDefault();
				addRow();
			});

			document.addEventListener('click', function(e) {
				if (e.target.classList.contains('ail-remove-row')) {
					const rows = document.querySelectorAll('#ail-builder-body tr');
					if (rows.length > 1) {
						e.target.closest('tr').remove();
					} else {
						const keyword = e.target.closest('tr').querySelector('.ail-keyword-input');
						const target = e.target.closest('tr').querySelector('.ail-target-input');
						keyword.value = '';
						target.value = '';
					}
				}
			});

			document.querySelector('form[action*="admin-post.php"]').addEventListener('submit', function(e) {
				const rows = document.querySelectorAll('#ail-builder-body tr');
				let validRows = 0;

				for (const row of rows) {
					const keyword = row.querySelector('.ail-keyword-input').value.trim();
					const target = row.querySelector('.ail-target-input').value.trim();

					if (keyword || target) {
						if (!keyword || !target) {
							e.preventDefault();
							alert('If keyword is added, target URL is mandatory.');
							return;
						}
						validRows++;
					}
				}

				if (validRows === 0) {
					e.preventDefault();
					alert('Please add at least one keyword mapping.');
				}
			});

			const modal = document.getElementById('ail-stats-modal');
			const modalTitle = document.getElementById('ail-modal-title');
			const modalClicks = document.getElementById('ail-modal-clicks');
			const modalSources = document.getElementById('ail-modal-sources');
			const closeModal = document.getElementById('ail-close-modal');

			document.querySelectorAll('.ail-open-stats').forEach(function(link) {
				link.addEventListener('click', function(e) {
					e.preventDefault();
					const keyword = link.getAttribute('data-keyword') || '';
					const clicks = link.getAttribute('data-clicks') || '0';
					const sourcesRaw = link.getAttribute('data-sources') || '{}';
					let sources = {};
					try {
						sources = JSON.parse(sourcesRaw);
					} catch (err) {
						sources = {};
					}

					modalTitle.textContent = 'Keyword stats: ' + keyword;
					modalClicks.textContent = clicks;
					modalSources.innerHTML = '';

					const sourceEntries = Object.entries(sources);
					if (sourceEntries.length === 0) {
						const li = document.createElement('li');
						li.textContent = 'No clicks yet.';
						modalSources.appendChild(li);
					} else {
						sourceEntries.sort(function(a, b) { return b[1] - a[1]; });
						sourceEntries.forEach(function(entry) {
							const li = document.createElement('li');
							li.innerHTML = '<a href="' + entry[0] + '" target="_blank" rel="noopener noreferrer">' + entry[0] + '</a> (' + entry[1] + ')';
							modalSources.appendChild(li);
						});
					}

					modal.style.display = 'block';
				});
			});

			closeModal.addEventListener('click', function() {
				modal.style.display = 'none';
			});

			modal.addEventListener('click', function(e) {
				if (e.target === modal) {
					modal.style.display = 'none';
				}
			});

			const editModal = document.getElementById('ail-edit-modal');
			const editId = document.getElementById('ail_edit_mapping_id');
			const editKeyword = document.getElementById('ail_edit_keyword');
			const editTarget = document.getElementById('ail_edit_target');
			const editExclude = document.getElementById('ail_edit_exclude');
			const editCase = document.getElementById('ail_edit_case');
			const editExcludeTarget = document.getElementById('ail_edit_exclude_target');
			const closeEdit = document.getElementById('ail-close-edit');

			document.querySelectorAll('.ail-open-edit').forEach(function(btn) {
				btn.addEventListener('click', function() {
					editId.value = btn.getAttribute('data-map-id') || '';
					editKeyword.value = btn.getAttribute('data-keyword') || '';
					editTarget.value = btn.getAttribute('data-target') || '';
					editExclude.value = btn.getAttribute('data-exclude') || '';
					editCase.checked = '1' === (btn.getAttribute('data-case') || '0');
					editExcludeTarget.checked = '1' === (btn.getAttribute('data-exclude-target') || '0');
					editModal.style.display = 'block';
				});
			});

			closeEdit.addEventListener('click', function() {
				editModal.style.display = 'none';
			});
			editModal.addEventListener('click', function(e) {
				if (e.target === editModal) {
					editModal.style.display = 'none';
				}
			});

			initActiveTable();
		})();
		</script>
		<?php endif; ?>
		<?php
	}

	public function handle_add_mapping() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized request.', 'lumos-linked'));
		}

		check_admin_referer('ail_add_mapping');

		$keywords        = isset($_POST['keywords']) && is_array($_POST['keywords']) ? wp_unslash($_POST['keywords']) : array();
		$target_urls     = isset($_POST['target_urls']) && is_array($_POST['target_urls']) ? wp_unslash($_POST['target_urls']) : array();
		$exclude_rows    = isset($_POST['exclude_from']) && is_array($_POST['exclude_from']) ? wp_unslash($_POST['exclude_from']) : array();
		$case_sensitive_global = isset($_POST['case_sensitive_global']) && '1' === (string) wp_unslash($_POST['case_sensitive_global']);
		$exclude_target_url_global = isset($_POST['exclude_target_url_global']) && '1' === (string) wp_unslash($_POST['exclude_target_url_global']);
		if (empty($keywords) || empty($target_urls)) {
			$this->redirect_with_notice('invalid', self::LINKS_SLUG);
		}

		$mappings  = $this->get_mappings();
		$to_append = array();
		$max       = max(count($keywords), count($target_urls));

		for ($i = 0; $i < $max; $i++) {
			$keyword    = isset($keywords[ $i ]) ? sanitize_text_field((string) $keywords[ $i ]) : '';
			$target_url = isset($target_urls[ $i ]) ? sanitize_text_field((string) $target_urls[ $i ]) : '';
			$target_url = $this->normalize_target_url($target_url);
			$exclude_raw = isset($exclude_rows[ $i ]) ? sanitize_text_field((string) $exclude_rows[ $i ]) : '';
			$exclude_from = $this->normalize_exclude_patterns($exclude_raw);

			if ('' === $keyword && '' === $target_url) {
				continue;
			}

			if ('' === $keyword || '' === $target_url) {
				$this->redirect_with_notice('invalid', self::LINKS_SLUG);
			}

			$id          = md5(strtolower($keyword) . '|' . strtolower($target_url));
			$to_append[] = array(
				'id'         => $id,
				'keyword'    => $keyword,
				'target_url' => $target_url,
				'case_sensitive' => $case_sensitive_global,
				'exclude_from' => $exclude_from,
				'exclude_target_url_page' => $exclude_target_url_global,
			);
		}

		if (empty($to_append)) {
			$this->redirect_with_notice('invalid', self::LINKS_SLUG);
		}

		$existing = array();
		foreach ($mappings as $mapping) {
			$existing[ $mapping['id'] ] = true;
		}
		foreach ($to_append as $item) {
			if (!isset($existing[ $item['id'] ])) {
				$mappings[] = $item;
			}
		}

		$this->save_mappings($mappings);
		$this->redirect_with_notice('added', self::LINKS_SLUG);
	}

	public function handle_save_settings() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized request.', 'lumos-linked'));
		}

		check_admin_referer('ail_save_settings');
		$link_color = isset($_POST['link_color']) ? sanitize_hex_color((string) wp_unslash($_POST['link_color'])) : '';
		if (!$link_color) {
			$link_color = '#2a7cc7';
		}
		$hover_color = isset($_POST['hover_color']) ? sanitize_hex_color((string) wp_unslash($_POST['hover_color'])) : '';
		if (!$hover_color) {
			$hover_color = '#2a7cc7';
		}
		$hover_style = isset($_POST['hover_style']) ? sanitize_key((string) wp_unslash($_POST['hover_style'])) : 'underline';
		if (!in_array($hover_style, array('underline', 'none', 'bold', 'italic', 'elara'), true)) {
			$hover_style = 'underline';
		}

		$this->save_settings(
			array(
				'link_color'  => $link_color,
				'hover_color' => $hover_color,
				'hover_style' => $hover_style,
			)
		);
		$this->redirect_with_notice('settings_saved', self::SETTINGS_SLUG);
	}

	public function handle_update_mapping() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized request.', 'lumos-linked'));
		}

		check_admin_referer('ail_update_mapping');
		$mapping_id = isset($_POST['mapping_id']) ? sanitize_key((string) wp_unslash($_POST['mapping_id'])) : '';
		$keyword    = isset($_POST['keyword']) ? sanitize_text_field((string) wp_unslash($_POST['keyword'])) : '';
		$target_url = isset($_POST['target_url']) ? sanitize_text_field((string) wp_unslash($_POST['target_url'])) : '';
		$target_url = $this->normalize_target_url($target_url);
		$exclude_raw = isset($_POST['exclude_from']) ? (string) wp_unslash($_POST['exclude_from']) : '';
		$exclude_from = $this->normalize_exclude_patterns($exclude_raw);
		$case_sensitive = isset($_POST['case_sensitive']) && '1' === (string) wp_unslash($_POST['case_sensitive']);
		$exclude_target = isset($_POST['exclude_target_url_page']) && '1' === (string) wp_unslash($_POST['exclude_target_url_page']);

		if ('' === $mapping_id || '' === $keyword || '' === $target_url) {
			$this->redirect_with_notice('invalid', self::LINKS_SLUG);
		}

		$mappings = $this->get_mappings();
		foreach ($mappings as $idx => $mapping) {
			if ((string) $mapping['id'] !== $mapping_id) {
				continue;
			}
			$mappings[$idx]['keyword'] = $keyword;
			$mappings[$idx]['target_url'] = $target_url;
			$mappings[$idx]['exclude_from'] = $exclude_from;
			$mappings[$idx]['case_sensitive'] = $case_sensitive;
			$mappings[$idx]['exclude_target_url_page'] = $exclude_target;
		}

		$this->save_mappings($mappings);
		$this->redirect_with_notice('added', self::LINKS_SLUG);
	}

	public function handle_delete_mapping() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized request.', 'lumos-linked'));
		}

		$mapping_id = isset($_POST['mapping_id']) ? sanitize_key((string) wp_unslash($_POST['mapping_id'])) : '';
		check_admin_referer('ail_delete_mapping_' . $mapping_id);

		$mappings = $this->get_mappings();
		$filtered = array();
		foreach ($mappings as $mapping) {
			if ($mapping['id'] !== $mapping_id) {
				$filtered[] = $mapping;
			}
		}
		$this->save_mappings($filtered);
		$this->delete_stats_for_mapping($mapping_id);

		$this->redirect_with_notice('deleted', self::LINKS_SLUG);
	}

	public function handle_scan_content() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized request.', 'lumos-linked'));
		}

		check_admin_referer('ail_scan_content');
		$result = $this->scan_posts_and_pages();
		$this->save_scan_summary($result);
		$updated = isset($result['updated']) ? (int) $result['updated'] : 0;
		$this->redirect_with_notice('scanned_' . (string) $updated, self::MENU_SLUG);
	}

	public function handle_migrate_legacy_links() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized request.', 'lumos-linked'));
		}

		check_admin_referer('ail_migrate_legacy_links');
		$updated = $this->migrate_legacy_links_in_all_content();
		$this->redirect_with_notice('migrated_' . (string) $updated, self::MENU_SLUG);
	}

	public function auto_link_on_save($post_id, $post, $update) {
		if (!$update || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
			return;
		}

		if (!in_array($post->post_type, array('post', 'page'), true)) {
			return;
		}

		$this->link_single_post($post_id, $post->post_content, get_permalink($post_id));
	}

	private function scan_posts_and_pages() {
		$post_ids = get_posts(
			array(
				'post_type'      => array('post', 'page'),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$updated = 0;
		$mappings = $this->get_mappings();
		$rows = array();
		foreach ($mappings as $mapping) {
			$rows[ $mapping['id'] ] = array(
				'keyword'       => $mapping['keyword'],
				'pages'         => 0,
				'page_keywords' => 0,
				'posts'         => 0,
				'post_keywords' => 0,
			);
		}

		foreach ($post_ids as $post_id) {
			$post_id  = (int) $post_id;
			$content  = (string) get_post_field('post_content', $post_id);
			$post_type = (string) get_post_type($post_id);
			$updated_content = $this->apply_links_to_content($content, (string) get_permalink($post_id));
			if ($updated_content !== $content) {
				$this->update_post_content($post_id, $updated_content);
				$updated++;
			}

			foreach ($mappings as $mapping) {
				$map_id = $mapping['id'];
				$occurrences = $this->count_mapping_occurrences($updated_content, $map_id);
				if ($occurrences <= 0 || !isset($rows[ $map_id ])) {
					continue;
				}

				if ('page' === $post_type) {
					$rows[ $map_id ]['pages']++;
					$rows[ $map_id ]['page_keywords'] += $occurrences;
				} else {
					$rows[ $map_id ]['posts']++;
					$rows[ $map_id ]['post_keywords'] += $occurrences;
				}
			}
		}

		return array(
			'updated' => $updated,
			'rows'    => array_values($rows),
		);
	}

	private function link_single_post($post_id, $content, $source_permalink) {
		$updated_content = $this->apply_links_to_content($content, (string) $source_permalink);
		if ($updated_content === $content) {
			return false;
		}

		$this->update_post_content($post_id, $updated_content);
		return true;
	}

	private function update_post_content($post_id, $updated_content) {
		remove_action('save_post', array($this, 'auto_link_on_save'), 20);
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $updated_content,
			)
		);
		add_action('save_post', array($this, 'auto_link_on_save'), 20, 3);
	}

	private function apply_links_to_content($content, $source_permalink) {
		$mappings = $this->get_mappings();
		$hover_style = $this->get_settings()['hover_style'];
		$link_class = 'lumos_link lumos_linked_hover' . ('elara' === $hover_style ? ' lumos_linked_hover--elara' : '');
		if (empty($mappings) || '' === trim($content)) {
			return $content;
		}
		$content = $this->replace_legacy_tracked_links($content);

		$parts = preg_split('/(<a\b[^>]*>.*?<\/a>)/is', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
		if (!is_array($parts)) {
			return $content;
		}

		foreach ($parts as $idx => $part) {
			if (preg_match('/^<a\b/i', $part)) {
				continue;
			}

			foreach ($mappings as $mapping) {
				$map_id  = isset($mapping['id']) ? $mapping['id'] : '';
				$keyword = trim($mapping['keyword']);
				$url     = $mapping['target_url'];
				$case_sensitive = !empty($mapping['case_sensitive']);
				if ('' === $keyword || '' === $url || '' === $map_id) {
					continue;
				}
				if ($this->is_mapping_excluded_for_source($mapping, $source_permalink)) {
					continue;
				}

				$pattern = '/(?<![\p{L}\p{N}_])(' . preg_quote($keyword, '/') . ')(?![\p{L}\p{N}_])/u';
				if (!$case_sensitive) {
					$pattern .= 'i';
				}
				$part    = preg_replace(
					$pattern,
					'<a class="' . esc_attr($link_class) . '" href="' . esc_url($url) . '" data-lumos-linked-map="' . esc_attr($map_id) . '" data-lumos-linked-source="' . esc_attr((string) $source_permalink) . '"><span>$1</span></a>',
					$part,
					1
				);
			}

			$parts[$idx] = $part;
		}

		return implode('', $parts);
	}

	private function get_mappings() {
		$stored = $this->read_json(self::MAPS_FILE, array());
		if (!is_array($stored)) {
			return array();
		}

		$normalized = array();
		foreach ($stored as $item) {
			$id      = isset($item['id']) ? sanitize_key((string) $item['id']) : '';
			$keyword = isset($item['keyword']) ? sanitize_text_field((string) $item['keyword']) : '';
			$url     = isset($item['target_url']) ? $this->normalize_target_url((string) $item['target_url']) : '';
			$case_sensitive = !empty($item['case_sensitive']);
			$exclude_from = isset($item['exclude_from']) && is_array($item['exclude_from']) ? $item['exclude_from'] : array();
			if ('' === $keyword || '' === $url) {
				continue;
			}
			if ('' === $id) {
				$id = md5(strtolower($keyword) . '|' . strtolower($url));
			}

			$normalized[] = array(
				'id'         => $id,
				'keyword'    => $keyword,
				'target_url' => $url,
				'case_sensitive' => $case_sensitive,
				'exclude_from' => $this->normalize_exclude_patterns($exclude_from),
			);
		}

		return $normalized;
	}

	private function save_mappings($mappings) {
		$this->write_json(self::MAPS_FILE, array_values($mappings));
	}

	private function get_stats() {
		$stats = $this->read_json(
			self::STATS_FILE,
			array(
				'by_mapping' => array(),
			)
		);

		if (!isset($stats['by_mapping']) || !is_array($stats['by_mapping'])) {
			$stats['by_mapping'] = array();
		}

		return $stats;
	}

	public function handle_track_click() {
		if (!isset($_GET['lumos_linked_track'])) {
			return;
		}

		$mapping_id = isset($_GET['map']) ? sanitize_key(rawurldecode((string) wp_unslash($_GET['map']))) : '';
		$source     = isset($_GET['src']) ? esc_url_raw(rawurldecode((string) wp_unslash($_GET['src']))) : '';
		$target_raw = isset($_GET['to']) ? rawurldecode((string) wp_unslash($_GET['to'])) : '';
		$target     = base64_decode($target_raw, true);
		$target     = $target ? esc_url_raw($target) : '';

		if ('' === $mapping_id || '' === $target) {
			wp_safe_redirect(home_url('/'));
			exit;
		}

		$this->record_click($mapping_id, $source);
		wp_redirect($target);
		exit;
	}

	public function handle_track_click_ajax() {
		$mapping_id = isset($_POST['map']) ? sanitize_key((string) wp_unslash($_POST['map'])) : '';
		$source     = isset($_POST['src']) ? esc_url_raw((string) wp_unslash($_POST['src'])) : '';
		if ('' === $mapping_id) {
			wp_send_json_error(array('message' => 'Missing map id'), 400);
		}

		$this->record_click($mapping_id, $source);
		wp_send_json_success(array('tracked' => true));
	}

	private function data_dir() {
		$upload = wp_upload_dir();
		if (empty($upload['basedir'])) {
			return '';
		}

		$path = trailingslashit($upload['basedir']) . 'lumos-linked';
		if (!is_dir($path)) {
			wp_mkdir_p($path);
		}

		return $path;
	}

	private function read_json($filename, $default) {
		$dir = $this->data_dir();
		$option_name = $this->option_name_for_file($filename);
		if ('' === $dir) {
			return get_option($option_name, $default);
		}

		$path = trailingslashit($dir) . $filename;
		if (!file_exists($path)) {
			return get_option($option_name, $default);
		}

		$raw = file_get_contents($path);
		if (false === $raw || '' === $raw) {
			return get_option($option_name, $default);
		}

		$data = json_decode($raw, true);
		if (is_array($data)) {
			return $data;
		}

		return get_option($option_name, $default);
	}

	private function write_json($filename, $data) {
		$dir = $this->data_dir();
		$option_name = $this->option_name_for_file($filename);
		update_option($option_name, $data, false);

		if ('' === $dir) {
			return true;
		}

		$path = trailingslashit($dir) . $filename;
		$json = wp_json_encode($data, JSON_PRETTY_PRINT);
		if (!$json) {
			return false;
		}

		if (false !== file_put_contents($path, $json, LOCK_EX)) {
			return true;
		}

		return true;
	}

	private function option_name_for_file($filename) {
		if (self::MAPS_FILE === $filename) {
			return self::MAPS_OPTION;
		}
		if (self::SCAN_FILE === $filename) {
			return self::SCAN_OPTION;
		}
		if (self::SETTINGS_FILE === $filename) {
			return self::SETTINGS_OPTION;
		}

		return self::STATS_OPTION;
	}

	private function count_mapping_occurrences($content, $mapping_id) {
		if ('' === $mapping_id || '' === $content) {
			return 0;
		}

		$count = 0;
		preg_match_all('/data-lumos-linked-map="' . preg_quote($mapping_id, '/') . '"/u', $content, $matches_data);
		if (isset($matches_data[0]) && is_array($matches_data[0])) {
			$count += count($matches_data[0]);
		}
		preg_match_all('/map=' . preg_quote($mapping_id, '/') . '/u', $content, $matches);
		if (isset($matches[0]) && is_array($matches[0])) {
			$count += count($matches[0]);
		}
		return $count;
	}

	private function save_scan_summary($result) {
		$rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
		$this->write_json(
			self::SCAN_FILE,
			array(
				'rows' => $rows,
				'updated_at' => time(),
			)
		);
	}

	private function get_scan_summary() {
		$data = $this->read_json(
			self::SCAN_FILE,
			array(
				'rows' => array(),
				'updated_at' => 0,
			)
		);

		if (!isset($data['rows']) || !is_array($data['rows'])) {
			$data['rows'] = array();
		}

		return $data;
	}

	private function get_linked_pages_count($mapping_id) {
		if ('' === $mapping_id) {
			return 0;
		}

		$post_ids = get_posts(
			array(
				'post_type'      => array('post', 'page'),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$count = 0;
		$needle_legacy = 'map=' . $mapping_id;
		$needle_data = 'data-lumos-linked-map="' . $mapping_id . '"';
		foreach ($post_ids as $post_id) {
			$content = (string) get_post_field('post_content', (int) $post_id);
			if (
				(false !== strpos($content, 'lumos_linked_track=1') && false !== strpos($content, $needle_legacy)) ||
				false !== strpos($content, $needle_data)
			) {
				$count++;
			}
		}

		return $count;
	}

	private function get_pages_keywords_report($page_number, $per_page) {
		$page_number = max(1, (int) $page_number);
		$per_page    = max(1, (int) $per_page);
		$mappings    = $this->get_mappings();
		$keyword_by_id = array();
		foreach ($mappings as $mapping) {
			if (!empty($mapping['id']) && !empty($mapping['keyword'])) {
				$keyword_by_id[ (string) $mapping['id'] ] = (string) $mapping['keyword'];
			}
		}

		$post_ids = get_posts(
			array(
				'post_type'      => array('post', 'page'),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$rows = array();
		$total_keyword_links_all = 0;
		foreach ($post_ids as $post_id) {
			$post_id = (int) $post_id;
			$content = (string) get_post_field('post_content', $post_id);
			if (
				'' === $content ||
				(false === strpos($content, 'lumos_linked_track=1') && false === strpos($content, 'data-lumos-linked-map="'))
			) {
				continue;
			}

			$keywords_count = 0;
			preg_match_all('/lumos_linked_track=1/u', $content, $matches);
			if (isset($matches[0]) && is_array($matches[0])) {
				$keywords_count = count($matches[0]);
			}
			preg_match_all('/data-lumos-linked-map="[a-z0-9]+"/i', $content, $matches_data);
			if (isset($matches_data[0]) && is_array($matches_data[0])) {
				$keywords_count += count($matches_data[0]);
			}
			if ($keywords_count <= 0) {
				continue;
			}
			$total_keyword_links_all += $keywords_count;

			$by_keyword = array();
			preg_match_all('/map=([a-z0-9]+)/i', $content, $map_matches);
			if (isset($map_matches[1]) && is_array($map_matches[1])) {
				foreach ($map_matches[1] as $map_id) {
					$map_id = (string) $map_id;
					$keyword = isset($keyword_by_id[ $map_id ]) ? $keyword_by_id[ $map_id ] : $map_id;
					if (!isset($by_keyword[ $keyword ])) {
						$by_keyword[ $keyword ] = 0;
					}
					$by_keyword[ $keyword ]++;
				}
			}
			preg_match_all('/data-lumos-linked-map="([a-z0-9]+)"/i', $content, $data_map_matches);
			if (isset($data_map_matches[1]) && is_array($data_map_matches[1])) {
				foreach ($data_map_matches[1] as $map_id) {
					$map_id = (string) $map_id;
					$keyword = isset($keyword_by_id[ $map_id ]) ? $keyword_by_id[ $map_id ] : $map_id;
					if (!isset($by_keyword[ $keyword ])) {
						$by_keyword[ $keyword ] = 0;
					}
					$by_keyword[ $keyword ]++;
				}
			}
			arsort($by_keyword);
			$parts = array();
			foreach ($by_keyword as $keyword => $count) {
				$parts[] = $keyword . ' (' . (int) $count . ')';
			}

			$rows[] = array(
				'url'            => get_permalink($post_id),
				'keywords_count' => $keywords_count,
				'keyword_breakdown' => implode(', ', $parts),
			);
		}

		usort(
			$rows,
			function ($a, $b) {
				return (int) $b['keywords_count'] <=> (int) $a['keywords_count'];
			}
		);

		$total_rows  = count($rows);
		$total_pages = max(1, (int) ceil($total_rows / $per_page));
		$offset      = ($page_number - 1) * $per_page;
		$paged_rows  = array_slice($rows, $offset, $per_page);

		return array(
			'rows'         => $paged_rows,
			'total_rows'   => $total_rows,
			'total_pages'  => $total_pages,
			'current_page' => min($page_number, $total_pages),
			'total_keyword_links' => $total_keyword_links_all,
		);
	}

	private function get_settings() {
		$defaults = array(
			'link_color'  => '#2a7cc7',
			'hover_color' => '#2a7cc7',
			'hover_style' => 'underline',
		);
		$stored = $this->read_json(self::SETTINGS_FILE, $defaults);
		if (!is_array($stored)) {
			return $defaults;
		}

		$link_color  = isset($stored['link_color']) ? sanitize_hex_color((string) $stored['link_color']) : '';
		$hover_color = isset($stored['hover_color']) ? sanitize_hex_color((string) $stored['hover_color']) : '';
		$hover_style = isset($stored['hover_style']) ? sanitize_key((string) $stored['hover_style']) : 'underline';
		if (!in_array($hover_style, array('underline', 'none', 'bold', 'italic', 'elara'), true)) {
			$hover_style = 'underline';
		}

		return array(
			'link_color'  => $link_color ? $link_color : '#2a7cc7',
			'hover_color' => $hover_color ? $hover_color : '#2a7cc7',
			'hover_style' => $hover_style,
		);
	}

	private function save_settings($settings) {
		$this->write_json(self::SETTINGS_FILE, $settings);
	}

	private function normalize_exclude_patterns($input) {
		$values = is_array($input)
			? $input
			: preg_split('/[\r\n,;|]+/', (string) $input);
		if (!is_array($values)) {
			$values = array();
		}
		$result = array();
		foreach ($values as $value) {
			$value = trim((string) $value);
			if ('' === $value) {
				continue;
			}
			$result[] = $value;
		}
		return array_values(array_unique($result));
	}

	private function is_mapping_excluded_for_source($mapping, $source_permalink) {
		if (!empty($mapping['exclude_target_url_page']) && $this->urls_match($source_permalink, (string) $mapping['target_url'])) {
			return true;
		}

		$patterns = isset($mapping['exclude_from']) && is_array($mapping['exclude_from']) ? $mapping['exclude_from'] : array();
		if (empty($patterns) || '' === $source_permalink) {
			return false;
		}

		foreach ($patterns as $pattern) {
			$pattern = trim((string) $pattern);
			if ('' === $pattern) {
				continue;
			}
			if (false !== stripos($source_permalink, $pattern)) {
				return true;
			}
		}
		return false;
	}

	private function urls_match($source_url, $target_url) {
		$source = $this->normalize_url_for_compare($source_url);
		$target = $this->normalize_url_for_compare($target_url);
		return '' !== $source && '' !== $target && $source === $target;
	}

	private function normalize_url_for_compare($url) {
		$url = trim((string) $url);
		if ('' === $url) {
			return '';
		}
		if (0 === strpos($url, '/')) {
			$url = home_url($url);
		}

		$parts = wp_parse_url($url);
		if (empty($parts['host'])) {
			return untrailingslashit(strtolower($url));
		}

		$scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'https';
		$host   = strtolower((string) $parts['host']);
		$path   = isset($parts['path']) ? untrailingslashit((string) $parts['path']) : '';
		$path   = '' === $path ? '/' : $path;

		return $scheme . '://' . $host . $path;
	}

	private function normalize_target_url($target_url) {
		$target_url = trim($target_url);
		if ('' === $target_url) {
			return '';
		}

		if (is_numeric($target_url)) {
			$permalink = get_permalink((int) $target_url);
			if ($permalink) {
				return $permalink;
			}
		}

		if (strpos($target_url, '/') === 0) {
			return home_url($target_url);
		}

		$validated = esc_url_raw($target_url);
		if ('' !== $validated) {
			return $validated;
		}

		$validated = esc_url_raw(home_url('/' . ltrim($target_url, '/')));
		return '' !== $validated ? $validated : '';
	}

	private function redirect_with_notice($notice, $page_slug = self::MENU_SLUG) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => $page_slug,
					'ail_notice' => $notice,
				),
				admin_url('admin.php')
			)
		);
		exit;
	}

	private function migrate_legacy_links_in_all_content() {
		$post_ids = get_posts(
			array(
				'post_type'      => array('post', 'page'),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$updated = 0;
		foreach ($post_ids as $post_id) {
			$post_id = (int) $post_id;
			$changed = false;

			$content = (string) get_post_field('post_content', $post_id);
			$updated_content = $this->replace_legacy_tracked_links($content);
			if ($updated_content !== $content) {
				$this->update_post_content($post_id, $updated_content);
				$changed = true;
			}

			$elementor_raw = get_post_meta($post_id, '_elementor_data', true);
			if (is_string($elementor_raw) && '' !== $elementor_raw) {
				$updated_elementor_raw = $this->replace_legacy_tracked_urls_in_text($elementor_raw);
				if ($updated_elementor_raw !== $elementor_raw) {
					update_post_meta($post_id, '_elementor_data', $updated_elementor_raw);
					$changed = true;
				}
			}

			if ($changed) {
				$updated++;
			}
		}

		return $updated;
	}

	private function replace_legacy_tracked_links($content) {
		if ('' === $content || false === strpos($content, 'lumos_linked_track=')) {
			return $content;
		}

		return preg_replace_callback(
			'/<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>/iu',
			function ($matches) {
				$href_raw = isset($matches[2]) ? (string) $matches[2] : '';
				if ('' === $href_raw) {
					return $matches[0];
				}

				$target_url = $this->extract_legacy_tracked_target($href_raw);
				if ('' === $target_url) {
					return $matches[0];
				}

				return preg_replace(
					'/\bhref=(["\'])(.*?)\1/iu',
					'href="' . esc_url($target_url) . '"',
					$matches[0],
					1
				);
			},
			$content
		);
	}

	private function replace_legacy_tracked_urls_in_text($text) {
		if ('' === $text || false === strpos($text, 'lumos_linked_track=')) {
			return $text;
		}

		return preg_replace_callback(
			'/https?:\/\/[^\s"\']*lumos_linked_track=1[^\s"\']*/iu',
			function ($matches) {
				$target_url = $this->extract_legacy_tracked_target((string) $matches[0]);
				return '' !== $target_url ? $target_url : $matches[0];
			},
			$text
		);
	}

	private function extract_legacy_tracked_target($url) {
		$href = html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8');
		$parts = wp_parse_url($href);
		if (!is_array($parts) || empty($parts['query'])) {
			return '';
		}
		parse_str((string) $parts['query'], $params);
		if (empty($params['lumos_linked_track']) || empty($params['to'])) {
			return '';
		}

		$decoded_target = base64_decode((string) $params['to'], true);
		$target_url = $decoded_target ? esc_url_raw($decoded_target) : '';
		return '' !== $target_url ? $target_url : '';
	}

	private function record_click($mapping_id, $source) {
		if ('' === $mapping_id) {
			return;
		}

		$stats = $this->get_stats();
		if (!isset($stats['by_mapping'][ $mapping_id ])) {
			$stats['by_mapping'][ $mapping_id ] = array(
				'clicks'  => 0,
				'sources' => array(),
			);
		}

		$stats['by_mapping'][ $mapping_id ]['clicks']++;
		if ('' === $source) {
			$source = home_url('/');
		}
		if (!isset($stats['by_mapping'][ $mapping_id ]['sources'][ $source ])) {
			$stats['by_mapping'][ $mapping_id ]['sources'][ $source ] = 0;
		}
		$stats['by_mapping'][ $mapping_id ]['sources'][ $source ]++;

		$this->write_json(self::STATS_FILE, $stats);
	}

	private function delete_stats_for_mapping($mapping_id) {
		$mapping_id = sanitize_key((string) $mapping_id);
		if ('' === $mapping_id) {
			return;
		}

		$stats = $this->get_stats();
		if (!isset($stats['by_mapping'][ $mapping_id ])) {
			return;
		}

		unset($stats['by_mapping'][ $mapping_id ]);
		$this->write_json(self::STATS_FILE, $stats);
	}
}

new Lumos_Linked_GitHub_Updater(__FILE__, '0.4.6');
new AIL_Auto_Internal_Linker();

