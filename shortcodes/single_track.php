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
		'layer_ids' => '', // Optional: comma-separated layer IDs for navigation
	), $atts));

	$single_track_base_url = get_option('track_url');
	$geojson_url = $single_track_base_url . $track_id . ".json";

	$track_data = json_decode(file_get_contents($geojson_url), true);
	$track = $track_data['properties'] ?? [];
	$track_geometry = $track_data['geometry'] ?? null;
	$related_pois = $track_data['related_pois'] ?? ($track['related_pois'] ?? []);
	if (!is_array($related_pois)) {
		$related_pois = [];
	}
	if (!empty($related_pois)) {
		$related_poi_ids = [];
		foreach ($related_pois as $poi_feature) {
			$poi_id = $poi_feature['properties']['id'] ?? null;
			if (!empty($poi_id)) {
				$related_poi_ids[] = (string)$poi_id;
			}
		}
		$related_poi_ids = array_values(array_unique($related_poi_ids));

		if (!empty($related_poi_ids)) {
			$poi_posts = get_posts([
				'post_type' => 'poi',
				'post_status' => 'publish',
				'posts_per_page' => -1,
				'meta_query' => [
					[
						'key' => 'wm_poi_id',
						'value' => $related_poi_ids,
						'compare' => 'IN',
					],
				],
			]);

			$related_poi_urls = [];
			foreach ($poi_posts as $poi_post) {
				$source_id = get_post_meta($poi_post->ID, 'wm_poi_id', true);
				if (!empty($source_id)) {
					$related_poi_urls[(string)$source_id] = get_permalink($poi_post->ID);
				}
			}

			foreach ($related_pois as &$poi_feature) {
				$source_id = $poi_feature['properties']['id'] ?? null;
				if (!empty($source_id) && isset($related_poi_urls[(string)$source_id])) {
					$poi_feature['properties']['wm_poi_url'] = $related_poi_urls[(string)$source_id];
				}
			}
			unset($poi_feature);
		}
	}

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
	$default_image = plugins_url('wm-package/assets/default_image.png');

	if ($track) {
		$description = $track['description'][$language] ?? null;
		$excerpt = $track['excerpt'][$language] ?? null;
		$title = $track['name'][$language] ?? null;
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
						<span class="wm_taxonomy_name"><?= esc_html($type['name'][$language] ?? 'N/A') ?></span>
					</span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<!-- 4. Map and Technical Details Container -->
		<?php if (!empty($track_geometry) || (!empty($dem_data) && is_array($dem_data))) : ?>
			<div class="wm_map_technical_wrapper">
				<!-- 4. Map -->
				<?php if (!empty($track_geometry)) : ?>
					<div class="wm_map">
						<div
							id="wm-leaflet-map-track-<?= esc_attr($track_id) ?>"
							class="wm_leaflet_map"
							data-geometry='<?= esc_attr(json_encode($track_geometry)) ?>'
							data-related-pois='<?= esc_attr(wp_json_encode($related_pois)) ?>'
						></div>
						<?php if (!empty($gpx)) : ?>
							<div class="wm_download_links wm_download_links--map">
								<a class="wm_download_link" href="<?= esc_url($gpx) ?>">
									<i class="fa fa-download"></i>
									<?= __('Download GPX', 'wm-package') ?>
								</a>
							</div>
						<?php endif; ?>
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

							<?php if (isset($dem_data['duration_backward']) && $dem_data['duration_backward'] !== null) : ?>
								<div class="wm_technical_detail_item">
									<span class="wm_technical_detail_label"><?= __('Duration Backward', 'wm-package') ?>:</span>
									<span class="wm_technical_detail_value"><?= esc_html($dem_data['duration_backward']) ?> <?= __('min', 'wm-package') ?></span>
								</div>
							<?php endif; ?>

							<?php if (isset($dem_data['duration_forward']) && $dem_data['duration_forward'] !== null) : ?>
								<div class="wm_technical_detail_item">
									<span class="wm_technical_detail_label"><?= __('Duration Forward', 'wm-package') ?>:</span>
									<span class="wm_technical_detail_value"><?= esc_html($dem_data['duration_forward']) ?> <?= __('min', 'wm-package') ?></span>
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
				<div class="swiper-container wm_swiper">
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

		<!-- 7. Related POIs -->
		<?php if (!empty($related_pois)) : ?>
			<div class="wm_related_pois">
				<h2 class="wm_related_pois_title"><?= __('Related POIs', 'wm-package') ?></h2>
				<div class="swiper-container wm_swiper">
					<div class="swiper-wrapper">
						<?php foreach ($related_pois as $poi_feature) : ?>
							<?php
							$poi_properties = $poi_feature['properties'] ?? [];
							$poi_name = '';
							if (!empty($poi_properties['name'])) {
								if (is_string($poi_properties['name'])) {
									$poi_name = $poi_properties['name'];
								} elseif (!empty($poi_properties['name'][$language])) {
									$poi_name = $poi_properties['name'][$language];
								} else {
									foreach ($poi_properties['name'] as $name_value) {
										if (!empty($name_value)) {
											$poi_name = $name_value;
											break;
										}
									}
								}
							}

							$poi_image = $default_image;
							if (!empty($poi_properties['feature_image']['sizes']['1440x500'])) {
								$poi_image = $poi_properties['feature_image']['sizes']['1440x500'];
							} elseif (!empty($poi_properties['feature_image']['url'])) {
								$poi_image = $poi_properties['feature_image']['url'];
							} elseif (!empty($poi_properties['featureImage']['thumbnail'])) {
								$poi_image = $poi_properties['featureImage']['thumbnail'];
							} elseif (!empty($poi_feature['featureImage']['thumbnail'])) {
								$poi_image = $poi_feature['featureImage']['thumbnail'];
							}
							if (empty($poi_image)) {
								$poi_image = $default_image;
							}

							$poi_url = $poi_properties['wm_poi_url'] ?? '';

							if (empty($poi_name) && empty($poi_image)) {
								continue;
							}
							?>
							<div class="swiper-slide">
								<?php if (!empty($poi_url)) : ?>
									<a href="<?= esc_url($poi_url) ?>">
									<?php endif; ?>
									<div class="wm_related_poi_card">
										<?php if (!empty($poi_image)) : ?>
											<div class="wm_related_poi_image">
												<img src="<?= esc_url($poi_image) ?>" alt="<?= esc_attr($poi_name) ?>" loading="lazy">
											</div>
										<?php endif; ?>
										<?php if (!empty($poi_name)) : ?>
											<div class="wm_related_poi_name"><?= esc_html($poi_name) ?></div>
										<?php endif; ?>
									</div>
									<?php if (!empty($poi_url)) : ?>
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

		<!-- 8. Track Navigation -->
		<?php
		$navigation_enabled = get_option('track_navigation_enabled') === '1';
		if ($navigation_enabled) {
			// Get layer IDs from shortcode parameter or admin option
			$nav_layer_ids = !empty($layer_ids) ? $layer_ids : get_option('track_navigation_layer_ids');

			if (!empty($nav_layer_ids)) {
				$nav_layer_ids_array = array_map('trim', explode(',', $nav_layer_ids));

				// Get Elasticsearch configuration
				$elastic_api_base = get_option('elastic_api');
				$app_id = get_option('app_configuration_id') ?: '49';
				$shard = get_option('wm_shard') ?: 'geohub';
				$shard_app = $shard . '_app';

				// Fallback: if elastic_api is not set, try to construct it from origin
				if (empty($elastic_api_base)) {
					if (function_exists('wm_get_api_urls')) {
						$api_urls = wm_get_api_urls($shard, $app_id);
						$elastic_api_base = $api_urls['elastic_api'] ?? null;
					}
					if (empty($elastic_api_base)) {
						$origin = get_option('layer_api');
						if ($origin) {
							$origin = preg_replace('#/api/app/webapp/.*$#', '', $origin);
							if ($shard === 'geohub') {
								$elastic_api_base = 'https://elastic-json.webmapp.it/v2/search/';
							} else {
								$elastic_api_base = rtrim($origin, '/') . '/api/v2/elasticsearch';
							}
						}
					}
				}

				// Fetch all tracks from Elasticsearch
				$all_tracks = array();
				if (!empty($elastic_api_base)) {
					foreach ($nav_layer_ids_array as $layer_id) {
						if (empty($layer_id)) continue;

						$elastic_url = $elastic_api_base;
						if (strpos($elastic_url, '?') === false) {
							$elastic_url .= '?';
						} else {
							$elastic_url .= '&';
						}
						$elastic_url .= "app={$shard_app}_{$app_id}&layer=" . urlencode($layer_id) . "&size=1000";

						$response = wp_remote_get($elastic_url, array('timeout' => 15));

						if (!is_wp_error($response)) {
							$elastic_data = json_decode(wp_remote_retrieve_body($response), true);

							if (!empty($elastic_data['hits']) && is_array($elastic_data['hits'])) {
								foreach ($elastic_data['hits'] as $hit) {
									$track_item = array();
									$track_item['id'] = $hit['id'] ?? null;
									$track_item['name'] = is_array($hit['name'] ?? null) ? $hit['name'] : array($language => $hit['name'] ?? '');
									$track_item['slug'] = is_array($hit['slug'] ?? null) ? $hit['slug'] : array($language => wm_custom_slugify($track_item['name'][$language] ?? ''));
									$track_item['updatedAt'] = $hit['properties']['updatedAt'] ?? $hit['updatedAt'] ?? null;
									$all_tracks[] = $track_item;
								}
							}
						}
					}

					// Keep tracks in the exact order as returned by Elasticsearch API (no sorting)
					// The order in hits array is the correct navigation order

					// Find current track index
					$current_index = -1;
					$current_track_slug = $track['slug'][$language] ?? '';
					$current_track_id = $track['id'] ?? $track_id;

					// Also try to get slug from track name if slug is not available
					if (empty($current_track_slug) && !empty($track['name'][$language])) {
						$current_track_slug = wm_custom_slugify($track['name'][$language]);
					}

					foreach ($all_tracks as $index => $track_item) {
						$item_slug = $track_item['slug'][$language] ?? '';
						$item_id = $track_item['id'] ?? '';

						// Match by ID or slug
						if (!empty($current_track_id) && $item_id == $current_track_id) {
							$current_index = $index;
							break;
						}
						if (!empty($current_track_slug) && $item_slug === $current_track_slug) {
							$current_index = $index;
							break;
						}
						// Also try matching track_id parameter (might be a slug)
						if ($track_id && ($item_id == $track_id || $item_slug === $track_id)) {
							$current_index = $index;
							break;
						}
					}

					// Get previous and next tracks
					$prev_track = null;
					$next_track = null;

					if ($current_index >= 0) {
						if ($current_index > 0) {
							$prev_track = $all_tracks[$current_index - 1];
						}
						if ($current_index < count($all_tracks) - 1) {
							$next_track = $all_tracks[$current_index + 1];
						}
					}

					// Display navigation buttons
					if ($prev_track || $next_track) :
						$base_url = apply_filters('wpml_home_url', get_site_url(), $language);
		?>
						<div class="wm_track_navigation">
							<?php if ($prev_track) :
								$prev_slug = $prev_track['slug'][$language] ?? '';
								$prev_url = trailingslashit($base_url) . "track/{$prev_slug}/";
								$prev_name = $prev_track['name'][$language] ?? __('Previous Track', 'wm-package');
							?>
								<a href="<?= esc_url($prev_url) ?>" class="wm_track_nav_button wm_track_nav_prev">
									<span class="wm_track_nav_arrow">←</span>
									<span class="wm_track_nav_label"><?= __('Previous', 'wm-package') ?></span>
									<span class="wm_track_nav_name"><?= esc_html($prev_name) ?></span>
								</a>
							<?php else : ?>
								<span class="wm_track_nav_button wm_track_nav_prev wm_track_nav_disabled">
									<span class="wm_track_nav_arrow">←</span>
									<span class="wm_track_nav_label"><?= __('Previous', 'wm-package') ?></span>
								</span>
							<?php endif; ?>

							<?php if ($next_track) :
								$next_slug = $next_track['slug'][$language] ?? '';
								$next_url = trailingslashit($base_url) . "track/{$next_slug}/";
								$next_name = $next_track['name'][$language] ?? __('Next Track', 'wm-package');
							?>
								<a href="<?= esc_url($next_url) ?>" class="wm_track_nav_button wm_track_nav_next">
									<span class="wm_track_nav_label"><?= __('Next', 'wm-package') ?></span>
									<span class="wm_track_nav_name"><?= esc_html($next_name) ?></span>
									<span class="wm_track_nav_arrow">→</span>
								</a>
							<?php else : ?>
								<span class="wm_track_nav_button wm_track_nav_next wm_track_nav_disabled">
									<span class="wm_track_nav_label"><?= __('Next', 'wm-package') ?></span>
									<span class="wm_track_nav_arrow">→</span>
								</span>
							<?php endif; ?>
						</div>
		<?php endif;
				}
			}
		}
		?>
	</div>

	<script>
		document.addEventListener('DOMContentLoaded', function() {
			if (typeof Swiper !== 'undefined') {
				var swiperContainers = document.querySelectorAll('.wm_swiper');
				swiperContainers.forEach(function(container) {
					var config = {
						slidesPerView: 1,
						spaceBetween: 10,
						freeMode: true,
						loop: true
					};

					var paginationEl = container.querySelector('.swiper-pagination');
					if (paginationEl) {
						config.pagination = {
							el: paginationEl,
							clickable: true
						};
					}

					var nextEl = container.querySelector('.swiper-button-next');
					var prevEl = container.querySelector('.swiper-button-prev');
					if (nextEl && prevEl) {
						config.navigation = {
							nextEl: nextEl,
							prevEl: prevEl
						};
					}

					new Swiper(container, config);
				});
			}

			// Initialize Leaflet map for Track
			<?php if (!empty($track_geometry)) : ?>
				var mapElement = document.getElementById('wm-leaflet-map-track-<?= esc_js($track_id) ?>');
				if (mapElement && typeof wmInitLeafletMap !== 'undefined') {
					var geometryJson = mapElement.getAttribute('data-geometry');
					var relatedPoisJson = mapElement.getAttribute('data-related-pois');
					wmInitLeafletMap('wm-leaflet-map-track-<?= esc_js($track_id) ?>', geometryJson, relatedPoisJson);
				}
			<?php endif; ?>
		});
	</script>

<?php
	return ob_get_clean();
}
?>