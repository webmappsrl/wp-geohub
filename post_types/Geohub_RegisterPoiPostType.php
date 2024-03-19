<?php
// Check if the class doesn't already exist
if (!class_exists('Poi_Post_Type')) {

    function create_poi_post_type() {
        $labels = array(
            'name'                  => _x('Pois', 'Post Type General Name', 'geohub'),
            'singular_name'         => _x('Poi', 'Post Type Singular Name', 'geohub'),
            'menu_name'             => __('Pois', 'geohub'),
            'name_admin_bar'        => __('Poi', 'geohub'),
            'archives'              => __('Archivi Poi', 'geohub'),
            'attributes'            => __('Attributi Poi', 'geohub'),
            'parent_item_colon'     => __('Parente Poi:', 'geohub'),
            'all_items'             => __('Tutti i Pois', 'geohub'),
            'add_new_item'          => __('Aggiungi Nuovo Poi', 'geohub'),
            'add_new'               => __('Aggiungi Nuovo', 'geohub'),
            'new_item'              => __('Nuovo Poi', 'geohub'),
            'edit_item'             => __('Modifica Poi', 'geohub'),
            'update_item'           => __('Aggiorna Poi', 'geohub'),
            'view_item'             => __('Vedi Poi', 'geohub'),
            'view_items'            => __('Vedi Pois', 'geohub'),
            'search_items'          => __('Cerca Poi', 'geohub'),
            'not_found'             => __('Non trovato', 'geohub'),
            'not_found_in_trash'    => __('Non trovato nel cestino', 'geohub'),
            'featured_image'        => __('Immagine in Evidenza', 'geohub'),
            'set_featured_image'    => __('Imposta immagine in evidenza', 'geohub'),
            'remove_featured_image' => __('Rimuovi immagine in evidenza', 'geohub'),
            'use_featured_image'    => __('Usa come immagine in evidenza', 'geohub'),
            'insert_into_item'      => __('Inserisci nel Poi', 'geohub'),
            'uploaded_to_this_item' => __('Caricato su questo Poi', 'geohub'),
            'items_list'            => __('Lista Pois', 'geohub'),
            'items_list_navigation' => __('Navigazione Lista Pois', 'geohub'),
            'filter_items_list'     => __('Filtra Lista Pois', 'geohub'),
        );
        
        $args = array(
            'label'                 => __('Poi', 'geohub'),
            'description'           => __('Post Type per i Pois', 'geohub'),
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
        
        register_post_type('poi', $args);
    }
    add_action('init', 'create_poi_post_type', 0);
}
