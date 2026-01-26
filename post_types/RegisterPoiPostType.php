<?php
// Check if the class doesn't already exist
if (!class_exists('Poi_Post_Type')) {

    function create_poi_post_type()
    {
        $labels = array(
            'name'                  => _x('Pois', 'Post Type General Name', 'wm-package'),
            'singular_name'         => _x('Poi', 'Post Type Singular Name', 'wm-package'),
            'menu_name'             => __('Pois', 'wm-package'),
            'name_admin_bar'        => __('Poi', 'wm-package'),
            'archives'              => __('Archivi Poi', 'wm-package'),
            'attributes'            => __('Attributi Poi', 'wm-package'),
            'parent_item_colon'     => __('Parente Poi:', 'wm-package'),
            'all_items'             => __('Tutti i Pois', 'wm-package'),
            'add_new_item'          => __('Aggiungi Nuovo Poi', 'wm-package'),
            'add_new'               => __('Aggiungi Nuovo', 'wm-package'),
            'new_item'              => __('Nuovo Poi', 'wm-package'),
            'edit_item'             => __('Modifica Poi', 'wm-package'),
            'update_item'           => __('Aggiorna Poi', 'wm-package'),
            'view_item'             => __('Vedi Poi', 'wm-package'),
            'view_items'            => __('Vedi Pois', 'wm-package'),
            'search_items'          => __('Cerca Poi', 'wm-package'),
            'not_found'             => __('Non trovato', 'wm-package'),
            'not_found_in_trash'    => __('Non trovato nel cestino', 'wm-package'),
            'featured_image'        => __('Immagine in Evidenza', 'wm-package'),
            'set_featured_image'    => __('Imposta immagine in evidenza', 'wm-package'),
            'remove_featured_image' => __('Rimuovi immagine in evidenza', 'wm-package'),
            'use_featured_image'    => __('Usa come immagine in evidenza', 'wm-package'),
            'insert_into_item'      => __('Inserisci nel Poi', 'wm-package'),
            'uploaded_to_this_item' => __('Caricato su questo Poi', 'wm-package'),
            'items_list'            => __('Lista Pois', 'wm-package'),
            'items_list_navigation' => __('Navigazione Lista Pois', 'wm-package'),
            'filter_items_list'     => __('Filtra Lista Pois', 'wm-package'),
        );

        $args = array(
            'label'                 => __('Poi', 'wm-package'),
            'description'           => __('Post Type per i Pois', 'wm-package'),
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
            'show_in_rest'          => true, // Impostalo su 'false' se non vuoi abilitare il supporto a Gutenberg.
        );

        register_post_type('poi', $args);
    }
    add_action('init', 'create_poi_post_type', 0);
}
