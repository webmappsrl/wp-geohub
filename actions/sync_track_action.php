<?php
function sync_tracks_action()
{
    if (is_wp_error(required_plugins())) {
        if (wp_doing_ajax()) {
            wp_send_json_error(['message' => 'Required plugins are not installed or activated']);
        }
        return;
    }

    $track_url = get_option('track_url');
    $tracks_list = get_option('tracks_list');
    $track_shortcode = get_option('track_shortcode');
    $default_lang = apply_filters('wpml_default_language', NULL);

    // Check if required options are set
    if (empty($track_url) || empty($tracks_list) || empty($track_shortcode)) {
        $error_message = 'Required configuration options are missing. Please check your settings.';
        set_transient('geohub_sync_tracks_notification', $error_message, 60);
        if (wp_doing_ajax()) {
            wp_send_json_error(['message' => $error_message]);
        }
        return new WP_Error('missing_config', $error_message);
    }

    if (!empty($track_url) && !empty($tracks_list) && !empty($track_shortcode)) {

        $tracks = wp_remote_get($tracks_list);
        if (is_wp_error($tracks)) {
            $error_message = 'API track list non valida o non disponibile.';
            set_transient('geohub_sync_tracks_notification', $error_message, 60);
            if (wp_doing_ajax()) {
                wp_send_json_error(['message' => $error_message]);
            }
            return new WP_Error('invalid_api', $error_message);
        }
        $tracks = json_decode(wp_remote_retrieve_body($tracks), true);
        if (empty($tracks) || !is_array($tracks)) {
            $error_message = 'Nessun track fornito o formato non valido.';
            set_transient('geohub_sync_tracks_notification', $error_message, 60);
            if (wp_doing_ajax()) {
                wp_send_json_error(['message' => $error_message]);
            }
            return new WP_Error('invalid_input', $error_message);
        }
        foreach ($tracks as $geohub_id => $updated_at) {
            $post_id = null;
            $track_shortcode_final = '';

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

            $existing_post_modified_time = $existing_posts ? strtotime(get_post_modified_time('Y-m-d H:i:s', false, $existing_posts[0]->ID, true)) : null;
            $new_updated_time = strtotime($updated_at);

            if ($existing_posts && $new_updated_time <= $existing_post_modified_time) {
                continue;
            }

            $response = wp_remote_get($track_url . $geohub_id . ".json");
            if (is_wp_error($response)) {
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (empty($data)) {
                continue;
            }

            // Generate post data from track information
            $post_title = (isset($data['properties']['name'][$default_lang]) && $data['properties']['name'][$default_lang]) ? $data['properties']['name'][$default_lang] : 'Track no title ' . $geohub_id;
            $post_slug = sanitize_title($post_title);
            $track_shortcode_final = str_replace('$1', $geohub_id, $track_shortcode);

            // Insert or update post
            $post_data = [
                'post_title'   => $post_title,
                'post_name'    => $post_slug,
                'post_content' => $track_shortcode_final,
                'post_status'  => 'publish',
                'post_type'    => 'track',
            ];
            if ($existing_posts) {
                $post_data['ID'] = $existing_posts[0]->ID;
                $post_id = wp_update_post($post_data);
            } else {
                $post_id = wp_insert_post($post_data);
            }

            // Check for errors
            if (is_wp_error($post_id)) {
                continue;
            }

            // Update post meta field
            update_post_meta($post_id, 'geohub_track_id', $geohub_id);

            // WPML integration: Set the language information for the inserted/updated post
            // and create translations for available languages
            $wpml_element_type = apply_filters('wpml_element_type', 'post_track');
            $original_language_info = apply_filters('wpml_element_language_details', null, ['element_id' => $post_id, 'element_type' => $wpml_element_type]);
            $languages = apply_filters('wpml_active_languages', NULL, 'orderby=id&order=desc');

            foreach ($languages as $lang_code => $lang_details) {
                if ($lang_code == $original_language_info->language_code) continue;

                // Generate post data from track information
                $post_title = (isset($data['properties']['name'][$lang_code]) && $data['properties']['name'][$lang_code]) ? $data['properties']['name'][$lang_code] : 'Track no title ' . $geohub_id;
                $post_slug = sanitize_title($post_title);

                // Create translation post object (you should modify this part according to how you manage translations)
                $translated_post_data = [
                    'post_title'    => $post_title,
                    'post_content'  => $track_shortcode_final,
                    'post_status'   => 'publish',
                    'post_author'   => 1,
                    'post_type'     => 'track',
                    'post_name'     => $post_slug . '-' . $lang_code,
                ];
                $translated_post_id = wp_insert_post($translated_post_data);

                if (!is_wp_error($translated_post_id)) {
                    // Associate the translation with the original post
                    $set_language_args = [
                        'element_id'            => $translated_post_id,
                        'element_type'          => $wpml_element_type,
                        'trid'                  => $original_language_info->trid,
                        'language_code'         => $lang_code,
                        'source_language_code'  => $original_language_info->language_code
                    ];
                    do_action('wpml_set_element_language_details', $set_language_args);

                    // Update post meta field for the translation
                    update_post_meta($translated_post_id, 'geohub_track_id', $geohub_id);
                }
            }
        }
    } else {
        // If configuration is missing, this should have been caught earlier, but handle it here too
        if (wp_doing_ajax()) {
            wp_send_json_error(['message' => 'Configuration incomplete. Please check your settings.']);
            return;
        }
    }

    delete_transient('geohub_transient_warning_message');
    set_transient('geohub_transient_success_message', 'Greate! Tracks synchronized successfully.', 60);

    // AJAX response - always send response when called via AJAX
    if (wp_doing_ajax()) {
        wp_send_json_success([
            'message' => 'Tracks synchronized successfully!'
        ]);
        return; // Ensure execution stops after sending JSON
    }
}

// Register AJAX action
add_action('wp_ajax_sync_tracks_action', 'sync_tracks_action');

// Ensure WPML functions are available
// if (function_exists('icl_object_id') && function_exists('wpml_insert_translation')) {
//     // https://wpml.org/wpml-hook/wpml_set_element_language_details/
//     // https://wpml.org/forums/topic/programmatically-create-a-post-only-in-a-secondary-language/
// }