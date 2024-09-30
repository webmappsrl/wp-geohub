<?php
if (!is_admin()) {
    add_shortcode('wm_single_layer', 'wm_single_layer');
}

function wm_single_layer($atts)
{
    if (defined('ICL_LANGUAGE_CODE')) {
        $language = ICL_LANGUAGE_CODE;
    } else {
        $language = 'it';
    }
    extract(shortcode_atts(array(
        'layer' => '',
    ), $atts));

    $layer_api_base = get_option('layer_api');
    $layer_url = "{$layer_api_base}{$layer}";
    $layer_data = json_decode(file_get_contents($layer_url), true);

    $featured_image_url = $layer_data['featureImage']['url'] ?? get_stylesheet_directory_uri() . '/assets/images/background.jpg';
    $title = $layer_data['title'][$language] ?? null;
    $subtitle = $layer_data['subtitle'][$language] ?? null;
    $description = $layer_data['description'][$language] ?? null;


    ob_start();
?>
    <section class="l-section wpb_row height_small with_img with_overlay wm_header_section">
        <div class="l-section-img loaded wm-header-image" style="background-image: url(<?= $featured_image_url; ?>);"></div>
        <div class="l-section-h i-cf wm_header_wrapper">
        </div>
    </section>

    <div class="wm_layer_wrapper">
        <?php if ($title) : ?>
            <h1 class="wm_header_title"><?= $title; ?></h1>
        <?php endif; ?>

        <div class="wm_body_section">
            <?php if ($subtitle) : ?>
                <h5 class="wm_description"><?= $subtitle; ?></h5>
            <?php endif; ?>
            <?php if ($description) : ?>
                <div class="wm_description"><?= $description; ?></div>
            <?php endif; ?>
        </div>
        <div class="wm_body_section">
            <?php echo do_shortcode("[wm_grid_track layer_id='{$layer}']"); ?>
        </div>
    </div>
<?php
    return ob_get_clean();
}
?>