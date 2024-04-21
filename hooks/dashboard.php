<?php

function admin_dashboard_widget()
{
    wp_add_dashboard_widget(
        'geohub_dashboard_widget',
        'Geohub',
        'geohub_dashboard_widget_content'
    );
}
add_action('wp_dashboard_setup', 'admin_dashboard_widget');

function geohub_dashboard_widget_content()
{
    $pois = 0;
    $tracks = 0;
    $current_pois = 0;
    $current_tracks = 0;

    // Geohub
    $pois_list = get_option('poi_list');
    $tracks_list = get_option('tracks_list');
    if (!empty($pois_list)) {
        $pois = wp_remote_get($pois_list);
        $pois = json_decode(wp_remote_retrieve_body($pois), true);
    }
    if (!empty($tracks_list)) {
        $tracks = wp_remote_get($tracks_list);
        $tracks = json_decode(wp_remote_retrieve_body($tracks), true);
    }

    // Wordpress
    $args_track = array(
        'post_type' => 'track', // Replace 'track' with your custom post type name
        'post_status' => 'publish', // Only count published posts
        'posts_per_page' => -1 // Retrieve all posts
    );
    $track_query = new WP_Query($args_track);
    $current_tracks = $track_query->found_posts;
    $args_poi = array(
        'post_type' => 'poi', // Replace 'track' with your custom post type name
        'post_status' => 'publish', // Only count published posts
        'posts_per_page' => -1 // Retrieve all posts
    );
    $poi_query = new WP_Query($args_poi);
    $current_pois = $poi_query->found_posts;

    // Admin url
    $adminPageURL = admin_url('admin.php?page=geohub-settings');
    ?>
    <div class="wrap">
        <p>Geohub POIs number: <strong><?php echo count($pois) ?></strong></p>
        <p>Geohub Tracks number: <strong><?php echo count($tracks) ?></strong></p>
        </br>
        <p>Current POIs number: <strong><?php echo $current_pois ?></strong></p>
        <p>Current Tracks number: <strong><?php echo $current_tracks ?></strong></p>
        </br>
        <p>Manage Import and Sync:</p>
        <a href="<?php echo esc_url($adminPageURL) ?>" class="button button-primary">Go to GeoHub Settings</a>
    </div>

<?php
}
?>