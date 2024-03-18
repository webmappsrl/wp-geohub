<?php

function ensure_acf_field_for_tracks() {
    if(function_exists('acf_add_local_field_group')) {

        acf_add_local_field_group(array(
            'key' => 'geohub_group',
            'title' => 'Dettagli Track',
            'fields' => array (
                array(
                    'key' => 'geohub_group_geohub_id',
                    'label' => 'Geohub ID',
                    'name' => 'geohub_id',
                    'type' => 'number',
                    'instructions' => 'Inserisci l\'ID originale della track',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array (
                        'width' => '',
                        'class' => '',
                        'id' => '',
                    ),
                    'default_value' => '',
                    'placeholder' => '',
                    'prepend' => '',
                    'append' => '',
                    'maxlength' => '',
                )
            ),
            'location' => array (
                array (
                    array (
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'track',
                    ),
                ),
            ),
            'menu_order' => 0,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen' => '',
            'active' => true,
            'description' => '',
        ));
    }
}
add_action('acf/init', 'ensure_acf_field_for_tracks');