<?php

function delete_all_tracks_action()
{
    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'wm-package')]);
        return;
    }

    // Verify nonce for security
    if (wp_doing_ajax() && isset($_POST['nonce'])) {
        if (!wp_verify_nonce($_POST['nonce'], 'delete_all_tracks')) {
            wp_send_json_error(['message' => __('Invalid nonce', 'wm-package')]);
            return;
        }
    }

    // Batch processing parameters
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 20;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $is_first_batch = ($offset === 0);
    $transient_key = 'wm_delete_tracks_list';
    $progress_key = 'wm_delete_tracks_progress';

    // On first batch, get all tracks and cache the list
    if ($is_first_batch) {
        // Initialize progress tracking early to show we're working
        $progress_data = [
            'start_time' => time(),
            'last_update' => time(),
            'last_offset' => 0,
            'total_deleted' => 0,
            'total_errors' => 0
        ];
        set_transient($progress_key, $progress_data, HOUR_IN_SECONDS);
        
        // Get all posts of type 'track' including trashed ones
        $tracks = get_posts([
            'post_type' => 'track',
            'post_status' => 'any', // Include all statuses: publish, draft, trash, etc.
            'posts_per_page' => -1, // All posts
            'fields' => 'ids' // Only IDs for better performance
        ]);

        $posts_to_delete = [];
        $total_tracks = count($tracks);
        $processed = 0;

        // Collect all post IDs to delete (including translations)
        foreach ($tracks as $track_id) {
            $posts_to_delete[] = $track_id;
            $processed++;

            // If WPML is active, collect translations as well
            if (function_exists('apply_filters') && function_exists('wpml_get_language_information')) {
                $wpml_element_type = apply_filters('wpml_element_type', 'post_track');
                $translations = apply_filters('wpml_get_element_translations', null, [
                    'element_id' => $track_id,
                    'element_type' => $wpml_element_type
                ]);

                if ($translations && is_array($translations)) {
                    foreach ($translations as $lang_code => $translation) {
                        if (isset($translation->element_id) && $translation->element_id != $track_id) {
                            $posts_to_delete[] = $translation->element_id;
                        }
                    }
                }
            }
            
            // Update progress every 100 tracks to show we're working
            if ($processed % 100 === 0) {
                $progress_data['last_update'] = time();
                set_transient($progress_key, $progress_data, HOUR_IN_SECONDS);
            }
        }

        // Remove duplicates
        $posts_to_delete = array_unique($posts_to_delete);
        
        // Cache the list for 1 hour
        set_transient($transient_key, $posts_to_delete, HOUR_IN_SECONDS);
        
        // Update progress tracking
        $progress_data['last_update'] = time();
        set_transient($progress_key, $progress_data, HOUR_IN_SECONDS);
    } else {
        // Get cached list
        $posts_to_delete = get_transient($transient_key);
        if ($posts_to_delete === false) {
            if (wp_doing_ajax()) {
                wp_send_json_error(['message' => __('Deletion session expired. Please start over.', 'wm-package')]);
            }
            return;
        }
        
        // Get progress data
        $progress_data = get_transient($progress_key);
        if ($progress_data === false) {
            if (wp_doing_ajax()) {
                wp_send_json_error(['message' => __('Deletion session expired. Please start over.', 'wm-package')]);
            }
            return;
        }
    }

    $total_posts = count($posts_to_delete);
    
    // Get batch slice
    $batch_posts = array_slice($posts_to_delete, $offset, $batch_size);
    $deleted_count = 0;
    $errors = [];

    // Delete batch posts
    foreach ($batch_posts as $post_id) {
        // Verify that the post still exists
        if (get_post($post_id)) {
            // Delete associated post meta as well
            delete_post_meta($post_id, 'wm_track_id');

            // Delete the post (force delete bypasses trash)
            $result = wp_delete_post($post_id, true);
            if ($result) {
                $deleted_count++;
            } else {
                $errors[] = sprintf(__('Error deleting track ID: %d', 'wm-package'), $post_id);
            }
        }
    }

    // Calculate progress
    $next_offset = $offset + $batch_size;
    $is_complete = ($next_offset >= $total_posts);
    $progress_percent = min(100, round(($next_offset / $total_posts) * 100));

    // Update progress tracking
    $progress_data['last_update'] = time();
    $progress_data['last_offset'] = $next_offset;
    $progress_data['total_deleted'] += $deleted_count;
    $progress_data['total_errors'] += count($errors);
    set_transient($progress_key, $progress_data, HOUR_IN_SECONDS);

    // Clean up cache if complete
    if ($is_complete) {
        delete_transient($transient_key);
        delete_transient($progress_key);
        
        if (!empty($errors)) {
            set_transient('wm_delete_tracks_notification', sprintf(__('Deleted %d tracks. Some errors occurred.', 'wm-package'), $progress_data['total_deleted']), 60);
        } else {
            set_transient('wm_delete_tracks_notification', sprintf(__('Successfully deleted %d tracks.', 'wm-package'), $progress_data['total_deleted']), 60);
        }
    }

    // AJAX response
    if (wp_doing_ajax()) {
        wp_send_json_success([
            'message' => $is_complete 
                ? sprintf(__('Deletion completed! Deleted %d tracks.', 'wm-package'), $progress_data['total_deleted'])
                : sprintf(__('Batch completed: %d/%d tracks (%d%%)', 'wm-package'), $next_offset, $total_posts, $progress_percent),
            'progress' => [
                'deleted' => $deleted_count,
                'errors' => count($errors),
                'current' => $next_offset,
                'total' => $total_posts,
                'percent' => $progress_percent,
                'complete' => $is_complete,
                'next_offset' => $is_complete ? null : $next_offset,
                'total_deleted' => $progress_data['total_deleted'],
                'total_errors' => $progress_data['total_errors']
            ]
        ]);
    }
}

// Register AJAX action
add_action('wp_ajax_delete_all_tracks_action', 'delete_all_tracks_action');
