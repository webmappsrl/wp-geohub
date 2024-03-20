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
    ?>
<div class="wrap">
	<h1>Geohub Settings</h1>
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
				<td><input type="text" size="5" name="app_configuration_id"
						value="<?php echo esc_attr(get_option('app_configuration_id')) ?: '49'; ?>"
						placeholder="49" />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">Tracks list API</th>
				<td><input type="text" size="50" name="tracks_list"
						value="<?php echo esc_attr(get_option('tracks_list')) ?: 'https://geohub.webmapp.it/api/app/webapp/49/tracks_list'; ?>"
						placeholder="https://geohub.webmapp.it/api/app/webapp/49/tracks_list" />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">Single Track API</th>
				<td><input type="text" size="50" name="track_url"
						value="<?php echo esc_attr(get_option('track_url')) ?: 'https://geohub.webmapp.it/api/ec/track/'; ?>"
						placeholder="https://geohub.webmapp.it/api/ec/track/" />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">POIs list API</th>
				<td><input type="text" size="50" name="poi_list"
						value="<?php echo esc_attr(get_option('poi_list')) ?: 'https://geohub.webmapp.it/api/app/webapp/49/pois_list'; ?>"
						placeholder="https://geohub.webmapp.it/api/app/webapp/49/pois_list" />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">Single POI API</th>
				<td><input type="text" size="50" name="poi_url"
						value="<?php echo esc_attr(get_option('poi_url')) ?: 'https://geohub.webmapp.it/api/ec/poi/'; ?>"
						placeholder="https://geohub.webmapp.it/api/ec/poi/" />
				</td>
			</tr>
		</table>
		<h2>Short codes:</h2>
		<table class="form-table" style="margin-left: 30px;">
			<tr valign="top">
				<th scope="row">Track page:</th>
				<td><input type="text" size="50" name="track_shortcode"
						value="<?php echo esc_attr(get_option('track_shortcode')) ?: "[wm_single_track track_id='$1' activity='$2']"; ?>"
						placeholder="[wm_single_track track_id='$1' activity='$2']" />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">POI page:</th>
				<td><input type="text" size="50" name="poi_shortcode"
						value="<?php echo esc_attr(get_option('poi_shortcode')) ?: "[wm_single_poi poi_id='$1']"; ?>"
						placeholder="[wm_single_track track_id='$1']" />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">Layer taxonomy page:</th>
				<td><input type="text" size="50" name="layer_taxonomy_shortcode"
						value="<?php echo esc_attr(get_option('layer_taxonomy_shortcode')) ?: "[wm_single_layer activity='id']"; ?>"
						placeholder="[wm_single_layer layer='id']" />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">Taxonomy Track page:</th>
				<td><input type="text" size="50" name="taxonomy_track_shortcode"
						value="<?php echo esc_attr(get_option('taxonomy_track_shortcode')) ?: "[wm_grid_track activity='id']"; ?>"
						placeholder="[wm_grid_track activity='id']" />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">Taxonomy Poi page:</th>
				<td><input type="text" size="50" name="taxonomy_poi_shortcode"
						value="<?php echo esc_attr(get_option('taxonomy_poi_shortcode')) ?: "[wm_grid_poi poi_type='id']"; ?>"
						placeholder="[wm_grid_poi poi_type='id']" />
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
	add_action( 'admin_init', 'sync_tracks_action' );
}

if (isset($_POST['generate_poi'])) {
	save_geohub_options();
	add_action( 'admin_init', 'sync_pois_action' );
}

add_action('admin_init', 'geohub_settings_init');
function geohub_settings_init()
{
    register_setting('geohub-settings', 'track_url', 'sanitize_text_field');
    register_setting('geohub-settings', 'poi_url', 'sanitize_text_field');
    register_setting('geohub-settings', 'tracks_list', 'sanitize_text_field');
    register_setting('geohub-settings', 'poi_list', 'sanitize_text_field');
    register_setting('geohub-settings', 'track_shortcode', 'sanitize_text_field');
    register_setting('geohub-settings', 'poi_shortcode', 'sanitize_text_field');
    register_setting('geohub-settings', 'layer_taxonomy_shortcode', 'sanitize_text_field');
    register_setting('geohub-settings', 'taxonomy_track_shortcode', 'sanitize_text_field');
    register_setting('geohub-settings', 'taxonomy_poi_shortcode', 'sanitize_text_field');
    register_setting('geohub-settings', 'app_configuration_id', 'sanitize_text_field');
}

function wp_geohub_footer()
{
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#generate_track').click(function() {
				window.scrollTo(0, 0);
                $('#spinner').css('display', 'flex');
                $.post(ajaxurl, { action: 'sync_tracks_action' }, function(response) {
                    $('#spinner').hide();
                });
            });
            $('#generate_poi').click(function() {
				window.scrollTo(0, 0);
                $('#spinner').css('display', 'flex');
                $.post(ajaxurl, { action: 'sync_pois_action' }, function(response) {
                    $('#spinner').hide();
                });
            });
        });
    </script>
    <?php
}
add_action('admin_footer-toplevel_page_geohub-settings', 'wp_geohub_footer');

function save_geohub_options() {
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
}