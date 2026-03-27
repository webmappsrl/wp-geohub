<?php

function required_plugins()
{
    $required_plugins = [
        'sitepress-multilingual-cms/sitepress.php' => [
            'name' => 'WPML',
            'error_message' => __('The WPML plugin is not installed or activated. This plugin requires WPML to function correctly.', 'wm-package')
        ]
    ];

    foreach ($required_plugins as $plugin_path => $plugin_data) {
        if (!is_plugin_active($plugin_path)) {
            $error_message = __('ERROR:', 'wm-package') . " " . $plugin_data['error_message'];
            set_transient('wm_transient_error_message', $error_message, 60);
            return new WP_Error('required_plugin', $error_message);
        }
    }

    $translated_post_types = [
        'track' => 'Track',
        'poi' => 'Poi'
    ];

    foreach ($translated_post_types as $post_type => $name) {
        if (!apply_filters('wpml_is_translated_post_type', null, $post_type)) {
            $error_message = sprintf(__('ERROR: The %s post type is not translatable. Please enable the translation for the %s post type in WPML settings > Post type translation.', 'wm-package'), $name, $name);
            set_transient('wm_transient_error_message', $error_message, 60);
            return new WP_Error('required_plugin', $error_message);
        }
    }
}
