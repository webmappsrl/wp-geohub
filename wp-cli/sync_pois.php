<?php
if (defined('WP_CLI') && WP_CLI) {
    class Sync_Pois_Command {
        /**
         * Synchronizes pois.
         *
         * ## EXAMPLES
         *
         *     wp sync_pois
         *
         * @when after_wp_load
         */
        public function __invoke($args, $assoc_args) {
            // This is where you add your code to sync pois.
            // For now, we'll just update the poi_url option as an example.
            // Ideally, you would replace this with your actual poi synchronization logic.
            if (defined('WP_CLI') && WP_CLI) {
                sync_pois_action();                       
                $poi_url = get_option('poi_url'); 
                WP_CLI::success("Synchronizing pois from $poi_url.");
                // Add your synchronization logic here
            } else {
                WP_CLI::error("No poi URL set.");
            }
        }
    }

    WP_CLI::add_command('sync_pois', 'Sync_Pois_Command');
}
