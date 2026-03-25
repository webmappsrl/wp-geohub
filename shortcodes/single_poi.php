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

/**
 * Normalize a string for name matching (lowercase, trim, collapse multiple spaces to one).
 * Used for tappa → track match by name when id does not match.
 *
 * @param string $name
 * @return string
 */
function wm_normalize_name_for_match($name)
{
	if (!is_string($name) || $name === '') {
		return '';
	}
	$n = trim($name);
	$n = mb_strtolower($n, 'UTF-8');
	$n = preg_replace('/\s+/u', ' ', $n);
	return $n;
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
		return __('Failed to load POI data.', 'wm-package');
	}
	$pois = json_decode($response["body"], true);

	$poi = getPoiById($pois, $poi_id);

	if (!$poi || !isset($poi['properties'])) {
		return __('Failed to load POI data.', 'wm-package');
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
	$prenota_rifugio_url = null;
	$regione = null;
	$tappe = [];
	$tappe_with_urls = [];
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

		// For osm2cai shards: properties are primary; sicai is fallback only when the main field is empty
		if (function_exists('wm_is_osm2cai_shard_type')) {
			$shard = get_option('wm_shard', 'geohub');
			$sicai = isset($poi_properties['sicai']) && is_array($poi_properties['sicai']) ? $poi_properties['sicai'] : [];
			// Prenota button (osm2cai only): first from related_url["Prenota"], then fallback to sicai.link; if both missing, button is hidden
			if (wm_is_osm2cai_shard_type($shard)) {
				if (is_array($related_urls) && isset($related_urls['Prenota']) && trim((string) $related_urls['Prenota']) !== '') {
					$prenota_rifugio_url = trim((string) $related_urls['Prenota']);
				} elseif (!empty($sicai['link']) && trim((string) $sicai['link']) !== '') {
					$prenota_rifugio_url = trim((string) $sicai['link']);
				}
			}
			if (wm_is_osm2cai_shard_type($shard) && !empty($sicai)) {
				// Address, phone, email: use sicai only when the primary (properties) value is empty
				if ((string) $addr_street === '') {
					$addr_street = (isset($sicai['addr:street']) && (string) $sicai['addr:street'] !== '') ? (string) $sicai['addr:street'] : $addr_street;
				}
				if ((string) $addr_postcode === '') {
					$addr_postcode = (isset($sicai['addr:postcode']) && (string) $sicai['addr:postcode'] !== '') ? (string) $sicai['addr:postcode'] : $addr_postcode;
				}
				if ((string) $addr_locality === '') {
					$addr_locality = (isset($sicai['addr:city']) && (string) $sicai['addr:city'] !== '') ? (string) $sicai['addr:city'] : $addr_locality;
				}
				if ((string) $contact_phone === '') {
					$contact_phone = (isset($sicai['phone']) && (string) $sicai['phone'] !== '') ? (string) $sicai['phone'] : $contact_phone;
				}
				if ((string) $contact_email === '') {
					$contact_email = (isset($sicai['email']) && (string) $sicai['email'] !== '') ? (string) $sicai['email'] : $contact_email;
				}
				// related_url is primary; use sicai.website only when related_url is empty
				if (empty($related_urls) && !empty($sicai['website'])) {
					$related_urls = [__('Click here', 'wm-package') => $sicai['website']];
				}
				if ((string) $regione === '' && isset($sicai['Regione']) && (string) $sicai['Regione'] !== '') {
					$regione = (string) $sicai['Regione'];
				}
				// Tappe: only in sicai, use as fallback when no tappe from elsewhere (currently only sicai provides tappe)
				if (empty($tappe)) {
					$tappe = [];
					foreach ($sicai as $key => $value) {
						if (preg_match('/^tappa(\d+)$/', $key, $m) && $value !== null && (string) $value !== '') {
							$tappe[(int) $m[1]] = (string) $value;
						}
					}
					ksort($tappe, SORT_NUMERIC);
					$tappe = array_values($tappe);
				}
			}
		}

		// Resolve track URL for each stage: first by wm_track_id, then fallback by normalized name (same as related POI in tracks)
		if (!empty($tappe)) {
			$track_urls_by_id = [];
			$track_urls_by_name = [];
			$track_posts = get_posts([
				'post_type'   => 'track',
				'post_status' => 'publish',
				'posts_per_page' => -1,
			]);
			foreach ($track_posts as $track_post) {
				$tid = get_post_meta($track_post->ID, 'wm_track_id', true);
				if ($tid !== '' && $tid !== null) {
					$track_urls_by_id[(string) $tid] = get_permalink($track_post->ID);
				}
				$track_title = $track_post->post_title;
				if ($track_title !== '') {
					$key = wm_normalize_name_for_match($track_title);
					if ($key !== '') {
						$track_urls_by_name[$key] = get_permalink($track_post->ID);
					}
				}
			}
			foreach ($tappe as $tappa_name) {
				$url = isset($track_urls_by_id[(string) $tappa_name])
					? $track_urls_by_id[(string) $tappa_name]
					: (isset($track_urls_by_name[wm_normalize_name_for_match($tappa_name)]) ? $track_urls_by_name[wm_normalize_name_for_match($tappa_name)] : null);
				$tappe_with_urls[] = ['name' => $tappa_name, 'url' => $url];
			}
		}
	}
	// Get featured image display location setting
	$featured_image_location = get_option('featured_image_location', 'content');
	$use_page_header = ($featured_image_location === 'page-header');

	// Build valid gallery early for layout (map + description + gallery on left, sidebar on right)
	$valid_gallery = [];
	if (is_array($gallery) && !empty($gallery)) {
		foreach ($gallery as $image) {
			if ((isset($image['url']) && !empty($image['url'])) || (isset($image['thumbnail']) && !empty($image['thumbnail']))) {
				$valid_gallery[] = $image;
			}
		}
	}

	$has_map = !empty($poi_geometry);
	// For osm2cai: in Website section exclude "Prenota" (shown only in the Prenota button)
	$related_urls_for_website = is_array($related_urls) ? $related_urls : [];
	if (!empty($related_urls_for_website) && function_exists('wm_is_osm2cai_shard_type') && wm_is_osm2cai_shard_type(get_option('wm_shard', 'geohub')) && isset($related_urls_for_website['Prenota'])) {
		$related_urls_for_website = array_diff_key($related_urls_for_website, ['Prenota' => true]);
	}
	$has_info = !empty($tappe_with_urls) || !empty($regione) || !empty($addr_street) || !empty($addr_postcode) || !empty($addr_locality) || !empty($contact_phone) || !empty($contact_email) || !empty($related_urls) || !empty($prenota_rifugio_url);
	$has_sidebar_layout = $has_map || $has_info;

	ob_start();
?>
	<div class="wm_content_wrapper">
		<!-- 1. Featured Image -->
		<?php if ($featured_image && !$use_page_header) : ?>
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
						<span class="wm_taxonomy_name"><?= esc_html($type['name'][$language] ?? __('N/A', 'wm-package')) ?></span>
					</span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<!-- 4. Main content + optional sidebar (map, description, gallery left | contact info right) -->
		<?php if ($has_sidebar_layout) : ?>
			<div class="wm_detail_with_sidebar">
				<div class="wm_detail_main_content">
					<?php if ($has_map) : ?>
						<div class="wm_map">
							<div id="wm-leaflet-map-poi-<?= esc_attr($poi_id) ?>" class="wm_leaflet_map"
								data-geometry='<?= esc_attr(json_encode($poi_geometry)) ?>'
								data-poi-image='<?= esc_attr($featured_image) ?>'></div>
						</div>
					<?php endif; ?>

					<?php if (!empty($description)) : ?>
						<div class="wm_description">
							<?php echo wp_kses_post($description); ?>
						</div>
					<?php endif; ?>

					<?php if (!empty($valid_gallery)) : ?>
						<div class="wm_gallery">
							<div class="swiper-container">
								<div class="swiper-wrapper">
									<?php foreach ($valid_gallery as $image) : ?>
										<div class="swiper-slide">
											<?php
											$high_res_url = isset($image['url']) && !empty($image['url']) ? esc_url($image['url']) : '';
											$thumbnail_url = isset($image['thumbnail']) && !empty($image['thumbnail']) ? esc_url($image['thumbnail']) : '';
											$swiper_image_url = $high_res_url ?: $thumbnail_url;
											$lightbox_url = $high_res_url ?: $thumbnail_url;
											$caption = isset($image['caption'][$language]) ? esc_attr($image['caption'][$language]) : '';
											if ($swiper_image_url) : ?>
												<a href="<?= esc_url($lightbox_url) ?>" data-lightbox="poi-gallery" data-title="<?= esc_attr($caption) ?>">
													<img src="<?= esc_url($swiper_image_url) ?>" alt="<?= esc_attr($caption) ?>" loading="lazy">
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

				<?php if ($has_info) : ?>
					<aside class="wm_detail_sidebar">
						<div class="wm_info_details">
							<h2 class="wm_info_details_title"><?= __('Information', 'wm-package') ?></h2>
							<div class="wm_info_details_grid">
								<?php if (!empty($tappe_with_urls)) : ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-map-signs" aria-hidden="true"></i> <?= __('Stage', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value">
											<?php
											$tappa_parts = [];
											foreach ($tappe_with_urls as $t) {
												if (!empty($t['url'])) {
													$tappa_parts[] = '<a href="' . esc_url($t['url']) . '">' . esc_html($t['name']) . '</a>';
												} else {
													$tappa_parts[] = esc_html($t['name']);
												}
											}
											echo implode(', ', $tappa_parts);
											?>
										</span>
									</div>
								<?php endif; ?>

								<?php if (!empty($regione)) : ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-map-marker" aria-hidden="true"></i> <?= __('Region', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value"><?= esc_html($regione) ?></span>
									</div>
								<?php endif; ?>

								<?php if (!empty($addr_locality)) : ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-building" aria-hidden="true"></i> <?= __('City', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value"><?= esc_html($addr_locality) ?></span>
									</div>
								<?php endif; ?>

								<?php if (!empty($addr_street) || !empty($addr_postcode)) : ?>
									<?php $address = trim($addr_street . ', ' . $addr_postcode, ', '); ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-map-marker" aria-hidden="true"></i> <?= __('Address', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value"><?= esc_html($address) ?></span>
									</div>
								<?php endif; ?>

								<?php if (!empty($contact_phone)) : ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-phone" aria-hidden="true"></i> <?= __('Phone', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value"><?= esc_html($contact_phone) ?></span>
									</div>
								<?php endif; ?>

								<?php if (!empty($contact_email)) : ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-envelope" aria-hidden="true"></i> <?= __('Email', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value">
											<a href="mailto:<?= esc_attr($contact_email) ?>"><?= esc_html($contact_email) ?></a>
										</span>
									</div>
								<?php endif; ?>

								<?php if (!empty($related_urls_for_website)) : ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-globe" aria-hidden="true"></i> <?= __('Website', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value">
											<?php
											$urls_output = [];
											foreach ($related_urls_for_website as $url_name => $url) {
												$display_label = wm_website_link_label($url_name, $url);
												$urls_output[] = '<a href="' . esc_url($url) . '" target="_blank">' . esc_html($display_label) . '</a>';
											}
											echo implode(', ', $urls_output);
											?>
										</span>
									</div>
								<?php endif; ?>

								<?php if (!empty($prenota_rifugio_url)) : ?>
									<div class="wm_info_detail_item wm_info_detail_item--button">
										<a href="<?= esc_url($prenota_rifugio_url) ?>" target="_blank" rel="noopener" class="wm_btn wm_btn--prenota_rifugio"><?= esc_html(__('Book', 'wm-package')) ?></a>
									</div>
								<?php endif; ?>
							</div>
						</div>
					</aside>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<?php if (!empty($description)) : ?>
				<div class="wm_description">
					<?php echo wp_kses_post($description); ?>
				</div>
			<?php endif; ?>

			<?php if (!empty($valid_gallery)) : ?>
				<div class="wm_gallery">
					<div class="swiper-container">
						<div class="swiper-wrapper">
							<?php foreach ($valid_gallery as $image) : ?>
								<div class="swiper-slide">
									<?php
									$high_res_url = isset($image['url']) && !empty($image['url']) ? esc_url($image['url']) : '';
									$thumbnail_url = isset($image['thumbnail']) && !empty($image['thumbnail']) ? esc_url($image['thumbnail']) : '';
									$swiper_image_url = $high_res_url ?: $thumbnail_url;
									$lightbox_url = $high_res_url ?: $thumbnail_url;
									$caption = isset($image['caption'][$language]) ? esc_attr($image['caption'][$language]) : '';
									if ($swiper_image_url) : ?>
										<a href="<?= esc_url($lightbox_url) ?>" data-lightbox="poi-gallery" data-title="<?= esc_attr($caption) ?>">
											<img src="<?= esc_url($swiper_image_url) ?>" alt="<?= esc_attr($caption) ?>" loading="lazy">
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
		<?php endif; ?>
	</div>

	<script>
		document.addEventListener('DOMContentLoaded', function() {
			<?php if ($use_page_header && $featured_image) : ?>
				// Set featured image in page-header section
				var pageHeader = document.querySelector('header.page-header');
				if (pageHeader) {
					pageHeader.style.backgroundImage = 'url(<?= esc_js($featured_image) ?>)';
					pageHeader.style.backgroundSize = 'cover';
					pageHeader.style.backgroundPosition = 'center';
					pageHeader.style.backgroundRepeat = 'no-repeat';
				}
			<?php endif; ?>

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
					var defaultImageUrl = '<?= esc_js($default_image) ?>';
					wmInitLeafletMap('wm-leaflet-map-poi-<?= esc_js($poi_id) ?>', geometryJson, null, defaultImageUrl);
				}
			<?php endif; ?>
		});
	</script>

<?php

	return ob_get_clean();
}
?>