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
            'archives'              => __('Archivi Track', 'wm-package'),
            'attributes'            => __('Attributi Track', 'wm-package'),
            'parent_item_colon'     => __('Parente Track:', 'wm-package'),
            'all_items'             => __('Tutti i Tracks', 'wm-package'),
            'add_new_item'          => __('Aggiungi Nuovo Track', 'wm-package'),
            'add_new'               => __('Aggiungi Nuovo', 'wm-package'),
            'new_item'              => __('Nuovo Track', 'wm-package'),
            'edit_item'             => __('Modifica Track', 'wm-package'),
            'update_item'           => __('Aggiorna Track', 'wm-package'),
            'view_item'             => __('Vedi Track', 'wm-package'),
            'view_items'            => __('Vedi Tracks', 'wm-package'),
            'search_items'          => __('Cerca Track', 'wm-package'),
            'not_found'             => __('Non trovato', 'wm-package'),
            'not_found_in_trash'    => __('Non trovato nel cestino', 'wm-package'),
            'featured_image'        => __('Immagine in Evidenza', 'wm-package'),
            'set_featured_image'    => __('Imposta immagine in evidenza', 'wm-package'),
            'remove_featured_image' => __('Rimuovi immagine in evidenza', 'wm-package'),
            'use_featured_image'    => __('Usa come immagine in evidenza', 'wm-package'),
            'insert_into_item'      => __('Inserisci nel Track', 'wm-package'),
            'uploaded_to_this_item' => __('Caricato su questo Track', 'wm-package'),
            'items_list'            => __('Lista Tracks', 'wm-package'),
            'items_list_navigation' => __('Navigazione Lista Tracks', 'wm-package'),
            'filter_items_list'     => __('Filtra Lista Tracks', 'wm-package'),
        );

        $args = array(
            'label'                 => __('Track', 'wm-package'),
            'description'           => __('Post Type per i Tracks', 'wm-package'),
            'labels'                => $labels,
            'supports'              => array('title', 'editor', 'thumbnail', 'custom-fields'),
            'hierarchical'          => false,
            'public'                => true,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 5,
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => true,
            'can_export'            => true,
            'has_archive'           => true,
            'exclude_from_search'   => false,
            'publicly_queryable'    => true,
            'capability_type'       => 'post',
            'show_in_rest'          => true, // Impostalo su 'false' se non vuoi abilitare il supporto a Gutenberg.
        );

        register_post_type('track', $args);
    }
    add_action('init', 'create_track_post_type', 0);
}
