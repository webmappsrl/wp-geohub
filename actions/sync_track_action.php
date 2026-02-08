<?php
function sync_tracks_action()
{
    if (is_wp_error(required_plugins())) {
        if (wp_doing_ajax()) {
            wp_send_json_error(['message' => __('Required plugins are not installed or activated', 'wm-package')]);
        }
        return;
    }

    $track_url = get_option('track_url');
    $tracks_list = get_option('tracks_list');
    $track_shortcode = get_option('track_shortcode');
    $default_lang = apply_filters('wpml_default_language', NULL);

    // Check if required options are set
    if (empty($track_url) || empty($tracks_list) || empty($track_shortcode)) {
        $error_message = __('Required configuration options are missing. Please check your settings.', 'wm-package');
        set_transient('wm_sync_tracks_notification', $error_message, 60);
        if (wp_doing_ajax()) {
            wp_send_json_error(['message' => $error_message]);
        }
        return new WP_Error('missing_config', $error_message);
    }

    // Batch processing parameters
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $is_first_batch = ($offset === 0);
    $transient_key = 'wm_sync_tracks_list';
    $progress_key = 'wm_sync_tracks_progress';
    $max_consecutive_errors = 3; // Stop after 3 consecutive batch errors
    $max_total_time = 30 * MINUTE_IN_SECONDS; // Maximum 30 minutes total sync time

    if (!empty($track_url) && !empty($tracks_list) && !empty($track_shortcode)) {

        // On first batch, fetch and cache the tracks list
        if ($is_first_batch) {
            $tracks_response = wp_remote_get($tracks_list);
            if (is_wp_error($tracks_response)) {
                $error_message = __('API track list invalid or unavailable.', 'wm-package');
                set_transient('wm_sync_tracks_notification', $error_message, 60);
                if (wp_doing_ajax()) {
                    wp_send_json_error(['message' => $error_message]);
                }
                return new WP_Error('invalid_api', $error_message);
            }
            $tracks = json_decode(wp_remote_retrieve_body($tracks_response), true);
            if (!is_array($tracks)) {
                $error_message = __('No tracks provided or invalid format.', 'wm-package');
                set_transient('wm_sync_tracks_notification', $error_message, 60);
                if (wp_doing_ajax()) {
                    wp_send_json_error(['message' => $error_message]);
                }
                return new WP_Error('invalid_input', $error_message);
            }
            if (empty($tracks)) {
                $error_message = __('No tracks in the source. The list is empty.', 'wm-package');
                set_transient('wm_sync_tracks_notification', $error_message, 60);
                if (wp_doing_ajax()) {
                    wp_send_json_error(['message' => $error_message]);
                }
                return new WP_Error('empty_list', $error_message);
            }
            // Cache the tracks list for 1 hour
            set_transient($transient_key, $tracks, HOUR_IN_SECONDS);
            // Initialize progress tracking
            $progress_data = [
                'start_time' => time(),
                'last_update' => time(),
                'last_offset' => 0,
                'consecutive_errors' => 0,
                'total_processed' => 0,
                'total_skipped' => 0,
                'total_errors' => 0
            ];
            set_transient($progress_key, $progress_data, HOUR_IN_SECONDS);
        } else {
            // Get cached tracks list
            $tracks = get_transient($transient_key);
            if ($tracks === false) {
                if (wp_doing_ajax()) {
                    wp_send_json_error(['message' => __('Synchronization session expired. Please start over.', 'wm-package')]);
                }
                return new WP_Error('session_expired', __('Synchronization session expired.', 'wm-package'));
            }
            
            // Check progress and detect if stuck
            $progress_data = get_transient($progress_key);
            if ($progress_data === false) {
                if (wp_doing_ajax()) {
                    wp_send_json_error(['message' => __('Synchronization session expired. Please start over.', 'wm-package')]);
                }
                return new WP_Error('session_expired', __('Synchronization session expired.', 'wm-package'));
            }
            
            // Check if process is stuck (no progress for more than 5 minutes)
            $time_since_last_update = time() - $progress_data['last_update'];
            if ($time_since_last_update > 5 * MINUTE_IN_SECONDS) {
                delete_transient($transient_key);
                delete_transient($progress_key);
                if (wp_doing_ajax()) {
                    wp_send_json_error(['message' => __('Stuck process detected. Synchronization has been stopped. Please start over.', 'wm-package')]);
                }
                return new WP_Error('process_stuck', __('Stuck process detected.', 'wm-package'));
            }
            
            // Check if total time exceeded
            $total_time = time() - $progress_data['start_time'];
            if ($total_time > $max_total_time) {
                delete_transient($transient_key);
                delete_transient($progress_key);
                if (wp_doing_ajax()) {
                    wp_send_json_error(['message' => sprintf(__('Global timeout reached (%d minutes). Synchronization has been stopped. Please start over.', 'wm-package'), round($max_total_time / 60))]);
                }
                return new WP_Error('global_timeout', __('Global timeout reached.', 'wm-package'));
            }
            
            // Check if offset hasn't changed (stuck on same batch)
            if ($offset <= $progress_data['last_offset'] && $offset > 0) {
                $progress_data['consecutive_errors']++;
                if ($progress_data['consecutive_errors'] >= $max_consecutive_errors) {
                    delete_transient($transient_key);
                    delete_transient($progress_key);
                    if (wp_doing_ajax()) {
                        wp_send_json_error(['message' => sprintf(__('Too many consecutive errors (%d). Synchronization has been stopped to avoid an infinite loop.', 'wm-package'), $max_consecutive_errors)]);
                    }
                    return new WP_Error('too_many_errors', __('Too many consecutive errors.', 'wm-package'));
                }
            } else {
                // Reset error counter on successful progress
                $progress_data['consecutive_errors'] = 0;
            }
        }

        // Convert tracks array to indexed array for batch processing
        $tracks_array = [];
        foreach ($tracks as $source_id => $updated_at) {
            $tracks_array[] = ['id' => $source_id, 'updated_at' => $updated_at];
        }
        $total_tracks = count($tracks_array);
        
        // Get batch slice
        $batch_tracks = array_slice($tracks_array, $offset, $batch_size);
        $processed_count = 0;
        $skipped_count = 0;
        $error_count = 0;

        foreach ($batch_tracks as $track_item) {
            $source_id = $track_item['id'];
            $updated_at = $track_item['updated_at'];
            $post_id = null;
            $track_shortcode_final = '';

            $existing_posts = get_posts([
                'post_type' => 'track',
                'meta_query' => [
                    [
                        'key' => 'wm_track_id',
                        'value' => $source_id,
                    ],
                ],
                'numberposts' => 1,
            ]);

            $existing_post_modified_time = $existing_posts ? strtotime(get_post_modified_time('Y-m-d H:i:s', false, $existing_posts[0]->ID, true)) : null;
            $new_updated_time = strtotime($updated_at);

            if ($existing_posts && $new_updated_time <= $existing_post_modified_time) {
                $skipped_count++;
                continue;
            }

            $response = wp_remote_get($track_url . $source_id . ".json");
            if (is_wp_error($response)) {
                $error_count++;
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (empty($data)) {
                $error_count++;
                continue;
            }

            // Generate post data from track information
            $post_title = (isset($data['properties']['name'][$default_lang]) && $data['properties']['name'][$default_lang]) ? $data['properties']['name'][$default_lang] : __('Track no title', 'wm-package') . ' ' . $source_id;
            $post_slug = sanitize_title($post_title);
            $track_shortcode_final = str_replace('$1', $source_id, $track_shortcode);

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
                $error_count++;
                continue;
            }

            // Update post meta field
            update_post_meta($post_id, 'wm_track_id', $source_id);
            $processed_count++;

            // WPML integration: Set the language information for the inserted/updated post
            // and create translations for available languages
            $wpml_element_type = apply_filters('wpml_element_type', 'post_track');
            $original_language_info = apply_filters('wpml_element_language_details', null, ['element_id' => $post_id, 'element_type' => $wpml_element_type]);
            $languages = apply_filters('wpml_active_languages', NULL, 'orderby=id&order=desc');

            foreach ($languages as $lang_code => $lang_details) {
                if ($lang_code == $original_language_info->language_code) continue;

                // Generate post data from track information
                $post_title = (isset($data['properties']['name'][$lang_code]) && $data['properties']['name'][$lang_code]) ? $data['properties']['name'][$lang_code] : __('Track no title', 'wm-package') . ' ' . $source_id;
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
                    update_post_meta($translated_post_id, 'wm_track_id', $source_id);
                }
            }
        }

        // Calculate progress (avoid division by zero when total_tracks is 0)
        $next_offset = $offset + $batch_size;
        $is_complete = ($next_offset >= $total_tracks);
        $progress_percent = $total_tracks > 0 ? min(100, round(($next_offset / $total_tracks) * 100)) : 100;

        // Update progress tracking
        $progress_data['last_update'] = time();
        $progress_data['last_offset'] = $next_offset;
        $progress_data['total_processed'] += $processed_count;
        $progress_data['total_skipped'] += $skipped_count;
        $progress_data['total_errors'] += $error_count;
        
        // If batch had errors but we made progress, reset consecutive errors
        if ($processed_count > 0 || $skipped_count > 0) {
            $progress_data['consecutive_errors'] = 0;
        } elseif ($error_count > 0) {
            // If batch had only errors and no progress, increment error counter
            $progress_data['consecutive_errors']++;
        }
        
        set_transient($progress_key, $progress_data, HOUR_IN_SECONDS);

        // Check if too many consecutive errors
        if ($progress_data['consecutive_errors'] >= $max_consecutive_errors) {
            delete_transient($transient_key);
            delete_transient($progress_key);
            if (wp_doing_ajax()) {
                wp_send_json_error([
                    'message' => sprintf(__('Too many consecutive errors (%d). Synchronization has been stopped to avoid an infinite loop.', 'wm-package'), $max_consecutive_errors),
                    'progress' => [
                        'processed' => $processed_count,
                        'skipped' => $skipped_count,
                        'errors' => $error_count,
                        'current' => $next_offset,
                        'total' => $total_tracks,
                        'percent' => $progress_percent,
                        'complete' => false,
                        'stopped' => true,
                        'reason' => 'too_many_errors'
                    ]
                ]);
            }
            return;
        }

        // Clean up cache if complete
        if ($is_complete) {
            delete_transient($transient_key);
            delete_transient($progress_key);
            delete_transient('wm_transient_warning_message');
            set_transient('wm_transient_success_message', __('Great! Tracks synchronized successfully.', 'wm-package'), 60);
        }

        // AJAX response - always send response when called via AJAX
        if (wp_doing_ajax()) {
            wp_send_json_success([
                'message' => $is_complete 
                    ? sprintf(__('Synchronization completed! Processed %d tracks.', 'wm-package'), $total_tracks)
                    : sprintf(__('Batch completed: %d/%d tracks (%d%%)', 'wm-package'), $next_offset, $total_tracks, $progress_percent),
                'progress' => [
                    'processed' => $processed_count, // Batch current
                    'skipped' => $skipped_count, // Batch current
                    'errors' => $error_count, // Batch current
                    'total_processed' => $progress_data['total_processed'], // Cumulative total
                    'total_skipped' => $progress_data['total_skipped'], // Cumulative total
                    'total_errors' => $progress_data['total_errors'], // Cumulative total
                    'current' => $next_offset,
                    'total' => $total_tracks,
                    'percent' => $progress_percent,
                    'complete' => $is_complete,
                    'next_offset' => $is_complete ? null : $next_offset
                ]
            ]);
            return; // Ensure execution stops after sending JSON
        }
    } else {
        // If configuration is missing, this should have been caught earlier, but handle it here too
        if (wp_doing_ajax()) {
            wp_send_json_error(['message' => __('Configuration incomplete. Please check your settings.', 'wm-package')]);
            return;
        }
    }

    // Non-AJAX fallback (for backward compatibility)
    delete_transient('wm_transient_warning_message');
    set_transient('wm_transient_success_message', __('Great! Tracks synchronized successfully.', 'wm-package'), 60);
}

// Register AJAX action
add_action('wp_ajax_sync_tracks_action', 'sync_tracks_action');

// Ensure WPML functions are available
// if (function_exists('icl_object_id') && function_exists('wpml_insert_translation')) {
//     // https://wpml.org/wpml-hook/wpml_set_element_language_details/
//     // https://wpml.org/forums/topic/programmatically-create-a-post-only-in-a-secondary-language/
// }
