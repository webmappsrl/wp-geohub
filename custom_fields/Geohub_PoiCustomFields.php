<?php

function ensure_acf_field_for_pois() {
    if(function_exists('acf_add_local_field_group')) {

        acf_add_local_field_group(array(
            'key' => 'geohub_group_poi',
            'title' => 'Dettagli Poi',
            'fields' => array (
                array(
                    'key' => 'geohub_group_poi_geohub_id',
                    'label' => 'Geohub ID',
                    'name' => 'geohub_poi_id',
                    'type' => 'number',
                    'instructions' => 'Inserisci l\'ID originale della poi',
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
                        'value' => 'poi',
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
add_action('acf/init', 'ensure_acf_field_for_pois');