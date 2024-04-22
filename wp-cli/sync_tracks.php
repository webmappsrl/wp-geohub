<?php

if (defined('WP_CLI') && WP_CLI) {
    class Sync_Tracks_Command
    {
        /**
         * Synchronizes tracks.
         *
         * ## EXAMPLES
         *
         *     wp sync_tracks
         *
         * @when after_wp_load
         */
        public function __invoke($args, $assoc_args)
        {
            if (defined('WP_CLI') && WP_CLI) {
                sync_tracks_action();
                $track_url = get_option('track_url');
                WP_CLI::success("Synchronizing tracks from $track_url.");
            } else {
                WP_CLI::error("No track URL set.");
            }
        }
    }

    WP_CLI::add_command('sync_tracks', 'Sync_Tracks_Command');
}
