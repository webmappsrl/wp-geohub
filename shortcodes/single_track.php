<?php
if (!is_admin()) {
	add_shortcode('wm_single_track', 'wm_single_track');
}

/**
 * Estrae solo la prima parte di una label da taxonomy_where (prima di ";" se presente).
 *
 * @param string $label
 * @return string
 */
function wm_taxonomy_where_label_first($label)
{
	if (!is_string($label) || $label === '') {
		return '';
	}
	$parts = explode(';', $label);
	return trim($parts[0]);
}

/**
 * Normalizza una stringa per il confronto nome POI (lowercase, trim, spazi multipli → uno).
 *
 * @param string $name
 * @return string
 */
function wm_normalize_poi_name_for_match($name)
{
	if (!is_string($name) || $name === '') {
		return '';
	}
	$n = trim($name);
	$n = mb_strtolower($n, 'UTF-8');
	$n = preg_replace('/\s+/u', ' ', $n);
	return $n;
}

/**
 * For osm2cai shards: strip from track description the blocks "Percorribilità", "Ultimo aggiornamento", "Stato di accatastamento" and "Modifica questo percorso" link.
 *
 * @param string $html
 * @return string
 */
function wm_strip_osm2cai_track_description_blocks($html)
{
	if (!is_string($html) || $html === '') {
		return $html;
	}
	// Remove <p>...</p> containing "Percorribilità"
	$html = preg_replace('/<p[^>]*>.*?Percorribilità.*?<\/p>/isu', '', $html);
	// Remove <p>...</p> containing "Ultimo aggiornamento"
	$html = preg_replace('/<p[^>]*>.*?Ultimo aggiornamento.*?<\/p>/isu', '', $html);
	// Remove "Stato di accatastamento:" and content up to next <br> (inclusive)
	$html = preg_replace('/Stato di accatastamento:.*?<br\s*\/?>\s*/isu', '', $html);
	// Remove link "Modifica questo percorso"
	$html = preg_replace('/<a\s[^>]*>Modifica questo percorso<\/a>/iu', '', $html);
	// Clean up repeated <br> and trim
	$html = preg_replace('/(<br\s*\/?>\s*){2,}/i', '<br><br>', $html);
	return trim($html);
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

		$related_poi_urls = [];
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
			foreach ($poi_posts as $poi_post) {
				$source_id = get_post_meta($poi_post->ID, 'wm_poi_id', true);
				if (!empty($source_id)) {
					$related_poi_urls[(string)$source_id] = get_permalink($poi_post->ID);
				}
			}
		}

		// Fallback: mappa nome normalizzato → URL (tutti i POI pubblicati) per match quando l'id non combacia
		$related_poi_urls_by_name = [];
		$all_poi_posts = get_posts([
			'post_type' => 'poi',
			'post_status' => 'publish',
			'posts_per_page' => -1,
		]);
		foreach ($all_poi_posts as $poi_post) {
			$title = $poi_post->post_title;
			if ($title !== '') {
				$key = wm_normalize_poi_name_for_match($title);
				if ($key !== '') {
					$related_poi_urls_by_name[$key] = get_permalink($poi_post->ID);
				}
			}
		}

		foreach ($related_pois as &$poi_feature) {
			$source_id = $poi_feature['properties']['id'] ?? null;
			if (!empty($source_id) && isset($related_poi_urls[(string)$source_id])) {
				$poi_feature['properties']['wm_poi_url'] = $related_poi_urls[(string)$source_id];
			} else {
				// Fallback: match per name (stesso criterio usato per il titolo in pagina)
				$props = $poi_feature['properties'] ?? [];
				$name = null;
				if (!empty($props['name'])) {
					if (is_string($props['name'])) {
						$name = $props['name'];
					} elseif (!empty($props['name'][$language])) {
						$name = $props['name'][$language];
					} else {
						foreach (is_array($props['name']) ? $props['name'] : [] as $v) {
							if ($v !== '' && $v !== null) {
								$name = $v;
								break;
							}
						}
					}
				}
				if ($name !== '' && $name !== null) {
					$key = wm_normalize_poi_name_for_match($name);
					if ($key !== '' && isset($related_poi_urls_by_name[$key])) {
						$poi_feature['properties']['wm_poi_url'] = $related_poi_urls_by_name[$key];
					}
				}
			}
		}
		unset($poi_feature);

		// Mostra solo i POI presenti nel sync WordPress (con wm_poi_url), così non compaiono in gallery POI non cliccabili
		$related_pois = array_values(array_filter($related_pois, function ($f) {
			return !empty(($f['properties'] ?? [])['wm_poi_url'] ?? '');
		}));
	}

	// For osm2cai shards: split related POIs into "Welcome points" (punto-accoglienza) and the rest
	$related_pois_accoglienza = [];
	$related_pois_other = $related_pois;
	$is_osm2cai = false;
	if (!empty($related_pois) && function_exists('wm_is_osm2cai_shard_type')) {
		$shard = get_option('wm_shard', 'geohub');
		if (wm_is_osm2cai_shard_type($shard)) {
			$is_osm2cai = true;
			$related_pois_accoglienza = [];
			$related_pois_other = [];
			foreach ($related_pois as $poi_feature) {
				$tax = $poi_feature['properties']['taxonomy'] ?? [];
				$poi_type = $tax['poi_type'] ?? null;
				$identifier = is_array($poi_type) && isset($poi_type['identifier']) ? (string) $poi_type['identifier'] : '';
				if ($identifier === 'punto-accoglienza') {
					$related_pois_accoglienza[] = $poi_feature;
				} else {
					$related_pois_other[] = $poi_feature;
				}
			}
		}
	}

	// Enqueue Leaflet for shortcode
	wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4');
	wp_enqueue_style('leaflet-fullscreen-css', 'https://unpkg.com/leaflet-fullscreen@1.0.2/dist/Leaflet.fullscreen.css', array('leaflet-css'), '1.0.2');
	wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true);
	wp_enqueue_script('leaflet-fullscreen-js', 'https://unpkg.com/leaflet-fullscreen@1.0.2/dist/Leaflet.fullscreen.min.js', array('leaflet-js'), '1.0.2', true);
	// Enqueue togpx library for GPX generation from GeoJSON
	wp_enqueue_script('togpx-js', 'https://cdn.jsdelivr.net/npm/togpx@0.5.4/togpx.min.js', array(), '0.5.4', true);

	$description = null;
	$excerpt = null;
	$title = null;
	$featured_image = null;
	$gallery = [];
	$gpx = null;
	$activity = null;
	$dem_data = null;
	$not_accessible_message = null;
	$region_name = '';
	$city_name = '';
	$related_urls = [];
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

		// Extract not_accessible_message if track is not accessible
		if (!empty($track['not_accessible']) && !empty($track['not_accessible_message'])) {
			$not_accessible_message = $track['not_accessible_message'][$language] ??
				$track['not_accessible_message']['it'] ??
				$track['not_accessible_message']['en'] ??
				null;
			// Fallback: get first available translation
			if (empty($not_accessible_message) && is_array($track['not_accessible_message'])) {
				$not_accessible_message = reset($track['not_accessible_message']);
			}
		}

		// Technical data: manual_data has priority over dem_data; if both missing, nothing is shown
		if (isset($track['manual_data'])) {
			$manual_raw = $track['manual_data'];
			if (is_string($manual_raw)) {
				$dem_data = json_decode($manual_raw, true);
			} elseif (is_array($manual_raw)) {
				$dem_data = $manual_raw;
			}
		}
		if (empty($dem_data) && isset($track['dem_data'])) {
			$dem_data_raw = $track['dem_data'];
			if (is_string($dem_data_raw)) {
				$dem_data = json_decode($dem_data_raw, true);
			} elseif (is_array($dem_data_raw)) {
				$dem_data = $dem_data_raw;
			}
		}

		// Region from taxonomy_where: entry with _admin_level 4 (translations in it, en, etc.)
		$region_name = '';
		if (!empty($track['taxonomy_where']) && is_array($track['taxonomy_where'])) {
			foreach ($track['taxonomy_where'] as $term) {
				if (!is_array($term) || empty($term['_admin_level']) || (int) $term['_admin_level'] !== 4) {
					continue;
				}
				if (!empty($term[$language])) {
					$region_name = wm_taxonomy_where_label_first($term[$language]);
					break;
				}
				if (!empty($term['it'])) {
					$region_name = wm_taxonomy_where_label_first($term['it']);
					break;
				}
				if (!empty($term['en'])) {
					$region_name = wm_taxonomy_where_label_first($term['en']);
					break;
				}
				foreach ($term as $lang_key => $val) {
					if ($lang_key !== '_admin_level' && is_string($val) && $val !== '') {
						$region_name = wm_taxonomy_where_label_first($val);
						break 2;
					}
				}
			}
		}
		// City from taxonomy_where: entry with _admin_level 8 (translations in it, en, etc.)
		$city_name = '';
		if (!empty($track['taxonomy_where']) && is_array($track['taxonomy_where'])) {
			foreach ($track['taxonomy_where'] as $term) {
				if (!is_array($term) || empty($term['_admin_level']) || (int) $term['_admin_level'] !== 8) {
					continue;
				}
				if (!empty($term[$language])) {
					$city_name = wm_taxonomy_where_label_first($term[$language]);
					break;
				}
				if (!empty($term['it'])) {
					$city_name = wm_taxonomy_where_label_first($term['it']);
					break;
				}
				if (!empty($term['en'])) {
					$city_name = wm_taxonomy_where_label_first($term['en']);
					break;
				}
				foreach ($term as $lang_key => $val) {
					if ($lang_key !== '_admin_level' && is_string($val) && $val !== '') {
						$city_name = wm_taxonomy_where_label_first($val);
						break 2;
					}
				}
			}
		}

		// related_url (primary from properties, sicai.website fallback when empty)
		$related_urls = $track['related_url'] ?? [];
		if (!is_array($related_urls)) {
			$related_urls = [];
		}
		$sicai = isset($track['sicai']) && is_array($track['sicai']) ? $track['sicai'] : [];
		if (empty($related_urls) && !empty($sicai['website'])) {
			$related_urls = [__('Click here', 'wm-package') => $sicai['website']];
		}
	}
	// For osm2cai shards: strip Percorribilità, Ultimo aggiornamento, Stato di accatastamento and "Modifica questo percorso" from description
	if (!empty($description) && function_exists('wm_is_osm2cai_shard_type') && wm_is_osm2cai_shard_type(get_option('wm_shard', 'geohub'))) {
		$description = wm_strip_osm2cai_track_description_blocks($description);
		if ($description === '') {
			$description = null;
		}
	}
	// Get featured image display location setting
	$featured_image_location = get_option('featured_image_location', 'content');
	$use_page_header = ($featured_image_location === 'page-header');

	// Layout flags (align with single_poi: main + sidebar when map or technical details)
	$has_map = !empty($track_geometry);
	$has_technical = false;
	$show_distance = true;
	$show_duration_forward = true;
	$show_duration_backward = true;
	$show_ascent = true;
	$show_descent = true;
	$show_ele_from = true;
	$show_ele_to = true;
	if (!empty($dem_data) && is_array($dem_data)) {
		$config = function_exists('wm_get_default_config') ? wm_get_default_config() : false;
		if ($config && isset($config['WORDPRESS'])) {
			if (isset($config['WORDPRESS']['showDistance'])) {
				$show_distance = (bool) $config['WORDPRESS']['showDistance'];
			}
			if (isset($config['WORDPRESS']['showDurationForward'])) {
				$show_duration_forward = (bool) $config['WORDPRESS']['showDurationForward'];
			}
			if (isset($config['WORDPRESS']['showDurationBackward'])) {
				$show_duration_backward = (bool) $config['WORDPRESS']['showDurationBackward'];
			}
			if (isset($config['WORDPRESS']['showAscent'])) {
				$show_ascent = (bool) $config['WORDPRESS']['showAscent'];
			}
			if (isset($config['WORDPRESS']['showDescent'])) {
				$show_descent = (bool) $config['WORDPRESS']['showDescent'];
			}
			if (isset($config['WORDPRESS']['showEleFrom'])) {
				$show_ele_from = (bool) $config['WORDPRESS']['showEleFrom'];
			}
			if (isset($config['WORDPRESS']['showEleTo'])) {
				$show_ele_to = (bool) $config['WORDPRESS']['showEleTo'];
			}
		}
		if (($show_distance && isset($dem_data['distance']) && $dem_data['distance'] !== null) ||
			($show_duration_forward && isset($dem_data['duration_forward']) && $dem_data['duration_forward'] !== null) ||
			($show_duration_backward && isset($dem_data['duration_backward']) && $dem_data['duration_backward'] !== null) ||
			($show_ascent && isset($dem_data['ascent']) && $dem_data['ascent'] !== null) ||
			($show_descent && isset($dem_data['descent']) && $dem_data['descent'] !== null) ||
			($show_ele_from && isset($dem_data['ele_from']) && $dem_data['ele_from'] !== null) ||
			($show_ele_to && isset($dem_data['ele_to']) && $dem_data['ele_to'] !== null)) {
			$has_technical = true;
		}
	}
	if (!empty($region_name)) {
		$has_technical = true;
	}
	if (!empty($city_name)) {
		$has_technical = true;
	}
	if (!empty($related_urls)) {
		$has_technical = true;
	}
	$has_sidebar_layout = $has_map || $has_technical;

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

		<!-- 2.5. Not Accessible Message -->
		<?php if ($not_accessible_message) : ?>
			<div class="wm_not_accessible_message">
				<?= esc_html($not_accessible_message) ?>
			</div>
		<?php endif; ?>

		<!-- 3. Taxonomies -->
		<?php if (!empty($activity)) : ?>
			<div class="wm_taxonomies">
				<?php foreach ($activity as $type) : ?>
					<span class="wm_taxonomy_item">
						<span class="wm_taxonomy_name"><?= esc_html($type['name'][$language] ?? __('N/A', 'wm-package')) ?></span>
					</span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<!-- 4. Main content + optional sidebar (same layout as single_poi) -->
		<?php if ($has_sidebar_layout) : ?>
			<div class="wm_detail_with_sidebar">
				<div class="wm_detail_main_content">
		<?php endif; ?>

				<!-- 4. Map -->
				<?php if ($has_map) : ?>
					<div class="wm_map">
						<div
							id="wm-leaflet-map-track-<?= esc_attr($track_id) ?>"
							class="wm_leaflet_map"
							data-geometry='<?= esc_attr(json_encode($track_geometry)) ?>'
							data-related-pois='<?= esc_attr(wp_json_encode($related_pois)) ?>'></div>
						<?php
						$download_enabled = false;
						if (function_exists('wm_get_default_config')) {
							$config_dl = wm_get_default_config();
							if ($config_dl && isset($config_dl['WORDPRESS']['downloadTrackEnable'])) {
								$download_enabled = (bool) $config_dl['WORDPRESS']['downloadTrackEnable'];
							}
						}
						if ($download_enabled && (!empty($gpx) || !empty($track_geometry))) :
							$track_feature = null;
							if (!empty($track_geometry) && !empty($track)) {
								$track_feature = [
									'type' => 'Feature',
									'geometry' => $track_geometry,
									'properties' => $track
								];
							}
							$track_name = 'track';
							if (!empty($track['name'])) {
								$track_name = is_array($track['name']) ? ($track['name'][$language] ?? reset($track['name']) ?? 'track') : $track['name'];
							}
						?>
							<div class="wm_download_links wm_download_links--map">
								<?php if (!empty($gpx)) : ?>
									<a class="wm_download_link" href="<?= esc_url($gpx) ?>" download>
										<i class="fa fa-download"></i>
										<?= __('Download GPX', 'wm-package') ?>
									</a>
								<?php else : ?>
									<a class="wm_download_link wm_download_link--generate"
										href="#"
										data-track-feature='<?= esc_attr(wp_json_encode($track_feature)) ?>'
										data-track-name="<?= esc_attr($track_name) ?>">
										<i class="fa fa-download"></i>
										<?= __('Download GPX', 'wm-package') ?>
									</a>
								<?php endif; ?>
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
		<?php
		// Filtra le immagini che hanno almeno url o thumbnail
		$valid_gallery = [];
		if (is_array($gallery) && !empty($gallery)) {
			foreach ($gallery as $image) {
				if ((isset($image['url']) && !empty($image['url'])) || (isset($image['thumbnail']) && !empty($image['thumbnail']))) {
					$valid_gallery[] = $image;
				}
			}
		}
		if (!empty($valid_gallery)) : ?>
			<div class="wm_gallery">
				<div class="swiper-container wm_swiper">
					<div class="swiper-wrapper">
						<?php foreach ($valid_gallery as $image) : ?>
							<div class="swiper-slide">
								<?php
								// Usa prima url (alta risoluzione), fallback a thumbnail
								$high_res_url = isset($image['url']) && !empty($image['url']) ? esc_url($image['url']) : '';
								$thumbnail_url = isset($image['thumbnail']) && !empty($image['thumbnail']) ? esc_url($image['thumbnail']) : '';
								$swiper_image_url = $high_res_url ?: $thumbnail_url;
								// Per il lightbox usa sempre url se disponibile, altrimenti thumbnail
								$lightbox_url = $high_res_url ?: $thumbnail_url;
								$caption = isset($image['caption'][$language]) ? esc_attr($image['caption'][$language]) : '';
								if ($swiper_image_url) : ?>
									<a href="<?= esc_url($lightbox_url) ?>" data-lightbox="track-gallery" data-title="<?= esc_attr($caption) ?>">
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

		<?php
		// Helper to render related POI cards (used for Welcome points and Related POIs galleries)
		$wm_render_related_poi_cards = function ($poi_list) use ($language, $default_image) {
			foreach ($poi_list as $poi_feature) {
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
			<?php }
		};
		?>
		<!-- 7a. Welcome points (osm2cai only: POIs with identifier punto-accoglienza) -->
		<?php if ($is_osm2cai && !empty($related_pois_accoglienza)) : ?>
			<div class="wm_related_pois">
				<h2 class="wm_related_pois_title"><?= __('Welcome points', 'wm-package') ?></h2>
				<div class="swiper-container wm_swiper wm_related_pois_swiper">
					<div class="swiper-wrapper">
						<?php $wm_render_related_poi_cards($related_pois_accoglienza); ?>
					</div>
					<div class="swiper-pagination"></div>
					<div class="swiper-button-prev"></div>
					<div class="swiper-button-next"></div>
				</div>
			</div>
		<?php endif; ?>

		<!-- 7b. Related POIs -->
		<?php
		$related_pois_main = $is_osm2cai ? $related_pois_other : $related_pois;
		if (!empty($related_pois_main)) : ?>
			<div class="wm_related_pois">
				<h2 class="wm_related_pois_title"><?= __('Points of interest', 'wm-package') ?></h2>
				<div class="swiper-container wm_swiper wm_related_pois_swiper">
					<div class="swiper-wrapper">
						<?php $wm_render_related_poi_cards($related_pois_main); ?>
					</div>
					<div class="swiper-pagination"></div>
					<div class="swiper-button-prev"></div>
					<div class="swiper-button-next"></div>
				</div>
			</div>
		<?php endif; ?>

		<!-- 8. Track Navigation -->
		<?php
		// Get generateEdges from config JSON to determine if navigation should be enabled
		// track_navigation_enabled is now controlled by generateEdges in wm_default_config.json
		$generate_edges_enabled = false;
		if (function_exists('wm_get_default_config')) {
			$config = wm_get_default_config();
			if ($config && isset($config['WORDPRESS']['generateEdges'])) {
				$generate_edges_enabled = (bool) $config['WORDPRESS']['generateEdges'];
			}
		}

		// Only show navigation if generateEdges is enabled in config JSON
		if ($generate_edges_enabled) {
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

		<?php if ($has_sidebar_layout) : ?>
				</div><!-- .wm_detail_main_content -->
				<?php if ($has_technical) : ?>
					<?php $dem_data = $dem_data ?? []; ?>
					<aside class="wm_detail_sidebar">
						<div class="wm_info_details">
							<h2 class="wm_info_details_title"><?= __('Information', 'wm-package') ?></h2>
							<div class="wm_info_details_grid">
								<?php if (!empty($region_name)) : ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-map-marker" aria-hidden="true"></i> <?= __('Region', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value"><?= esc_html($region_name) ?></span>
									</div>
								<?php endif; ?>
								<?php if (!empty($city_name)) : ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-building" aria-hidden="true"></i> <?= __('City', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value"><?= esc_html($city_name) ?></span>
									</div>
								<?php endif; ?>
								<?php if ($show_distance && isset($dem_data['distance']) && $dem_data['distance'] !== null) : ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-route" aria-hidden="true"></i> <?= __('Distance', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value"><?= esc_html(number_format($dem_data['distance'], 1)) ?> <?= esc_html(__('km', 'wm-package')) ?></span>
									</div>
								<?php endif; ?>
								<?php if ($show_duration_forward && isset($dem_data['duration_forward']) && $dem_data['duration_forward'] !== null) : ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-clock" aria-hidden="true"></i> <?= __('Duration Forward', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value"><?= esc_html($dem_data['duration_forward']) ?> <?= __('min', 'wm-package') ?></span>
									</div>
								<?php endif; ?>
								<?php if ($show_duration_backward && isset($dem_data['duration_backward']) && $dem_data['duration_backward'] !== null) : ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-clock" aria-hidden="true"></i> <?= __('Duration Backward', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value"><?= esc_html($dem_data['duration_backward']) ?> <?= __('min', 'wm-package') ?></span>
									</div>
								<?php endif; ?>
								<?php if ($show_ascent && isset($dem_data['ascent']) && $dem_data['ascent'] !== null) : ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-arrow-up" aria-hidden="true"></i> <?= __('Ascent', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value"><?= esc_html($dem_data['ascent']) ?> <?= esc_html(__('m', 'wm-package')) ?></span>
									</div>
								<?php endif; ?>
								<?php if ($show_descent && isset($dem_data['descent']) && $dem_data['descent'] !== null) : ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-arrow-down" aria-hidden="true"></i> <?= __('Descent', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value"><?= esc_html($dem_data['descent']) ?> <?= esc_html(__('m', 'wm-package')) ?></span>
									</div>
								<?php endif; ?>
								<?php if ($show_ele_from && isset($dem_data['ele_from']) && $dem_data['ele_from'] !== null) : ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-plus" aria-hidden="true"></i> <?= __('Start Elevation', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value"><?= esc_html($dem_data['ele_from']) ?> <?= esc_html(__('m', 'wm-package')) ?></span>
									</div>
								<?php endif; ?>
								<?php if ($show_ele_to && isset($dem_data['ele_to']) && $dem_data['ele_to'] !== null) : ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-minus" aria-hidden="true"></i> <?= __('End Elevation', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value"><?= esc_html($dem_data['ele_to']) ?> <?= esc_html(__('m', 'wm-package')) ?></span>
									</div>
								<?php endif; ?>

								<?php if (!empty($related_urls)) : ?>
									<div class="wm_info_detail_item">
										<span class="wm_info_detail_label"><i class="fa fa-globe" aria-hidden="true"></i> <?= __('Website', 'wm-package') ?>:</span>
										<span class="wm_info_detail_value">
											<?php
											$urls_output = [];
											foreach ($related_urls as $url_name => $url) {
												$display_label = wm_website_link_label($url_name, $url);
												$urls_output[] = '<a href="' . esc_url($url) . '" target="_blank">' . esc_html($display_label) . '</a>';
											}
											echo implode(', ', $urls_output);
											?>
										</span>
									</div>
								<?php endif; ?>
							</div>
						</div>
					</aside>
				<?php endif; ?>
			</div><!-- .wm_detail_with_sidebar -->
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
					var defaultImageUrl = '<?= esc_js($default_image) ?>';
					wmInitLeafletMap('wm-leaflet-map-track-<?= esc_js($track_id) ?>', geometryJson, relatedPoisJson, defaultImageUrl);
				}
			<?php endif; ?>

			// Handle GPX generation from GeoJSON
			var gpxGenerateLinks = document.querySelectorAll('.wm_download_link--generate');
			gpxGenerateLinks.forEach(function(link) {
				link.addEventListener('click', function(e) {
					e.preventDefault();

					var trackFeatureJson = this.getAttribute('data-track-feature');
					var trackName = this.getAttribute('data-track-name') || 'track';

					if (!trackFeatureJson) {
						console.error('Track feature data not found');
						alert('<?= esc_js(__('Error: Track data not available', 'wm-package')) ?>');
						return;
					}

					// Function to generate and download GPX
					function generateAndDownloadGPX() {
						try {
							var trackFeature = JSON.parse(trackFeatureJson);

							// Check if togpx is available
							if (typeof togpx === 'undefined') {
								console.error('togpx library not loaded');
								alert('<?= esc_js(__('Error: GPX generation library not available. Please refresh the page.', 'wm-package')) ?>');
								return;
							}

							// Generate GPX from GeoJSON
							var gpxString = togpx(trackFeature);

							if (!gpxString || gpxString.trim() === '') {
								console.error('Failed to generate GPX');
								alert('<?= esc_js(__('Error: Failed to generate GPX file', 'wm-package')) ?>');
								return;
							}

							// Clean track name for filename (remove spaces and special chars)
							var cleanName = trackName.replace(/\s+/g, '').replace(/[^a-zA-Z0-9-_]/g, '') || 'track';

							// Create blob and download
							var blob = new Blob([gpxString], {
								type: 'application/gpx+xml'
							});
							var url = window.URL.createObjectURL(blob);
							var a = document.createElement('a');
							a.href = url;
							a.download = cleanName + '.gpx';
							document.body.appendChild(a);
							a.click();
							document.body.removeChild(a);
							window.URL.revokeObjectURL(url);
						} catch (error) {
							console.error('Error generating GPX:', error);
							alert('<?= esc_js(__('Error: Failed to generate GPX file', 'wm-package')) ?>');
						}
					}

					// Wait for togpx library to be loaded if not already available
					if (typeof togpx !== 'undefined') {
						generateAndDownloadGPX();
					} else {
						// Wait a bit for the library to load
						var attempts = 0;
						var checkInterval = setInterval(function() {
							attempts++;
							if (typeof togpx !== 'undefined') {
								clearInterval(checkInterval);
								generateAndDownloadGPX();
							} else if (attempts > 20) {
								clearInterval(checkInterval);
								alert('<?= esc_js(__('Error: GPX generation library failed to load. Please refresh the page.', 'wm-package')) ?>');
							}
						}, 100);
					}
				});
			});
		});
	</script>

<?php
	return ob_get_clean();
}
?>