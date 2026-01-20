<?php
if (!is_admin()) {
	add_shortcode('wm_single_track', 'wm_single_track');
}

function wm_single_track($atts)
{
	if (defined('ICL_LANGUAGE_CODE')) {
		$language = ICL_LANGUAGE_CODE;
	} else {
		$language = 'it';
	}

	$supported_languages = ['it', 'en', 'fr', 'de', 'es', 'nl', 'sq'];

	if (!in_array($language, $supported_languages)) {
		$language = substr(get_locale(), 0, 2);
	}

	if (!in_array($language, $supported_languages)) {
		$language = 'en';
	}

	extract(shortcode_atts(array(
		'track_id' => '',
	), $atts));

	$single_track_base_url = get_option('track_url');
	$geojson_url = $single_track_base_url . $track_id . ".json";

	$track_data = json_decode(file_get_contents($geojson_url), true);
	$track = $track_data['properties'] ?? [];
	$track_geometry = $track_data['geometry'] ?? null;

	// Enqueue Leaflet for shortcode
	wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4');
	wp_enqueue_style('leaflet-fullscreen-css', 'https://unpkg.com/leaflet-fullscreen@1.0.2/dist/Leaflet.fullscreen.css', array('leaflet-css'), '1.0.2');
	wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true);
	wp_enqueue_script('leaflet-fullscreen-js', 'https://unpkg.com/leaflet-fullscreen@1.0.2/dist/Leaflet.fullscreen.min.js', array('leaflet-js'), '1.0.2', true);

	$description = null;
	$excerpt = null;
	$title = null;
	$featured_image = null;
	$gallery = [];
	$gpx = null;
	$activity = null;
	$dem_data = null;

	if ($track) {
		$description = $track['description'][$language] ?? null;
		$excerpt = $track['excerpt'][$language] ?? null;
		$title = $track['name'][$language] ?? null;
		$default_image = plugins_url('wm-package/assets/default_image.png');
		$featured_image_url = isset($track['feature_image']['url']) && !empty($track['feature_image']['url'])
			? $track['feature_image']['url']
			: $default_image;
		$featured_image = isset($track['feature_image']['sizes']['1440x500']) && !empty($track['feature_image']['sizes']['1440x500'])
			? $track['feature_image']['sizes']['1440x500']
			: $featured_image_url;
		$gallery = $track['image_gallery'] ?? [];
		$gpx = $track['gpx_url'];
		$activity = $track['taxonomy']['activity'] ?? [];

		// Extract and decode dem_data
		if (isset($track['dem_data'])) {
			$dem_data_raw = $track['dem_data'];
			if (is_string($dem_data_raw)) {
				$dem_data = json_decode($dem_data_raw, true);
			} else if (is_array($dem_data_raw)) {
				$dem_data = $dem_data_raw;
			}
		}
	}
	ob_start();
?>
	<div class="wm_content_wrapper">
		<!-- 1. Featured Image -->
		<?php if ($featured_image) : ?>
			<div class="wm_featured_image">
				<img src="<?= esc_url($featured_image) ?>" alt="<?= esc_attr($title) ?>" />
			</div>
		<?php endif; ?>

		<!-- 2. Title -->
		<?php if ($title) : ?>
			<h1 class="wm_title">
				<?= esc_html($title) ?>
			</h1>
		<?php endif; ?>

		<!-- 3. Taxonomies -->
		<?php if (!empty($activity)) : ?>
			<div class="wm_taxonomies">
				<?php foreach ($activity as $type) : ?>
					<span class="wm_taxonomy_item">
						<?php if (!empty($type['icon'])) : ?>
							<span class="wm_taxonomy_icon"><?= wm_render_svg_icon($type['icon']) ?></span>
						<?php endif; ?>
						<span class="wm_taxonomy_name"><?= esc_html($type['name'][$language] ?? 'N/A') ?></span>
					</span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<!-- 4. Map -->
		<?php if (!empty($track_geometry)) : ?>
			<div class="wm_map">
				<div id="wm-leaflet-map-track-<?= esc_attr($track_id) ?>" class="wm_leaflet_map" data-geometry='<?= esc_attr(json_encode($track_geometry)) ?>'></div>
			</div>
		<?php endif; ?>

		<!-- 4.5. Technical Details -->
		<?php if (!empty($dem_data) && is_array($dem_data)) : ?>
			<div class="wm_technical_details">
				<h2 class="wm_technical_details_title"><?= __('Technical Details', 'wm-package') ?></h2>
				<div class="wm_technical_details_grid">
					<?php if (isset($dem_data['distance']) && $dem_data['distance'] !== null) : ?>
						<div class="wm_technical_detail_item">
							<span class="wm_technical_detail_label"><?= __('Distance', 'wm-package') ?>:</span>
							<span class="wm_technical_detail_value"><?= esc_html(number_format($dem_data['distance'], 1)) ?> km</span>
						</div>
					<?php endif; ?>

					<?php if (isset($dem_data['ele_min']) && $dem_data['ele_min'] !== null) : ?>
						<div class="wm_technical_detail_item">
							<span class="wm_technical_detail_label"><?= __('Min Elevation', 'wm-package') ?>:</span>
							<span class="wm_technical_detail_value"><?= esc_html($dem_data['ele_min']) ?> m</span>
						</div>
					<?php endif; ?>

					<?php if (isset($dem_data['ele_max']) && $dem_data['ele_max'] !== null) : ?>
						<div class="wm_technical_detail_item">
							<span class="wm_technical_detail_label"><?= __('Max Elevation', 'wm-package') ?>:</span>
							<span class="wm_technical_detail_value"><?= esc_html($dem_data['ele_max']) ?> m</span>
						</div>
					<?php endif; ?>

					<?php if (isset($dem_data['ele_from']) && $dem_data['ele_from'] !== null) : ?>
						<div class="wm_technical_detail_item">
							<span class="wm_technical_detail_label"><?= __('Start Elevation', 'wm-package') ?>:</span>
							<span class="wm_technical_detail_value"><?= esc_html($dem_data['ele_from']) ?> m</span>
						</div>
					<?php endif; ?>

					<?php if (isset($dem_data['ele_to']) && $dem_data['ele_to'] !== null) : ?>
						<div class="wm_technical_detail_item">
							<span class="wm_technical_detail_label"><?= __('End Elevation', 'wm-package') ?>:</span>
							<span class="wm_technical_detail_value"><?= esc_html($dem_data['ele_to']) ?> m</span>
						</div>
					<?php endif; ?>

					<?php if (isset($dem_data['ascent']) && $dem_data['ascent'] !== null) : ?>
						<div class="wm_technical_detail_item">
							<span class="wm_technical_detail_label"><?= __('Ascent', 'wm-package') ?>:</span>
							<span class="wm_technical_detail_value"><?= esc_html($dem_data['ascent']) ?> m</span>
						</div>
					<?php endif; ?>

					<?php if (isset($dem_data['descent']) && $dem_data['descent'] !== null) : ?>
						<div class="wm_technical_detail_item">
							<span class="wm_technical_detail_label"><?= __('Descent', 'wm-package') ?>:</span>
							<span class="wm_technical_detail_value"><?= esc_html($dem_data['descent']) ?> m</span>
						</div>
					<?php endif; ?>

					<?php if (isset($dem_data['duration_forward_hiking']) && $dem_data['duration_forward_hiking'] !== null) : ?>
						<div class="wm_technical_detail_item">
							<span class="wm_technical_detail_label"><?= __('Duration Forward (Hiking)', 'wm-package') ?>:</span>
							<span class="wm_technical_detail_value"><?= esc_html($dem_data['duration_forward_hiking']) ?> <?= __('min', 'wm-package') ?></span>
						</div>
					<?php endif; ?>

					<?php if (isset($dem_data['duration_backward_hiking']) && $dem_data['duration_backward_hiking'] !== null) : ?>
						<div class="wm_technical_detail_item">
							<span class="wm_technical_detail_label"><?= __('Duration Backward (Hiking)', 'wm-package') ?>:</span>
							<span class="wm_technical_detail_value"><?= esc_html($dem_data['duration_backward_hiking']) ?> <?= __('min', 'wm-package') ?></span>
						</div>
					<?php endif; ?>

					<?php if (isset($dem_data['duration_forward_bike']) && $dem_data['duration_forward_bike'] !== null) : ?>
						<div class="wm_technical_detail_item">
							<span class="wm_technical_detail_label"><?= __('Duration Forward (Bike)', 'wm-package') ?>:</span>
							<span class="wm_technical_detail_value"><?= esc_html($dem_data['duration_forward_bike']) ?> <?= __('min', 'wm-package') ?></span>
						</div>
					<?php endif; ?>

					<?php if (isset($dem_data['duration_backward_bike']) && $dem_data['duration_backward_bike'] !== null) : ?>
						<div class="wm_technical_detail_item">
							<span class="wm_technical_detail_label"><?= __('Duration Backward (Bike)', 'wm-package') ?>:</span>
							<span class="wm_technical_detail_value"><?= esc_html($dem_data['duration_backward_bike']) ?> <?= __('min', 'wm-package') ?></span>
						</div>
					<?php endif; ?>

					<?php if (isset($dem_data['duration_forward']) && $dem_data['duration_forward'] !== null) : ?>
						<div class="wm_technical_detail_item">
							<span class="wm_technical_detail_label"><?= __('Duration Forward', 'wm-package') ?>:</span>
							<span class="wm_technical_detail_value"><?= esc_html($dem_data['duration_forward']) ?> <?= __('min', 'wm-package') ?></span>
						</div>
					<?php endif; ?>

					<?php if (isset($dem_data['duration_backward']) && $dem_data['duration_backward'] !== null) : ?>
						<div class="wm_technical_detail_item">
							<span class="wm_technical_detail_label"><?= __('Duration Backward', 'wm-package') ?>:</span>
							<span class="wm_technical_detail_value"><?= esc_html($dem_data['duration_backward']) ?> <?= __('min', 'wm-package') ?></span>
						</div>
					<?php endif; ?>

					<?php if (isset($dem_data['round_trip']) && $dem_data['round_trip'] !== null) : ?>
						<div class="wm_technical_detail_item">
							<span class="wm_technical_detail_label"><?= __('Round Trip', 'wm-package') ?>:</span>
							<span class="wm_technical_detail_value"><?= $dem_data['round_trip'] ? __('Yes', 'wm-package') : __('No', 'wm-package') ?></span>
						</div>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>

		<!-- 5. Description -->
		<?php if ($description) : ?>
			<div class="wm_description">
				<?php echo wp_kses_post($description); ?>
			</div>
		<?php endif; ?>

		<!-- 6. Gallery -->
		<?php if (is_array($gallery) && !empty($gallery)) : ?>
			<div class="wm_gallery">
				<div class="swiper-container">
					<div class="swiper-wrapper">
						<?php foreach ($gallery as $image) : ?>
							<div class="swiper-slide">
								<?php
								$thumbnail_url = isset($image['thumbnail']) ? esc_url($image['thumbnail']) : '';
								$high_res_url = isset($image['url']) ? esc_url($image['url']) : $thumbnail_url;
								$caption = isset($image['caption'][$language]) ? esc_attr($image['caption'][$language]) : '';
								if ($thumbnail_url) : ?>
									<a href="<?= esc_url($high_res_url) ?>" data-lightbox="track-gallery" data-title="<?= esc_attr($caption) ?>">
										<img src="<?= esc_url($thumbnail_url) ?>" alt="<?= esc_attr($caption) ?>" loading="lazy">
									</a>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
					<div class="swiper-pagination"></div>
					<div class="swiper-button-prev"></div>
					<div class="swiper-button-next"></div>
				</div>
			</div>
		<?php endif; ?>

		<!-- 7. Download Links -->
		<?php if (!empty($gpx)) : ?>
			<div class="wm_download_links">
				<a class="wm_download_link" href="<?= esc_url($gpx) ?>">
					<i class="fa fa-download"></i>
					<?= __('Download GPX', 'wm-package') ?>
				</a>
			</div>
		<?php endif; ?>
	</div>

	<script>
		document.addEventListener('DOMContentLoaded', function() {
			var swiper = new Swiper('.swiper-container', {
				slidesPerView: 1,
				spaceBetween: 10,
				freeMode: true,
				loop: true,
				pagination: {
					el: '.swiper-pagination',
					clickable: true,
				},
				navigation: {
					nextEl: '.swiper-button-next',
					prevEl: '.swiper-button-prev',
				},
			});

			// Initialize Leaflet map for Track
			<?php if (!empty($track_geometry)) : ?>
				var mapElement = document.getElementById('wm-leaflet-map-track-<?= esc_js($track_id) ?>');
				if (mapElement && typeof wmInitLeafletMap !== 'undefined') {
					var geometryJson = mapElement.getAttribute('data-geometry');
					wmInitLeafletMap('wm-leaflet-map-track-<?= esc_js($track_id) ?>', geometryJson);
				}
			<?php endif; ?>
		});
	</script>

<?php
	return ob_get_clean();
}
?>