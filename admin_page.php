<?php

add_action('admin_menu', 'geohub_add_admin_menu');
function geohub_add_admin_menu()
{
	add_menu_page(
		'Geohub Settings',     // Page title
		'Geohub',              // Menu title
		'manage_options',         // Capability
		'geohub-settings',     // Menu slug
		'geohub_settings_page', // Function to display the page
		plugins_url('assets/menu-icon.png', __FILE__) // Icon URL, dynamically getting the correct path
	);
}

function geohub_settings_page()
{
	$app_id = get_option('app_configuration_id');
	if (!is_numeric($app_id) || empty($app_id)) {
		$app_id = '49';
	}


	$aws_api = "https://wmfe.s3.eu-central-1.amazonaws.com/geohub";

	$tracks_list_api = "https://geohub.webmapp.it/api/app/webapp/{$app_id}/tracks_list";
	$single_track_api = "{$aws_api}/tracks/";
	$poi_api = "{$aws_api}/pois/{$app_id}.geojson";
	$layer_api = "https://geohub.webmapp.it/api/app/webapp/{$app_id}/layer/";
	$poi_type_api = "https://geohub.webmapp.it/api/app/webapp/{$app_id}/taxonomies/poi_type/";

?>
	<div class="wrap">
		<h1 style="display: flex; align-items: center;">
			<img src="<?php echo plugins_url('assets/menu-icon.png', __FILE__); ?>" alt="Geohub Icon" style="margin-right: 10px; height: 30px; width: 30px;" />
			Geohub Settings
		</h1>
		<div style="display: none;" id="spinner" class="notice notice-warning">
			<img src="<?php echo admin_url('images/spinner.gif'); ?>" style="margin:8px;">
			<p>Do not close this page while it is loading</p>
		</div>
		<form method="post" action="options.php">
			<?php settings_fields('geohub-settings'); ?>
			<?php do_settings_sections('geohub-settings'); ?>
			<h2>APIs:</h2>
			<table class="form-table" style="margin-left: 30px;">
				<tr valign="top">
					<th scope="row">APP ID</th>
					<td>
						<input type="text" size="5" name="app_configuration_id"
							value="<?php echo esc_attr($app_id); ?>" placeholder="49" />
						<p class="description">
							This APP ID refers to the ID of the app on GeoHub.
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">Tracks list API</th>
					<td>
						<input type="text" size="50" name="tracks_list"
							value="<?php echo esc_attr($tracks_list_api); ?>" readonly />
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">Layer API</th>
					<td>
						<input type="text" size="50" name="layer_api"
							value="<?php echo esc_attr($layer_api); ?>" readonly />
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">Single Track API</th>
					<td>
						<input type="text" size="50" name="track_url"
							value="<?php echo esc_attr($single_track_api); ?>" readonly />
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">POI API</th>
					<td>
						<input type="text" size="50" name="poi_url"
							value="<?php echo esc_attr($poi_api); ?>" readonly />
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">POI Type API</th>
					<td>
						<input type="text" size="50" name="poi_type_api"
							value="<?php echo esc_attr($poi_type_api); ?>" readonly />
					</td>
				</tr>
			</table>
			<h2> Links: </h2>
			<table class="form-table" style="margin-left: 30px;">
				<tr valign="top">
					<th scope="row">iOS App URL</th>
					<td>
						<input type="text" size="50" value="<?php echo esc_attr(get_option('ios_app_url')) ?>" name="ios_app_url"/>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">Android App URL</th>
					<td>
						<input type="text" size="50"  value="<?php echo esc_attr(get_option('android_app_url')) ?>" name="android_app_url"/>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">Website URL</th>
					<td>
						<input type="text" size="50" value="<?php echo esc_attr(get_option('website_url')) ?>" name="website_url" />
					</td>
				</tr>
			</table>
			<h2>Short codes:</h2>
			<table class="form-table" style="margin-left: 30px;">
				<tr valign="top">
					<th scope="row">Track page:</th>
					<td>
						<input type="text" size="50" name="track_shortcode"
							value="<?php echo esc_attr(get_option('track_shortcode')) ?: "[wm_single_track track_id='$1']"; ?>"
							readonly />
						<p class="description">Shortcode to display a single track. <br>
							<strong>Parameters:</strong><br>
							<strong>track_id</strong>: The ID of the track. <br>
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">POI page:</th>
					<td>
						<input type="text" size="50" name="poi_shortcode"
							value="<?php echo esc_attr(get_option('poi_shortcode')) ?: "[wm_single_poi poi_id='$1';]"; ?>"
							readonly />
						<p class="description">Shortcode to display a single Point of Interest (POI). <br>
							<strong>Parameters:</strong><br>
							<strong>poi_id</strong>: The ID of the POI.
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">Layer taxonomy page:</th>
					<td>
						<input type="text" size="50" name="layer_taxonomy_shortcode"
							value="<?php echo esc_attr(get_option('layer_taxonomy_shortcode')) ?: "[wm_single_layer layer='id']"; ?>"
							readonly />
						<p class="description">Shortcode to display a single layer taxonomy. <br>
							<strong>Parameters:</strong><br>
							<strong>layer</strong>: The ID of the layer.
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">Taxonomy Track page:</th>
					<td>
						<input type="text" size="50" name="taxonomy_track_shortcode"
							value="<?php echo esc_attr(get_option('taxonomy_track_shortcode')) ?: "[wm_grid_track activity='id']"; ?>"
							readonly />
						<p class="description">Shortcode to display a grid of tracks based on taxonomy. <br>
							<strong>Parameters:</strong><br>
							<strong>layer_id</strong>: ID of the single layer. <br>
							<strong>layer_ids</strong>: List of layer IDs (comma-separated). <br>
							<strong>quantity</strong>: Maximum number of tracks to display. <br>
							<strong>random</strong>: Display tracks in random order (true/false).
						</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">Taxonomy Poi page:</th>
					<td>
						<input type="text" size="50" name="taxonomy_poi_shortcode"
							value="<?php echo esc_attr(get_option('taxonomy_poi_shortcode')) ?: "[wm_grid_poi poi_type='id']"; ?>"
							readonly />
						<p class="description">Shortcode to display a grid of POIs based on taxonomy. <br>
							<strong>Parameters:</strong><br>
							<strong>poi_type_id</strong>: ID of the single POI type. <br>
							<strong>poi_type_ids</strong>: List of POI type IDs (comma-separated). <br>
							<strong>quantity</strong>: Maximum number of POIs to display. <br>
							<strong>random</strong>: Display POIs in random order (true/false).
						</p>
					</td>
				</tr>
			</table>


			<h2>Import and Sync:</h2>
			<table class="form-table" style="margin-left: 30px;">
				<tr valign="top">
					<th scope="row">Generate TRACK</th>
					<td><?php submit_button('Generate Tracks', 'primary', 'generate_track'); ?>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">Generate POI</th>
					<td><?php submit_button('Generate POIs', 'primary', 'generate_poi'); ?>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
<?php
}

if (isset($_POST['generate_track'])) {
	save_geohub_options();
	add_action('admin_init', 'sync_tracks_action');
}

if (isset($_POST['generate_poi'])) {
	save_geohub_options();
	add_action('admin_init', 'sync_pois_action');
}

add_action('admin_init', 'geohub_settings_init');
function geohub_settings_init()
{
	register_setting('geohub-settings', 'app_configuration_id', 'sanitize_text_field');
	register_setting('geohub-settings', 'track_shortcode', 'sanitize_text_field');
	register_setting('geohub-settings', 'poi_shortcode', 'sanitize_text_field');
	register_setting('geohub-settings', 'track_url', 'sanitize_text_field');
	register_setting('geohub-settings', 'poi_url', 'sanitize_text_field');
	register_setting('geohub-settings', 'tracks_list', 'sanitize_text_field');
	register_setting('geohub-settings', 'track_shortcode', 'sanitize_text_field');
	register_setting('geohub-settings', 'poi_shortcode', 'sanitize_text_field');
	register_setting('geohub-settings', 'layer_taxonomy_shortcode', 'sanitize_text_field');
	register_setting('geohub-settings', 'taxonomy_track_shortcode', 'sanitize_text_field');
	register_setting('geohub-settings', 'taxonomy_poi_shortcode', 'sanitize_text_field');
	register_setting('geohub-settings', 'app_configuration_id', 'sanitize_text_field');
	register_setting('geohub-settings', 'layer_api', 'sanitize_text_field');
	register_setting('geohub-settings', 'poi_type_api', 'sanitize_text_field');
	register_setting('geohub-settings', 'ios_app_url', 'sanitize_text_field');
	register_setting('geohub-settings', 'android_app_url', 'sanitize_text_field');
	register_setting('geohub-settings', 'website_url', 'sanitize_text_field');
}

function wp_geohub_footer()
{
?>
	<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#generate_track').click(function() {
				window.scrollTo(0, 0);
				$('#spinner').css('display', 'flex');
				$.post(ajaxurl, {
					action: 'sync_tracks_action'
				}, function(response) {
					$('#spinner').hide();
				});
			});
			$('#generate_poi').click(function() {
				window.scrollTo(0, 0);
				$('#spinner').css('display', 'flex');
				$.post(ajaxurl, {
					action: 'sync_pois_action'
				}, function(response) {
					$('#spinner').hide();
				});
			});
		});
	</script>
<?php
}
add_action('admin_footer-toplevel_page_geohub-settings', 'wp_geohub_footer');

function save_geohub_options()
{
	update_option('track_url', sanitize_text_field($_POST['track_url']));
	update_option('poi_url', sanitize_text_field($_POST['poi_url']));
	update_option('tracks_list', sanitize_text_field($_POST['tracks_list']));
	update_option('poi_list', sanitize_text_field($_POST['poi_list']));
	update_option('track_shortcode', sanitize_text_field($_POST['track_shortcode']));
	update_option('poi_shortcode', sanitize_text_field($_POST['poi_shortcode']));
	update_option('layer_taxonomy_shortcode', sanitize_text_field($_POST['layer_taxonomy_shortcode']));
	update_option('taxonomy_track_shortcode', sanitize_text_field($_POST['taxonomy_track_shortcode']));
	update_option('taxonomy_poi_shortcode', sanitize_text_field($_POST['taxonomy_poi_shortcode']));
	update_option('app_configuration_id', sanitize_text_field($_POST['app_configuration_id']));
	update_option('layer_api', sanitize_text_field($_POST['layer_api']));
	update_option('poi_type_api', sanitize_text_field($_POST['poi_type_api']));
	update_option('ios_app_url', sanitize_text_field($_POST['ios_app_url']));
	update_option('android_app_url', sanitize_text_field($_POST['android_app_url']));
	update_option('website_url', sanitize_text_field($_POST['website_url']));
}
