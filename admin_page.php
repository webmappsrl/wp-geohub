<?php

/**
 * Get list of .po translation files available in the plugin languages folder.
 *
 * @return array List of [ 'filename' => 'language_label' ]
 */
function wm_get_available_po_files()
{
	$lang_dir = dirname(__FILE__) . '/languages';
	$files = [];
	$locale_names = [
		'de' => __('German', 'wm-package'),
		'en' => __('English', 'wm-package'),
		'es' => __('Spanish', 'wm-package'),
		'fr' => __('French', 'wm-package'),
		'it' => __('Italian', 'wm-package'),
		'nl' => __('Dutch', 'wm-package'),
		'sq' => __('Albanian', 'wm-package'),
	];
	foreach (glob($lang_dir . '/wm-package-*.po') ?: [] as $path) {
		$filename = basename($path);
		if (!preg_match('/^wm-package-([a-z]{2,3})\.po$/', $filename, $m)) {
			continue;
		}
		$code = $m[1];
		$label = isset($locale_names[$code]) ? $locale_names[$code] : strtoupper($code);
		$files[$filename] = $label . ' (' . $code . ')';
	}
	return $files;
}

/**
 * Serve .po file for download (admin only, nonce-checked). Prevents path traversal.
 */
function wm_serve_po_download()
{
	if (!isset($_GET['wm_download_po']) || !is_user_logged_in() || !current_user_can('manage_options')) {
		return;
	}
	$file = sanitize_file_name($_GET['wm_download_po']);
	if (!preg_match('/^wm-package-[a-z]{2,3}\.po$/', $file)) {
		return;
	}
	if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wm_download_po')) {
		wp_die(esc_html__('Security check failed.', 'wm-package'));
		return;
	}
	$path = dirname(__FILE__) . '/languages/' . $file;
	if (!is_readable($path) || !is_file($path)) {
		wp_die(esc_html__('File not found.', 'wm-package'));
		return;
	}
	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename="' . $file . '"');
	header('Content-Length: ' . filesize($path));
	readfile($path);
	exit;
}
add_action('admin_init', 'wm_serve_po_download');

add_action('admin_menu', 'wm_add_admin_menu');
function wm_add_admin_menu()
{
	add_menu_page(
		__('WM Package Settings', 'wm-package'),     // Page title
		__('WM Package', 'wm-package'),              // Menu title
		'manage_options',         // Capability
		'wm-settings',     // Menu slug
		'wm_settings_page', // Function to display the page
		plugins_url('assets/menu-icon.png', __FILE__) // Icon URL, dynamically getting the correct path
	);
}

/**
 * Default image filename used for tracks/POIs when no image is available (can be overwritten by upload)
 */
define('WM_DEFAULT_IMAGE_FILENAME', 'default_image.png');

/**
 * Example image shown in admin as reference; never overwritten by upload
 */
define('WM_DEFAULT_IMAGE_EXAMPLE_FILENAME', 'default_image_example.png');

/**
 * Validate uploaded file as PNG with same format as current default image (PNG, optional max dimensions)
 *
 * @param array $file $_FILES['key'] entry
 * @return true|WP_Error True on success, WP_Error on failure
 */
function wm_validate_default_image_upload($file)
{
	if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
		return new WP_Error('no_file', __('No file uploaded or invalid file.', 'wm-package'));
	}
	$allowed_type = 'image/png';
	if (isset($file['type']) && $file['type'] !== $allowed_type) {
		return new WP_Error('invalid_type', __('File must be in PNG format. Current format not allowed.', 'wm-package'));
	}
	$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
	if ($ext !== 'png') {
		return new WP_Error('invalid_extension', __('Extension not allowed. Use .png files only.', 'wm-package'));
	}
	$image_info = @getimagesize($file['tmp_name']);
	if ($image_info === false) {
		return new WP_Error('invalid_image', __('The file is not a valid PNG image.', 'wm-package'));
	}
	if (isset($image_info[2]) && $image_info[2] !== IMAGETYPE_PNG) {
		return new WP_Error('invalid_image', __('The file must be a valid PNG.', 'wm-package'));
	}
	// Optional: limit dimensions (e.g. max 1024x1024, square recommended)
	$max_w = 1024;
	$max_h = 1024;
	if (isset($image_info[0], $image_info[1]) && ($image_info[0] > $max_w || $image_info[1] > $max_h)) {
		return new WP_Error('dimensions', sprintf(__('Maximum dimensions allowed: %1$d×%2$d pixels.', 'wm-package'), $max_w, $max_h));
	}
	return true;
}

/**
 * URL to fetch shards configuration from wm-types repository
 */
define('WM_SHARDS_CONFIG_URL', 'https://raw.githubusercontent.com/webmappsrl/wm-types/refs/heads/main/src/environment.ts');
define('WM_SHARDS_CACHE_KEY', 'wm_shards_config_cache');
define('WM_SHARDS_CACHE_EXPIRATION', DAY_IN_SECONDS); // Cache for 24 hours

/**
 * Parse TypeScript shards configuration from environment.ts content
 * 
 * @param string $content The TypeScript file content
 * @return array Parsed shards configuration
 */
function wm_parse_shards_from_typescript($content)
{
	$shards = [];

	// Extract the shards object using regex
	// Match pattern: shardName: { origin: '...', ... awsApi: '...' }
	$pattern = "/(\w+):\s*\{\s*origin:\s*['\"]([^'\"]+)['\"],\s*elasticApi:\s*['\"][^'\"]+['\"],\s*graphhopperHost:\s*['\"][^'\"]+['\"],\s*awsApi:\s*['\"]([^'\"]+)['\"]/";

	if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
		foreach ($matches as $match) {
			$shard_name = $match[1];
			$shards[$shard_name] = [
				'origin' => $match[2],
				'awsApi' => $match[3],
			];
		}
	}

	return $shards;
}

/**
 * Get fallback shards configuration (used when remote fetch fails)
 */
function wm_get_fallback_shards_config()
{
	return [
		'geohub' => [
			'origin' => 'https://geohub.webmapp.it',
			'awsApi' => 'https://wmfe.s3.eu-central-1.amazonaws.com/geohub',
		],
		'osm2cai' => [
			'origin' => 'https://osm2cai.cai.it',
			'awsApi' => 'https://wmfe.s3.eu-central-1.amazonaws.com/osm2cai2',
		],
		'osm2cai2dev' => [
			'origin' => 'https://osm2cai.dev.maphub.it',
			'awsApi' => 'https://wmfe.s3.eu-central-1.amazonaws.com/osm2cai2dev',
		],
	];
}

/**
 * Get all available shards configuration
 * Fetched dynamically from: https://github.com/webmappsrl/wm-types/blob/main/src/environment.ts
 * Results are cached for 24 hours
 * 
 * @param bool $force_refresh Force refresh from remote source
 * @return array Shards configuration
 */
function wm_get_shards_config($force_refresh = false)
{
	// Check cache first
	if (!$force_refresh) {
		$cached = get_transient(WM_SHARDS_CACHE_KEY);
		if ($cached !== false && is_array($cached) && !empty($cached)) {
			return $cached;
		}
	}

	// Fetch from remote
	$response = wp_remote_get(WM_SHARDS_CONFIG_URL, [
		'timeout' => 10,
		'sslverify' => true,
	]);

	if (is_wp_error($response)) {
		error_log('WM Package: Failed to fetch shards config - ' . $response->get_error_message());
		// Return cached data if available, otherwise fallback
		$cached = get_transient(WM_SHARDS_CACHE_KEY);
		return ($cached !== false && is_array($cached)) ? $cached : wm_get_fallback_shards_config();
	}

	$status_code = wp_remote_retrieve_response_code($response);
	if ($status_code !== 200) {
		error_log('WM Package: Failed to fetch shards config - HTTP ' . $status_code);
		$cached = get_transient(WM_SHARDS_CACHE_KEY);
		return ($cached !== false && is_array($cached)) ? $cached : wm_get_fallback_shards_config();
	}

	$body = wp_remote_retrieve_body($response);
	$shards = wm_parse_shards_from_typescript($body);

	if (empty($shards)) {
		error_log('WM Package: Failed to parse shards from TypeScript content');
		$cached = get_transient(WM_SHARDS_CACHE_KEY);
		return ($cached !== false && is_array($cached)) ? $cached : wm_get_fallback_shards_config();
	}

	// Cache the result
	set_transient(WM_SHARDS_CACHE_KEY, $shards, WM_SHARDS_CACHE_EXPIRATION);

	return $shards;
}

/**
 * AJAX handler to refresh shards configuration
 */
function wm_ajax_refresh_shards()
{
	if (!current_user_can('manage_options')) {
		wp_send_json_error(['message' => __('Unauthorized', 'wm-package')]);
		return;
	}

	$shards = wm_get_shards_config(true); // Force refresh

	if (!empty($shards)) {
		wp_send_json_success([
			'message' => __('Shards configuration refreshed successfully', 'wm-package'),
			'shards' => $shards,
			'count' => count($shards)
		]);
	} else {
		wp_send_json_error(['message' => __('Failed to refresh shards configuration', 'wm-package')]);
	}
}
add_action('wp_ajax_wm_refresh_shards', 'wm_ajax_refresh_shards');

/**
 * AJAX handler to refresh config from API (same check as on save).
 * Uses current wm_shard and app_configuration_id; updates wm_cached_api_config and wm_config_source.
 */
function wm_ajax_refresh_config()
{
	if (!current_user_can('manage_options')) {
		wp_send_json_error(['message' => __('Unauthorized', 'wm-package')]);
		return;
	}

	$shard = get_option('wm_shard');
	$app_id = get_option('app_configuration_id');
	if (empty($shard) || !is_numeric($app_id) || empty($app_id)) {
		wp_send_json_error(['message' => __('Shard and APP ID must be set.', 'wm-package')]);
		return;
	}

	if (!function_exists('wm_get_config_api_url') || !function_exists('wm_fetch_remote_config') || !defined('WM_CONFIG_SOURCE_OPTION') || !defined('WM_CACHED_API_CONFIG_OPTION')) {
		wp_send_json_error(['message' => __('Config API helpers not available.', 'wm-package')]);
		return;
	}

	$config_url = wm_get_config_api_url($shard, $app_id);
	if (!$config_url) {
		wp_send_json_error(['message' => __('Could not build config API URL for this shard.', 'wm-package')]);
		return;
	}

	$remote = wm_fetch_remote_config($config_url);
	if (is_array($remote)) {
		update_option(WM_CACHED_API_CONFIG_OPTION, $remote);
		update_option(WM_CONFIG_SOURCE_OPTION, 'api');
		wp_send_json_success([
			'message' => __('Config updated from API (WORDPRESS/APP merged with local when present).', 'wm-package'),
			'source'  => 'api',
		]);
	} else {
		update_option(WM_CONFIG_SOURCE_OPTION, 'local');
		wp_send_json_success([
			'message' => __('Using local config (API unavailable or invalid response).', 'wm-package'),
			'source'  => 'local',
		]);
	}
}
add_action('wp_ajax_wm_refresh_config', 'wm_ajax_refresh_config');

/**
 * AJAX handler to upload and replace the default image (tracks/POIs placeholder)
 */
function wm_ajax_upload_default_image()
{
	if (!current_user_can('manage_options')) {
		wp_send_json_error(['message' => __('Unauthorized', 'wm-package')]);
		return;
	}
	if (!isset($_POST['wm_default_image_nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['wm_default_image_nonce']), 'wm_upload_default_image')) {
		wp_send_json_error(['message' => __('Security verification failed. Please try again.', 'wm-package')]);
		return;
	}
	if (empty($_FILES['default_image_file']['tmp_name']) || !is_uploaded_file($_FILES['default_image_file']['tmp_name'])) {
		wp_send_json_error(['message' => __('No file uploaded or invalid file.', 'wm-package')]);
		return;
	}
	$valid = wm_validate_default_image_upload($_FILES['default_image_file']);
	if (is_wp_error($valid)) {
		wp_send_json_error(['message' => $valid->get_error_message()]);
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$overrides = array('test_form' => false, 'mimes' => array('png' => 'image/png'));
	$upload = wp_handle_upload($_FILES['default_image_file'], $overrides);
	if (isset($upload['error'])) {
		wp_send_json_error(['message' => $upload['error']]);
		return;
	}
	$plugin_assets_dir = dirname(__FILE__) . '/assets';
	$dest_path = $plugin_assets_dir . '/' . WM_DEFAULT_IMAGE_FILENAME;
	if (!is_dir($plugin_assets_dir) || !is_writable($plugin_assets_dir)) {
		@unlink($upload['file']);
		wp_send_json_error(['message' => __('The plugin assets folder is not writable.', 'wm-package')]);
		return;
	}
	if (!copy($upload['file'], $dest_path)) {
		@unlink($upload['file']);
		wp_send_json_error(['message' => __('Unable to replace the default image.', 'wm-package')]);
		return;
	}
	@unlink($upload['file']);
	set_transient('wm_default_image_message', ['success', __('Default image updated successfully.', 'wm-package')], 45);
	wp_send_json_success(['message' => __('Default image updated successfully.', 'wm-package')]);
}
add_action('wp_ajax_wm_upload_default_image', 'wm_ajax_upload_default_image');

/**
 * Check if a shard is osm2cai-type (affects POI URL structure)
 */
function wm_is_osm2cai_shard($shard)
{
	return strpos($shard, 'osm2cai') === 0 || $shard === 'local';
}

function wm_get_api_urls($shard, $app_id)
{
	$shards = wm_get_shards_config();
	$urls = [];

	// Fallback to geohub if shard is not found
	if (!isset($shards[$shard])) {
		$shard = 'geohub';
	}

	$origin = rtrim($shards[$shard]['origin'], '/');
	$aws_api = rtrim($shards[$shard]['awsApi'], '/');

	$urls['aws_api'] = $aws_api;
	$urls['origin'] = $origin;
	$urls['tracks_list_api'] = "{$origin}/api/app/webapp/{$app_id}/tracks_list";
	$urls['single_track_api'] = "{$aws_api}/tracks/";
	$urls['layer_api'] = "{$origin}/api/app/webapp/{$app_id}/layer/";
	$urls['poi_type_api'] = "{$origin}/api/app/webapp/{$app_id}/taxonomies/poi_type/";

	// Elasticsearch API URL - different patterns for different shards
	if ($shard === 'geohub') {
		// Geohub uses a different domain for Elasticsearch
		$urls['elastic_api'] = "https://elastic-json.webmapp.it/v2/search/";
	} else {
		// Other shards use /api/v2/elasticsearch
		$urls['elastic_api'] = "{$origin}/api/v2/elasticsearch";
	}

	// POI URL structure differs for osm2cai-type shards
	if (wm_is_osm2cai_shard($shard)) {
		$urls['poi_api'] = "{$aws_api}/{$app_id}/pois.geojson";
		$urls['default_app_url'] = "https://{$app_id}.osm2cai.webmapp.it/";
	} else {
		$urls['poi_api'] = "{$aws_api}/pois/{$app_id}.geojson";
		$urls['default_app_url'] = "https://{$app_id}.app.webmapp.it";
	}

	return $urls;
}

function wm_settings_page()
{
	$app_id = get_option('app_configuration_id');
	if (!is_numeric($app_id) || empty($app_id)) {
		$app_id = '49';
	}

	// Ensure the config JSON file has the correct ID value
	if (function_exists('wm_update_config_id')) {
		wm_update_config_id($app_id);
	}

	$shard = get_option('wm_shard');
	if (empty($shard)) {
		$shard = 'geohub';
	}

	$api_urls = wm_get_api_urls($shard, $app_id);
	$tracks_list_api = $api_urls['tracks_list_api'];
	$single_track_api = $api_urls['single_track_api'];
	$poi_api = $api_urls['poi_api'];
	$layer_api = $api_urls['layer_api'];
	$poi_type_api = $api_urls['poi_type_api'];
	$elastic_api = $api_urls['elastic_api'];
	$default_app_url = $api_urls['default_app_url'];
	$config_api_url = function_exists('wm_get_config_api_url') ? wm_get_config_api_url($shard, $app_id) : '';

?>
	<div class="wrap">
		<h1 style="display: flex; align-items: center;">
			<img src="<?php echo plugins_url('assets/menu-icon.png', __FILE__); ?>" alt="<?php echo esc_attr__('WM Icon', 'wm-package'); ?>" style="margin-right: 10px; height: 30px; width: 30px;" />
			<?php echo esc_html__('WM Package Settings', 'wm-package'); ?>
		</h1>
		<?php
		$wm_default_image_msg = get_transient('wm_default_image_message');
		if ($wm_default_image_msg && is_array($wm_default_image_msg) && count($wm_default_image_msg) >= 2) {
			delete_transient('wm_default_image_message');
			$msg_type = $wm_default_image_msg[0];
			$msg_text = $wm_default_image_msg[1];
			$notice_class = ($msg_type === 'success') ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible"><p>' . esc_html($msg_text) . '</p></div>';
		}
		?>
		<div style="display: none;" id="spinner" class="notice notice-warning">
			<img src="<?php echo admin_url('images/spinner.gif'); ?>" style="margin:8px;">
			<p><?php echo esc_html__('Do not close this page while it is loading', 'wm-package'); ?></p>
		</div>
		<!-- Sync Progress Modal -->
		<div id="sync-progress-modal">
			<div class="modal-content">
				<div class="spinner-container">
					<img src="<?php echo admin_url('images/spinner.gif'); ?>" alt="<?php echo esc_attr__('Loading...', 'wm-package'); ?>">
				</div>
				<h2 id="modal-title"><?php echo esc_html__('Processing...', 'wm-package'); ?></h2>
				<div id="sync-progress-bar" style="width: 100%; background-color: #f0f0f0; border-radius: 4px; height: 20px; margin: 15px 0; overflow: hidden;">
					<div id="sync-progress-bar-fill" style="width: 0%; background-color: #2271b1; height: 100%; transition: width 0.3s ease;"></div>
				</div>
				<p id="sync-progress-message">
					<?php echo esc_html__('Please do not close or reload this page while the operation is in progress.', 'wm-package'); ?>
				</p>
				<button type="button" id="stop-sync-btn" class="button button-secondary" style="margin-top: 15px; display: none;">
					<?php echo esc_html__('Stop Synchronization', 'wm-package'); ?>
				</button>
			</div>
		</div>
		<form method="post" action="options.php">
			<?php settings_fields('wm-settings'); ?>
			<?php do_settings_sections('wm-settings'); ?>
			<h2><?php echo esc_html__('Backend Configuration:', 'wm-package'); ?></h2>
			<table class="form-table" style="margin-left: 30px;">
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Shard', 'wm-package'); ?></th>
					<td>
						<?php $shards_config = wm_get_shards_config(); ?>
						<select name="wm_shard" id="wm_shard">
							<?php foreach ($shards_config as $shard_name => $shard_data) : ?>
								<option value="<?php echo esc_attr($shard_name); ?>" <?php selected($shard, $shard_name); ?>>
									<?php echo esc_html($shard_name); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<button type="button" id="refresh_shards" class="button button-secondary" style="margin-left: 10px;">
							🔄 <?php echo esc_html__('Refresh Shards', 'wm-package'); ?>
						</button>
						<button type="button" id="refresh_config" class="button button-secondary" style="margin-left: 10px;">
							🔄 <?php echo esc_html__('Refresh Config API', 'wm-package'); ?>
						</button>
						<p class="description">
							<?php echo esc_html__('Select the backend shard from which data will be retrieved.', 'wm-package'); ?>
							<br><small>
								<?php echo esc_html__('Configuration from:', 'wm-package'); ?> <a href="https://github.com/webmappsrl/wm-types/blob/main/src/environment.ts" target="_blank">wm-types/environment.ts</a>
								<br><?php echo esc_html__('Available shards:', 'wm-package'); ?> <strong id="shards_count"><?php echo count($shards_config); ?></strong> <?php echo esc_html__('(cached for 24 hours)', 'wm-package'); ?>
							</small>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('APP ID', 'wm-package'); ?></th>
					<td>
						<input type="text" size="5" name="app_configuration_id"
							value="<?php echo esc_attr($app_id); ?>" placeholder="49" />
						<p class="description">
							<?php echo esc_html__('This APP ID refers to the ID of the app on the backend.', 'wm-package'); ?>
						</p>
					</td>
				</tr>
			</table>
			<h2><?php echo esc_html__('APIs:', 'wm-package'); ?></h2>
			<table class="form-table" style="margin-left: 30px;">
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Tracks list API', 'wm-package'); ?></th>
					<td>
						<a href="<?php echo esc_attr($tracks_list_api); ?>" target="_blank" class="api-link-tracks-list">
							<p class="api-url-tracks-list"><?php echo esc_attr($tracks_list_api); ?></p>
						</a>
						<input type="hidden" size="50" name="tracks_list" class="api-input-tracks-list"
							value="<?php echo esc_attr($tracks_list_api); ?>" readonly />
						<p class="description">
							<?php echo esc_html__('API endpoint used to retrieve the list of tracks for a specific app.', 'wm-package'); ?>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Layer API', 'wm-package'); ?></th>
					<td>
						<a href="<?php echo esc_attr($layer_api); ?>" target="_blank" class="api-link-layer">
							<p class="api-url-layer"><?php echo esc_attr($layer_api); ?></p>
						</a>
						<input type="hidden" size="50" name="layer_api" class="api-input-layer"
							value="<?php echo esc_attr($layer_api); ?>" readonly />
						<p class="description">
							<?php echo esc_html__('API endpoint used to retrieve layer information including tracks, metadata, and content for grid displays.', 'wm-package'); ?>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Single Track API', 'wm-package'); ?></th>
					<td>
						<a href="<?php echo esc_attr($single_track_api); ?>" target="_blank" class="api-link-track">
							<p class="api-url-track"><?php echo esc_attr($single_track_api); ?></p>
						</a>
						<input type="hidden" size="50" name="track_url" class="api-input-track"
							value="<?php echo esc_attr($single_track_api); ?>" readonly />
						<p class="description">
							<?php echo esc_html__('API endpoint used to retrieve detailed information for a single track by ID.', 'wm-package'); ?>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('POI API', 'wm-package'); ?></th>
					<td>
						<a href="<?php echo esc_attr($poi_api); ?>" target="_blank" class="api-link-poi">
							<p class="api-url-poi"><?php echo esc_attr($poi_api); ?></p>
						</a>
						<input type="hidden" size="50" name="poi_url" class="api-input-poi"
							value="<?php echo esc_attr($poi_api); ?>" readonly />
						<p class="description">
							<?php echo esc_html__('API endpoint used to retrieve Points of Interest (POI) data in GeoJSON format for a specific app.', 'wm-package'); ?>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('POI Type API', 'wm-package'); ?></th>
					<td>
						<a href="<?php echo esc_attr($poi_type_api); ?>" target="_blank" class="api-link-poi-type">
							<p class="api-url-poi-type"><?php echo esc_attr($poi_type_api); ?></p>
						</a>
						<input type="hidden" size="50" name="poi_type_api" class="api-input-poi-type"
							value="<?php echo esc_attr($poi_type_api); ?>" readonly />
						<p class="description">
							<?php echo esc_html__('API endpoint used to retrieve POI type taxonomies for filtering and categorizing Points of Interest.', 'wm-package'); ?>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Elasticsearch API', 'wm-package'); ?></th>
					<td>
						<a href="<?php echo esc_attr($elastic_api); ?>" target="_blank" class="api-link-elastic">
							<p class="api-url-elastic"><?php echo esc_attr($elastic_api); ?></p>
						</a>
						<input type="hidden" size="50" name="elastic_api" class="api-input-elastic"
							value="<?php echo esc_attr($elastic_api); ?>" readonly />
						<p class="description">
							<?php echo esc_html__('Elasticsearch API used for advanced track filtering and search in grid views.', 'wm-package'); ?>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Config API', 'wm-package'); ?></th>
					<td>
						<a href="<?php echo !empty($config_api_url) ? esc_url($config_api_url) : '#'; ?>" target="_blank" class="api-link-config">
							<p class="api-url-config"><?php echo !empty($config_api_url) ? esc_html($config_api_url) : '—'; ?></p>
						</a>
						<p class="description">
							<?php echo esc_html__('Config JSON used for this app (shard + APP ID). Source for WORDPRESS/APP options when "Refresh Config API" is used.', 'wm-package'); ?>
						</p>
					</td>
				</tr>
			</table>
			<h2><?php echo esc_html__('Links:', 'wm-package'); ?></h2>
			<p class="description" style="margin-left: 30px; margin-bottom: 12px;">
				<?php echo esc_html__('Configure the URLs used for the dynamic redirect: on desktop the link opens the Website URL, on iOS the iOS App URL (App Store), on Android the Android App URL (Play Store). Add the CSS class below to any element (menu item, link, button, or container) to enable this redirect.', 'wm-package'); ?>
			</p>
			<?php
			// Get APP config for iOS/Android store URLs (from wm_default_config.json)
			$links_config = function_exists('wm_get_default_config') ? wm_get_default_config() : false;
			$ios_store_from_config = !empty($links_config['APP']['iosStore']) ? $links_config['APP']['iosStore'] : '';
			$android_store_from_config = !empty($links_config['APP']['androidStore']) ? $links_config['APP']['androidStore'] : '';
			$ios_url_value = $ios_store_from_config ? $ios_store_from_config : get_option('ios_app_url');
			$android_url_value = $android_store_from_config ? $android_store_from_config : get_option('android_app_url');
			?>
			<table class="form-table" style="margin-left: 30px;">
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Webapp URL', 'wm-package'); ?></th>
					<td>
						<input type="text" size="50" value="<?php echo esc_attr(get_option('website_url')) ? esc_attr(get_option('website_url')) : esc_attr($default_app_url) ?>" placeholder="<?php echo esc_attr($default_app_url); ?>" name="website_url" />
						<p class="description">
							<?php echo esc_html__('URL that display the Website version of the map', 'wm-package'); ?>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('iOS App URL', 'wm-package'); ?></th>
					<td>
						<input type="text" size="50" value="<?php echo esc_attr($ios_url_value); ?>" name="ios_app_url" <?php echo $ios_store_from_config ? ' readonly' : ''; ?> />
						<p class="description">
							<?php echo esc_html__('URL that display the Mobile version of the map for iOS', 'wm-package'); ?>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Android App URL', 'wm-package'); ?></th>
					<td>
						<input type="text" size="50" value="<?php echo esc_attr($android_url_value); ?>" name="android_app_url" <?php echo $android_store_from_config ? ' readonly' : ''; ?> />
						<p class="description">
							<?php echo esc_html__('URL that display the Mobile version of the map for Android', 'wm-package'); ?>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('CSS class for redirect', 'wm-package'); ?></th>
					<td>
						<p class="copiable">wm-custom-link</p>
						<p class="description">
							<?php echo esc_html__('Add this class to any element to enable the redirect to the URLs above (based on device). You can use it on: a menu item (e.g. in Appearance → Menus, in "CSS Classes"), on a link, on a button, or on a container that wraps a link. The link opens in a new tab.', 'wm-package'); ?>
						</p>
					</td>
				</tr>
			</table>
			<h2><?php echo esc_html__('Featured Image Display', 'wm-package'); ?></h2>
			<p class="description" style="margin-left: 30px; margin-bottom: 12px;">
				<?php echo esc_html__('Choose where to display featured images for tracks and POIs.', 'wm-package'); ?>
			</p>
			<table class="form-table" style="margin-left: 30px;">
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Featured Image Location', 'wm-package'); ?></th>
					<td>
						<label>
							<input type="radio" name="featured_image_location" value="content" <?php checked(get_option('featured_image_location', 'content'), 'content'); ?> />
							<?php echo esc_html__('In content area (default)', 'wm-package'); ?>
						</label>
						<br>
						<label>
							<input type="radio" name="featured_image_location" value="page-header" <?php checked(get_option('featured_image_location', 'content'), 'page-header'); ?> />
							<?php echo esc_html__('In WordPress page-header section', 'wm-package'); ?>
						</label>
						<p class="description">
							<?php echo esc_html__('Select where featured images should be displayed. "In content area" shows images in the standard content wrapper. "In WordPress page-header section" displays images in the theme\'s page-header area.', 'wm-package'); ?>
						</p>
					</td>
				</tr>
			</table>
			<h2><?php echo esc_html__('Default image', 'wm-package'); ?></h2>
			<p class="description" style="margin-left: 30px; margin-bottom: 12px;">
				<?php echo esc_html__('Image used for tracks and POIs when no photo is available. Allowed format: PNG. Recommended: square, max 1024×1024 px.', 'wm-package'); ?>
			</p>
			<table class="form-table" style="margin-left: 30px;">
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Current image', 'wm-package'); ?></th>
					<td>
						<?php
						$wm_current_image_path = dirname(__FILE__) . '/assets/' . WM_DEFAULT_IMAGE_FILENAME;
						$wm_current_image_ver = file_exists($wm_current_image_path) ? (string) filemtime($wm_current_image_path) : (string) time();
						?>
						<p class="wm-default-image-row">
							<a href="<?php echo esc_url(plugins_url('assets/' . WM_DEFAULT_IMAGE_FILENAME, __FILE__)); ?>?v=<?php echo esc_attr($wm_current_image_ver); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary">
								<?php echo esc_html__('View current image', 'wm-package'); ?>
							</a>
						</p>
						<p class="description">
							<?php echo esc_html__('Opens the image currently used for tracks and POIs in a new tab.', 'wm-package'); ?>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Example image', 'wm-package'); ?></th>
					<td>
						<?php
						$wm_example_image_path = dirname(__FILE__) . '/assets/' . WM_DEFAULT_IMAGE_EXAMPLE_FILENAME;
						$wm_example_image_ver = file_exists($wm_example_image_path) ? (string) filemtime($wm_example_image_path) : (string) time();
						?>
						<p class="wm-default-image-row">
							<a href="<?php echo esc_url(plugins_url('assets/' . WM_DEFAULT_IMAGE_EXAMPLE_FILENAME, __FILE__)); ?>?v=<?php echo esc_attr($wm_example_image_ver); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary">
								<?php echo esc_html__('View example image', 'wm-package'); ?>
							</a>
						</p>
						<p class="description">
							<?php echo esc_html__('Reference image.', 'wm-package'); ?>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Upload new image', 'wm-package'); ?></th>
					<td>
						<p class="wm-default-image-row" id="wm-default-image-upload-wrap" data-nonce="<?php echo esc_attr(wp_create_nonce('wm_upload_default_image')); ?>">
							<input type="file" id="wm_default_image_file" name="default_image_file" accept=".png,image/png" />
							<button type="button" id="wm_replace_default_image_btn" class="button button-secondary"><?php echo esc_html__('Replace default image', 'wm-package'); ?></button>
						</p>
						<p class="description">
							<?php echo esc_html__('PNG files only. The image will replace the current one.', 'wm-package'); ?>
						</p>
					</td>
				</tr>
			</table>
			<h2><?php echo esc_html__('Short codes:', 'wm-package'); ?></h2>
			<table class="form-table" style="margin-left: 30px;">
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Track page:', 'wm-package'); ?></th>
					<td>
						<p class="copiable"><?php echo esc_attr(get_option('track_shortcode')) ?: "[wm_single_track track_id='$1']"; ?></p>
						<input type="hidden" size="50" name="track_shortcode"
							value="<?php echo esc_attr(get_option('track_shortcode')) ?: "[wm_single_track track_id='$1']"; ?>"
							readonly />
						<p class="description"><?php echo esc_html__('Shortcode to display a single track with full details, map, and related POIs.', 'wm-package'); ?> <br>
							<strong><?php echo esc_html__('Parameters:', 'wm-package'); ?></strong><br>
							<strong>track_id</strong>: <?php echo esc_html__('(Required) The ID of the track to display.', 'wm-package'); ?> <br>
							<strong>layer_ids</strong>: <?php echo esc_html__('(Optional) Comma-separated list of layer IDs for track navigation (Previous/Next buttons).', 'wm-package'); ?> <br>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('POI page:', 'wm-package'); ?></th>
					<td>
						<p class="copiable"><?php echo esc_attr(get_option('poi_shortcode')) ?: "[wm_single_poi poi_id='$1']"; ?></p>
						<input type="hidden" size="50" name="poi_shortcode"
							value="<?php echo esc_attr(get_option('poi_shortcode')) ?: "[wm_single_poi poi_id='$1']"; ?>"
							readonly />
						<p class="description"><?php echo esc_html__('Shortcode to display a single Point of Interest (POI) with full details and map location.', 'wm-package'); ?> <br>
							<strong><?php echo esc_html__('Parameters:', 'wm-package'); ?></strong><br>
							<strong>poi_id</strong>: <?php echo esc_html__('(Required) The ID of the POI to display.', 'wm-package'); ?>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Grid Tracks page:', 'wm-package'); ?></th>
					<td>
						<p class="copiable"><?php echo esc_attr(get_option('taxonomy_track_shortcode')) ?: "[wm_grid_track layer_id='id']"; ?></p>
						<input type="hidden" size="50" name="taxonomy_track_shortcode"
							value="<?php echo esc_attr(get_option('taxonomy_track_shortcode')) ?: "[wm_grid_track layer_id='id']"; ?>"
							readonly />
						<p class="description"><?php echo esc_html__('Shortcode to display a grid of tracks with optional filters (distance, region, difficulty, elevation). Uses Elasticsearch API for advanced filtering.', 'wm-package'); ?> <br>
							<strong><?php echo esc_html__('Parameters:', 'wm-package'); ?></strong><br>
							<strong>layer_id</strong>: <?php echo esc_html__('(Optional) ID of a single layer to display tracks from.', 'wm-package'); ?> <br>
							<strong>layer_ids</strong>: <?php echo esc_html__('(Optional) Comma-separated list of layer IDs. Takes precedence over layer_id if both are provided.', 'wm-package'); ?> <br>
							<strong>quantity</strong>: <?php echo esc_html__('(Optional) Maximum number of tracks to display. Default: -1 (unlimited).', 'wm-package'); ?> <br>
							<strong>random</strong>: <?php echo esc_html__('(Optional) Display tracks in random order. Values: "true" or "false". Default: "false".', 'wm-package'); ?> <br>
							<strong>content</strong>: <?php echo esc_html__('(Optional) Display layer content (title, subtitle, description, featured image). Values: true/false. Default: false.', 'wm-package'); ?> <br>
							<strong>use_elastic</strong>: <?php echo esc_html__('(Optional) Use Elasticsearch API for advanced filtering. Values: "true" or "false". Default: "true".', 'wm-package'); ?> <br>
							<strong>show_filters</strong>: <?php echo esc_html__('(Optional) Show filter interface (distance, region, difficulty, elevation). Values: "true" or "false". Default: "true".', 'wm-package'); ?>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Grid POIs page:', 'wm-package'); ?></th>
					<td>
						<p class="copiable"><?php echo esc_attr(get_option('taxonomy_poi_shortcode')) ?: "[wm_grid_poi poi_type_id='id']"; ?></p>
						<input type="hidden" size="50" name="taxonomy_poi_shortcode"
							value="<?php echo esc_attr(get_option('taxonomy_poi_shortcode')) ?: "[wm_grid_poi poi_type_id='id']"; ?>"
							readonly />
						<p class="description"><?php echo esc_html__('Shortcode to display a grid of POIs filtered by POI type taxonomy.', 'wm-package'); ?> <br>
							<strong><?php echo esc_html__('Parameters:', 'wm-package'); ?></strong><br>
							<strong>poi_type_id</strong>: <?php echo esc_html__('(Optional) ID of a single POI type to display POIs from.', 'wm-package'); ?> <br>
							<strong>poi_type_ids</strong>: <?php echo esc_html__('(Optional) Comma-separated list of POI type IDs. Takes precedence over poi_type_id if both are provided.', 'wm-package'); ?> <br>
							<strong>quantity</strong>: <?php echo esc_html__('(Optional) Maximum number of POIs to display. Default: -1 (unlimited).', 'wm-package'); ?> <br>
							<strong>random</strong>: <?php echo esc_html__('(Optional) Display POIs in random order. Values: "true" or "false". Default: "false".', 'wm-package'); ?>
						</p>
					</td>
				</tr>
			</table>
			<h2><?php echo esc_html__('Translations', 'wm-package'); ?></h2>
			<p class="description" style="margin-left: 30px; margin-bottom: 12px;">
				<?php echo esc_html__('You can download the .po translation files below and import them into a translation plugin (e.g. WPML, Loco Translate, Poedit) to translate or edit the plugin strings.', 'wm-package'); ?>
			</p>
			<table class="form-table" style="margin-left: 30px;">
				<?php
				$po_files = wm_get_available_po_files();
				if (!empty($po_files)) :
					foreach ($po_files as $filename => $label) :
						$download_url = wp_nonce_url(admin_url('admin.php?page=wm-settings&wm_download_po=' . rawurlencode($filename)), 'wm_download_po');
				?>
						<tr valign="top">
							<th scope="row"><?php echo esc_html($label); ?></th>
							<td>
								<a href="<?php echo esc_url($download_url); ?>" class="button button-secondary">
									<?php echo esc_html__('Download .po', 'wm-package'); ?> — <?php echo esc_html($filename); ?>
								</a>
							</td>
						</tr>
					<?php
					endforeach;
				else :
					?>
					<tr valign="top">
						<td colspan="2">
							<p class="description"><?php echo esc_html__('No .po translation files found in the languages folder.', 'wm-package'); ?></p>
						</td>
					</tr>
				<?php endif; ?>
			</table>

			<?php
			// Check config JSON values to determine which fields to show
			$config = function_exists('wm_get_default_config') ? wm_get_default_config() : false;
			$generate_edges_enabled = false;
			$download_track_enabled = false;

			if ($config) {
				if (isset($config['WORDPRESS']['generateEdges'])) {
					$generate_edges_enabled = (bool) $config['WORDPRESS']['generateEdges'];
				}
				if (isset($config['WORDPRESS']['downloadTrackEnable'])) {
					$download_track_enabled = (bool) $config['WORDPRESS']['downloadTrackEnable'];
				}
			}

			// Only show "Track Configuration" section if at least one field is enabled
			if ($generate_edges_enabled || $download_track_enabled) : ?>
				<h2><?php echo esc_html__('Track Configuration:', 'wm-package'); ?></h2>
				<table class="form-table" style="margin-left: 30px;">
					<?php
					// Only show navigation fields if generateEdges is enabled in config JSON
					if ($generate_edges_enabled) : ?>
						<tr valign="top">
							<th scope="row"><?php echo esc_html__('Enable Track Navigation', 'wm-package'); ?></th>
							<td>
								<label>
									<input type="checkbox" name="track_navigation_enabled" value="1" checked disabled />
									<?php echo esc_html__('Enabled', 'wm-package'); ?>
								</label>
								<p class="description">
									<?php echo esc_html__('When enabled, navigation buttons will appear at the bottom of each track page to navigate between tracks.', 'wm-package'); ?>
								</p>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row"><?php echo esc_html__('Default Layer IDs for Navigation', 'wm-package'); ?></th>
							<td>
								<input type="text" size="50" name="track_navigation_layer_ids"
									value="<?php echo esc_attr(get_option('track_navigation_layer_ids')); ?>"
									placeholder="<?php echo esc_attr__('e.g., 123,456,789', 'wm-package'); ?>" />
								<p class="description">
									<?php echo esc_html__('Comma-separated list of layer IDs to use for track navigation. If empty, navigation will only work if layer_ids are provided via shortcode parameter.', 'wm-package'); ?>
								</p>
							</td>
						</tr>
					<?php endif; ?>
					<?php
					// Only show this field if enabled in config JSON
					if ($download_track_enabled) : ?>
						<tr valign="top">
							<th scope="row"><?php echo esc_html__('Download Track', 'wm-package'); ?></th>
							<td>
								<label>
									<input type="checkbox" name="track_download_enabled" value="1" checked disabled />
									<?php echo esc_html__('Enabled', 'wm-package'); ?>
								</label>
								<p class="description">
									<?php echo esc_html__('When enabled, a download button will appear on the map to allow users to download the track GPX file.', 'wm-package'); ?>
								</p>
							</td>
						</tr>
					<?php endif; ?>
				</table>
			<?php endif; ?>

			<h2><?php echo esc_html__('Import and Sync:', 'wm-package'); ?></h2>
			<table class="form-table" style="margin-left: 30px;">
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Generate TRACK', 'wm-package'); ?></th>
					<td><?php submit_button(__('Generate Tracks', 'wm-package'), 'primary', 'generate_track'); ?>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Delete TRACK', 'wm-package'); ?></th>
					<td><?php submit_button(__('Delete All Tracks', 'wm-package'), 'delete', 'delete_track', false, array('style' => 'background-color: #dc3232; border-color: #dc3232; color: #fff;')); ?>
						<p class="description" style="color: #dc3232; font-weight: bold;">
							⚠️ <?php echo esc_html__('Warning: This action will PERMANENTLY delete all Track posts. This operation cannot be undone.', 'wm-package'); ?>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Generate POI', 'wm-package'); ?></th>
					<td><?php submit_button(__('Generate POIs', 'wm-package'), 'primary', 'generate_poi'); ?>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php echo esc_html__('Delete POI', 'wm-package'); ?></th>
					<td><?php submit_button(__('Delete All POIs', 'wm-package'), 'delete', 'delete_poi', false, array('style' => 'background-color: #dc3232; border-color: #dc3232; color: #fff;')); ?>
						<p class="description" style="color: #dc3232; font-weight: bold;">
							⚠️ <?php echo esc_html__('Warning: This action will PERMANENTLY delete all POI posts. This operation cannot be undone.', 'wm-package'); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
<?php
}

if (isset($_POST['generate_track'])) {
	wm_save_options();
	add_action('admin_init', 'sync_tracks_action');
}

if (isset($_POST['generate_poi'])) {
	wm_save_options();
	add_action('admin_init', 'sync_pois_action');
}

// Delete actions are handled completely via AJAX, so no need to handle POST here

add_action('admin_init', 'wm_settings_init');
function wm_settings_init()
{
	register_setting('wm-settings', 'wm_shard', 'sanitize_text_field');
	register_setting('wm-settings', 'app_configuration_id', 'sanitize_text_field');
	register_setting('wm-settings', 'track_shortcode', 'sanitize_text_field');
	register_setting('wm-settings', 'poi_shortcode', 'sanitize_text_field');
	register_setting('wm-settings', 'track_url', 'sanitize_text_field');
	register_setting('wm-settings', 'poi_url', 'sanitize_text_field');
	register_setting('wm-settings', 'tracks_list', 'sanitize_text_field');
	register_setting('wm-settings', 'track_shortcode', 'sanitize_text_field');
	register_setting('wm-settings', 'poi_shortcode', 'sanitize_text_field');
	register_setting('wm-settings', 'taxonomy_track_shortcode', 'sanitize_text_field');
	register_setting('wm-settings', 'taxonomy_poi_shortcode', 'sanitize_text_field');
	register_setting('wm-settings', 'app_configuration_id', 'sanitize_text_field');
	register_setting('wm-settings', 'layer_api', 'sanitize_text_field');
	register_setting('wm-settings', 'poi_type_api', 'sanitize_text_field');
	register_setting('wm-settings', 'ios_app_url', 'sanitize_text_field');
	register_setting('wm-settings', 'android_app_url', 'sanitize_text_field');
	register_setting('wm-settings', 'website_url', 'sanitize_text_field');
	register_setting('wm-settings', 'featured_image_location', 'sanitize_text_field');
	// track_navigation fields are only shown if generateEdges is enabled in wm_default_config.json
	$config = function_exists('wm_get_default_config') ? wm_get_default_config() : false;
	$generate_edges_enabled = false;
	if ($config && isset($config['WORDPRESS']['generateEdges'])) {
		$generate_edges_enabled = (bool) $config['WORDPRESS']['generateEdges'];
	}
	if ($generate_edges_enabled) {
		// track_navigation_enabled is now controlled by wm_default_config.json (generateEdges), not WordPress options
		register_setting('wm-settings', 'track_navigation_layer_ids', 'sanitize_text_field');
	}
	// track_download_enabled is now controlled by wm_default_config.json, not WordPress options
}

function wm_admin_footer()
{
?>
	<style type="text/css">
		#sync-progress-modal {
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background-color: rgba(0, 0, 0, 0.75);
			z-index: 999999;
			display: none;
			align-items: center;
			justify-content: center;
		}

		#sync-progress-modal .modal-content {
			background: #fff;
			padding: 40px;
			border-radius: 8px;
			text-align: center;
			max-width: 450px;
			box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
			position: relative;
		}

		#sync-progress-modal .spinner-container {
			margin-bottom: 25px;
		}

		#sync-progress-modal .spinner-container img {
			width: 50px;
			height: 50px;
			margin: 0 auto;
			display: block;
		}

		#sync-progress-modal h2 {
			margin: 0 0 15px 0;
			font-size: 20px;
			color: #23282d;
			font-weight: 600;
		}

		#sync-progress-modal #sync-progress-message {
			margin: 0;
			color: #666;
			font-size: 14px;
			line-height: 1.6;
		}

		.wm-default-image-row {
			display: flex;
			align-items: center;
			gap: 10px;
			margin: 0 0 4px 0;
		}

		.wm-default-image-row .button {
			margin: 0;
		}

		.wm-default-image-row input[type="file"] {
			margin: 0;
		}
	</style>
	<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Localized strings
			var wmPackageStrings = {
				loading: '<?php echo esc_js(__('Loading...', 'wm-package')); ?>',
				processing: '<?php echo esc_js(__('Processing...', 'wm-package')); ?>',
				synchronizing: '<?php echo esc_js(__('Synchronizing...', 'wm-package')); ?>',
				deletingTracks: '<?php echo esc_js(__('Deleting Tracks...', 'wm-package')); ?>',
				deletingPois: '<?php echo esc_js(__('Deleting POIs...', 'wm-package')); ?>',
				doNotCloseSync: '<?php echo esc_js(__('Please do not close or reload this page while synchronization is in progress.', 'wm-package')); ?>',
				doNotCloseDelete: '<?php echo esc_js(__('Please do not close or reload this page while deletion is in progress.', 'wm-package')); ?>',
				warningSyncTracks: '<?php echo esc_js(__('⚠️ WARNING: You are about to sync/generate all Tracks. This may take some time and will update or create Track posts. Are you sure you want to proceed?', 'wm-package')); ?>',
				warningSyncPois: '<?php echo esc_js(__('⚠️ WARNING: You are about to sync/generate all POIs. This may take some time and will update or create POI posts. Are you sure you want to proceed?', 'wm-package')); ?>',
				warningDeleteTracks: '<?php echo esc_js(__('⚠️ WARNING: You are about to PERMANENTLY delete all Track posts. This operation cannot be undone. Are you sure you want to proceed?', 'wm-package')); ?>',
				warningDeletePois: '<?php echo esc_js(__('⚠️ WARNING: You are about to PERMANENTLY delete all POI posts. This operation cannot be undone. Are you sure you want to proceed?', 'wm-package')); ?>',
				confirmDeleteTracks: '<?php echo esc_js(__('Final confirmation: Delete ALL Tracks?', 'wm-package')); ?>',
				confirmDeletePois: '<?php echo esc_js(__('Final confirmation: Delete ALL POIs?', 'wm-package')); ?>',
				tracksSynced: '<?php echo esc_js(__('Tracks synchronized successfully!', 'wm-package')); ?>',
				poisSynced: '<?php echo esc_js(__('POIs synchronized successfully!', 'wm-package')); ?>',
				errorUnknown: '<?php echo esc_js(__('Unknown error occurred', 'wm-package')); ?>',
				errorServer: '<?php echo esc_js(__('Error communicating with the server', 'wm-package')); ?>',
				errorTimeout: '<?php echo esc_js(__('Request timeout. The operation may still be processing. Please check the results.', 'wm-package')); ?>',
				errorFormat: '<?php echo esc_js(__('Response format error, but operation may have completed. Please check the results.', 'wm-package')); ?>',
				errorRefresh: '<?php echo esc_js(__('Failed to refresh', 'wm-package')); ?>',
				errorRefreshConfig: '<?php echo esc_js(__('Failed to refresh config', 'wm-package')); ?>',
				shards: '<?php echo esc_js(__('shards', 'wm-package')); ?>',
				defaultImageSelectFile: '<?php echo esc_js(__('Please select a PNG file first.', 'wm-package')); ?>',
				defaultImageSuccess: '<?php echo esc_js(__('Default image updated successfully.', 'wm-package')); ?>',
				syncSessionExpired: '<?php echo esc_js(__('Synchronization session expired. Please start over.', 'wm-package')); ?>',
				syncStuckProcess: '<?php echo esc_js(__('Stuck process detected. Synchronization has been stopped. Please start over.', 'wm-package')); ?>',
				syncGlobalTimeout: '<?php echo esc_js(__('Global timeout reached (%d minutes). Synchronization has been stopped. Please start over.', 'wm-package')); ?>',
				syncTooManyErrors: '<?php echo esc_js(__('Too many consecutive errors (%d). Synchronization has been stopped to avoid an infinite loop.', 'wm-package')); ?>',
				syncCompleted: '<?php echo esc_js(__('Synchronization completed! Processed %d tracks.', 'wm-package')); ?>',
				syncBatchCompleted: '<?php echo esc_js(__('Batch completed: %d/%d tracks (%d%%)', 'wm-package')); ?>',
				syncProcessed: '<?php echo esc_js(__('Processed', 'wm-package')); ?>',
				syncSkipped: '<?php echo esc_js(__('Skipped', 'wm-package')); ?>',
				syncErrors: '<?php echo esc_js(__('Errors', 'wm-package')); ?>',
				syncTime: '<?php echo esc_js(__('Time', 'wm-package')); ?>',
				syncMinutes: '<?php echo esc_js(__('min', 'wm-package')); ?>',
				syncTracks: '<?php echo esc_js(__('tracks', 'wm-package')); ?>',
				syncStopConfirm: '<?php echo esc_js(__('Do you want to stop the synchronization?', 'wm-package')); ?>',
				syncStoppedByUser: '<?php echo esc_js(__('Synchronization stopped by user.', 'wm-package')); ?>',
				syncGlobalTimeoutReached: '<?php echo esc_js(__('Global timeout reached (30 minutes). Synchronization has been stopped for safety.', 'wm-package')); ?>',
				syncStuckDetected: '<?php echo esc_js(__('Stuck process detected (no progress for 5 minutes). Synchronization has been stopped.', 'wm-package')); ?>',
				syncTooManyErrorsStopped: '<?php echo esc_js(__('Too many consecutive errors. Synchronization has been stopped.', 'wm-package')); ?>',
				syncTooManyErrorsStoppedLoop: '<?php echo esc_js(__('Too many consecutive errors. Synchronization has been stopped to avoid an infinite loop.', 'wm-package')); ?>',
				syncAlreadyRunning: '<?php echo esc_js(__('A synchronization is already in progress. Do you want to stop it and start over?', 'wm-package')); ?>',
				stopSynchronization: '<?php echo esc_js(__('Stop Synchronization', 'wm-package')); ?>',
				syncProcessStoppedByServer: '<?php echo esc_js(__('Process stopped by server', 'wm-package')); ?>',
				deleteTracksCompleted: '<?php echo esc_js(__('Deletion completed! Deleted %d tracks.', 'wm-package')); ?>',
				deleteDeleted: '<?php echo esc_js(__('Deleted', 'wm-package')); ?>',
				deleteBatchMayProcessed: '<?php echo esc_js(__('The batch may have been processed. Check the results.', 'wm-package')); ?>',
				deleteAlreadyRunning: '<?php echo esc_js(__('A deletion is already in progress. Do you want to stop it and start over?', 'wm-package')); ?>',
				deleteSessionExpired: '<?php echo esc_js(__('Deletion session expired. Please start over.', 'wm-package')); ?>',
				deletePoisCompleted: '<?php echo esc_js(__('Deletion completed! Deleted %d POIs.', 'wm-package')); ?>',
				poisSyncedProgress: '<?php echo esc_js(__('POIs synchronized successfully!', 'wm-package')); ?>',
				apiPoiListInvalid: '<?php echo esc_js(__('API POI list invalid or unavailable.', 'wm-package')); ?>',
				noPoisProvided: '<?php echo esc_js(__('No POIs provided or invalid format.', 'wm-package')); ?>',
				syncPoisCompleted: '<?php echo esc_js(__('Synchronization completed! Processed %d POIs.', 'wm-package')); ?>',
				syncPoisBatchCompleted: '<?php echo esc_js(__('Batch completed: %d/%d POIs (%d%%)', 'wm-package')); ?>',
				preparingDeletion: '<?php echo esc_js(__('Preparing deletion...', 'wm-package')); ?>',
				continuingNextBatch: '<?php echo esc_js(__('Assuming batch was processed. Continuing with next batch...', 'wm-package')); ?>',
				tooManyTimeouts: '<?php echo esc_js(__('Too many consecutive timeouts. Process stopped.', 'wm-package')); ?>'
			};

			// Shards configuration dynamically loaded from wm-types/environment.ts via PHP
			var shardsConfig = <?php echo json_encode(wm_get_shards_config()); ?>;

			// Refresh shards button handler
			$('#refresh_shards').on('click', function() {
				var $btn = $(this);
				var originalText = $btn.text();
				$btn.prop('disabled', true).text('⏳ ' + wmPackageStrings.loading);

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'wm_refresh_shards'
					},
					dataType: 'json',
					success: function(response) {
						if (response.success) {
							shardsConfig = response.data.shards;
							// Update select options
							var $select = $('#wm_shard');
							var currentVal = $select.val();
							$select.empty();
							$.each(shardsConfig, function(name, data) {
								$select.append($('<option>', {
									value: name,
									text: name
								}));
							});
							// Restore selection if still exists
							if ($select.find('option[value="' + currentVal + '"]').length) {
								$select.val(currentVal);
							}
							$('#shards_count').text(response.data.count);
							updateApiUrls();
							alert('✅ ' + response.data.message + ' (' + response.data.count + ' ' + wmPackageStrings.shards + ')');
						} else {
							alert('❌ ' + (response.data.message || wmPackageStrings.errorRefresh));
						}
					},
					error: function() {
						alert('❌ ' + wmPackageStrings.errorServer);
					},
					complete: function() {
						$btn.prop('disabled', false).text(originalText);
					}
				});
			});

			// Refresh config API button handler
			$('#refresh_config').on('click', function() {
				var $btn = $(this);
				var originalText = $btn.text();
				$btn.prop('disabled', true).text('⏳ ' + wmPackageStrings.loading);

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'wm_refresh_config'
					},
					dataType: 'json',
					success: function(response) {
						if (response.success) {
							alert('✅ ' + response.data.message);
						} else {
							alert('❌ ' + (response.data && response.data.message ? response.data.message : wmPackageStrings.errorRefreshConfig));
						}
					},
					error: function() {
						alert('❌ ' + wmPackageStrings.errorRefreshConfig);
					},
					complete: function() {
						$btn.prop('disabled', false).text(originalText);
					}
				});
			});

			// Default image upload (AJAX to avoid nested form)
			$('#wm_replace_default_image_btn').on('click', function() {
				var $wrap = $('#wm-default-image-upload-wrap');
				var $fileInput = $('#wm_default_image_file');
				var file = $fileInput[0] && $fileInput[0].files && $fileInput[0].files[0];
				if (!file) {
					alert(wmPackageStrings.defaultImageSelectFile);
					return;
				}
				var formData = new FormData();
				formData.append('action', 'wm_upload_default_image');
				formData.append('wm_default_image_nonce', $wrap.data('nonce'));
				formData.append('default_image_file', file);
				var $btn = $(this);
				$btn.prop('disabled', true);
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					dataType: 'json',
					success: function(response) {
						if (response && response.success) {
							location.reload();
						} else {
							alert('❌ ' + (response.data && response.data.message ? response.data.message : wmPackageStrings.errorUnknown));
						}
					},
					error: function(xhr) {
						var msg = wmPackageStrings.errorServer;
						if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
							msg = xhr.responseJSON.data.message;
						}
						alert('❌ ' + msg);
					},
					complete: function() {
						$btn.prop('disabled', false);
					}
				});
			});

			// Check if shard is osm2cai-type (affects POI URL structure)
			function isOsm2caiShard(shard) {
				return shard.indexOf('osm2cai') === 0 || shard === 'local';
			}

			// Function to update API URLs based on shard and app_id
			function updateApiUrls() {
				var shard = $('#wm_shard').val();
				var appId = $('input[name="app_configuration_id"]').val() || '49';

				// Fallback to geohub if shard not found
				if (!shardsConfig[shard]) {
					shard = 'geohub';
				}

				var origin = shardsConfig[shard].origin.replace(/\/$/, '');
				var awsApi = shardsConfig[shard].awsApi.replace(/\/$/, '');

				var apiUrls = {
					tracksList: origin + '/api/app/webapp/' + appId + '/tracks_list',
					singleTrack: awsApi + '/tracks/',
					layer: origin + '/api/app/webapp/' + appId + '/layer/',
					poiType: origin + '/api/app/webapp/' + appId + '/taxonomies/poi_type/'
				};

				// Elasticsearch API URL - different patterns for different shards
				if (shard === 'geohub') {
					// Geohub uses a different domain for Elasticsearch
					apiUrls.elastic = 'https://elastic-json.webmapp.it/v2/search/';
				} else {
					// Other shards use /api/v2/elasticsearch
					apiUrls.elastic = origin + '/api/v2/elasticsearch';
				}

				// POI URL structure differs for osm2cai-type shards
				if (isOsm2caiShard(shard)) {
					apiUrls.poi = awsApi + '/' + appId + '/pois.geojson';
				} else {
					apiUrls.poi = awsApi + '/pois/' + appId + '.geojson';
				}

				// Update links and inputs
				$('.api-link-tracks-list').attr('href', apiUrls.tracksList);
				$('.api-url-tracks-list').text(apiUrls.tracksList);
				$('.api-input-tracks-list').val(apiUrls.tracksList);

				$('.api-link-layer').attr('href', apiUrls.layer);
				$('.api-url-layer').text(apiUrls.layer);
				$('.api-input-layer').val(apiUrls.layer);

				$('.api-link-track').attr('href', apiUrls.singleTrack);
				$('.api-url-track').text(apiUrls.singleTrack);
				$('.api-input-track').val(apiUrls.singleTrack);

				$('.api-link-poi').attr('href', apiUrls.poi);
				$('.api-url-poi').text(apiUrls.poi);
				$('.api-input-poi').val(apiUrls.poi);

				$('.api-link-poi-type').attr('href', apiUrls.poiType);
				$('.api-url-poi-type').text(apiUrls.poiType);
				$('.api-input-poi-type').val(apiUrls.poiType);

				$('.api-link-elastic').attr('href', apiUrls.elastic);
				$('.api-url-elastic').text(apiUrls.elastic);
				$('.api-input-elastic').val(apiUrls.elastic);

				// Config API: {awsApi}/{app_id}/config.json
				var configApiUrl = awsApi + '/' + appId + '/config.json';
				$('.api-link-config').attr('href', configApiUrl);
				$('.api-url-config').text(configApiUrl);
			}

			// Update APIs when shard or app_id changes
			$('#wm_shard, input[name="app_configuration_id"]').on('change keyup', updateApiUrls);

			// Function to show sync progress modal
			function showSyncModal(message, title) {
				if (message) {
					$('#sync-progress-message').html(message);
				}
				if (title) {
					$('#modal-title').text(title);
				} else {
					$('#modal-title').text(wmPackageStrings.processing);
				}
				// Reset progress bar
				$('#sync-progress-bar-fill').css('width', '0%');
				// Show stop button
				$('#stop-sync-btn').show();
				$('#sync-progress-modal').css('display', 'flex');
				// Prevent closing modal by clicking outside or pressing ESC
				$('#sync-progress-modal').on('click', function(e) {
					if (e.target === this) {
						e.preventDefault();
						e.stopPropagation();
					}
				});
				$(document).on('keydown.syncModal', function(e) {
					if (e.keyCode === 27) { // ESC key
						e.preventDefault();
						e.stopPropagation();
					}
				});
			}

			// Function to hide sync progress modal
			function hideSyncModal() {
				$('#sync-progress-modal').hide();
				$('#stop-sync-btn').hide();
				$(document).off('keydown.syncModal');
			}

			// Stop sync/delete button handler
			$('#stop-sync-btn').on('click', function() {
				if (confirm(wmPackageStrings.syncStopConfirm)) {
					// Stop all sync and delete processes
					syncTracksState.isRunning = false;
					deleteTracksState.isRunning = false;
					syncPoisState.isRunning = false;
					deletePoisState.isRunning = false;
					hideSyncModal();
					alert(wmPackageStrings.syncStoppedByUser);
				}
			});

			// Batch sync state tracking
			var syncTracksState = {
				startTime: null,
				lastUpdate: null,
				consecutiveErrors: 0,
				maxConsecutiveErrors: 3,
				maxTotalTime: 30 * 60 * 1000, // 30 minutes in milliseconds
				maxStuckTime: 5 * 60 * 1000, // 5 minutes without progress
				isRunning: false
			};

			// Batch sync function for tracks
			function syncTracksBatch(offset, batchSize) {
				batchSize = batchSize || 10;
				offset = offset || 0;

				// Initialize state on first batch
				if (offset === 0) {
					syncTracksState.startTime = Date.now();
					syncTracksState.lastUpdate = Date.now();
					syncTracksState.consecutiveErrors = 0;
					syncTracksState.isRunning = true;
				}

				// Check if process should be stopped
				var currentTime = Date.now();
				var totalTime = currentTime - syncTracksState.startTime;
				var timeSinceLastUpdate = currentTime - syncTracksState.lastUpdate;

				if (totalTime > syncTracksState.maxTotalTime) {
					syncTracksState.isRunning = false;
					hideSyncModal();
					alert('❌ ' + wmPackageStrings.syncGlobalTimeoutReached);
					return;
				}

				if (timeSinceLastUpdate > syncTracksState.maxStuckTime && offset > 0) {
					syncTracksState.isRunning = false;
					hideSyncModal();
					alert('❌ ' + wmPackageStrings.syncStuckDetected);
					return;
				}

				if (syncTracksState.consecutiveErrors >= syncTracksState.maxConsecutiveErrors) {
					syncTracksState.isRunning = false;
					hideSyncModal();
					alert('❌ ' + wmPackageStrings.syncTooManyErrorsStoppedLoop.replace('%d', syncTracksState.maxConsecutiveErrors));
					return;
				}

				if (!syncTracksState.isRunning) {
					return; // Process was stopped
				}

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'sync_tracks_action',
						offset: offset,
						batch_size: batchSize
					},
					dataType: 'json',
					timeout: 120000, // 2 minutes per batch
					success: function(response) {
						if (!syncTracksState.isRunning) {
							return; // Process was stopped
						}

						if (response && response.success) {
							var progress = response.data && response.data.progress;
							
							// Reset error counter on success
							syncTracksState.consecutiveErrors = 0;
							syncTracksState.lastUpdate = Date.now();
							
							if (progress) {
								// Check if process was stopped server-side
								if (progress.stopped) {
									syncTracksState.isRunning = false;
									hideSyncModal();
									var stopReason = progress.reason === 'too_many_errors' 
										? wmPackageStrings.syncTooManyErrorsStopped
										: wmPackageStrings.syncProcessStoppedByServer;
									alert('❌ ' + stopReason + '. ' + (response.data.message || ''));
									return;
								}

								// Update progress bar
								$('#sync-progress-bar-fill').css('width', progress.percent + '%');
								
								// Update modal with progress
								var progressText = progress.complete 
									? wmPackageStrings.tracksSynced + ' (' + progress.total + ' ' + wmPackageStrings.syncTracks + ')'
									: progress.current + '/' + progress.total + ' ' + wmPackageStrings.syncTracks + ' (' + progress.percent + '%)';
								
								var elapsedMinutes = Math.floor((Date.now() - syncTracksState.startTime) / 60000);
								var timeInfo = elapsedMinutes > 0 ? ' | ' + wmPackageStrings.syncTime + ': ' + elapsedMinutes + ' ' + wmPackageStrings.syncMinutes : '';
								
								// Use cumulative totals instead of batch values
								var totalProcessed = progress.total_processed !== undefined ? progress.total_processed : progress.processed;
								var totalSkipped = progress.total_skipped !== undefined ? progress.total_skipped : progress.skipped;
								var totalErrors = progress.total_errors !== undefined ? progress.total_errors : progress.errors;
								
								$('#sync-progress-message').html(
									progressText + '<br><small style="color: #666;">' + wmPackageStrings.syncProcessed + ': ' + totalProcessed + 
									' | ' + wmPackageStrings.syncSkipped + ': ' + totalSkipped + 
									' | ' + wmPackageStrings.syncErrors + ': ' + totalErrors + timeInfo + '</small>'
								);
								
								// Update title
								if (!progress.complete) {
									$('#modal-title').text(wmPackageStrings.synchronizing + ' (' + progress.percent + '%)');
								}
							}

							// If not complete, continue with next batch
							if (progress && !progress.complete && progress.next_offset !== null && syncTracksState.isRunning) {
								setTimeout(function() {
									if (syncTracksState.isRunning) {
										syncTracksBatch(progress.next_offset, batchSize);
									}
								}, 500); // Small delay between batches
							} else {
								// Complete!
								syncTracksState.isRunning = false;
								hideSyncModal();
								alert('✅ ' + (response.data && response.data.message ? response.data.message : wmPackageStrings.tracksSynced));
								location.reload();
							}
						} else {
							syncTracksState.consecutiveErrors++;
							if (syncTracksState.consecutiveErrors >= syncTracksState.maxConsecutiveErrors) {
								syncTracksState.isRunning = false;
								hideSyncModal();
								alert('❌ ' + wmPackageStrings.syncTooManyErrorsStopped);
							} else {
								// Retry after a delay
								setTimeout(function() {
									if (syncTracksState.isRunning) {
										syncTracksBatch(offset, batchSize);
									}
								}, 2000);
							}
						}
					},
					error: function(xhr, status, error) {
						syncTracksState.consecutiveErrors++;
						
						// Try to parse error response if available
						var errorMessage = wmPackageStrings.errorServer;
						var shouldStop = false;
						
						if (status === 'timeout') {
							errorMessage = wmPackageStrings.errorTimeout + ' Il batch potrebbe essere stato processato.';
							// Don't stop on timeout, might have succeeded
							syncTracksState.consecutiveErrors = Math.max(0, syncTracksState.consecutiveErrors - 1);
						} else if (xhr.responseJSON && xhr.responseJSON.data) {
							if (xhr.responseJSON.data.message) {
								errorMessage = xhr.responseJSON.data.message;
							}
							// Check if server stopped the process
							if (xhr.responseJSON.data.progress && xhr.responseJSON.data.progress.stopped) {
								shouldStop = true;
							}
						} else if (xhr.responseText) {
							try {
								var parsed = JSON.parse(xhr.responseText);
								if (parsed.data && parsed.data.message) {
									errorMessage = parsed.data.message;
								}
								if (parsed.data && parsed.data.progress && parsed.data.progress.stopped) {
									shouldStop = true;
								}
							} catch (e) {
								// If parsing fails, check if operation might have succeeded
								if (xhr.status === 200) {
									errorMessage = wmPackageStrings.errorFormat;
									syncTracksState.consecutiveErrors = Math.max(0, syncTracksState.consecutiveErrors - 1);
								}
							}
						}

						if (shouldStop || syncTracksState.consecutiveErrors >= syncTracksState.maxConsecutiveErrors) {
							syncTracksState.isRunning = false;
							hideSyncModal();
							alert('❌ ' + errorMessage + (shouldStop ? '' : ' ' + wmPackageStrings.syncTooManyErrorsStopped));
						} else {
							// Retry after a delay
							setTimeout(function() {
								if (syncTracksState.isRunning) {
									syncTracksBatch(offset, batchSize);
								}
							}, 2000);
						}
					}
				});
			}

			$('#generate_track').click(function(e) {
				e.preventDefault();
				
				// Check if sync is already running
				if (syncTracksState.isRunning) {
					if (!confirm(wmPackageStrings.syncAlreadyRunning)) {
						return false;
					}
					syncTracksState.isRunning = false;
				}
				
				if (!confirm(wmPackageStrings.warningSyncTracks)) {
					return false;
				}

				// Show modal with spinner
				showSyncModal(wmPackageStrings.doNotCloseSync, wmPackageStrings.synchronizing);
				
				// Start batch processing from the beginning
				syncTracksBatch(0, 10);
			});
			// Batch sync state tracking for POIs
			var syncPoisState = {
				startTime: null,
				lastUpdate: null,
				consecutiveErrors: 0,
				maxConsecutiveErrors: 3,
				maxTotalTime: 30 * 60 * 1000, // 30 minutes in milliseconds
				maxStuckTime: 5 * 60 * 1000, // 5 minutes without progress
				isRunning: false
			};

			// Batch sync function for POIs
			function syncPoisBatch(offset, batchSize) {
				batchSize = batchSize || 10;
				offset = offset || 0;

				// Initialize state on first batch
				if (offset === 0) {
					syncPoisState.startTime = Date.now();
					syncPoisState.lastUpdate = Date.now();
					syncPoisState.consecutiveErrors = 0;
					syncPoisState.isRunning = true;
				}

				// Check if process should be stopped
				var currentTime = Date.now();
				var totalTime = currentTime - syncPoisState.startTime;
				var timeSinceLastUpdate = currentTime - syncPoisState.lastUpdate;

				if (totalTime > syncPoisState.maxTotalTime) {
					syncPoisState.isRunning = false;
					hideSyncModal();
					alert('❌ ' + wmPackageStrings.syncGlobalTimeoutReached);
					return;
				}

				if (timeSinceLastUpdate > syncPoisState.maxStuckTime && offset > 0) {
					syncPoisState.isRunning = false;
					hideSyncModal();
					alert('❌ ' + wmPackageStrings.syncStuckDetected);
					return;
				}

				if (syncPoisState.consecutiveErrors >= syncPoisState.maxConsecutiveErrors) {
					syncPoisState.isRunning = false;
					hideSyncModal();
					alert('❌ ' + wmPackageStrings.syncTooManyErrorsStoppedLoop.replace('%d', syncPoisState.maxConsecutiveErrors));
					return;
				}

				if (!syncPoisState.isRunning) {
					return; // Process was stopped
				}

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'sync_pois_action',
						offset: offset,
						batch_size: batchSize
					},
					dataType: 'json',
					timeout: 120000, // 2 minutes per batch
					success: function(response) {
						if (!syncPoisState.isRunning) {
							return; // Process was stopped
						}

						if (response && response.success) {
							var progress = response.data && response.data.progress;
							
							// Reset error counter on success
							syncPoisState.consecutiveErrors = 0;
							syncPoisState.lastUpdate = Date.now();
							
							if (progress) {
								// Check if process was stopped server-side
								if (progress.stopped) {
									syncPoisState.isRunning = false;
									hideSyncModal();
									var stopReason = progress.reason === 'too_many_errors' 
										? wmPackageStrings.syncTooManyErrorsStopped
										: wmPackageStrings.syncProcessStoppedByServer;
									alert('❌ ' + stopReason + '. ' + (response.data.message || ''));
									return;
								}

								// Update progress bar
								$('#sync-progress-bar-fill').css('width', progress.percent + '%');
								
								// Update modal with progress
								var progressText = progress.complete 
									? wmPackageStrings.poisSynced + ' (' + progress.total + ' POI)'
									: progress.current + '/' + progress.total + ' POI (' + progress.percent + '%)';
								
								var elapsedMinutes = Math.floor((Date.now() - syncPoisState.startTime) / 60000);
								var timeInfo = elapsedMinutes > 0 ? ' | ' + wmPackageStrings.syncTime + ': ' + elapsedMinutes + ' ' + wmPackageStrings.syncMinutes : '';
								
								// Use cumulative totals instead of batch values
								var totalProcessed = progress.total_processed !== undefined ? progress.total_processed : progress.processed;
								var totalSkipped = progress.total_skipped !== undefined ? progress.total_skipped : progress.skipped;
								var totalErrors = progress.total_errors !== undefined ? progress.total_errors : progress.errors;
								
								$('#sync-progress-message').html(
									progressText + '<br><small style="color: #666;">' + wmPackageStrings.syncProcessed + ': ' + totalProcessed + 
									' | ' + wmPackageStrings.syncSkipped + ': ' + totalSkipped + 
									' | ' + wmPackageStrings.syncErrors + ': ' + totalErrors + timeInfo + '</small>'
								);
								
								// Update title
								if (!progress.complete) {
									$('#modal-title').text(wmPackageStrings.synchronizing + ' (' + progress.percent + '%)');
								}
							}

							// If not complete, continue with next batch
							if (progress && !progress.complete && progress.next_offset !== null && syncPoisState.isRunning) {
								setTimeout(function() {
									if (syncPoisState.isRunning) {
										syncPoisBatch(progress.next_offset, batchSize);
									}
								}, 500); // Small delay between batches
							} else {
								// Complete!
								syncPoisState.isRunning = false;
								hideSyncModal();
								alert('✅ ' + (response.data && response.data.message ? response.data.message : wmPackageStrings.poisSynced));
								location.reload();
							}
						} else {
							syncPoisState.consecutiveErrors++;
							if (syncPoisState.consecutiveErrors >= syncPoisState.maxConsecutiveErrors) {
								syncPoisState.isRunning = false;
								hideSyncModal();
								alert('❌ ' + wmPackageStrings.syncTooManyErrorsStopped);
							} else {
								// Retry after a delay
								setTimeout(function() {
									if (syncPoisState.isRunning) {
										syncPoisBatch(offset, batchSize);
									}
								}, 2000);
							}
						}
					},
					error: function(xhr, status, error) {
						syncPoisState.consecutiveErrors++;
						
						// Try to parse error response if available
						var errorMessage = wmPackageStrings.errorServer;
						var shouldStop = false;
						
						if (status === 'timeout') {
							errorMessage = wmPackageStrings.errorTimeout + ' ' + wmPackageStrings.deleteBatchMayProcessed;
							// Don't stop on timeout, might have succeeded
							syncPoisState.consecutiveErrors = Math.max(0, syncPoisState.consecutiveErrors - 1);
						} else if (xhr.responseJSON && xhr.responseJSON.data) {
							if (xhr.responseJSON.data.message) {
								errorMessage = xhr.responseJSON.data.message;
							}
							// Check if server stopped the process
							if (xhr.responseJSON.data.progress && xhr.responseJSON.data.progress.stopped) {
								shouldStop = true;
							}
						} else if (xhr.responseText) {
							try {
								var parsed = JSON.parse(xhr.responseText);
								if (parsed.data && parsed.data.message) {
									errorMessage = parsed.data.message;
								}
								if (parsed.data && parsed.data.progress && parsed.data.progress.stopped) {
									shouldStop = true;
								}
							} catch (e) {
								// If parsing fails, check if operation might have succeeded
								if (xhr.status === 200) {
									errorMessage = wmPackageStrings.errorFormat;
									syncPoisState.consecutiveErrors = Math.max(0, syncPoisState.consecutiveErrors - 1);
								}
							}
						}

						if (shouldStop || syncPoisState.consecutiveErrors >= syncPoisState.maxConsecutiveErrors) {
							syncPoisState.isRunning = false;
							hideSyncModal();
							alert('❌ ' + errorMessage + (shouldStop ? '' : ' ' + wmPackageStrings.syncTooManyErrorsStopped));
						} else {
							// Retry after a delay
							setTimeout(function() {
								if (syncPoisState.isRunning) {
									syncPoisBatch(offset, batchSize);
								}
							}, 2000);
						}
					}
				});
			}

			$('#generate_poi').click(function(e) {
				e.preventDefault();
				
				// Check if sync is already running
				if (syncPoisState.isRunning) {
					if (!confirm(wmPackageStrings.syncAlreadyRunning)) {
						return false;
					}
					syncPoisState.isRunning = false;
				}
				
				if (!confirm(wmPackageStrings.warningSyncPois)) {
					return false;
				}

				// Show modal with spinner
				showSyncModal(wmPackageStrings.doNotCloseSync, wmPackageStrings.synchronizing);
				
				// Start batch processing from the beginning
				syncPoisBatch(0, 10);
			});

			// Batch delete state tracking
			var deleteTracksState = {
				startTime: null,
				lastUpdate: null,
				isRunning: false,
				consecutiveTimeouts: 0,
				maxConsecutiveTimeouts: 2
			};

			// Batch delete function for tracks
			function deleteTracksBatch(offset, batchSize) {
				batchSize = batchSize || 20;
				offset = offset || 0;

				// Initialize state on first batch
				if (offset === 0) {
					deleteTracksState.startTime = Date.now();
					deleteTracksState.lastUpdate = Date.now();
					deleteTracksState.isRunning = true;
					deleteTracksState.consecutiveTimeouts = 0;
					deleteTracksState.maxConsecutiveTimeouts = 2; // Allow 2 consecutive timeouts before stopping
				}

				if (!deleteTracksState.isRunning) {
					return; // Process was stopped
				}

				// Show initial progress for first batch
				if (offset === 0) {
					$('#sync-progress-message').html(
						wmPackageStrings.deletingTracks + '<br><small style="color: #666;">' + wmPackageStrings.preparingDeletion + '</small>'
					);
				}

				// Longer timeout for first batch (needs to collect all tracks and translations)
				var timeoutDuration = (offset === 0) ? 300000 : 120000; // 5 minutes for first batch, 2 minutes for others

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'delete_all_tracks_action',
						nonce: '<?php echo wp_create_nonce("delete_all_tracks"); ?>',
						offset: offset,
						batch_size: batchSize
					},
					dataType: 'json',
					timeout: timeoutDuration,
					success: function(response) {
						if (!deleteTracksState.isRunning) {
							return; // Process was stopped
						}

						// Reset timeout counter on success
						deleteTracksState.consecutiveTimeouts = 0;
						deleteTracksState.lastUpdate = Date.now();

						if (response && response.success) {
							var progress = response.data && response.data.progress;
							
							if (progress) {
								// Update progress bar
								$('#sync-progress-bar-fill').css('width', progress.percent + '%');
								
								// Update modal with progress
								var progressText = progress.complete 
									? wmPackageStrings.deleteTracksCompleted.replace('%d', progress.total_deleted)
									: progress.current + '/' + progress.total + ' ' + wmPackageStrings.syncTracks + ' (' + progress.percent + '%)';
								
								var elapsedMinutes = Math.floor((Date.now() - deleteTracksState.startTime) / 60000);
								var timeInfo = elapsedMinutes > 0 ? ' | ' + wmPackageStrings.syncTime + ': ' + elapsedMinutes + ' ' + wmPackageStrings.syncMinutes : '';
								
								// Use cumulative totals instead of batch values
								var totalDeleted = progress.total_deleted !== undefined ? progress.total_deleted : progress.deleted;
								var totalErrors = progress.total_errors !== undefined ? progress.total_errors : progress.errors;
								
								$('#sync-progress-message').html(
									progressText + '<br><small style="color: #666;">' + wmPackageStrings.deleteDeleted + ': ' + totalDeleted + 
									' | ' + wmPackageStrings.syncErrors + ': ' + totalErrors + timeInfo + '</small>'
								);
								
								// Update title
								if (!progress.complete) {
									$('#modal-title').text(wmPackageStrings.deletingTracks + ' (' + progress.percent + '%)');
								}
							}

							// If not complete, continue with next batch
							if (progress && !progress.complete && progress.next_offset !== null && deleteTracksState.isRunning) {
								setTimeout(function() {
									if (deleteTracksState.isRunning) {
										deleteTracksBatch(progress.next_offset, batchSize);
									}
								}, 500); // Small delay between batches
							} else {
								// Complete!
								deleteTracksState.isRunning = false;
								hideSyncModal();
								alert('✅ ' + (response.data && response.data.message ? response.data.message : wmPackageStrings.deleteTracksCompleted.replace('%d', progress.total_deleted)));
								location.reload();
							}
						} else {
							deleteTracksState.isRunning = false;
							hideSyncModal();
							alert('❌ Error: ' + (response && response.data && response.data.message ? response.data.message : wmPackageStrings.errorUnknown));
						}
					},
					error: function(xhr, status, error) {
						// Try to parse error response if available
						var errorMessage = wmPackageStrings.errorServer;
						var shouldContinue = false;
						
						if (status === 'timeout') {
							deleteTracksState.consecutiveTimeouts++;
							
							// If too many consecutive timeouts, stop
							if (deleteTracksState.consecutiveTimeouts >= deleteTracksState.maxConsecutiveTimeouts) {
								deleteTracksState.isRunning = false;
								hideSyncModal();
								alert('❌ ' + wmPackageStrings.errorTimeout + ' ' + wmPackageStrings.tooManyTimeouts);
								return;
							}
							
							// On timeout, assume batch was processed and continue
							// This handles cases where the server processed the batch but response was slow
							errorMessage = wmPackageStrings.errorTimeout + ' ' + wmPackageStrings.continuingNextBatch;
							shouldContinue = true;
							
							// Update progress message to show we're continuing
							$('#sync-progress-message').html(
								$('#sync-progress-message').html() + '<br><small style="color: #ff9800;">⚠️ ' + errorMessage + '</small>'
							);
							
							// Calculate next offset (assume batch was processed)
							var nextOffset = offset + batchSize;
							
							// Continue with next batch after a short delay
							if (deleteTracksState.isRunning) {
								setTimeout(function() {
									if (deleteTracksState.isRunning) {
										deleteTracksBatch(nextOffset, batchSize);
									}
								}, 1000);
								return; // Don't show error alert, continue processing
							}
						} else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
							errorMessage = xhr.responseJSON.data.message;
						} else if (xhr.responseText) {
							try {
								var parsed = JSON.parse(xhr.responseText);
								if (parsed.data && parsed.data.message) {
									errorMessage = parsed.data.message;
								}
							} catch (e) {
								if (xhr.status === 200) {
									errorMessage = wmPackageStrings.errorFormat;
									shouldContinue = true;
								}
							}
						}
						
						// Only stop and show error if we shouldn't continue
						if (!shouldContinue) {
							deleteTracksState.isRunning = false;
							hideSyncModal();
							alert('❌ ' + errorMessage);
						}
					}
				});
			}

			$('#delete_track').click(function(e) {
				e.preventDefault();
				
				// Check if delete is already running
				if (deleteTracksState.isRunning) {
					if (!confirm(wmPackageStrings.deleteAlreadyRunning)) {
						return false;
					}
					deleteTracksState.isRunning = false;
				}
				
				if (!confirm(wmPackageStrings.warningDeleteTracks)) {
					return false;
				}
				if (!confirm(wmPackageStrings.confirmDeleteTracks)) {
					return false;
				}

				// Show modal with spinner
				showSyncModal(wmPackageStrings.doNotCloseDelete, wmPackageStrings.deletingTracks);
				
				// Start batch processing from the beginning
				deleteTracksBatch(0, 20);
			});

			// Batch delete state tracking for POIs
			var deletePoisState = {
				startTime: null,
				lastUpdate: null,
				isRunning: false,
				consecutiveTimeouts: 0,
				maxConsecutiveTimeouts: 2
			};

			// Batch delete function for POIs
			function deletePoisBatch(offset, batchSize) {
				batchSize = batchSize || 20;
				offset = offset || 0;

				// Initialize state on first batch
				if (offset === 0) {
					deletePoisState.startTime = Date.now();
					deletePoisState.lastUpdate = Date.now();
					deletePoisState.isRunning = true;
					deletePoisState.consecutiveTimeouts = 0;
					deletePoisState.maxConsecutiveTimeouts = 2; // Allow 2 consecutive timeouts before stopping
				}

				if (!deletePoisState.isRunning) {
					return; // Process was stopped
				}

				// Show initial progress for first batch
				if (offset === 0) {
					$('#sync-progress-message').html(
						wmPackageStrings.deletingPois + '<br><small style="color: #666;">' + wmPackageStrings.preparingDeletion + '</small>'
					);
				}

				// Longer timeout for first batch (needs to collect all POIs and translations)
				var timeoutDuration = (offset === 0) ? 300000 : 120000; // 5 minutes for first batch, 2 minutes for others

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'delete_all_pois_action',
						nonce: '<?php echo wp_create_nonce("delete_all_pois"); ?>',
						offset: offset,
						batch_size: batchSize
					},
					dataType: 'json',
					timeout: timeoutDuration,
					success: function(response) {
						if (!deletePoisState.isRunning) {
							return; // Process was stopped
						}

						// Reset timeout counter on success
						deletePoisState.consecutiveTimeouts = 0;
						deletePoisState.lastUpdate = Date.now();

						if (response && response.success) {
							var progress = response.data && response.data.progress;
							
							if (progress) {
								// Update progress bar
								$('#sync-progress-bar-fill').css('width', progress.percent + '%');
								
								// Update modal with progress
								var progressText = progress.complete 
									? wmPackageStrings.deletePoisCompleted.replace('%d', progress.total_deleted)
									: progress.current + '/' + progress.total + ' POI (' + progress.percent + '%)';
								
								var elapsedMinutes = Math.floor((Date.now() - deletePoisState.startTime) / 60000);
								var timeInfo = elapsedMinutes > 0 ? ' | ' + wmPackageStrings.syncTime + ': ' + elapsedMinutes + ' ' + wmPackageStrings.syncMinutes : '';
								
								// Use cumulative totals instead of batch values
								var totalDeleted = progress.total_deleted !== undefined ? progress.total_deleted : progress.deleted;
								var totalErrors = progress.total_errors !== undefined ? progress.total_errors : progress.errors;
								
								$('#sync-progress-message').html(
									progressText + '<br><small style="color: #666;">' + wmPackageStrings.deleteDeleted + ': ' + totalDeleted + 
									' | ' + wmPackageStrings.syncErrors + ': ' + totalErrors + timeInfo + '</small>'
								);
								
								// Update title
								if (!progress.complete) {
									$('#modal-title').text(wmPackageStrings.deletingPois + ' (' + progress.percent + '%)');
								}
							}

							// If not complete, continue with next batch
							if (progress && !progress.complete && progress.next_offset !== null && deletePoisState.isRunning) {
								setTimeout(function() {
									if (deletePoisState.isRunning) {
										deletePoisBatch(progress.next_offset, batchSize);
									}
								}, 500); // Small delay between batches
							} else {
								// Complete!
								deletePoisState.isRunning = false;
								hideSyncModal();
								alert('✅ ' + (response.data && response.data.message ? response.data.message : wmPackageStrings.deletePoisCompleted.replace('%d', progress.total_deleted)));
								location.reload();
							}
						} else {
							deletePoisState.isRunning = false;
							hideSyncModal();
							alert('❌ Error: ' + (response && response.data && response.data.message ? response.data.message : wmPackageStrings.errorUnknown));
						}
					},
					error: function(xhr, status, error) {
						// Try to parse error response if available
						var errorMessage = wmPackageStrings.errorServer;
						var shouldContinue = false;
						
						if (status === 'timeout') {
							deletePoisState.consecutiveTimeouts++;
							
							// If too many consecutive timeouts, stop
							if (deletePoisState.consecutiveTimeouts >= deletePoisState.maxConsecutiveTimeouts) {
								deletePoisState.isRunning = false;
								hideSyncModal();
								alert('❌ ' + wmPackageStrings.errorTimeout + ' ' + wmPackageStrings.tooManyTimeouts);
								return;
							}
							
							// On timeout, assume batch was processed and continue
							// This handles cases where the server processed the batch but response was slow
							errorMessage = wmPackageStrings.errorTimeout + ' ' + wmPackageStrings.continuingNextBatch;
							shouldContinue = true;
							
							// Update progress message to show we're continuing
							$('#sync-progress-message').html(
								$('#sync-progress-message').html() + '<br><small style="color: #ff9800;">⚠️ ' + errorMessage + '</small>'
							);
							
							// Calculate next offset (assume batch was processed)
							var nextOffset = offset + batchSize;
							
							// Continue with next batch after a short delay
							if (deletePoisState.isRunning) {
								setTimeout(function() {
									if (deletePoisState.isRunning) {
										deletePoisBatch(nextOffset, batchSize);
									}
								}, 1000);
								return; // Don't show error alert, continue processing
							}
						} else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
							errorMessage = xhr.responseJSON.data.message;
						} else if (xhr.responseText) {
							try {
								var parsed = JSON.parse(xhr.responseText);
								if (parsed.data && parsed.data.message) {
									errorMessage = parsed.data.message;
								}
							} catch (e) {
								if (xhr.status === 200) {
									errorMessage = wmPackageStrings.errorFormat;
									shouldContinue = true;
								}
							}
						}
						
						// Only stop and show error if we shouldn't continue
						if (!shouldContinue) {
							deletePoisState.isRunning = false;
							hideSyncModal();
							alert('❌ ' + errorMessage);
						}
					}
				});
			}

			$('#delete_poi').click(function(e) {
				e.preventDefault();
				
				// Check if delete is already running
				if (deletePoisState.isRunning) {
					if (!confirm(wmPackageStrings.deleteAlreadyRunning)) {
						return false;
					}
					deletePoisState.isRunning = false;
				}
				
				if (!confirm(wmPackageStrings.warningDeletePois)) {
					return false;
				}
				if (!confirm(wmPackageStrings.confirmDeletePois)) {
					return false;
				}

				// Show modal with spinner
				showSyncModal(wmPackageStrings.doNotCloseDelete, wmPackageStrings.deletingPois);
				
				// Start batch processing from the beginning
				deletePoisBatch(0, 20);
			});
		});
	</script>
<?php
}
add_action('admin_footer-toplevel_page_wm-settings', 'wm_admin_footer');

function wm_save_options()
{
	$shard = isset($_POST['wm_shard']) ? sanitize_text_field($_POST['wm_shard']) : 'geohub';
	$app_id = isset($_POST['app_configuration_id']) ? sanitize_text_field($_POST['app_configuration_id']) : '49';

	// Save the shard
	update_option('wm_shard', $shard);

	// Build API URLs based on selected shard
	$api_urls = wm_get_api_urls($shard, $app_id);

	update_option('track_url', $api_urls['single_track_api']);
	update_option('poi_url', $api_urls['poi_api']);
	update_option('tracks_list', $api_urls['tracks_list_api']);
	if (isset($_POST['poi_list'])) {
		update_option('poi_list', sanitize_text_field($_POST['poi_list']));
	}
	update_option('track_shortcode', sanitize_text_field($_POST['track_shortcode']));
	update_option('poi_shortcode', sanitize_text_field($_POST['poi_shortcode']));
	update_option('taxonomy_track_shortcode', sanitize_text_field($_POST['taxonomy_track_shortcode']));
	update_option('taxonomy_poi_shortcode', sanitize_text_field($_POST['taxonomy_poi_shortcode']));
	update_option('app_configuration_id', $app_id);

	// Update the ID field in wm_default_config.json dynamically
	if (function_exists('wm_update_config_id')) {
		wm_update_config_id($app_id);
	}

	// Check API config at save: if API returns WORDPRESS/APP use that, otherwise use local (no merge)
	if (function_exists('wm_get_config_api_url') && function_exists('wm_fetch_remote_config') && defined('WM_CONFIG_SOURCE_OPTION') && defined('WM_CACHED_API_CONFIG_OPTION')) {
		$config_url = wm_get_config_api_url($shard, $app_id);
		if ($config_url) {
			$remote = wm_fetch_remote_config($config_url);
			if (is_array($remote)) {
				update_option(WM_CACHED_API_CONFIG_OPTION, $remote);
				update_option(WM_CONFIG_SOURCE_OPTION, 'api');
			} else {
				update_option(WM_CONFIG_SOURCE_OPTION, 'local');
			}
		} else {
			update_option(WM_CONFIG_SOURCE_OPTION, 'local');
		}
	}

	update_option('layer_api', $api_urls['layer_api']);
	update_option('poi_type_api', $api_urls['poi_type_api']);
	update_option('elastic_api', $api_urls['elastic_api']);
	// iOS/Android URLs: use config values when present in wm_default_config.json (APP.iosStore / APP.androidStore)
	$links_config = function_exists('wm_get_default_config') ? wm_get_default_config() : false;
	$ios_from_config = !empty($links_config['APP']['iosStore']) ? $links_config['APP']['iosStore'] : null;
	$android_from_config = !empty($links_config['APP']['androidStore']) ? $links_config['APP']['androidStore'] : null;
	update_option('ios_app_url', $ios_from_config !== null ? $ios_from_config : sanitize_text_field($_POST['ios_app_url'] ?? ''));
	update_option('android_app_url', $android_from_config !== null ? $android_from_config : sanitize_text_field($_POST['android_app_url'] ?? ''));
	update_option('website_url', sanitize_text_field($_POST['website_url']));
	update_option('featured_image_location', sanitize_text_field($_POST['featured_image_location'] ?? 'content'));

	// Only save track_navigation_layer_ids if generateEdges is enabled in config JSON
	// track_navigation_enabled is now controlled by wm_default_config.json (generateEdges), not WordPress options
	$config = function_exists('wm_get_default_config') ? wm_get_default_config() : false;
	$generate_edges_enabled = false;
	if ($config && isset($config['WORDPRESS']['generateEdges'])) {
		$generate_edges_enabled = (bool) $config['WORDPRESS']['generateEdges'];
	}
	if ($generate_edges_enabled) {
		update_option('track_navigation_layer_ids', sanitize_text_field($_POST['track_navigation_layer_ids'] ?? ''));
	}

	// track_download_enabled is now controlled by wm_default_config.json, not WordPress options
}
