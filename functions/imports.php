<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

// Shortcodes
$shortcodesPath = 'wp-content/plugins/wp-geohub/shortcodes';
requireAllPHPFilesInDirectory($shortcodesPath);


//Swiper Slider CSS da CDN
function child_theme_enqueue_swiper()
{
    wp_enqueue_style('swiper-css', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css');
    wp_enqueue_script('swiper-js', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js', array(), null, true);
}
add_action('wp_enqueue_scripts', 'child_theme_enqueue_swiper');

// Lightbox2 CSS and JS from CDN
function child_theme_enqueue_lightbox2_cdn()
{
    wp_enqueue_style('lightbox2-css', 'https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/css/lightbox.min.css');
    wp_enqueue_script('lightbox2-js', 'https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/js/lightbox.min.js', array('jquery'), '', true);
    add_action('wp_footer', 'configure_lightbox2');
}
add_action('wp_enqueue_scripts', 'child_theme_enqueue_lightbox2_cdn');



//Font awesome
function load_font_awesome()
{
    wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css');
}
add_action('wp_enqueue_scripts', 'load_font_awesome');



