<?php
/**
 * Restrict WM Package and CPTs (track, poi) to admin-only in the dashboard.
 * Frontend remains public: CPTs are still visible and readable on the site.
 *
 * @package wm-package
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Map track and poi capabilities to manage_options so only admins see them in the dashboard.
 *
 * @param string[] $caps    Required capabilities.
 * @param string   $cap     Capability being checked.
 * @param int      $user_id User ID.
 * @param array    $args    Additional arguments.
 * @return string[] Mapped capabilities.
 */
function wm_package_map_meta_cap_admin_only($caps, $cap, $user_id, $args)
{
    $track_caps = array(
        'edit_track',
        'read_track',
        'delete_track',
        'edit_tracks',
        'edit_others_tracks',
        'publish_tracks',
        'read_private_tracks',
    );
    $poi_caps = array(
        'edit_poi',
        'read_poi',
        'delete_poi',
        'edit_pois',
        'edit_others_pois',
        'publish_pois',
        'read_private_pois',
    );
    $restricted = array_merge($track_caps, $poi_caps);
    if (in_array($cap, $restricted, true)) {
        return array('manage_options');
    }
    return $caps;
}
add_filter('map_meta_cap', 'wm_package_map_meta_cap_admin_only', 10, 4);
