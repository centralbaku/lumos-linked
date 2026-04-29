<?php
/**
 * Plugin Name: Lumos-linked
 * Description: Scan posts and pages and add internal links based on admin-defined keywords.
 * Version: 0.1
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
	const OPTION_KEY = 'ail_keyword_links';
	const MENU_SLUG  = 'ail-internal-linker';

	public function __construct() {
		add_action('admin_menu', array($this, 'register_admin_page'));
		add_action('admin_post_ail_add_mapping', array($this, 'handle_add_mapping'));
		add_action('admin_post_ail_delete_mapping', array($this, 'handle_delete_mapping'));
		add_action('admin_post_ail_scan_content', array($this, 'handle_scan_content'));
		add_action('save_post', array($this, 'auto_link_on_save'), 20, 3);
	}

	public function register_admin_page() {
		add_menu_page(
			__('Internal Linker', 'lumos-linked'),
			__('Internal Linker', 'lumos-linked'),
			'manage_options',
			self::MENU_SLUG,
			array($this, 'render_admin_page'),
			'dashicons-admin-links',
			58
		);
	}

	public function render_admin_page() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$mappings = $this->get_mappings();
		$notice   = isset($_GET['ail_notice']) ? sanitize_text_field(wp_unslash($_GET['ail_notice'])) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Lumos-linked', 'lumos-linked'); ?></h1>
			<?php if ($notice === 'added') : ?>
				<div class="notice notice-success"><p><?php esc_html_e('Keyword mapping added.', 'lumos-linked'); ?></p></div>
			<?php elseif ($notice === 'deleted') : ?>
				<div class="notice notice-success"><p><?php esc_html_e('Keyword mapping deleted.', 'lumos-linked'); ?></p></div>
			<?php elseif ($notice === 'scanned') : ?>
				<div class="notice notice-success"><p><?php esc_html_e('Scan completed.', 'lumos-linked'); ?></p></div>
			<?php elseif ($notice === 'invalid') : ?>
				<div class="notice notice-error"><p><?php esc_html_e('Please provide a valid keyword and target URL.', 'lumos-linked'); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e('Add keyword mapping', 'lumos-linked'); ?></h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('ail_add_mapping'); ?>
				<input type="hidden" name="action" value="ail_add_mapping" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ail_keyword"><?php esc_html_e('Keyword / phrase', 'lumos-linked'); ?></label></th>
						<td><input name="keyword" id="ail_keyword" type="text" class="regular-text" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ail_target"><?php esc_html_e('Target URL', 'lumos-linked'); ?></label></th>
						<td>
							<input name="target_url" id="ail_target" type="text" class="regular-text" placeholder="/route-page or https://example.com/route-page" required />
							<p class="description"><?php esc_html_e('Use an internal URL like /railway-logistics or full URL.', 'lumos-linked'); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(__('Save mapping', 'lumos-linked')); ?>
			</form>

			<hr />

			<h2><?php esc_html_e('Keyword mappings', 'lumos-linked'); ?></h2>
			<?php if (empty($mappings)) : ?>
				<p><?php esc_html_e('No mappings yet.', 'lumos-linked'); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e('Keyword', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Target URL', 'lumos-linked'); ?></th>
							<th><?php esc_html_e('Action', 'lumos-linked'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($mappings as $index => $mapping) : ?>
							<tr>
								<td><?php echo esc_html($mapping['keyword']); ?></td>
								<td><a href="<?php echo esc_url($mapping['target_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($mapping['target_url']); ?></a></td>
								<td>
									<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
										<?php wp_nonce_field('ail_delete_mapping_' . $index); ?>
										<input type="hidden" name="action" value="ail_delete_mapping" />
										<input type="hidden" name="mapping_index" value="<?php echo esc_attr($index); ?>" />
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
		<?php
	}

	public function handle_add_mapping() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized request.', 'lumos-linked'));
		}

		check_admin_referer('ail_add_mapping');

		$keyword    = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';
		$target_url = isset($_POST['target_url']) ? sanitize_text_field(wp_unslash($_POST['target_url'])) : '';
		$target_url = $this->normalize_target_url($target_url);

		if ('' === $keyword || '' === $target_url) {
			$this->redirect_with_notice('invalid');
		}

		$mappings   = $this->get_mappings();
		$mappings[] = array(
			'keyword'    => $keyword,
			'target_url' => $target_url,
		);

		update_option(self::OPTION_KEY, $mappings, false);
		$this->redirect_with_notice('added');
	}

	public function handle_delete_mapping() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized request.', 'lumos-linked'));
		}

		$index = isset($_POST['mapping_index']) ? absint($_POST['mapping_index']) : -1;
		check_admin_referer('ail_delete_mapping_' . $index);

		$mappings = $this->get_mappings();
		if (isset($mappings[$index])) {
			unset($mappings[$index]);
			$mappings = array_values($mappings);
			update_option(self::OPTION_KEY, $mappings, false);
		}

		$this->redirect_with_notice('deleted');
	}

	public function handle_scan_content() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized request.', 'lumos-linked'));
		}

		check_admin_referer('ail_scan_content');
		$this->scan_posts_and_pages();
		$this->redirect_with_notice('scanned');
	}

	public function auto_link_on_save($post_id, $post, $update) {
		if (!$update || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
			return;
		}

		if (!in_array($post->post_type, array('post', 'page'), true)) {
			return;
		}

		$this->link_single_post($post_id, $post->post_content);
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

		foreach ($post_ids as $post_id) {
			$content = get_post_field('post_content', $post_id);
			$this->link_single_post((int) $post_id, (string) $content);
		}
	}

	private function link_single_post($post_id, $content) {
		$updated_content = $this->apply_links_to_content($content);
		if ($updated_content === $content) {
			return;
		}

		remove_action('save_post', array($this, 'auto_link_on_save'), 20);
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $updated_content,
			)
		);
		add_action('save_post', array($this, 'auto_link_on_save'), 20, 3);
	}

	private function apply_links_to_content($content) {
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
				$keyword = trim($mapping['keyword']);
				$url     = $mapping['target_url'];
				if ('' === $keyword || '' === $url) {
					continue;
				}

				$pattern = '/\b(' . preg_quote($keyword, '/') . ')\b/iu';
				$part    = preg_replace(
					$pattern,
					'<a href="' . esc_url($url) . '">$1</a>',
					$part,
					1
				);
			}

			$parts[$idx] = $part;
		}

		return implode('', $parts);
	}

	private function get_mappings() {
		$stored = get_option(self::OPTION_KEY, array());
		if (!is_array($stored)) {
			return array();
		}

		$normalized = array();
		foreach ($stored as $item) {
			$keyword = isset($item['keyword']) ? sanitize_text_field((string) $item['keyword']) : '';
			$url     = isset($item['target_url']) ? $this->normalize_target_url((string) $item['target_url']) : '';
			if ('' === $keyword || '' === $url) {
				continue;
			}

			$normalized[] = array(
				'keyword'    => $keyword,
				'target_url' => $url,
			);
		}

		return $normalized;
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

new Lumos_Linked_GitHub_Updater(__FILE__, '0.1');
new AIL_Auto_Internal_Linker();

