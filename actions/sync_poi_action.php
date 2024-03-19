<?php

function sync_pois_action()
{
    $acf_plugin = 'advanced-custom-fields/acf.php';
    if (!is_plugin_active($acf_plugin)) {
        $error_message = "ERROR: The ACF plugin is not installed or activated. This plugin requires ACF to function correctly.";
        set_transient('geohub_transient_error_message', $error_message, 60);
        return new WP_Error('invalid_api', 'The ACF plugin is not installed or activated. This plugin requires ACF to function correctly.');
    }

    $poi_url = get_option('poi_url');
    $pois_list = get_option('poi_list');
    $poi_shortcode = get_option('poi_shortcode');
    if (!empty($poi_url) && !empty($pois_list) && !empty($poi_shortcode)) {

        $pois = wp_remote_get($pois_list);
        if (is_wp_error($pois)) {
            set_transient('geohub_sync_pois_notification', 'API poi list non valida o non disponibile.', 60);
            return new WP_Error('invalid_api', 'API poi list non valida o non disponibile.');
        }
        $pois = json_decode(wp_remote_retrieve_body($pois), true);
        if (empty($pois) || !is_array($pois)) {
            set_transient('geohub_sync_pois_notification', 'Nessun poi fornito o formato non valido.', 60);
            return new WP_Error('invalid_input', 'Nessun poi fornito o formato non valido.');
        }

        foreach ($pois as $geohub_id => $updated_at) {
            $post_id = null;
            $track_shortcode_final = '';
            $post_title = '';
            $post_slug = '';
            
            $existing_posts = get_posts([
                'post_type' => 'poi',
                'meta_query' => [
                    [
                        'key' => 'geohub_poi_id',
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

            $response = wp_remote_get($poi_url.$geohub_id);
            if (is_wp_error($response)) {
                continue;
            }
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (empty($data)) {
                continue;
            }

            $post_title = $data['properties']['name']['it'] ?? 'poi senza titolo';
            $post_slug = sanitize_title($post_title);

            if ($existing_posts && $new_updated_time > $existing_post_modified_time) {
                wp_update_post([
                    'ID'         => $post_id,
                    'post_title' => $post_title,
                    'post_name'  => $post_slug,
                ]);
            } else {
                $poi_shortcode = str_replace('$1', $geohub_id, $poi_shortcode);
                try {
                    $post = sanitize_post([
                        'post_title'   => $post_title,
                        'post_name'    => $post_slug, // Impostazione dello slug
                        'post_content' => $poi_shortcode, // Aggiungi contenuto se necessario
                        'post_status'  => 'publish',
                        'post_type'    => 'poi',
                    ]);
                    $post_id = wp_insert_post($post);
                } catch (Exception $e) {
                    error_log('Errore durante l\'inserimento del poi: ' . $e->getMessage());
                    continue;
                }
                if (!is_wp_error($post_id)) {
                    update_field('geohub_group_poi_geohub_id', $geohub_id, $post_id);
                }
            }
        }
    } 
    delete_transient('geohub_transient_warning_message');
    set_transient('geohub_transient_success_message', 'Greate! Pois synchronized successfully.', 60);
}
add_action('wp_ajax_sync_pois_action', 'sync_pois_action');
