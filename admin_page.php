<?php

add_action('admin_menu', 'geohub_add_admin_menu');
function geohub_add_admin_menu()
{
    add_menu_page(
        'Geohub Settings',     // Page title
        'Geohub',              // Menu title
        'manage_options',         // Capability
        'geohub-settings',     // Menu slug
        'geohub_settings_page' // Function to display the page
    );
}

function geohub_settings_page()
{
    ?>
    <div class="wrap">
        <h1>Geohub Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('geohub-settings'); ?>
            <?php do_settings_sections('geohub-settings'); ?>
            <h2>APIs:</h2>
            <table class="form-table" style="margin-left: 30px;">
                <tr valign="top">
                    <th scope="row">APP configuration API</th>
                    <td><input type="text" size="50" name="app_configuration_api" value="<?php echo esc_attr(get_option('app_configuration_api')) ?: 'https://geohub.webmapp.it/api/app/webmapp/49/config.json'; ?>" placeholder="https://geohub.webmapp.it/api/app/webmapp/49/config.json"/>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Tracks list API</th>
                    <td><input type="text" size="50" name="tracks_list" value="<?php echo esc_attr(get_option('tracks_list')) ?: 'http://127.0.0.1:8000/api/app/webapp/49/tracks_list'; ?>" placeholder="http://127.0.0.1:8000/api/app/webapp/49/tracks_list" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Single Track API</th>
                    <td><input type="text" size="50" name="track_url" value="<?php echo esc_attr(get_option('track_url')) ?: 'https://geohub.webmapp.it/api/ec/track/'; ?>" placeholder="https://geohub.webmapp.it/api/ec/track/"/>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">POIs list API</th>
                    <td><input type="text" size="50" name="poi_list" value="<?php echo esc_attr(get_option('poi_list')) ?: 'http://127.0.0.1:8000/api/app/webapp/49/pois_list'; ?>" placeholder="http://127.0.0.1:8000/api/app/webapp/49/pois_list"/>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Single POI API</th>
                    <td><input type="text" size="50" name="poi_url" value="<?php echo esc_attr(get_option('poi_url')) ?: 'https://geohub.webmapp.it/api/ec/poi/'; ?>" placeholder="https://geohub.webmapp.it/api/ec/poi/"/>
                    </td>
                </tr>
            </table>
            <h2>Short codes:</h2>
            <table class="form-table" style="margin-left: 30px;">
                <tr valign="top">
                    <th scope="row">Track page:</th>
                    <td><input type="text" size="50" name="track_shortcode" value="<?php echo esc_attr(get_option('track_shortcode')) ?: "[wm_single_track track_id='$1' activity='$2']"; ?>" placeholder="[wm_single_track track_id='$1' activity='$2']"/>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">POI page:</th>
                    <td><input type="text" size="50" name="poi_shortcode" value="<?php echo esc_attr(get_option('poi_shortcode')) ?: "[wm_single_poi poi_id='$1']"; ?>" placeholder="[wm_single_track track_id='$1']"/>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Taxonomy page:</th>
                    <td><input type="text" size="50" name="taxonomy_shortcode" value="<?php echo esc_attr(get_option('taxonomy_shortcode')) ?: "[wm_grid_track activity='$1']"; ?>" placeholder="[wm_grid_track activity='$1']"/>
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
    // Handle the generation of the track.
    // Update the option with the new value from the form, or perform the action.
    update_option('track_url', sanitize_text_field($_POST['track_url']));
    // Add your code to generate the track here.
    sync_tracks_action();
}

if (isset($_POST['generate_poi'])) {
    // Handle the generation of the POI.
    update_option('poi_url', sanitize_text_field($_POST['poi_url']));
    // Add your code to generate the POI here.
}

add_action('admin_init', 'geohub_settings_init');
function geohub_settings_init() {
    register_setting('geohub-settings', 'track_url', 'sanitize_text_field');
    register_setting('geohub-settings', 'poi_url', 'sanitize_text_field');
    register_setting('geohub-settings', 'tracks_list', 'sanitize_text_field');
    register_setting('geohub-settings', 'poi_list', 'sanitize_text_field');
    register_setting('geohub-settings', 'track_shortcode', 'sanitize_text_field');
    register_setting('geohub-settings', 'poi_shortcode', 'sanitize_text_field');
    register_setting('geohub-settings', 'taxonomy_shortcode', 'sanitize_text_field');
    register_setting('geohub-settings', 'app_configuration_api', 'sanitize_text_field');
}

add_action('admin_notices', 'geohub_show_sync_tracks_notification');
function geohub_show_sync_tracks_notification() {
    // Check if our transient is set and if so, display the notification message.
    if ($message = get_transient('geohub_sync_tracks_notification')) {
        echo "<div class='notice notice-success is-dismissible'><p>$message</p></div>";
        // Once we've displayed the message, delete the transient so it's not shown again.
        delete_transient('geohub_sync_tracks_notification');
    }
}