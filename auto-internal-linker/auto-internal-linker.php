<?php
/**
 * Plugin Name: Lumos-linked
 * Description: Scan posts and pages and add internal links based on admin-defined keywords.
 * Version: 0.1
 * Author: Orkhan Hasanov
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
	exit;
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
			__('Internal Linker', 'auto-internal-linker'),
			__('Internal Linker', 'auto-internal-linker'),
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
			<h1><?php esc_html_e('Auto Internal Linker', 'auto-internal-linker'); ?></h1>
			<?php if ($notice === 'added') : ?>
				<div class="notice notice-success"><p><?php esc_html_e('Keyword mapping added.', 'auto-internal-linker'); ?></p></div>
			<?php elseif ($notice === 'deleted') : ?>
				<div class="notice notice-success"><p><?php esc_html_e('Keyword mapping deleted.', 'auto-internal-linker'); ?></p></div>
			<?php elseif ($notice === 'scanned') : ?>
				<div class="notice notice-success"><p><?php esc_html_e('Scan completed.', 'auto-internal-linker'); ?></p></div>
			<?php elseif ($notice === 'invalid') : ?>
				<div class="notice notice-error"><p><?php esc_html_e('Please provide a valid keyword and target URL.', 'auto-internal-linker'); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e('Add keyword mapping', 'auto-internal-linker'); ?></h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('ail_add_mapping'); ?>
				<input type="hidden" name="action" value="ail_add_mapping" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ail_keyword"><?php esc_html_e('Keyword / phrase', 'auto-internal-linker'); ?></label></th>
						<td><input name="keyword" id="ail_keyword" type="text" class="regular-text" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ail_target"><?php esc_html_e('Target URL', 'auto-internal-linker'); ?></label></th>
						<td>
							<input name="target_url" id="ail_target" type="text" class="regular-text" placeholder="/route-page or https://example.com/route-page" required />
							<p class="description"><?php esc_html_e('Use an internal URL like /railway-logistics or full URL.', 'auto-internal-linker'); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(__('Save mapping', 'auto-internal-linker')); ?>
			</form>

			<hr />

			<h2><?php esc_html_e('Keyword mappings', 'auto-internal-linker'); ?></h2>
			<?php if (empty($mappings)) : ?>
				<p><?php esc_html_e('No mappings yet.', 'auto-internal-linker'); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e('Keyword', 'auto-internal-linker'); ?></th>
							<th><?php esc_html_e('Target URL', 'auto-internal-linker'); ?></th>
							<th><?php esc_html_e('Action', 'auto-internal-linker'); ?></th>
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
										<?php submit_button(__('Delete', 'auto-internal-linker'), 'delete', 'submit', false); ?>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e('Scan all posts/pages', 'auto-internal-linker'); ?></h2>
			<p><?php esc_html_e('This will scan all published posts and pages and add internal links for your keywords.', 'auto-internal-linker'); ?></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('ail_scan_content'); ?>
				<input type="hidden" name="action" value="ail_scan_content" />
				<?php submit_button(__('Run scan now', 'auto-internal-linker'), 'primary'); ?>
			</form>
		</div>
		<?php
	}

	public function handle_add_mapping() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Unauthorized request.', 'auto-internal-linker'));
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
			wp_die(esc_html__('Unauthorized request.', 'auto-internal-linker'));
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
			wp_die(esc_html__('Unauthorized request.', 'auto-internal-linker'));
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

new AIL_Auto_Internal_Linker();

