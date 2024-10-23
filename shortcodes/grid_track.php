<?php
if (!is_admin()) {
    add_shortcode('wm_grid_track', 'wm_grid_track');
}

function wm_grid_track($atts)
{
    if (defined('ICL_LANGUAGE_CODE')) {
        $language = ICL_LANGUAGE_CODE;
    } else {
        $language = 'it';
    }

    extract(shortcode_atts(array(
        'layer_id' => '',
        'layer_ids' => '',
        'quantity' => -1,
        'random' => 'false',
        'content' => false
    ), $atts));

    $tracks = [];
    
    $layer_api_base = get_option('layer_api');

    if(!empty($layer_id) && ($content)) {

        $layer_url = "{$layer_api_base}{$layer_id}";
        $layer_data = json_decode(file_get_contents($layer_url), true);
        
        $featured_image_url = $layer_data['featureImage']['url'] ?? get_stylesheet_directory_uri() . '/assets/images/background.jpg';
        $title = $layer_data['title'][$language] ?? null;
        $subtitle = $layer_data['subtitle'][$language] ?? null;
        $description = $layer_data['description'][$language] ?? null;
    }


    $layer_ids_array = !empty($layer_ids) ? explode(',', $layer_ids) : (!empty($layer_id) ? [$layer_id] : []);



    foreach ($layer_ids_array as $id) {
        if (empty($id)) continue;
        $layer_url = "{$layer_api_base}{$id}";
        $response = wp_remote_get($layer_url);

        if (is_wp_error($response)) continue;

        $layer_data = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($layer_data['tracks'])) {
            foreach ($layer_data['tracks'] as $track) {
                if (!empty($layer_data['taxonomy_themes'][0]['icon'])) {
                    $track['svg_icon'] = $layer_data['taxonomy_themes'][0]['icon'];
                }
                $tracks[] = $track;
            }
        }
    }
    usort($tracks, function ($a, $b) use ($language) {
        $nameA = strtolower($a['name'][$language] ?? '');
        $nameB = strtolower($b['name'][$language] ?? '');
        return strcmp($nameA, $nameB);
    });
    if ('true' === $random) {
        shuffle($tracks);
    }
    if ($quantity > 0 && count($tracks) > $quantity) {
        $tracks = array_slice($tracks, 0, $quantity);
    }

    ob_start();
?>    
    <?php if(!empty($featured_image_url)) : ?>
    <section class="l-section wpb_row height_small with_img with_overlay wm_header_section">
        <div class="l-section-img loaded wm-header-image" style="background-image: url(<?= $featured_image_url; ?>);"></div>
        <div class="l-section-h i-cf wm_header_wrapper"></div>
    </section>
    <?php endif; ?> 

    <?php if(!empty($title) || !empty($subtitle) || !empty($description)) : ?>
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
    <?php endif; ?>
            <div class="wm_tracks_grid">
                <?php foreach ($tracks as $track) : ?>
                    <div class="wm_grid_track_item">
                        <?php
                        $name = $track['name'][$language] ?? '';
                        $feature_image_url = $track['featureImage']['thumbnail'] ?? '/assets/images/background.jpg';
                        $name_url = wm_custom_slugify($name);
                        $language_prefix = $language === 'en' ? '/en' : '';
                        $track_page_url = "{$language_prefix}/track/{$name_url}/";
                        $svg_icon = $track['svg_icon'] ?? '';
                        ?>
                        <a href="<?= esc_url($track_page_url); ?>">
                        <?php if ($feature_image_url) : ?>
                            <div class="wm_grid_track_image" style="background-image: url('<?= esc_url($feature_image_url); ?>');">
                            <?php if ($svg_icon) : ?>
                                <div class="wm_grid_icon"> <?= $svg_icon; ?></div>
                            <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($name) : ?>
                            <div class="wm_grid_track_name"><?= esc_html($name); ?></div>
                        <?php endif; ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
    <?php if(!empty($title) || !empty($subtitle) || !empty($description)) : ?>
        </div>
    </div>
    <?php endif; ?>


<?php
    return ob_get_clean();
}
