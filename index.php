<?php
/*
Plugin Name: WP GeoHub
Plugin URI: https://github.com/webmappsrl/wp-geohub
Description: Plugin to sync tracks from GeoHub to WordPress and display them in a map and in a list.
Version: 1.0
Author: Pedram Katanchi
Author URI: http://webmapp.it
License: GPL2
*/

require_once( ABSPATH . "wp-includes/pluggable.php" );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once dirname( __FILE__ ) . '/wp-cli/sync_tracks.php';
}
include_once('post_types/Geohub_RegisterTrackPostType.php');
include_once('custom_fields/Geohub_TrackCustomFields.php');
include_once('sync_track_action.php');
include_once('admin_page.php');
