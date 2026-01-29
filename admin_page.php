<?php

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
				<p id="sync-progress-message">
					<?php echo esc_html__('Please do not close or reload this page while the operation is in progress.', 'wm-package'); ?>
				</p>
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
					<th scope="row"><?php echo esc_html__('Website URL', 'wm-package'); ?></th>
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

			<?php
			// Check config JSON values to determine which fields to show
			$config = function_exists('wm_get_default_config') ? wm_get_default_config() : false;
			$generate_edges_enabled = false;
			$download_track_enabled = false;

			if ($config) {
				if (isset($config['WORDPRESS']['generate_edges'])) {
					$generate_edges_enabled = (bool) $config['WORDPRESS']['generate_edges'];
				}
				if (isset($config['WORDPRESS']['download_track_enable'])) {
					$download_track_enabled = (bool) $config['WORDPRESS']['download_track_enable'];
				}
			}

			// Only show "Track Configuration" section if at least one field is enabled
			if ($generate_edges_enabled || $download_track_enabled) : ?>
				<h2><?php echo esc_html__('Track Configuration:', 'wm-package'); ?></h2>
				<table class="form-table" style="margin-left: 30px;">
					<?php
					// Only show navigation fields if generate_edges is enabled in config JSON
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
	// track_navigation fields are only shown if generate_edges is enabled in wm_default_config.json
	$config = function_exists('wm_get_default_config') ? wm_get_default_config() : false;
	$generate_edges_enabled = false;
	if ($config && isset($config['WORDPRESS']['generate_edges'])) {
		$generate_edges_enabled = (bool) $config['WORDPRESS']['generate_edges'];
	}
	if ($generate_edges_enabled) {
		// track_navigation_enabled is now controlled by wm_default_config.json (generate_edges), not WordPress options
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
				warningSyncTracks: '<?php echo esc_js(__('⚠️ WARNING: You are about to sync/generate all Tracks. This may take some time and will update or create Track posts.\n\nAre you sure you want to proceed?', 'wm-package')); ?>',
				warningSyncPois: '<?php echo esc_js(__('⚠️ WARNING: You are about to sync/generate all POIs. This may take some time and will update or create POI posts.\n\nAre you sure you want to proceed?', 'wm-package')); ?>',
				warningDeleteTracks: '<?php echo esc_js(__('⚠️ WARNING: You are about to PERMANENTLY delete all Track posts. This operation cannot be undone.\n\nAre you sure you want to proceed?', 'wm-package')); ?>',
				warningDeletePois: '<?php echo esc_js(__('⚠️ WARNING: You are about to PERMANENTLY delete all POI posts. This operation cannot be undone.\n\nAre you sure you want to proceed?', 'wm-package')); ?>',
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
				defaultImageSuccess: '<?php echo esc_js(__('Default image updated successfully.', 'wm-package')); ?>'
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
					$('#sync-progress-message').text(message);
				}
				if (title) {
					$('#modal-title').text(title);
				} else {
					$('#modal-title').text(wmPackageStrings.processing);
				}
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
				$(document).off('keydown.syncModal');
			}

			$('#generate_track').click(function(e) {
				e.preventDefault();
				if (!confirm(wmPackageStrings.warningSyncTracks)) {
					return false;
				}

				// Show modal with spinner
				showSyncModal(wmPackageStrings.doNotCloseSync, wmPackageStrings.synchronizing);

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'sync_tracks_action'
					},
					dataType: 'json',
					timeout: 300000, // 5 minutes timeout
					success: function(response) {
						hideSyncModal();
						if (response && response.success) {
							alert('✅ ' + (response.data && response.data.message ? response.data.message : wmPackageStrings.tracksSynced));
							location.reload();
						} else {
							alert('❌ Error: ' + (response && response.data && response.data.message ? response.data.message : wmPackageStrings.errorUnknown));
						}
					},
					error: function(xhr, status, error) {
						hideSyncModal();
						// Try to parse error response if available
						var errorMessage = wmPackageStrings.errorServer;
						if (status === 'timeout') {
							errorMessage = wmPackageStrings.errorTimeout;
						} else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
							errorMessage = xhr.responseJSON.data.message;
						} else if (xhr.responseText) {
							try {
								var parsed = JSON.parse(xhr.responseText);
								if (parsed.data && parsed.data.message) {
									errorMessage = parsed.data.message;
								}
							} catch (e) {
								// If parsing fails, check if operation might have succeeded
								if (xhr.status === 200) {
									errorMessage = wmPackageStrings.errorFormat;
								}
							}
						}
						alert('❌ ' + errorMessage);
					}
				});
			});
			$('#generate_poi').click(function(e) {
				e.preventDefault();
				if (!confirm(wmPackageStrings.warningSyncPois)) {
					return false;
				}

				// Show modal with spinner
				showSyncModal(wmPackageStrings.doNotCloseSync, wmPackageStrings.synchronizing);

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'sync_pois_action'
					},
					dataType: 'json',
					timeout: 300000, // 5 minutes timeout
					success: function(response) {
						hideSyncModal();
						if (response && response.success) {
							alert('✅ ' + (response.data && response.data.message ? response.data.message : wmPackageStrings.poisSynced));
							location.reload();
						} else {
							alert('❌ Error: ' + (response && response.data && response.data.message ? response.data.message : wmPackageStrings.errorUnknown));
						}
					},
					error: function(xhr, status, error) {
						hideSyncModal();
						// Try to parse error response if available
						var errorMessage = wmPackageStrings.errorServer;
						if (status === 'timeout') {
							errorMessage = wmPackageStrings.errorTimeout;
						} else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
							errorMessage = xhr.responseJSON.data.message;
						} else if (xhr.responseText) {
							try {
								var parsed = JSON.parse(xhr.responseText);
								if (parsed.data && parsed.data.message) {
									errorMessage = parsed.data.message;
								}
							} catch (e) {
								// If parsing fails, check if operation might have succeeded
								if (xhr.status === 200) {
									errorMessage = wmPackageStrings.errorFormat;
								}
							}
						}
						alert('❌ ' + errorMessage);
					}
				});
			});

			$('#delete_track').click(function(e) {
				e.preventDefault();
				if (!confirm(wmPackageStrings.warningDeleteTracks)) {
					return false;
				}
				if (!confirm(wmPackageStrings.confirmDeleteTracks)) {
					return false;
				}

				// Show modal with spinner
				showSyncModal(wmPackageStrings.doNotCloseDelete, wmPackageStrings.deletingTracks);

				$.post(ajaxurl, {
					action: 'delete_all_tracks_action',
					nonce: '<?php echo wp_create_nonce("delete_all_tracks"); ?>'
				}, function(response) {
					hideSyncModal();
					if (response.success) {
						alert('✅ ' + response.data.message);
						location.reload();
					} else {
						alert('❌ Error: ' + (response.data.message || wmPackageStrings.errorUnknown));
					}
				}).fail(function() {
					hideSyncModal();
					alert('❌ ' + wmPackageStrings.errorServer);
				});
			});

			$('#delete_poi').click(function(e) {
				e.preventDefault();
				if (!confirm(wmPackageStrings.warningDeletePois)) {
					return false;
				}
				if (!confirm(wmPackageStrings.confirmDeletePois)) {
					return false;
				}

				// Show modal with spinner
				showSyncModal(wmPackageStrings.doNotCloseDelete, wmPackageStrings.deletingPois);

				$.post(ajaxurl, {
					action: 'delete_all_pois_action',
					nonce: '<?php echo wp_create_nonce("delete_all_pois"); ?>'
				}, function(response) {
					hideSyncModal();
					if (response.success) {
						alert('✅ ' + response.data.message);
						location.reload();
					} else {
						alert('❌ Error: ' + (response.data.message || wmPackageStrings.errorUnknown));
					}
				}).fail(function() {
					hideSyncModal();
					alert('❌ ' + wmPackageStrings.errorServer);
				});
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

	// Only save track_navigation_layer_ids if generate_edges is enabled in config JSON
	// track_navigation_enabled is now controlled by wm_default_config.json (generate_edges), not WordPress options
	$config = function_exists('wm_get_default_config') ? wm_get_default_config() : false;
	$generate_edges_enabled = false;
	if ($config && isset($config['WORDPRESS']['generate_edges'])) {
		$generate_edges_enabled = (bool) $config['WORDPRESS']['generate_edges'];
	}
	if ($generate_edges_enabled) {
		update_option('track_navigation_layer_ids', sanitize_text_field($_POST['track_navigation_layer_ids'] ?? ''));
	}

	// track_download_enabled is now controlled by wm_default_config.json, not WordPress options
}
