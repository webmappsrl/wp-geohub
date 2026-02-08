<?php

/**
 * Register meta box for Track post type
 */
function wm_track_meta_box()
{
    add_meta_box(
        'wm_track_details',
        __('Track Details', 'wm-package'),
        'wm_track_meta_box_callback',
        'track',
        'normal',
        'default'
    );
}
add_action('add_meta_boxes', 'wm_track_meta_box');

/**
 * Meta box callback function
 */
function wm_track_meta_box_callback($post)
{
    wp_nonce_field('wm_track_meta_box', 'wm_track_meta_box_nonce');
    $value = get_post_meta($post->ID, 'wm_track_id', true);
?>
    <table class="form-table">
        <tr>
            <th scope="row">
                <label for="wm_track_id">Source ID</label>
            </th>
            <td>
                <input type="number" id="wm_track_id" name="wm_track_id" value="<?php echo esc_attr($value); ?>" class="regular-text" />
                <p class="description"><?php echo esc_html(__('Enter the original Track ID', 'wm-package')); ?></p>
            </td>
        </tr>
    </table>
<?php
}

/**
 * Save meta box data
 */
function wm_track_save_meta_box($post_id)
{
    // Check nonce
    if (
        !isset($_POST['wm_track_meta_box_nonce']) ||
        !wp_verify_nonce($_POST['wm_track_meta_box_nonce'], 'wm_track_meta_box')
    ) {
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
    if (get_post_type($post_id) !== 'track') {
        return;
    }

    // Save meta field
    if (isset($_POST['wm_track_id'])) {
        update_post_meta($post_id, 'wm_track_id', sanitize_text_field($_POST['wm_track_id']));
    } else {
        delete_post_meta($post_id, 'wm_track_id');
    }
}
add_action('save_post_track', 'wm_track_save_meta_box');
