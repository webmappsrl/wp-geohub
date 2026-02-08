<?php
// Check if the class doesn't already exist
if (!class_exists('Track_Post_Type')) {

    function create_track_post_type()
    {
        $labels = array(
            'name'                  => _x('Tracks', 'Post Type General Name', 'wm-package'),
            'singular_name'         => _x('Track', 'Post Type Singular Name', 'wm-package'),
            'menu_name'             => __('Tracks', 'wm-package'),
            'name_admin_bar'        => __('Track', 'wm-package'),
            'archives'              => __('Track Archives', 'wm-package'),
            'attributes'            => __('Track Attributes', 'wm-package'),
            'parent_item_colon'     => __('Parent Track:', 'wm-package'),
            'all_items'             => __('All Tracks', 'wm-package'),
            'add_new_item'          => __('Add New Track', 'wm-package'),
            'add_new'               => __('Add New', 'wm-package'),
            'new_item'              => __('New Track', 'wm-package'),
            'edit_item'             => __('Edit Track', 'wm-package'),
            'update_item'           => __('Update Track', 'wm-package'),
            'view_item'             => __('View Track', 'wm-package'),
            'view_items'            => __('View Tracks', 'wm-package'),
            'search_items'          => __('Search Tracks', 'wm-package'),
            'not_found'             => __('Not Found', 'wm-package'),
            'not_found_in_trash'    => __('Not Found in Trash', 'wm-package'),
            'featured_image'        => __('Featured Image', 'wm-package'),
            'set_featured_image'    => __('Set Featured Image', 'wm-package'),
            'remove_featured_image' => __('Remove Featured Image', 'wm-package'),
            'use_featured_image'    => __('Use as Featured Image', 'wm-package'),
            'insert_into_item'      => __('Insert into Track', 'wm-package'),
            'uploaded_to_this_item' => __('Uploaded to this Track', 'wm-package'),
            'items_list'            => __('Tracks List', 'wm-package'),
            'items_list_navigation' => __('Tracks List Navigation', 'wm-package'),
            'filter_items_list'     => __('Filter Tracks List', 'wm-package'),
        );

        $args = array(
            'label'                 => __('Track', 'wm-package'),
            'description'           => __('Post type for Tracks', 'wm-package'),
            'labels'                => $labels,
            'supports'              => array('title', 'editor', 'thumbnail', 'custom-fields'),
            'hierarchical'          => false,
            'public'                => true,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 5,
            'menu_icon'             => 'dashicons-location-alt',
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => true,
            'can_export'            => true,
            'has_archive'           => true,
            'exclude_from_search'   => false,
            'publicly_queryable'    => true,
            'capability_type'       => 'post',
            'show_in_rest'          => true, // Set to 'false' if you don't want to enable Gutenberg support.
        );

        register_post_type('track', $args);
    }
    add_action('init', 'create_track_post_type', 0);
}
