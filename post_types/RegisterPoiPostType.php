<?php
// Check if the class doesn't already exist
if (!class_exists('Poi_Post_Type')) {

    function create_poi_post_type()
    {
        $labels = array(
            'name'                  => _x('POIs', 'Post Type General Name', 'wm-package'),
            'singular_name'         => _x('POI', 'Post Type Singular Name', 'wm-package'),
            'menu_name'             => __('POIs', 'wm-package'),
            'name_admin_bar'        => __('POI', 'wm-package'),
            'archives'              => __('POI Archives', 'wm-package'),
            'attributes'            => __('POI Attributes', 'wm-package'),
            'parent_item_colon'     => __('Parent POI:', 'wm-package'),
            'all_items'             => __('All POIs', 'wm-package'),
            'add_new_item'          => __('Add New POI', 'wm-package'),
            'add_new'               => __('Add New', 'wm-package'),
            'new_item'              => __('New POI', 'wm-package'),
            'edit_item'             => __('Edit POI', 'wm-package'),
            'update_item'           => __('Update POI', 'wm-package'),
            'view_item'             => __('View POI', 'wm-package'),
            'view_items'            => __('View POIs', 'wm-package'),
            'search_items'          => __('Search POIs', 'wm-package'),
            'not_found'             => __('Not Found', 'wm-package'),
            'not_found_in_trash'    => __('Not Found in Trash', 'wm-package'),
            'featured_image'        => __('Featured Image', 'wm-package'),
            'set_featured_image'    => __('Set Featured Image', 'wm-package'),
            'remove_featured_image' => __('Remove Featured Image', 'wm-package'),
            'use_featured_image'    => __('Use as Featured Image', 'wm-package'),
            'insert_into_item'      => __('Insert into POI', 'wm-package'),
            'uploaded_to_this_item' => __('Uploaded to this POI', 'wm-package'),
            'items_list'            => __('POIs List', 'wm-package'),
            'items_list_navigation' => __('POIs List Navigation', 'wm-package'),
            'filter_items_list'     => __('Filter POIs List', 'wm-package'),
        );

        $args = array(
            'label'                 => __('POI', 'wm-package'),
            'description'           => __('Post type for Points of Interest', 'wm-package'),
            'labels'                => $labels,
            'supports'              => array('title', 'editor', 'thumbnail', 'custom-fields'),
            'hierarchical'          => false,
            'public'                => true,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 6,
            'menu_icon'             => 'dashicons-location',
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => true,
            'can_export'            => true,
            'has_archive'           => true,
            'exclude_from_search'   => false,
            'publicly_queryable'    => true,
            'capability_type'       => 'post',
            'show_in_rest'          => true, // Set to 'false' if you don't want to enable Gutenberg support.
        );

        register_post_type('poi', $args);
    }
    add_action('init', 'create_poi_post_type', 0);
}
