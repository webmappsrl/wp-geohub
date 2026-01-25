<?php
if (!is_admin()) {
	add_shortcode('wm_single_poi', 'wm_single_poi');
}

function getPoiById($pois, $desiredId)
{
	foreach ($pois['features'] as $feature) {
		if (isset($feature['properties']['id']) && $feature['properties']['id'] == $desiredId) {
			return $feature;
		}
	}
	return null;
}

function wm_single_poi($atts)
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
		'poi_id' => '',
	), $atts));

	$base_url = get_option('poi_url');

	$response = wp_remote_get($base_url);
	if (is_wp_error($response)) {
		return 'Failed to load POI data.';
	}
	$pois = json_decode($response["body"], true);

	$poi = getPoiById($pois, $poi_id);

	if (!$poi || !isset($poi['properties'])) {
		return 'Failed to load POI data.';
	}

	$poi_properties = $poi['properties'];
	$poi_geometry = $poi['geometry'] ?? null;
	$default_image = plugins_url('wm-package/assets/default_image.png');

	// Enqueue Leaflet for shortcode
	wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4');
	wp_enqueue_style('leaflet-fullscreen-css', 'https://unpkg.com/leaflet-fullscreen@1.0.2/dist/Leaflet.fullscreen.css', array('leaflet-css'), '1.0.2');
	wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true);
	wp_enqueue_script('leaflet-fullscreen-js', 'https://unpkg.com/leaflet-fullscreen@1.0.2/dist/Leaflet.fullscreen.min.js', array('leaflet-js'), '1.0.2', true);

	$title = null;
	$description = null;
	$excerpt = null;
	$featured_image = null;
	$contact_phone = null;
	$contact_email = null;
	$addr_street = null;
	$addr_postcode = null;
	$addr_locality = null;
	$gallery = null;
	$related_urls = null;
	$poi_types = null;

	if (!empty($poi_properties)) {
		$title = $poi_properties['name'][$language] ?? '';
		$description = $poi_properties['description'][$language] ?? '';
		$excerpt = $poi_properties['excerpt'][$language] ?? '';
		$featured_image_url = isset($poi_properties['feature_image']['url']) && !empty($poi_properties['feature_image']['url'])
			? $poi_properties['feature_image']['url']
			: $default_image;

		$featured_image = isset($poi_properties['feature_image']['sizes']['1440x500']) && !empty($poi_properties['feature_image']['sizes']['1440x500'])
			? $poi_properties['feature_image']['sizes']['1440x500']
			: $featured_image_url;
		$contact_phone = $poi_properties['contact_phone'] ?? '';
		$contact_email = $poi_properties['contact_email'] ?? '';
		$addr_street = $poi_properties['addr_street'] ?? '';
		$addr_postcode = $poi_properties['addr_postcode'] ?? '';
		$addr_locality = $poi_properties['addr_locality'] ?? '';
		$gallery = $poi_properties['image_gallery'] ?? [];
		$related_urls = $poi_properties['related_url'] ?? [];
		$poi_types = $poi_properties['taxonomy']['poi_types'] ?? [];
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
		<?php if (!empty($poi_types)) : ?>
			<div class="wm_taxonomies">
				<?php foreach ($poi_types as $type) : ?>
					<span class="wm_taxonomy_item">
						<span class="wm_taxonomy_name"><?= esc_html($type['name'][$language] ?? 'N/A') ?></span>
					</span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<!-- 4. Map and Info Links Container -->
		<?php if (!empty($poi_geometry) || !empty($addr_street) || !empty($addr_postcode) || !empty($addr_locality) || !empty($contact_phone) || !empty($contact_email) || !empty($related_urls)) : ?>
			<div class="wm_map_info_wrapper">
				<!-- 4. Map -->
				<?php if (!empty($poi_geometry)) : ?>
					<div class="wm_map">
						<div id="wm-leaflet-map-poi-<?= esc_attr($poi_id) ?>" class="wm_leaflet_map" data-geometry='<?= esc_attr(json_encode($poi_geometry)) ?>'></div>
					</div>
				<?php endif; ?>

				<!-- 4.5. Info Links -->
				<?php if (!empty($addr_street) || !empty($addr_postcode) || !empty($addr_locality) || !empty($contact_phone) || !empty($contact_email) || !empty($related_urls)) : ?>
					<div class="wm_info_details">
						<h2 class="wm_info_details_title"><?= __('Contact Information', 'wm-package') ?></h2>
						<div class="wm_info_details_grid">
							<?php if (!empty($addr_street) || !empty($addr_postcode) || !empty($addr_locality)) : ?>
								<?php
								$address = trim($addr_street . ', ' . $addr_postcode . ' ' . $addr_locality, ', ');
								?>
								<div class="wm_info_detail_item">
									<span class="wm_info_detail_label"><?= __('Address', 'wm-package') ?>:</span>
									<span class="wm_info_detail_value"><?= esc_html($address) ?></span>
								</div>
							<?php endif; ?>

							<?php if (!empty($contact_phone)) : ?>
								<div class="wm_info_detail_item">
									<span class="wm_info_detail_label"><?= __('Phone', 'wm-package') ?>:</span>
									<span class="wm_info_detail_value"><?= esc_html($contact_phone) ?></span>
								</div>
							<?php endif; ?>

							<?php if (!empty($contact_email)) : ?>
								<div class="wm_info_detail_item">
									<span class="wm_info_detail_label"><?= __('Email', 'wm-package') ?>:</span>
									<span class="wm_info_detail_value">
										<a href="mailto:<?= esc_attr($contact_email) ?>"><?= esc_html($contact_email) ?></a>
									</span>
								</div>
							<?php endif; ?>

							<?php if (!empty($related_urls)) : ?>
								<div class="wm_info_detail_item">
									<span class="wm_info_detail_label"><?= __('Related Links', 'wm-package') ?>:</span>
									<span class="wm_info_detail_value">
										<?php
										$urls_output = [];
										foreach ($related_urls as $url_name => $url) {
											$urls_output[] = '<a href="' . esc_url($url) . '" target="_blank">' . esc_html($url_name) . '</a>';
										}
										echo implode(', ', $urls_output);
										?>
									</span>
								</div>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
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
									<a href="<?= esc_url($high_res_url) ?>" data-lightbox="poi-gallery" data-title="<?= esc_attr($caption) ?>">
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
	</div>

	<script>
		document.addEventListener('DOMContentLoaded', function() {
			if (typeof Swiper !== 'undefined') {
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
			}

			// Initialize Leaflet map for POI
			<?php if (!empty($poi_geometry)) : ?>
				var mapElement = document.getElementById('wm-leaflet-map-poi-<?= esc_js($poi_id) ?>');
				if (mapElement && typeof wmInitLeafletMap !== 'undefined') {
					var geometryJson = mapElement.getAttribute('data-geometry');
					wmInitLeafletMap('wm-leaflet-map-poi-<?= esc_js($poi_id) ?>', geometryJson);
				}
			<?php endif; ?>
		});
	</script>

<?php

	return ob_get_clean();
}
?>