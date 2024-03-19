use Exception;
<?php
function sync_tracks_action()
{
    $acf_plugin = 'advanced-custom-fields/acf.php';
    if (!is_plugin_active($acf_plugin)) {
        $error_message = "ERROR: The ACF plugin is not installed or activated. This plugin requires ACF to function correctly.";
        set_transient('geohub_transient_error_message', $error_message, 60);
        return new WP_Error('invalid_api', 'The ACF plugin is not installed or activated. This plugin requires ACF to function correctly.');
    }

    $track_url = get_option('track_url');
    $tracks_list = get_option('tracks_list');
    $track_shortcode = get_option('track_shortcode');
    if (!empty($track_url) && !empty($tracks_list) && !empty($track_shortcode)) {

        $tracks = wp_remote_get($tracks_list);
        if (is_wp_error($tracks)) {
            set_transient('geohub_sync_tracks_notification', 'API track list non valida o non disponibile.', 60);
            return new WP_Error('invalid_api', 'API track list non valida o non disponibile.');
        }
        $tracks = json_decode(wp_remote_retrieve_body($tracks), true);
        if (empty($tracks) || !is_array($tracks)) {
            set_transient('geohub_sync_tracks_notification', 'Nessun track fornito o formato non valido.', 60);
            return new WP_Error('invalid_input', 'Nessun track fornito o formato non valido.');
        }
        foreach ($tracks as $geohub_id => $updated_at) {
            $post_id = null;
            $track_shortcode_final = '';
            $post_title = '';
            $post_slug = '';

            $existing_posts = get_posts([
                'post_type' => 'track',
                'meta_query' => [
                    [
                        'key' => 'geohub_track_id',
                        'value' => $geohub_id,
                    ],
                ],
                'numberposts' => 1,
            ]);

            if ($existing_posts) {
                $post_id = $existing_posts[0]->ID;
                $existing_post_modified_time = strtotime(get_post_modified_time('Y-m-d H:i:s', false, $post_id, true));
                $new_updated_time = strtotime($updated_at);
            }

            if ($existing_posts && $new_updated_time < $existing_post_modified_time) {
                continue;
            }

            $response = wp_remote_get($track_url.$geohub_id);
            if (is_wp_error($response)) {
                continue;
            }
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (empty($data)) {
                continue;
            }

            $post_title = $data['properties']['name']['it'] ?? 'Track senza titolo';
            $post_slug = sanitize_title($post_title);

            if ($existing_posts && $new_updated_time > $existing_post_modified_time) {
                wp_update_post([
                    'ID'         => $post_id,
                    'post_title' => $post_title,
                    'post_name'  => $post_slug,
                ]);
            } else {
                $track_shortcode_final = str_replace('$1', $geohub_id, $track_shortcode);
                $post = sanitize_post([
                    'post_title'   => $post_title,
                    'post_name'    => $post_slug, // Impostazione dello slug
                    'post_content' => $track_shortcode_final, // Aggiungi contenuto se necessario
                    'post_status'  => 'publish',
                    'post_type'    => 'track',
                ]);
                $post_id = wp_insert_post($post);
                if (!is_wp_error($post_id)) {
                    update_field('geohub_group_track_geohub_id', $geohub_id, $post_id);
                }
            }
        }
    }
    delete_transient('geohub_transient_warning_message');
    set_transient('geohub_transient_success_message', 'Greate! Tracks synchronized successfully.', 60);
}
// add_action('wp_ajax_sync_tracks_action', 'sync_tracks_action');