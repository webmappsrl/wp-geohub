<?php

/**
 * Register meta box for POI post type
 */
function geohub_poi_meta_box() {
    add_meta_box(
        'geohub_poi_details',
        'Dettagli Poi',
        'geohub_poi_meta_box_callback',
        'poi',
        'normal',
        'default'
    );
}
add_action('add_meta_boxes', 'geohub_poi_meta_box');

/**
 * Meta box callback function
 */
function geohub_poi_meta_box_callback($post) {
    wp_nonce_field('geohub_poi_meta_box', 'geohub_poi_meta_box_nonce');
    $value = get_post_meta($post->ID, 'geohub_poi_id', true);
    ?>
    <table class="form-table">
        <tr>
            <th scope="row">
                <label for="geohub_poi_id">Geohub ID</label>
            </th>
            <td>
                <input type="number" id="geohub_poi_id" name="geohub_poi_id" value="<?php echo esc_attr($value); ?>" class="regular-text" />
                <p class="description">Inserisci l'ID originale della poi</p>
            </td>
        </tr>
    </table>
    <?php
}

/**
 * Save meta box data
 */
function geohub_poi_save_meta_box($post_id) {
    // Check nonce
    if (!isset($_POST['geohub_poi_meta_box_nonce']) || 
        !wp_verify_nonce($_POST['geohub_poi_meta_box_nonce'], 'geohub_poi_meta_box')) {
        return;
    }

    // Check autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Check permissions
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Check post type
    if (get_post_type($post_id) !== 'poi') {
        return;
    }

    // Save meta field
    if (isset($_POST['geohub_poi_id'])) {
        update_post_meta($post_id, 'geohub_poi_id', sanitize_text_field($_POST['geohub_poi_id']));
    } else {
        delete_post_meta($post_id, 'geohub_poi_id');
    }
}
add_action('save_post_poi', 'geohub_poi_save_meta_box');
