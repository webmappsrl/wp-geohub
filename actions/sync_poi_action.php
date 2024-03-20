<?php

function sync_pois_action()
{
    if (is_wp_error(required_plugins()))
        return;

    $poi_url = get_option('poi_url');
    $pois_list = get_option('poi_list');
    $poi_shortcode = get_option('poi_shortcode');
    $default_lang = apply_filters('wpml_default_language', NULL );
    if (!empty($poi_url) && !empty($pois_list) && !empty($poi_shortcode)) {

        $pois = wp_remote_get($pois_list);
        if (is_wp_error($pois)) {
            set_transient('geohub_sync_pois_notification', 'API poi list non valida o non disponibile.', 60);
            return new WP_Error('invalid_api', 'API poi list non valida o non disponibile.');
        }
        $pois = json_decode(wp_remote_retrieve_body($pois), true);
        if (empty($pois) || !is_array($pois)) {
            set_transient('geohub_sync_pois_notification', 'Nessun Poi fornito o formato non valido.', 60);
            return new WP_Error('invalid_input', 'Nessun Poi fornito o formato non valido.');
        }

        foreach ($pois as $geohub_id => $updated_at) {
            $post_id = null;
            $poi_shortcode_final = '';

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

            $existing_post_modified_time = $existing_posts ? strtotime(get_post_modified_time('Y-m-d H:i:s', false, $existing_posts[0]->ID, true)) : null;
            $new_updated_time = strtotime($updated_at);

            if ($existing_posts && $new_updated_time <= $existing_post_modified_time) {
                continue; 
            }

            $response = wp_remote_get($poi_url . $geohub_id);
            if (is_wp_error($response)) {
                continue; 
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (empty($data)) {
                continue;
            }

            // Generate post data from poi information
            $post_title = (isset($data['properties']['name'][$default_lang]) && $data['properties']['name'][$default_lang]) ? $data['properties']['name'][$default_lang] : 'poi no title ' . $geohub_id;
            $post_slug = sanitize_title($post_title);
            $poi_shortcode_final = str_replace('$1', $geohub_id, $poi_shortcode);

            // Insert or update post
            $post_data = [
                'post_title'   => $post_title,
                'post_name'    => $post_slug,
                'post_content' => $poi_shortcode_final,
                'post_status'  => 'publish',
                'post_type'    => 'poi',
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

            // Update ACF field
            update_field('geohub_poi_id', $geohub_id, $post_id);

            // WPML integration: Set the language information for the inserted/updated post
            // and create translations for available languages
            $wpml_element_type = apply_filters('wpml_element_type', 'post_poi');
            $original_language_info = apply_filters('wpml_element_language_details', null, ['element_id' => $post_id, 'element_type' => $wpml_element_type]);
            $languages = apply_filters('wpml_active_languages', NULL, 'orderby=id&order=desc');

            foreach ($languages as $lang_code => $lang_details) {
                if ($lang_code == $original_language_info->language_code) continue;

                // Generate post data from poi information
                $post_title = (isset($data['properties']['name'][$lang_code]) && $data['properties']['name'][$lang_code]) ? $data['properties']['name'][$lang_code] : 'Poi no title ' . $geohub_id;
                $post_slug = sanitize_title($post_title);

                // Create translation post object (you should modify this part according to how you manage translations)
                $translated_post_data = [
                    'post_title'    => $post_title, 
                    'post_content'  => $poi_shortcode_final,
                    'post_status'   => 'publish',
                    'post_author'   => 1,
                    'post_type'     => 'poi',
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

                    // Update ACF field for the translation
                    update_field('geohub_poi_id', $geohub_id, $translated_post_id);
                }
            }
        }
    } 
    delete_transient('geohub_transient_warning_message');
    set_transient('geohub_transient_success_message', 'Greate! Pois synchronized successfully.', 60);
}