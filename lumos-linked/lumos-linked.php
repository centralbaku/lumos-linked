<?php
/**
 * Plugin Name: Lumos Linker
 * Description: Scan posts and pages and add internal links based on admin-defined keywords.
 * Version: 0.2.5
 * Author: Orkhan Hasanov
 * Update URI: https://github.com/centralbaku/lumos-linked
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
	exit;
}

class Lumos_Linked_GitHub_Updater {
	const GITHUB_REPO = 'centralbaku/lumos-linked';

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
		delete_site_transient('update_plugins');
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

		$release = $this->get_latest_release();
		if (empty($release)) {
			return $transient;
		}

		$latest_version = isset($release['version']) ? $release['version'] : '';
		$package_url    = isset($release['package']) ? $release['package'] : '';
		if ('' === $latest_version || '' === $package_url) {
			return $transient;
		}

		if (version_compare($this->plugin_version, $latest_version, '>=')) {
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
		$cache_key = 'lumos_linked_latest_release';
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
	const MAPS_FILE  = 'mappings.json';
	const STATS_FILE = 'click-stats.json';
	const MAPS_OPTION = 'lumos_linked_mappings_backup';
	const STATS_OPTION = 'lumos_linked_stats_backup';

	public function __construct() {
		add_action('admin_menu', array($this, 'register_admin_page'));
		add_action('admin_post_ail_add_mapping', array($this, 'handle_add_mapping'));
		add_action('admin_post_ail_delete_mapping', array($this, 'handle_delete_mapping'));
		add_action('admin_post_ail_scan_content', array($this, 'handle_scan_content'));
		add_action('save_post', array($this, 'auto_link_on_save'), 20, 3);
		add_action('template_redirect', array($this, 'handle_track_click'));
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
	}

	private function menu_icon_data_uri() {
		// Use a compact 20x20 monochrome SVG for proper WP admin menu rendering.
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><path fill="#a7aaad" d="M3.9 9.8a1.1 1.1 0 0 1 0-1.6l3.5-3.5a1.1 1.1 0 0 1 1.6 1.6L6.3 9l2.7 2.7a1.1 1.1 0 1 1-1.6 1.6L3.9 9.8Zm6.2 0a1.1 1.1 0 0 1 0-1.6l3.5-3.5a1.1 1.1 0 1 1 1.6 1.6L12.5 9l2.7 2.7a1.1 1.1 0 1 1-1.6 1.6l-3.5-3.5Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode($svg);
	}

	public function render_admin_page() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$mappings = $this->get_mappings();
		$stats    = $this->get_stats();
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
			<?php elseif ($notice === 'invalid') : ?>
				<div class="notice notice-error"><p><?php esc_html_e('Please provide a valid keyword and target URL.', 'lumos-linked'); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e('Add keyword mappings', 'lumos-linked'); ?></h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('ail_add_mapping'); ?>
				<input type="hidden" name="action" value="ail_add_mapping" />
				<table class="widefat striped" id="ail-mapping-builder">
					<thead>
						<tr>
							<th><?php esc_html_e('Keyword / phrase', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Target URL', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Action', 'lumos-linked'); ?></th>
						</tr>
					</thead>
					<tbody id="ail-builder-body">
						<tr>
							<td><input name="keywords[]" type="text" class="regular-text ail-keyword-input" placeholder="middle corridor" /></td>
							<td><input name="target_urls[]" type="text" class="regular-text ail-target-input" placeholder="/route-page or https://example.com/route-page" /></td>
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
				<?php submit_button(__('Save mapping', 'lumos-linked')); ?>
			</form>

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
							<th><?php esc_html_e('Case', 'lumos-linked'); ?></th>
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
							?>
							<tr>
								<td>
									<a href="#" class="ail-open-stats" data-keyword="<?php echo esc_attr($mapping['keyword']); ?>" data-clicks="<?php echo esc_attr((string) $map_clicks); ?>" data-sources="<?php echo esc_attr(wp_json_encode($map_sources)); ?>">
										<?php echo esc_html($mapping['keyword']); ?>
									</a>
								</td>
								<td><a href="<?php echo esc_url($mapping['target_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($mapping['target_url']); ?></a></td>
								<td><?php echo !empty($mapping['case_sensitive']) ? esc_html__('Sensitive', 'lumos-linked') : esc_html__('Insensitive', 'lumos-linked'); ?></td>
								<td><?php echo esc_html((string) $map_clicks); ?></td>
								<td><?php echo esc_html((string) $source_count); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
										<?php wp_nonce_field('ail_delete_mapping_' . $map_id); ?>
										<input type="hidden" name="action" value="ail_delete_mapping" />
										<input type="hidden" name="mapping_id" value="<?php echo esc_attr($map_id); ?>" />
										<?php submit_button(__('Delete', 'lumos-linked'), 'delete', 'submit', false); ?>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e('Scan all posts/pages', 'lumos-linked'); ?></h2>
			<p><?php esc_html_e('This will scan all published posts and pages and add internal links for your keywords.', 'lumos-linked'); ?></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('ail_scan_content'); ?>
				<input type="hidden" name="action" value="ail_scan_content" />
				<?php submit_button(__('Run scan now', 'lumos-linked'), 'primary'); ?>
			</form>
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

				const data = [['Keyword', 'Target URL', 'Case', 'Clicks', 'Sources']];
				fallback.querySelectorAll('tbody tr').forEach(function(row) {
					const cells = row.querySelectorAll('td');
					if (cells.length >= 5) {
						const keyword = (cells[0].innerText || '').trim();
						const target = (cells[1].innerText || '').trim();
						const matchCase = (cells[2].innerText || '').trim();
						const clicks = (cells[3].innerText || '').trim();
						const sources = (cells[4].innerText || '').trim();
						data.push([keyword, target, matchCase, clicks, sources]);
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

			initActiveTable();
		})();
		</script>
		<?php
	}

	public function handle_add_mapping() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized request.', 'lumos-linked'));
		}

		check_admin_referer('ail_add_mapping');

		$keywords        = isset($_POST['keywords']) && is_array($_POST['keywords']) ? wp_unslash($_POST['keywords']) : array();
		$target_urls     = isset($_POST['target_urls']) && is_array($_POST['target_urls']) ? wp_unslash($_POST['target_urls']) : array();
		$case_sensitive_global = isset($_POST['case_sensitive_global']) && '1' === (string) wp_unslash($_POST['case_sensitive_global']);
		if (empty($keywords) || empty($target_urls)) {
			$this->redirect_with_notice('invalid');
		}

		$mappings  = $this->get_mappings();
		$to_append = array();
		$max       = max(count($keywords), count($target_urls));

		for ($i = 0; $i < $max; $i++) {
			$keyword    = isset($keywords[ $i ]) ? sanitize_text_field((string) $keywords[ $i ]) : '';
			$target_url = isset($target_urls[ $i ]) ? sanitize_text_field((string) $target_urls[ $i ]) : '';
			$target_url = $this->normalize_target_url($target_url);

			if ('' === $keyword && '' === $target_url) {
				continue;
			}

			if ('' === $keyword || '' === $target_url) {
				$this->redirect_with_notice('invalid');
			}

			$id          = md5(strtolower($keyword) . '|' . strtolower($target_url));
			$to_append[] = array(
				'id'         => $id,
				'keyword'    => $keyword,
				'target_url' => $target_url,
				'case_sensitive' => $case_sensitive_global,
			);
		}

		if (empty($to_append)) {
			$this->redirect_with_notice('invalid');
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
		$this->redirect_with_notice('added');
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

		$this->redirect_with_notice('deleted');
	}

	public function handle_scan_content() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized request.', 'lumos-linked'));
		}

		check_admin_referer('ail_scan_content');
		$updated = $this->scan_posts_and_pages();
		$this->redirect_with_notice('scanned_' . (string) $updated);
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
		foreach ($post_ids as $post_id) {
			$content = get_post_field('post_content', $post_id);
			if ($this->link_single_post((int) $post_id, (string) $content, get_permalink($post_id))) {
				$updated++;
			}
		}

		return $updated;
	}

	private function link_single_post($post_id, $content, $source_permalink) {
		$updated_content = $this->apply_links_to_content($content, (string) $source_permalink);
		if ($updated_content === $content) {
			return false;
		}

		remove_action('save_post', array($this, 'auto_link_on_save'), 20);
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $updated_content,
			)
		);
		add_action('save_post', array($this, 'auto_link_on_save'), 20, 3);
		return true;
	}

	private function apply_links_to_content($content, $source_permalink) {
		$mappings = $this->get_mappings();
		if (empty($mappings) || '' === trim($content)) {
			return $content;
		}

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

				$tracked_url = add_query_arg(
					array(
						'lumos_linked_track' => '1',
						'map'                => rawurlencode($map_id),
						'src'                => rawurlencode((string) $source_permalink),
						'to'                 => rawurlencode(base64_encode($url)),
					),
					home_url('/')
				);

				$pattern = '/(?<![\p{L}\p{N}_])(' . preg_quote($keyword, '/') . ')(?![\p{L}\p{N}_])/u';
				if (!$case_sensitive) {
					$pattern .= 'i';
				}
				$part    = preg_replace(
					$pattern,
					'<a href="' . esc_url($tracked_url) . '">$1</a>',
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
		wp_redirect($target);
		exit;
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

		return self::STATS_OPTION;
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

	private function redirect_with_notice($notice) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::MENU_SLUG,
					'ail_notice' => $notice,
				),
				admin_url('admin.php')
			)
		);
		exit;
	}
}

new Lumos_Linked_GitHub_Updater(__FILE__, '0.2.5');
new AIL_Auto_Internal_Linker();

