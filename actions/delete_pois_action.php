<?php

function delete_all_pois_action()
{
    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    // Verify nonce for security
    if (wp_doing_ajax() && isset($_POST['nonce'])) {
        if (!wp_verify_nonce($_POST['nonce'], 'delete_all_pois')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
    }

    // Get all posts of type 'poi' including trashed ones
    $pois = get_posts([
        'post_type' => 'poi',
        'post_status' => 'any', // Include all statuses: publish, draft, trash, etc.
        'posts_per_page' => -1, // All posts
        'fields' => 'ids' // Only IDs for better performance
    ]);

    $deleted_count = 0;
    $errors = [];
    $posts_to_delete = [];

    // Collect all post IDs to delete (including translations)
    foreach ($pois as $poi_id) {
        $posts_to_delete[] = $poi_id;

        // If WPML is active, collect translations as well
        if (function_exists('apply_filters') && function_exists('wpml_get_language_information')) {
            $wpml_element_type = apply_filters('wpml_element_type', 'post_poi');
            $translations = apply_filters('wpml_get_element_translations', null, [
                'element_id' => $poi_id,
                'element_type' => $wpml_element_type
            ]);

            if ($translations && is_array($translations)) {
                foreach ($translations as $lang_code => $translation) {
                    if (isset($translation->element_id) && $translation->element_id != $poi_id) {
                        $posts_to_delete[] = $translation->element_id;
                    }
                }
            }
        }
    }

    // Remove duplicates
    $posts_to_delete = array_unique($posts_to_delete);

    // Delete all collected posts
    foreach ($posts_to_delete as $post_id) {
        // Verify that the post still exists
        if (get_post($post_id)) {
            // Delete associated post meta as well
            delete_post_meta($post_id, 'wm_poi_id');

            // Delete the post (force delete bypasses trash)
            $result = wp_delete_post($post_id, true);
            if ($result) {
                $deleted_count++;
            } else {
                $errors[] = "Error deleting poi ID: " . $post_id;
            }
        }
    }

    if (!empty($errors)) {
        set_transient('wm_delete_pois_notification', 'Deleted ' . $deleted_count . ' pois. Some errors: ' . implode(', ', $errors), 60);
    } else {
        set_transient('wm_delete_pois_notification', 'Successfully deleted ' . $deleted_count . ' pois.', 60);
    }

    // AJAX response
    if (wp_doing_ajax()) {
        wp_send_json_success([
            'message' => 'Deleted ' . $deleted_count . ' pois.',
            'count' => $deleted_count,
            'errors' => $errors
        ]);
    }
}

// Register AJAX action
add_action('wp_ajax_delete_all_pois_action', 'delete_all_pois_action');
