<?php

if (defined('WP_CLI') && WP_CLI) {
    class Sync_Pois_Command
    {
        /**
         * Synchronizes pois.
         *
         * ## EXAMPLES
         *
         *     wp sync_pois
         *
         * @when after_wp_load
         */
        public function __invoke($args, $assoc_args)
        {
            if (defined('WP_CLI') && WP_CLI) {
                sync_pois_action();
                $poi_url = get_option('poi_url');
                WP_CLI::success("Synchronizing pois from $poi_url.");
            } else {
                WP_CLI::error("No poi URL set.");
            }
        }
    }

    WP_CLI::add_command('sync_pois', 'Sync_Pois_Command');
}
