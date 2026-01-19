<?php
function wm_transient_display_error_message()
{
    if ($error_message = get_transient('wm_transient_error_message')) {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error_message) . '</p></div>';
        delete_transient('wm_transient_error_message');
    }
    if ($success_message = get_transient('wm_transient_success_message')) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($success_message) . '</p></div>';
        delete_transient('wm_transient_success_message');
    }
    if ($warning_message = get_transient('wm_transient_warning_message')) {
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html($warning_message) . '</p></div>';
        delete_transient('wm_transient_warning_message');
    }
}
add_action('admin_notices', 'wm_transient_display_error_message');
