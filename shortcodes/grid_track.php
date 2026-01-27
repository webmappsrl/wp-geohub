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
        'content' => false,
        'use_elastic' => 'true', // Use Elasticsearch API by default
        'show_filters' => 'true' // Show filters interface
    ), $atts));

    $tracks = [];

    $elastic_api_base = get_option('elastic_api');
    $layer_api_base = get_option('layer_api');
    $app_id = get_option('app_configuration_id') ?: '49';
    $shard = get_option('wm_shard') ?: 'geohub';
    $shard_app = $shard . '_app';

    // Fallback: if elastic_api is not set, try to construct it from origin
    if (empty($elastic_api_base)) {
        // Try to get from shards config
        if (function_exists('wm_get_api_urls')) {
            $api_urls = wm_get_api_urls($shard, $app_id);
            $elastic_api_base = $api_urls['elastic_api'] ?? null;
        }
        // Final fallback
        if (empty($elastic_api_base)) {
            $origin = get_option('layer_api');
            if ($origin) {
                // Extract base URL from layer_api (remove /api/app/webapp/...)
                $origin = preg_replace('#/api/app/webapp/.*$#', '', $origin);
                if ($shard === 'geohub') {
                    $elastic_api_base = 'https://elastic-json.webmapp.it/v2/search/';
                } else {
                    $elastic_api_base = rtrim($origin, '/') . '/api/v2/elasticsearch';
                }
            }
        }
    }

    $default_image = plugins_url('wm-package/assets/default_image.png');

    // Get layer info for content display if needed (always use Layer API for this)
    $title = null;
    $subtitle = null;
    $description = null;
    $featured_image_url = null;

    if (!empty($layer_id) && ($content) && !empty($layer_api_base)) {
        $layer_url = "{$layer_api_base}{$layer_id}";
        $layer_response = wp_remote_get($layer_url);

        if (!is_wp_error($layer_response)) {
            $layer_data = json_decode(wp_remote_retrieve_body($layer_response), true);

            $title = $layer_data['title'][$language] ?? null;
            $subtitle = $layer_data['subtitle'][$language] ?? null;
            $description = $layer_data['description'][$language] ?? null;

            $featured_image_url = $default_image;
            if (!empty($layer_data['featureImage']['sizes']['1440x500'])) {
                $featured_image_url = esc_url($layer_data['featureImage']['sizes']['1440x500']);
            } elseif (!empty($layer_data['featureImage']['thumbnail'])) {
                $featured_image_url = esc_url($layer_data['featureImage']['thumbnail']);
            }
        }
    }


    $layer_ids_array = !empty($layer_ids) ? explode(',', $layer_ids) : (!empty($layer_id) ? [$layer_id] : []);

    // Get filter options from Elasticsearch aggregations if available
    $filter_options = array(
        'regions' => array(),
        'difficulties' => array(),
        'distance_min' => 1,
        'distance_max' => 40,
        'ascent_min' => 1,
        'ascent_max' => 2500
    );

    // Try to fetch regions from taxonomy API as fallback
    $all_regions_from_api = array();
    if (function_exists('wm_get_api_urls')) {
        $api_urls = wm_get_api_urls($shard, $app_id);
        $origin = $api_urls['origin'] ?? '';
        if (!empty($origin)) {
            // Try to get regions from taxonomy API
            $taxonomy_where_url = rtrim($origin, '/') . "/api/app/webapp/{$app_id}/taxonomies/where/";
            $taxonomy_response = wp_remote_get($taxonomy_where_url, array('timeout' => 10));
            if (!is_wp_error($taxonomy_response)) {
                $taxonomy_data = json_decode(wp_remote_retrieve_body($taxonomy_response), true);
                if (is_array($taxonomy_data)) {
                    foreach ($taxonomy_data as $term) {
                        if (is_array($term)) {
                            $region_name = $term['name'][$language] ?? $term['name']['it'] ?? $term['name']['en'] ?? $term['name'] ?? '';
                            if (!empty($region_name) && is_string($region_name)) {
                                $all_regions_from_api[] = $region_name;
                            }
                        } elseif (is_string($term)) {
                            $all_regions_from_api[] = $term;
                        }
                    }
                }
            }
        }
    }

    // Try to use Elasticsearch API first if enabled and available
    $use_elastic = ($use_elastic === 'true' || $use_elastic === true) && !empty($elastic_api_base) && !empty($layer_ids_array);

    if ($use_elastic) {
        // Use Elasticsearch API (like wm-home-result)
        // First, collect all regions from all layers before processing tracks
        $all_regions_collected = array();

        foreach ($layer_ids_array as $id) {
            if (empty($id)) continue;

            // Build Elasticsearch query URL to get all data (no limit)
            $elastic_url = $elastic_api_base;
            if (strpos($elastic_url, '?') === false) {
                $elastic_url .= '?';
            } else {
                $elastic_url .= '&';
            }
            $elastic_url .= "app={$shard_app}_{$app_id}&layer=" . urlencode($id) . "&size=1000";

            $response = wp_remote_get($elastic_url, array(
                'timeout' => 15,
            ));

            if (!is_wp_error($response)) {
                $elastic_data = json_decode(wp_remote_retrieve_body($response), true);

                // Collect regions from this layer's data
                if (!empty($elastic_data['hits']) && is_array($elastic_data['hits'])) {
                    foreach ($elastic_data['hits'] as $hit) {
                        if (!empty($hit['taxonomyWheres']) && is_array($hit['taxonomyWheres'])) {
                            foreach ($hit['taxonomyWheres'] as $where) {
                                $region_name = '';
                                if (is_string($where)) {
                                    $region_name = $where;
                                } elseif (is_array($where)) {
                                    if (isset($where['name'])) {
                                        if (is_array($where['name'])) {
                                            $region_name = $where['name'][$language] ?? $where['name']['it'] ?? $where['name']['en'] ?? '';
                                        } else {
                                            $region_name = $where['name'];
                                        }
                                    } elseif (isset($where['title'])) {
                                        if (is_array($where['title'])) {
                                            $region_name = $where['title'][$language] ?? $where['title']['it'] ?? $where['title']['en'] ?? '';
                                        } else {
                                            $region_name = $where['title'];
                                        }
                                    }
                                }
                                if (!empty($region_name) && !in_array($region_name, $all_regions_collected)) {
                                    $all_regions_collected[] = $region_name;
                                }
                            }
                        }
                    }
                }

                // Also check aggregations
                if (!empty($elastic_data['aggregations']['taxonomyWheres'])) {
                    $aggs = $elastic_data['aggregations']['taxonomyWheres'];
                    $buckets = $aggs['count']['buckets'] ?? $aggs['buckets'] ?? array();
                    foreach ($buckets as $bucket) {
                        $region_name = '';
                        $key = $bucket['key'] ?? $bucket;
                        if (is_string($key)) {
                            $region_name = $key;
                        } elseif (is_array($key)) {
                            if (isset($key['name'])) {
                                if (is_array($key['name'])) {
                                    $region_name = $key['name'][$language] ?? $key['name']['it'] ?? $key['name']['en'] ?? '';
                                } else {
                                    $region_name = $key['name'];
                                }
                            }
                        }
                        if (!empty($region_name) && !in_array($region_name, $all_regions_collected)) {
                            $all_regions_collected[] = $region_name;
                        }
                    }
                }
            }
        }

        // Merge collected regions with API regions
        if (!empty($all_regions_collected)) {
            $all_regions_from_api = array_merge($all_regions_from_api, $all_regions_collected);
        }

        // Now process tracks normally
        // Initialize arrays to collect filter options across all layers
        $all_regions = array();
        $distances = array();
        $ascents = array();
        $difficulties = array();

        foreach ($layer_ids_array as $id) {
            if (empty($id)) continue;

            // Build Elasticsearch query URL
            $elastic_url = $elastic_api_base;
            if (strpos($elastic_url, '?') === false) {
                $elastic_url .= '?';
            } else {
                $elastic_url .= '&';
            }
            $elastic_url .= "app={$shard_app}_{$app_id}&layer=" . urlencode($id);

            $response = wp_remote_get($elastic_url, array(
                'timeout' => 15,
            ));

            if (!is_wp_error($response)) {
                $elastic_data = json_decode(wp_remote_retrieve_body($response), true);

                // Helper function to extract region name from various formats
                $extract_region_name = function ($where) use ($language) {
                    if (is_string($where)) {
                        return $where;
                    } elseif (is_array($where)) {
                        // Try different possible structures
                        if (isset($where['name'])) {
                            if (is_array($where['name'])) {
                                return $where['name'][$language] ?? $where['name']['it'] ?? $where['name']['en'] ?? '';
                            }
                            return $where['name'];
                        } elseif (isset($where['title'])) {
                            if (is_array($where['title'])) {
                                return $where['title'][$language] ?? $where['title']['it'] ?? $where['title']['en'] ?? '';
                            }
                            return $where['title'];
                        } elseif (isset($where['label'])) {
                            if (is_array($where['label'])) {
                                return $where['label'][$language] ?? $where['label']['it'] ?? $where['label']['en'] ?? '';
                            }
                            return $where['label'];
                        }
                    }
                    return '';
                };

                if (!empty($elastic_data['aggregations'])) {
                    $aggs = $elastic_data['aggregations'];

                    // Get regions from taxonomyWheres aggregations - try different aggregation structures
                    if (!empty($aggs['taxonomyWheres'])) {
                        if (isset($aggs['taxonomyWheres']['count']['buckets'])) {
                            foreach ($aggs['taxonomyWheres']['count']['buckets'] as $bucket) {
                                $region_name = $extract_region_name($bucket['key'] ?? $bucket);
                                if (!empty($region_name) && !in_array($region_name, $all_regions)) {
                                    $all_regions[] = $region_name;
                                }
                            }
                        } elseif (isset($aggs['taxonomyWheres']['buckets'])) {
                            foreach ($aggs['taxonomyWheres']['buckets'] as $bucket) {
                                $region_name = $extract_region_name($bucket['key'] ?? $bucket);
                                if (!empty($region_name) && !in_array($region_name, $all_regions)) {
                                    $all_regions[] = $region_name;
                                }
                            }
                        }
                    }

                    // Get difficulties from cai_scale aggregations if available
                    if (!empty($aggs['cai_scale'])) {
                        if (isset($aggs['cai_scale']['count']['buckets'])) {
                            foreach ($aggs['cai_scale']['count']['buckets'] as $bucket) {
                                $difficulty_value = $bucket['key'] ?? $bucket;
                                if (!empty($difficulty_value) && is_string($difficulty_value) && !in_array($difficulty_value, $difficulties)) {
                                    $difficulties[] = $difficulty_value;
                                }
                            }
                        } elseif (isset($aggs['cai_scale']['buckets'])) {
                            foreach ($aggs['cai_scale']['buckets'] as $bucket) {
                                $difficulty_value = $bucket['key'] ?? $bucket;
                                if (!empty($difficulty_value) && is_string($difficulty_value) && !in_array($difficulty_value, $difficulties)) {
                                    $difficulties[] = $difficulty_value;
                                }
                            }
                        }
                    }
                }

                // Calculate min/max from hits for sliders and collect regions from all hits
                if (!empty($elastic_data['hits'])) {
                    foreach ($elastic_data['hits'] as $hit) {
                        if (isset($hit['distance'])) $distances[] = (float)$hit['distance'];
                        if (isset($hit['ascent'])) $ascents[] = (int)$hit['ascent'];

                        // Collect cai_scale difficulties from hits
                        if (isset($hit['cai_scale']) && !empty($hit['cai_scale'])) {
                            $difficulty_value = is_string($hit['cai_scale']) ? $hit['cai_scale'] : (string)$hit['cai_scale'];
                            if (!empty($difficulty_value) && !in_array($difficulty_value, $difficulties)) {
                                $difficulties[] = $difficulty_value;
                            }
                        }
                        // Also check properties.cai_scale if it exists
                        if (isset($hit['properties']['cai_scale']) && !empty($hit['properties']['cai_scale'])) {
                            $difficulty_value = is_string($hit['properties']['cai_scale']) ? $hit['properties']['cai_scale'] : (string)$hit['properties']['cai_scale'];
                            if (!empty($difficulty_value) && !in_array($difficulty_value, $difficulties)) {
                                $difficulties[] = $difficulty_value;
                            }
                        }

                        // Collect regions from hits - handle various data structures
                        if (!empty($hit['taxonomyWheres']) && is_array($hit['taxonomyWheres'])) {
                            foreach ($hit['taxonomyWheres'] as $where) {
                                $region_name = $extract_region_name($where);
                                if (!empty($region_name) && !in_array($region_name, $all_regions)) {
                                    $all_regions[] = $region_name;
                                }
                            }
                        }

                        // Also check properties.taxonomyWheres if it exists
                        if (!empty($hit['properties']['taxonomyWheres']) && is_array($hit['properties']['taxonomyWheres'])) {
                            foreach ($hit['properties']['taxonomyWheres'] as $where) {
                                $region_name = $extract_region_name($where);
                                if (!empty($region_name) && !in_array($region_name, $all_regions)) {
                                    $all_regions[] = $region_name;
                                }
                            }
                        }
                    }
                }

                // Process tracks from this layer
                if (!empty($elastic_data['hits']) && is_array($elastic_data['hits'])) {
                    foreach ($elastic_data['hits'] as $hit) {
                        $track = array();

                        // Map Elasticsearch hit to track structure
                        $track['id'] = $hit['id'] ?? null;
                        // Handle name field: check both hit['name'] and hit['properties']['name'] for geohub compatibility
                        $name_value = $hit['name'] ?? $hit['properties']['name'] ?? null;
                        if (is_array($name_value)) {
                            // If it's already an array (object with translations), use it directly
                            $track['name'] = $name_value;
                        } else {
                            // If it's a string or null, convert to array format
                            $track['name'] = array($language => $name_value ?? '');
                        }
                        $track['slug'] = is_array($hit['slug'] ?? null) ? $hit['slug'] : array($language => wm_custom_slugify($track['name'][$language] ?? ''));
                        $track['distance'] = $hit['distance'] ?? null;
                        $track['updatedAt'] = $hit['properties']['updatedAt'] ?? $hit['updatedAt'] ?? null;
                        $track['ascent'] = $hit['ascent'] ?? null;
                        $track['taxonomy_activities'] = $hit['taxonomyActivities'] ?? [];
                        $track['taxonomy_wheres'] = $hit['taxonomyWheres'] ?? [];

                        // Feature image
                        if (!empty($hit['feature_image'])) {
                            $track['featureImage'] = $hit['feature_image'];
                            if (is_string($hit['feature_image'])) {
                                $track['thumbnail_final'] = esc_url($hit['feature_image']);
                            } elseif (!empty($hit['feature_image']['sizes']['1440x500'])) {
                                $track['thumbnail_final'] = esc_url($hit['feature_image']['sizes']['1440x500']);
                            } elseif (!empty($hit['feature_image']['thumbnail'])) {
                                $track['thumbnail_final'] = esc_url($hit['feature_image']['thumbnail']);
                            } elseif (!empty($hit['feature_image']['url'])) {
                                $track['thumbnail_final'] = esc_url($hit['feature_image']['url']);
                            } else {
                                $track['thumbnail_final'] = $default_image;
                            }
                        } else {
                            $track['thumbnail_final'] = $default_image;
                        }

                        // SVG icon from taxonomy icons
                        $track['svg_icon'] = '';
                        if (!empty($hit['taxonomyIcons']) && is_array($hit['taxonomyIcons'])) {
                            $first_icon = reset($hit['taxonomyIcons']);
                            if (!empty($first_icon['icon_name'])) {
                                // Try to get icon from taxonomy activities
                                $track['svg_icon'] = $first_icon['icon_name'];
                            }
                        }

                        $tracks[] = $track;
                    }
                }
            }
        }

        // Process filter options after collecting data from all layers
        // Merge with regions collected from all layers and API
        if (!empty($all_regions_from_api)) {
            $all_regions = array_merge($all_regions, $all_regions_from_api);
        }

        // Sort and store unique regions
        $all_regions = array_unique($all_regions);
        $all_regions = array_filter($all_regions); // Remove empty values
        sort($all_regions);
        $filter_options['regions'] = $all_regions;

        // Calculate min/max for distance and ascent sliders
        $has_distance_data = false;
        if (!empty($distances)) {
            $calculated_min = max(1, floor(min($distances)));
            $calculated_max = ceil(max($distances));
            // Only consider valid if we have meaningful data and min < max
            if ($calculated_min < $calculated_max && count($distances) > 0) {
                $filter_options['distance_min'] = $calculated_min;
                $filter_options['distance_max'] = $calculated_max;
                $has_distance_data = true;
            }
        }

        $has_ascent_data = false;
        if (!empty($ascents)) {
            $calculated_min = max(1, min($ascents));
            $calculated_max = max($ascents);
            // Only consider valid if we have meaningful data and min < max
            if ($calculated_min < $calculated_max && count($ascents) > 0) {
                $filter_options['ascent_min'] = $calculated_min;
                $filter_options['ascent_max'] = $calculated_max;
                $has_ascent_data = true;
            }
        }

        // Sort and store unique difficulties
        $difficulties = array_unique($difficulties);
        $difficulties = array_filter($difficulties); // Remove empty values
        sort($difficulties);
        $filter_options['difficulties'] = $difficulties;
        $has_difficulty_data = !empty($difficulties);

        // Check if we have any valid filter data
        $has_region_data = !empty($filter_options['regions']);
        $has_any_filter_data = $has_distance_data || $has_region_data || $has_difficulty_data || $has_ascent_data;

        // Store flags for template rendering
        $filter_options['has_distance_data'] = $has_distance_data;
        $filter_options['has_region_data'] = $has_region_data;
        $filter_options['has_difficulty_data'] = $has_difficulty_data;
        $filter_options['has_ascent_data'] = $has_ascent_data;
        $filter_options['has_any_filter_data'] = $has_any_filter_data;
    } else {
        // No Elasticsearch data, no filters available
        $filter_options['has_distance_data'] = false;
        $filter_options['has_region_data'] = false;
        $filter_options['has_difficulty_data'] = false;
        $filter_options['has_ascent_data'] = false;
        $filter_options['has_any_filter_data'] = false;
    }

    // Fallback to Layer API if Elasticsearch failed or is disabled
    if (empty($tracks) && !empty($layer_api_base)) {
        foreach ($layer_ids_array as $id) {
            if (empty($id)) continue;
            $layer_url = "{$layer_api_base}{$id}";
            $response = wp_remote_get($layer_url);

            if (is_wp_error($response)) continue;

            $layer_data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($layer_data['tracks'])) {
                foreach ($layer_data['tracks'] as $track) {
                    $track['svg_icon'] = '';
                    if (!empty($layer_data['taxonomy_activities'][0]['icon'])) {
                        $track['svg_icon'] = $layer_data['taxonomy_activities'][0]['icon'];
                    } elseif (!empty($layer_data['taxonomy_themes'][0]['icon'])) {
                        $track['svg_icon'] = $layer_data['taxonomy_themes'][0]['icon'];
                    }
                    $track['thumbnail_final'] = $default_image;
                    if (!empty($track['featureImage']['sizes']['1440x500'])) {
                        $track['thumbnail_final'] = esc_url($track['featureImage']['sizes']['1440x500']);
                    } elseif (!empty($track['featureImage']['thumbnail'])) {
                        $track['thumbnail_final'] = esc_url($track['featureImage']['thumbnail']);
                    }
                    $tracks[] = $track;
                }
            }
        }
    }
    // Sort tracks by updatedAt (descending) like wm-home-result, fallback to name number if updatedAt not available
    usort($tracks, function ($a, $b) use ($language) {
        // First try to sort by updatedAt (most recent first)
        $updatedAtA = $a['updatedAt'] ?? null;
        $updatedAtB = $b['updatedAt'] ?? null;

        if ($updatedAtA && $updatedAtB) {
            $timeA = strtotime($updatedAtA);
            $timeB = strtotime($updatedAtB);
            if ($timeA !== $timeB) {
                return $timeB - $timeA; // Descending order (newest first)
            }
        }

        // Fallback: extract number from name for sequential ordering
        preg_match('/\d+/', $a['name'][$language] ?? '', $matchesA);
        preg_match('/\d+/', $b['name'][$language] ?? '', $matchesB);
        $numA = isset($matchesA[0]) ? (int)$matchesA[0] : 0;
        $numB = isset($matchesB[0]) ? (int)$matchesB[0] : 0;

        if ($numA !== $numB) {
            return $numA - $numB;
        }

        // If numbers are the same, sort alphabetically
        return strcasecmp($a['name'][$language] ?? '', $b['name'][$language] ?? '');
    });
    if ('true' === $random) {
        shuffle($tracks);
    }
    if ($quantity > 0 && count($tracks) > $quantity) {
        $tracks = array_slice($tracks, 0, $quantity);
    }
    ob_start();
?>
    <?php if (!empty($featured_image_url)) : ?>
        <section class="l-section wpb_row height_small with_img with_overlay wm_header_section">
            <div class="l-section-img loaded wm-header-image" style="background-image: url(<?= $featured_image_url; ?>);"></div>
            <div class="l-section-h i-cf wm_header_wrapper"></div>
        </section>
    <?php endif; ?>

    <?php if (!empty($title) || !empty($subtitle) || !empty($description)) : ?>
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

            <?php if ($show_filters === 'true' && $use_elastic && !empty($filter_options['has_any_filter_data'])) : ?>
                <!-- Filters Interface -->
                <div class="wm_tracks_filters" data-layer-id="<?= esc_attr($layer_id ?: $layer_ids_array[0] ?? ''); ?>" data-elastic-api="<?= esc_attr($elastic_api_base); ?>" data-shard-app="<?= esc_attr($shard_app); ?>" data-app-id="<?= esc_attr($app_id); ?>">
                    <?php if (!empty($filter_options['has_distance_data'])) : ?>
                        <div class="wm_filter_item">
                            <label class="wm_filter_label"><?= __('Length', 'wm-package'); ?> <span class="wm_filter_range" id="distance_range"><?= esc_html($filter_options['distance_min']); ?> - <?= esc_html($filter_options['distance_max']); ?> <?= __('km', 'wm-package'); ?></span></label>
                            <div class="wm_slider_container">
                                <input type="range" id="distance_min" class="wm_slider" min="<?= esc_attr($filter_options['distance_min']); ?>" max="<?= esc_attr($filter_options['distance_max']); ?>" value="<?= esc_attr($filter_options['distance_min']); ?>" step="1">
                                <input type="range" id="distance_max" class="wm_slider" min="<?= esc_attr($filter_options['distance_min']); ?>" max="<?= esc_attr($filter_options['distance_max']); ?>" value="<?= esc_attr($filter_options['distance_max']); ?>" step="1">
                                <div class="wm_slider_track"></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($filter_options['has_region_data'])) : ?>
                        <div class="wm_filter_item">
                            <label class="wm_filter_label"><?= __('Where', 'wm-package'); ?></label>
                            <select id="filter_region" class="wm_filter_select">
                                <option value=""><?= __('Select where', 'wm-package'); ?></option>
                                <?php foreach ($filter_options['regions'] as $region) : ?>
                                    <option value="<?= esc_attr($region); ?>"><?= esc_html($region); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($filter_options['has_difficulty_data'])) : ?>
                        <div class="wm_filter_item">
                            <label class="wm_filter_label"><?= __('Difficulty', 'wm-package'); ?></label>
                            <select id="filter_difficulty" class="wm_filter_select">
                                <option value=""><?= __('Select difficulty', 'wm-package'); ?></option>
                                <?php
                                // Map of difficulty codes to labels
                                $difficulty_labels = array(
                                    'T' => __('Tourist', 'wm-package') . ' (T)',
                                    'E' => __('Hiker', 'wm-package') . ' (E)',
                                    'EE' => __('Expert hiker', 'wm-package') . ' (EE)',
                                    'EEA' => __('Expert hiker with equipment', 'wm-package') . ' (EEA)'
                                );
                                // Only show options that exist in the data
                                foreach ($filter_options['difficulties'] as $difficulty) {
                                    $label = isset($difficulty_labels[$difficulty]) ? $difficulty_labels[$difficulty] : $difficulty;
                                    echo '<option value="' . esc_attr($difficulty) . '">' . esc_html($label) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($filter_options['has_ascent_data'])) : ?>
                        <div class="wm_filter_item">
                            <label class="wm_filter_label"><?= __('Elevation gain +', 'wm-package'); ?> <span class="wm_filter_range" id="ascent_range"><?= esc_html($filter_options['ascent_min']); ?> - <?= esc_html($filter_options['ascent_max']); ?> <?= __('m', 'wm-package'); ?></span></label>
                            <div class="wm_slider_container">
                                <input type="range" id="ascent_min" class="wm_slider" min="<?= esc_attr($filter_options['ascent_min']); ?>" max="<?= esc_attr($filter_options['ascent_max']); ?>" value="<?= esc_attr($filter_options['ascent_min']); ?>" step="1">
                                <input type="range" id="ascent_max" class="wm_slider" min="<?= esc_attr($filter_options['ascent_min']); ?>" max="<?= esc_attr($filter_options['ascent_max']); ?>" value="<?= esc_attr($filter_options['ascent_max']); ?>" step="1">
                                <div class="wm_slider_track"></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="wm_tracks_grid" id="wm_tracks_grid_container">
                <?php foreach ($tracks as $track) : ?>
                    <div class="wm_grid_track_item">
                        <?php
                        // Extract name with fallback to other languages if current language not available
                        $name = '';
                        if (is_array($track['name'] ?? null)) {
                            $name = $track['name'][$language] ?? $track['name']['it'] ?? $track['name']['en'] ?? '';
                            // If still empty, try to get first available value
                            if (empty($name) && !empty($track['name'])) {
                                $name = reset($track['name']);
                            }
                        } else {
                            $name = $track['name'] ?? '';
                        }
                        $default_image = plugins_url('wm-package/assets/default_image.png');
                        $feature_image_url = $track['thumbnail_final'];
                        $track_slug = $track['slug'][$language] ?? wm_custom_slugify($name);
                        $base_url = apply_filters('wpml_home_url', get_site_url(), $language);
                        $track_page_url = trailingslashit($base_url) . "track/{$track_slug}/";

                        // Get region/category from taxonomyWheres
                        $region = '';
                        if (!empty($track['taxonomy_wheres']) && is_array($track['taxonomy_wheres'])) {
                            // Get the last element (most specific location)
                            $last_where = end($track['taxonomy_wheres']);
                            if (is_string($last_where)) {
                                $region = $last_where;
                            } elseif (is_array($last_where) && isset($last_where['name'])) {
                                $region = is_array($last_where['name']) ? ($last_where['name'][$language] ?? '') : $last_where['name'];
                            }
                        }

                        // Extract track ID from name (e.g., "SI A01" from "SI A01: Rifugio...")
                        $track_id_display = '';
                        if (preg_match('/^([A-Z]+\s+[A-Z0-9]+)/', $name, $matches)) {
                            $track_id_display = $matches[1];
                        }

                        // Get activity category for header
                        $activity_category = '';
                        if (!empty($track['taxonomy_activities']) && is_array($track['taxonomy_activities'])) {
                            $first_activity = reset($track['taxonomy_activities']);
                            if (is_string($first_activity)) {
                                $activity_category = $first_activity;
                            }
                        }

                        // Build taxonomy display (category + track ID if available)
                        $taxonomy_display = '';
                        if ($activity_category) {
                            $taxonomy_display = $activity_category;
                            if ($track_id_display) {
                                $taxonomy_display .= ' ' . $track_id_display;
                            }
                        } elseif ($track_id_display) {
                            $taxonomy_display = $track_id_display;
                        }
                        ?>

                        <!-- Sezione superiore con immagine in evidenza -->
                        <div class="wm_grid_track_image_section" style="background-image: url('<?= esc_url($feature_image_url); ?>');">
                            <!-- Box tassonomia in alto a sinistra -->
                            <?php if ($taxonomy_display) : ?>
                                <div class="wm_grid_track_taxonomy_box">
                                    <span><?= esc_html($taxonomy_display); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Sezione inferiore con name e tasto view -->
                        <div class="wm_grid_track_footer">
                            <div class="wm_grid_track_footer_name">
                                <?php if ($name) : ?>
                                    <span><?= esc_html($name); ?></span>
                                <?php endif; ?>
                            </div>
                            <a href="<?= esc_url($track_page_url); ?>" class="wm_grid_track_view_button">
                                <?= __('View', 'wm-package'); ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($title) || !empty($subtitle) || !empty($description)) : ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($show_filters === 'true' && $use_elastic && !empty($filter_options['has_any_filter_data'])) : ?>
        <script>
            (function() {
                const filtersContainer = document.querySelector('.wm_tracks_filters');
                if (!filtersContainer) return;

                const layerId = filtersContainer.dataset.layerId;
                const elasticApi = filtersContainer.dataset.elasticApi;
                const shardApp = filtersContainer.dataset.shardApp;
                const appId = filtersContainer.dataset.appId;
                const tracksContainer = document.getElementById('wm_tracks_grid_container');
                const language = '<?= esc_js($language); ?>';

                let filterTimeout;

                // Function to get URL parameter
                function getUrlParameter(name) {
                    const urlParams = new URLSearchParams(window.location.search);
                    return urlParams.get(name);
                }

                // Function to update URL with where parameter
                function updateUrlWithWhere(whereValue) {
                    const url = new URL(window.location.href);
                    if (whereValue) {
                        url.searchParams.set('where', whereValue);
                    } else {
                        url.searchParams.delete('where');
                    }
                    // Update URL without reloading page
                    window.history.pushState({}, '', url.toString());
                }

                // Function to create slug from region name
                function createSlug(text) {
                    return text.toLowerCase()
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '') // Remove accents
                        .replace(/[^a-z0-9]+/g, '-') // Replace non-alphanumeric with dash
                        .replace(/^-+|-+$/g, ''); // Remove leading/trailing dashes
                }

                function buildFilters() {
                    const filters = [];
                    let whereValue = null;
                    let difficultyValue = null;

                    <?php if (!empty($filter_options['has_distance_data'])) : ?>
                        const defaultDistanceMin = <?= $filter_options['distance_min']; ?>;
                        const defaultDistanceMax = <?= $filter_options['distance_max']; ?>;
                        // Distance filter - only apply if different from default
                        const distanceMinEl = document.getElementById('distance_min');
                        const distanceMaxEl = document.getElementById('distance_max');
                        if (distanceMinEl && distanceMaxEl) {
                            const distanceMin = parseFloat(distanceMinEl.value);
                            const distanceMax = parseFloat(distanceMaxEl.value);
                            if (distanceMin > defaultDistanceMin || distanceMax < defaultDistanceMax) {
                                filters.push(JSON.stringify({
                                    identifier: 'distance',
                                    min: distanceMin,
                                    max: distanceMax
                                }));
                            }
                        }
                    <?php endif; ?>

                    <?php if (!empty($filter_options['has_ascent_data'])) : ?>
                        const defaultAscentMin = <?= $filter_options['ascent_min']; ?>;
                        const defaultAscentMax = <?= $filter_options['ascent_max']; ?>;
                        // Ascent filter - only apply if different from default
                        const ascentMinEl = document.getElementById('ascent_min');
                        const ascentMaxEl = document.getElementById('ascent_max');
                        if (ascentMinEl && ascentMaxEl) {
                            const ascentMin = parseInt(ascentMinEl.value);
                            const ascentMax = parseInt(ascentMaxEl.value);
                            if (ascentMin > defaultAscentMin || ascentMax < defaultAscentMax) {
                                filters.push(JSON.stringify({
                                    identifier: 'ascent',
                                    min: ascentMin,
                                    max: ascentMax
                                }));
                            }
                        }
                    <?php endif; ?>

                    // Where filter - get value separately to use as taxonomyWheres parameter
                    const whereElement = document.getElementById('filter_region');
                    if (whereElement) {
                        whereValue = whereElement.value;
                        if (whereValue) {
                            // Update URL with where parameter
                            updateUrlWithWhere(createSlug(whereValue));
                        } else {
                            // Remove where parameter if no where selected
                            updateUrlWithWhere(null);
                        }
                    }

                    // Difficulty filter - get value separately to use as cai_scale parameter
                    const difficultyElement = document.getElementById('filter_difficulty');
                    if (difficultyElement) {
                        difficultyValue = difficultyElement.value;
                    }

                    return {
                        filters: filters,
                        whereValue: whereValue,
                        difficultyValue: difficultyValue
                    };
                }

                function updateResults() {
                    clearTimeout(filterTimeout);
                    filterTimeout = setTimeout(function() {
                        const filterData = buildFilters();
                        const filters = filterData.filters;
                        const whereValue = filterData.whereValue;
                        const difficultyValue = filterData.difficultyValue;
                        let url = elasticApi;
                        if (url.indexOf('?') === -1) {
                            url += '?';
                        } else {
                            url += '&';
                        }
                        url += 'app=' + shardApp + '_' + appId + '&layer=' + encodeURIComponent(layerId);

                        // Add taxonomyWheres parameter directly if where filter is selected
                        if (whereValue) {
                            url += '&taxonomyWheres=' + encodeURIComponent(whereValue);
                        }

                        // Add cai_scale parameter directly if difficulty filter is selected
                        if (difficultyValue) {
                            url += '&cai_scale=' + encodeURIComponent(difficultyValue);
                        }

                        if (filters.length > 0) {
                            url += '&filters=[' + filters.join(',') + ']';
                        }

                        // Show loading
                        if (tracksContainer) {
                            tracksContainer.innerHTML = '<div class="wm_loading"><?= __('Loading...', 'wm-package'); ?></div>';
                        }

                        fetch(url)
                            .then(response => response.json())
                            .then(data => {
                                if (tracksContainer && data.hits) {
                                    renderTracks(data.hits);
                                }
                            })
                            .catch(error => {
                                console.error('Filter error:', error);
                                if (tracksContainer) {
                                    tracksContainer.innerHTML = '<div class="wm_error"><?= __('Error loading tracks', 'wm-package'); ?></div>';
                                }
                            });
                    }, 300);
                }

                function renderTracks(hits) {
                    if (!tracksContainer) return;

                    if (!hits || hits.length === 0) {
                        tracksContainer.innerHTML = '<div class="wm_no_results"><?= __('No tracks found', 'wm-package'); ?></div>';
                        return;
                    }

                    let html = '';
                    hits.forEach(function(hit) {
                        // Handle name field: check both hit.name and hit.properties.name for geohub compatibility
                        const nameValue = hit.name || (hit.properties && hit.properties.name) || '';
                        const name = (nameValue && typeof nameValue === 'object') ? (nameValue[language] || nameValue.it || nameValue.en || '') : (nameValue || '');
                        const slug = (hit.slug && typeof hit.slug === 'object') ? (hit.slug[language] || '') : (hit.slug || '');
                        const trackSlug = slug || name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
                        const baseUrl = '<?= esc_js(apply_filters('wpml_home_url', get_site_url(), $language)); ?>';
                        const trackUrl = baseUrl + '/track/' + trackSlug + '/';

                        const featureImage = hit.feature_image || hit.featureImage;
                        let imageUrl = '<?= esc_js($default_image); ?>';
                        if (featureImage) {
                            if (typeof featureImage === 'string') {
                                imageUrl = featureImage;
                            } else if (featureImage.sizes && featureImage.sizes['1440x500']) {
                                imageUrl = featureImage.sizes['1440x500'];
                            } else if (featureImage.thumbnail) {
                                imageUrl = featureImage.thumbnail;
                            } else if (featureImage.url) {
                                imageUrl = featureImage.url;
                            }
                        }

                        const activityCategory = (hit.taxonomyActivities && hit.taxonomyActivities.length > 0) ? hit.taxonomyActivities[0] : '';
                        const trackIdMatch = name.match(/^([A-Z]+\s+[A-Z0-9]+)/);
                        const trackIdDisplay = trackIdMatch ? trackIdMatch[1] : '';

                        // Build taxonomy display (category + track ID if available)
                        let taxonomyDisplay = '';
                        if (activityCategory) {
                            taxonomyDisplay = activityCategory;
                            if (trackIdDisplay) {
                                taxonomyDisplay += ' ' + trackIdDisplay;
                            }
                        } else if (trackIdDisplay) {
                            taxonomyDisplay = trackIdDisplay;
                        }

                        html += '<div class="wm_grid_track_item">';
                        // Sezione superiore con immagine
                        html += '<div class="wm_grid_track_image_section" style="background-image: url(\'' + escapeHtml(imageUrl) + '\');">';
                        // Box tassonomia in alto a sinistra
                        if (taxonomyDisplay) {
                            html += '<div class="wm_grid_track_taxonomy_box">';
                            html += '<span>' + escapeHtml(taxonomyDisplay) + '</span>';
                            html += '</div>';
                        }
                        html += '</div>';
                        // Sezione inferiore con name e tasto view
                        html += '<div class="wm_grid_track_footer">';
                        html += '<div class="wm_grid_track_footer_name">';
                        if (name) {
                            html += '<span>' + escapeHtml(name) + '</span>';
                        }
                        html += '</div>';
                        html += '<a href="' + escapeHtml(trackUrl) + '" class="wm_grid_track_view_button"><?= esc_js(__('View', 'wm-package')); ?></a>';
                        html += '</div>';
                        html += '</div>';
                    });

                    tracksContainer.innerHTML = html;
                }

                function escapeHtml(text) {
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }

                const kmUnit = '<?= esc_js(__('km', 'wm-package')); ?>';
                const mUnit = '<?= esc_js(__('m', 'wm-package')); ?>';

                function updateSliderRange(minId, maxId, rangeId, unit) {
                    const minSlider = document.getElementById(minId);
                    const maxSlider = document.getElementById(maxId);
                    const rangeDisplay = document.getElementById(rangeId);
                    if (minSlider && maxSlider && rangeDisplay) {
                        const minVal = parseFloat(minSlider.value);
                        const maxVal = parseFloat(maxSlider.value);
                        const unitText = unit === 'km' ? kmUnit : mUnit;
                        rangeDisplay.textContent = minVal + ' - ' + maxVal + ' ' + unitText;

                        // Update slider track visual
                        updateSliderTrack(minSlider, maxSlider);
                    }
                }

                function updateSliderTrack(minSlider, maxSlider) {
                    const min = parseFloat(minSlider.min);
                    const max = parseFloat(minSlider.max);
                    const minVal = parseFloat(minSlider.value);
                    const maxVal = parseFloat(maxSlider.value);

                    const minPercent = ((minVal - min) / (max - min)) * 100;
                    const maxPercent = ((maxVal - min) / (max - min)) * 100;

                    const container = minSlider.closest('.wm_slider_container');
                    if (container) {
                        const track = container.querySelector('.wm_slider_track');
                        if (track) {
                            track.style.setProperty('--min-percent', minPercent + '%');
                            track.style.setProperty('--max-percent', maxPercent + '%');
                        }
                    }
                }

                // Initialize sliders - only if they exist
                <?php if (!empty($filter_options['has_distance_data'])) : ?>
                    const distanceMin = document.getElementById('distance_min');
                    const distanceMax = document.getElementById('distance_max');
                    if (distanceMin && distanceMax) {
                        updateSliderRange('distance_min', 'distance_max', 'distance_range', 'km');
                        updateSliderTrack(distanceMin, distanceMax);

                        distanceMin.addEventListener('input', function() {
                            if (parseFloat(this.value) >= parseFloat(distanceMax.value)) {
                                this.value = parseFloat(distanceMax.value) - 1;
                            }
                            updateSliderRange('distance_min', 'distance_max', 'distance_range', 'km');
                            updateResults();
                        });
                        distanceMax.addEventListener('input', function() {
                            if (parseFloat(this.value) <= parseFloat(distanceMin.value)) {
                                this.value = parseFloat(distanceMin.value) + 1;
                            }
                            updateSliderRange('distance_min', 'distance_max', 'distance_range', 'km');
                            updateResults();
                        });
                    }
                <?php endif; ?>

                <?php if (!empty($filter_options['has_ascent_data'])) : ?>
                    const ascentMin = document.getElementById('ascent_min');
                    const ascentMax = document.getElementById('ascent_max');
                    if (ascentMin && ascentMax) {
                        updateSliderRange('ascent_min', 'ascent_max', 'ascent_range', 'm');
                        updateSliderTrack(ascentMin, ascentMax);

                        ascentMin.addEventListener('input', function() {
                            if (parseInt(this.value) >= parseInt(ascentMax.value)) {
                                this.value = parseInt(ascentMax.value) - 1;
                            }
                            updateSliderRange('ascent_min', 'ascent_max', 'ascent_range', 'm');
                            updateResults();
                        });
                        ascentMax.addEventListener('input', function() {
                            if (parseInt(this.value) <= parseInt(ascentMin.value)) {
                                this.value = parseInt(ascentMin.value) + 1;
                            }
                            updateSliderRange('ascent_min', 'ascent_max', 'ascent_range', 'm');
                            updateResults();
                        });
                    }
                <?php endif; ?>

                // Dropdown filters - only attach listeners if elements exist
                const whereFilter = document.getElementById('filter_region');
                if (whereFilter) {
                    // Check if URL has where parameter and set filter accordingly
                    const whereParam = getUrlParameter('where');
                    if (whereParam) {
                        // Find matching where by comparing slugs
                        const options = whereFilter.options;
                        for (let i = 0; i < options.length; i++) {
                            const optionValue = options[i].value;
                            if (optionValue && createSlug(optionValue) === whereParam) {
                                whereFilter.value = optionValue;
                                // Trigger update to apply filter
                                updateResults();
                                break;
                            }
                        }
                    }
                    whereFilter.addEventListener('change', updateResults);
                }
                const difficultyFilter = document.getElementById('filter_difficulty');
                if (difficultyFilter) {
                    difficultyFilter.addEventListener('change', updateResults);
                }
            })();
        </script>
    <?php endif; ?>

<?php
    return ob_get_clean();
}
