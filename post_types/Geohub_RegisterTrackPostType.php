<?php
// Check if the class doesn't already exist
if (!class_exists('Track_Post_Type')) {

    function create_track_post_type() {
        $labels = array(
            'name'                  => _x('Tracks', 'Post Type General Name', 'geohub'),
            'singular_name'         => _x('Track', 'Post Type Singular Name', 'geohub'),
            'menu_name'             => __('Tracks', 'geohub'),
            'name_admin_bar'        => __('Track', 'geohub'),
            'archives'              => __('Archivi Track', 'geohub'),
            'attributes'            => __('Attributi Track', 'geohub'),
            'parent_item_colon'     => __('Parente Track:', 'geohub'),
            'all_items'             => __('Tutti i Tracks', 'geohub'),
            'add_new_item'          => __('Aggiungi Nuovo Track', 'geohub'),
            'add_new'               => __('Aggiungi Nuovo', 'geohub'),
            'new_item'              => __('Nuovo Track', 'geohub'),
            'edit_item'             => __('Modifica Track', 'geohub'),
            'update_item'           => __('Aggiorna Track', 'geohub'),
            'view_item'             => __('Vedi Track', 'geohub'),
            'view_items'            => __('Vedi Tracks', 'geohub'),
            'search_items'          => __('Cerca Track', 'geohub'),
            'not_found'             => __('Non trovato', 'geohub'),
            'not_found_in_trash'    => __('Non trovato nel cestino', 'geohub'),
            'featured_image'        => __('Immagine in Evidenza', 'geohub'),
            'set_featured_image'    => __('Imposta immagine in evidenza', 'geohub'),
            'remove_featured_image' => __('Rimuovi immagine in evidenza', 'geohub'),
            'use_featured_image'    => __('Usa come immagine in evidenza', 'geohub'),
            'insert_into_item'      => __('Inserisci nel Track', 'geohub'),
            'uploaded_to_this_item' => __('Caricato su questo Track', 'geohub'),
            'items_list'            => __('Lista Tracks', 'geohub'),
            'items_list_navigation' => __('Navigazione Lista Tracks', 'geohub'),
            'filter_items_list'     => __('Filtra Lista Tracks', 'geohub'),
        );
        
        $args = array(
            'label'                 => __('Track', 'geohub'),
            'description'           => __('Post Type per i Tracks', 'geohub'),
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