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
        'random' => 'false'
    ), $atts));

    $tracks = [];
    $layer_ids_array = !empty($layer_ids) ? explode(',', $layer_ids) : (!empty($layer_id) ? [$layer_id] : []);
    $layer_api_base = get_option('layer_api');

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

    if ('true' === $random) {
        shuffle($tracks);
    }
    if ($quantity > 0 && count($tracks) > $quantity) {
        $tracks = array_slice($tracks, 0, $quantity);
    }

    ob_start();
?>
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
<?php
    return ob_get_clean();
}
