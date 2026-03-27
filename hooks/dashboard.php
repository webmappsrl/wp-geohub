<?php

function wm_admin_dashboard_widget()
{
    wp_add_dashboard_widget(
        'wm_dashboard_widget',
        __('WM Package', 'wm-package'),
        'wm_dashboard_widget_content'
    );
}
add_action('wp_dashboard_setup', 'wm_admin_dashboard_widget');

function wm_dashboard_widget_content()
{
    $pois = 0;
    $tracks = 0;
    $current_pois = 0;
    $current_tracks = 0;

    // API Data
    $pois_list = get_option('poi_url');
    $tracks_list = get_option('tracks_list');
    if (!empty($pois_list)) {
        $pois = wp_remote_get($pois_list);
        $pois = json_decode(wp_remote_retrieve_body($pois), true);
        $pois = $pois["features"];
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
    $adminPageURL = admin_url('admin.php?page=wm-settings');
?>
    <div class="wrap">
        <p><?php echo esc_html(__('Source POIs number:', 'wm-package')); ?> <strong><?php echo count($pois) ?></strong></p>
        <p><?php echo esc_html(__('Source Tracks number:', 'wm-package')); ?> <strong><?php echo count($tracks) ?></strong></p>
        </br>
        <p><?php echo esc_html(__('Current POIs number:', 'wm-package')); ?> <strong><?php echo $current_pois ?></strong> (<?php echo esc_html(__('published', 'wm-package')); ?>)</p>
        <p><?php echo esc_html(__('Current Tracks number:', 'wm-package')); ?> <strong><?php echo $current_tracks ?></strong> (<?php echo esc_html(__('published', 'wm-package')); ?>)</p>
        </br>
        <p><?php echo esc_html(__('Manage Import and Sync:', 'wm-package')); ?></p>
        <a href="<?php echo esc_url($adminPageURL) ?>" class="button button-primary"><?php echo esc_html(__('Go to WM Settings', 'wm-package')); ?></a>
    </div>

<?php
}
?>