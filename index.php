<?php
/*
Plugin Name: WM Package
Plugin URI: https://github.com/webmappsrl/wm-package
Description: Plugin to sync tracks and POIs from external APIs to WordPress and display them via shortcodes.
Version: 2.0
Author: Webmapp
Author URI: https://webmapp.it
License: GPL2
*/

require_once(ABSPATH . "wp-includes/pluggable.php");

if (defined('WP_CLI') && WP_CLI) {
    require_once dirname(__FILE__) . '/wp-cli/sync_tracks.php';
    require_once dirname(__FILE__) . '/wp-cli/sync_pois.php';
}

include_once('functions/functions.php');
include_once('functions/controls.php');
include_once('functions/imports.php');
include_once('hooks/transient.php');
include_once('hooks/dashboard.php');
include_once('post_types/RegisterTrackPostType.php');
include_once('post_types/RegisterPoiPostType.php');
include_once('restrict-admin-capabilities.php');
include_once('custom_fields/TrackCustomFields.php');
include_once('custom_fields/PoiCustomFields.php');
include_once('actions/sync_track_action.php');
include_once('actions/sync_poi_action.php');
include_once('actions/delete_tracks_action.php');
include_once('actions/delete_pois_action.php');
include_once('admin_page.php');
