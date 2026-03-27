<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * Load plugin textdomain for translations
 */
function wm_package_load_textdomain()
{
    load_plugin_textdomain(
        'wm-package',
        false,
        dirname(plugin_dir_path(__FILE__)) . '/languages/'
    );
}
add_action('plugins_loaded', 'wm_package_load_textdomain');

function requireAllPHPFilesInDirectory($directoryPath)
{
    $directoryPath = ABSPATH . $directoryPath;
    if (!is_dir($directoryPath)) {
        throw new InvalidArgumentException("The path '{$directoryPath}' is not a valid directory.");
    }

    $directoryIterator = new DirectoryIterator($directoryPath);

    foreach ($directoryIterator as $fileInfo) {
        if ($fileInfo->isDot() || $fileInfo->isDir()) {
            continue;
        }

        if ($fileInfo->getExtension() !== 'php') {
            continue; // Skip non-PHP files
        }

        $filePath = $fileInfo->getRealPath();

        if ($filePath === false) {
            throw new RuntimeException("Failed to get the real path for '{$fileInfo->getFilename()}'.");
        }

        require $filePath;
    }
}

// Configuration Lightbox2
function configure_lightbox2()
{
?>
    <script>
        lightbox.option({
            'fadeDuration': 50,
            'resizeDuration': 50,
            'wrapAround': true
        });
    </script>
<?php
}

//Slug
function wm_custom_slugify($title)
{
    $title = iconv('UTF-8', 'ASCII//TRANSLIT', $title);
    $title = str_replace('–', '-', $title);
    $title = str_replace("'", '', $title);
    $title = preg_replace('!\s+!', ' ', $title);
    $slug = sanitize_title_with_dashes($title);
    return $slug;
}

/**
 * Check if a shard is osm2cai-type
 * This affects URL structure for iframes
 */
function wm_is_osm2cai_shard_type($shard)
{
    return strpos($shard, 'osm2cai') === 0 || $shard === 'local';
}

/**
 * If the label is a full URL (http/https), returns a short label: host without "www." and only the name part (e.g. "sitoweb" from "https://www.sitoweb.it").
 * Used in POI and Track information sidebar for Website links.
 *
 * @param string $label
 * @param string $url
 * @return string
 */
function wm_website_link_label($label, $url)
{
    if (!is_string($label) || $label === '') {
        return $label;
    }
    if (!preg_match('#^https?://#i', trim($label))) {
        return $label;
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host || !is_string($host)) {
        return $label;
    }
    $host = preg_replace('/^www\./i', '', $host);
    $parts = explode('.', $host);
    if (count($parts) >= 1 && $parts[0] !== '') {
        return $parts[0];
    }
    return $host;
}

// Helper function to get iframe URL based on shard
function wm_get_iframe_url($type, $id, $language = 'it')
{
    $shard = get_option('wm_shard');
    if (empty($shard)) {
        $shard = 'geohub';
    }

    $app_id = get_option('app_configuration_id');
    if (!is_numeric($app_id) || empty($app_id)) {
        $app_id = '49';
    }

    // For osm2cai-type shards, use the osm2cai webmapp URL
    if (wm_is_osm2cai_shard_type($shard)) {
        if ($type === 'track') {
            return "https://{$app_id}.osm2cai.webmapp.it/w/simple/{$id}?locale={$language}";
        } elseif ($type === 'poi') {
            return "https://{$app_id}.osm2cai.webmapp.it/poi/simple/{$id}?locale={$language}";
        }
    } else {
        // For all other shards, use app.webmapp URL
        if ($type === 'track') {
            return "https://{$app_id}.app.webmapp.it/w/simple/{$id}?locale={$language}";
        } elseif ($type === 'poi') {
            return "https://{$app_id}.app.webmapp.it/poi/simple/{$id}?locale={$language}";
        }
    }

    return '';
}

// Add custom script: any element with class .wm-custom-link (link, menu item, button, container) redirects to the URL configured in admin based on device
function add_custom_menu_script()
{
    $hrefdefault = get_option("website_url");
    $hrefios = get_option("ios_app_url") ?: $hrefdefault;
    $hrefandroid = get_option("android_app_url") ?: $hrefdefault;
?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var userAgent = navigator.userAgent || navigator.vendor || window.opera;
            var url;
            if (/iPad|iPhone|iPod/.test(userAgent) && !window.MSStream) {
                url = <?php echo json_encode($hrefios); ?>;
            } else if (/android/i.test(userAgent)) {
                url = <?php echo json_encode($hrefandroid); ?>;
            } else {
                url = <?php echo json_encode($hrefdefault); ?>;
            }
            if (!url) return;
            document.querySelectorAll('.wm-custom-link').forEach(function(el) {
                var link = el.tagName === 'A' ? el : el.querySelector('a');
                if (link) {
                    link.href = url;
                    link.target = '_blank';
                } else {
                    el.style.cursor = 'pointer';
                    el.setAttribute('role', 'link');
                    el.addEventListener('click', function() {
                        window.open(url, '_blank');
                    });
                }
            });
        });
    </script>
<?php
}



add_action('wp_footer', 'add_custom_menu_script');


// Add custom script to copy content on click
function add_custom_copy_script()
{
?>
    <script>
        function copyCopiableElements() {
            const copiableElements = document.querySelectorAll('.copiable');

            copiableElements.forEach(element => {
                // Make element focusable for accessibility
                element.setAttribute('tabindex', '0');

                const copyText = () => {
                    const textToCopy = element.innerText;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(textToCopy)
                            .then(() => {
                                showCopySuccess(element);
                            })
                            .catch(() => {
                                fallbackCopyText(textToCopy);
                            });
                    } else {
                        fallbackCopyText(textToCopy);
                    }
                };

                // Add click event listener
                element.addEventListener('click', copyText);
            });
        }

        function showCopySuccess(element) {
            // Add class for visual feedback
            element.classList.add('copied');

            // Remove class after 2 seconds
            setTimeout(() => {
                element.classList.remove('copied');
            }, 2000);
        }

        function fallbackCopyText(text) {
            // Create a temporary textarea
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed'; // Avoid scroll
            textarea.style.left = '-9999px'; // Hide the textarea
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();

            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    alert('<?php echo esc_js(__('Text copied to clipboard!', 'wm-package')); ?>');
                } else {
                    throw new Error('Copy command failed');
                }
            } catch {
                alert('<?php echo esc_js(__('Unable to copy text. Please copy manually.', 'wm-package')); ?>');
            }

            // Remove the temporary textarea
            document.body.removeChild(textarea);
        }

        // Initialize function on DOM load
        document.addEventListener('DOMContentLoaded', copyCopiableElements);
    </script>
<?php
}



add_action('admin_footer-toplevel_page_wm-settings', 'add_custom_copy_script');

/**
 * Enqueue Leaflet CSS and JS for maps
 */
function wm_package_enqueue_leaflet()
{
    global $post;

    // Check if we're on a single POI or Track page
    if (is_singular(['poi', 'track'])) {
        // Enqueue Leaflet CSS
        wp_enqueue_style(
            'leaflet-css',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
            array(),
            '1.9.4'
        );

        // Enqueue Leaflet Fullscreen CSS
        wp_enqueue_style(
            'leaflet-fullscreen-css',
            'https://unpkg.com/leaflet-fullscreen@1.0.2/dist/Leaflet.fullscreen.css',
            array('leaflet-css'),
            '1.0.2'
        );

        // Enqueue Leaflet JS
        wp_enqueue_script(
            'leaflet-js',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
            array(),
            '1.9.4',
            true
        );

        // Enqueue Leaflet Fullscreen JS
        wp_enqueue_script(
            'leaflet-fullscreen-js',
            'https://unpkg.com/leaflet-fullscreen@1.0.2/dist/Leaflet.fullscreen.min.js',
            array('leaflet-js'),
            '1.0.2',
            true
        );

        // Enqueue Leaflet MarkerCluster CSS
        wp_enqueue_style(
            'leaflet-markercluster-css',
            'https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css',
            array('leaflet-css'),
            '1.4.1'
        );
        wp_enqueue_style(
            'leaflet-markercluster-default-css',
            'https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css',
            array('leaflet-markercluster-css'),
            '1.4.1'
        );

        // Enqueue Leaflet MarkerCluster JS
        wp_enqueue_script(
            'leaflet-markercluster-js',
            'https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js',
            array('leaflet-js'),
            '1.4.1',
            true
        );
        return;
    }

    // Check if shortcodes are used in the post content
    if ($post && (has_shortcode($post->post_content, 'wm_single_poi') || has_shortcode($post->post_content, 'wm_single_track'))) {
        // Enqueue Leaflet CSS
        wp_enqueue_style(
            'leaflet-css',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
            array(),
            '1.9.4'
        );

        // Enqueue Leaflet Fullscreen CSS
        wp_enqueue_style(
            'leaflet-fullscreen-css',
            'https://unpkg.com/leaflet-fullscreen@1.0.2/dist/Leaflet.fullscreen.css',
            array('leaflet-css'),
            '1.0.2'
        );

        // Enqueue Leaflet JS
        wp_enqueue_script(
            'leaflet-js',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
            array(),
            '1.9.4',
            true
        );

        // Enqueue Leaflet Fullscreen JS
        wp_enqueue_script(
            'leaflet-fullscreen-js',
            'https://unpkg.com/leaflet-fullscreen@1.0.2/dist/Leaflet.fullscreen.min.js',
            array('leaflet-js'),
            '1.0.2',
            true
        );

        // Enqueue Leaflet MarkerCluster CSS
        wp_enqueue_style(
            'leaflet-markercluster-css',
            'https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css',
            array('leaflet-css'),
            '1.4.1'
        );
        wp_enqueue_style(
            'leaflet-markercluster-default-css',
            'https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css',
            array('leaflet-markercluster-css'),
            '1.4.1'
        );

        // Enqueue Leaflet MarkerCluster JS
        wp_enqueue_script(
            'leaflet-markercluster-js',
            'https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js',
            array('leaflet-js'),
            '1.4.1',
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'wm_package_enqueue_leaflet');

/**
 * Enqueue Leaflet map initialization script
 */
function wm_package_enqueue_leaflet_map_script()
{
    global $post;

    // Check if we're on a single POI or Track page, or if shortcodes are used
    $should_enqueue = false;

    if (is_singular(['poi', 'track'])) {
        $should_enqueue = true;
    } elseif ($post && (has_shortcode($post->post_content, 'wm_single_poi') || has_shortcode($post->post_content, 'wm_single_track'))) {
        $should_enqueue = true;
    }

    if (!$should_enqueue) {
        return;
    }

    // Get default image URL
    $default_image_url = plugins_url('wm-package/assets/default_image.png');

    // Add inline script for Leaflet map initialization
    $script = "
    function wmInitLeafletMap(mapElementId, geometryJson, relatedPoisJson, defaultImageUrl) {
        var mapElement = document.getElementById(mapElementId);
        if (!mapElement || typeof L === 'undefined') {
            return;
        }

        var geometry = JSON.parse(geometryJson);
        var map = L.map(mapElement).setView([0, 0], 13);
        var defaultImgUrl = defaultImageUrl || '" . esc_js($default_image_url) . "';

        L.tileLayer('https://api.webmapp.it/tiles/{z}/{x}/{y}.png', {
            attribution: '&copy; Webmapp &copy; OpenStreetMap',
            maxZoom: 16
        }).addTo(map);

        // Add dynamic metric scale (updates with zoom), shown above attribution.
        L.control.scale({
            position: 'bottomright',
            imperial: false
        }).addTo(map);

        // Remove default Leaflet attribution prefix
        map.attributionControl.setPrefix(false);

        // Add fullscreen control: on iOS use pseudo-fullscreen so the map stays above header/menu (z-index fix)
        var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        map.addControl(new L.control.fullscreen({ pseudoFullscreen: isIOS }));

        // Function to create custom POI marker with circle and image
        function createPoiMarker(feature, latlng, defaultImg) {
            var poiImage = null;
            var properties = feature && feature.properties ? feature.properties : {};
            
            // Try to get featured image from various possible locations
            if (properties.feature_image) {
                if (properties.feature_image.sizes && properties.feature_image.sizes['1440x500']) {
                    poiImage = properties.feature_image.sizes['1440x500'];
                } else if (properties.feature_image.url) {
                    poiImage = properties.feature_image.url;
                } else if (properties.feature_image.thumbnail) {
                    poiImage = properties.feature_image.thumbnail;
                }
            }
            
            // Try alternative property names
            if (!poiImage && properties.featureImage && properties.featureImage.thumbnail) {
                poiImage = properties.featureImage.thumbnail;
            }
            
            // Use default image if no featured image found
            if (!poiImage) {
                poiImage = defaultImg;
            }
            
            // If still no image, use classic marker
            if (!poiImage) {
                return L.marker(latlng);
            }
            
            // Create custom div icon with circle and image
            var iconHtml = '<div class=\"wm-poi-marker-circle\">' +
                '<img src=\"' + poiImage + '\" alt=\"\" class=\"wm-poi-marker-image\" ' +
                'onerror=\"this.onerror=null; this.src=\'' + (defaultImg || '') + '\';\" />' +
                '</div>';
            
            var customIcon = L.divIcon({
                className: 'wm-poi-custom-marker',
                html: iconHtml,
                iconSize: [40, 40],
                iconAnchor: [20, 20],
                popupAnchor: [0, -20]
            });
            
            return L.marker(latlng, { icon: customIcon });
        }

        var bounds = null;
        var hasTrackPoint = false;
        var hasTrackBounds = false;

        if (geometry.type === 'Point' && geometry.coordinates) {
            var lat = geometry.coordinates[1];
            var lng = geometry.coordinates[0];
            map.setView([lat, lng], 15);
            
            // Check if this is a single POI page with image data
            var poiImage = mapElement.getAttribute('data-poi-image');
            if (poiImage) {
                // Create custom marker for single POI
                var poiFeature = {
                    properties: {
                        feature_image: {
                            url: poiImage,
                            sizes: {
                                '1440x500': poiImage
                            }
                        }
                    }
                };
                var customMarker = createPoiMarker(poiFeature, [lat, lng], defaultImgUrl);
                customMarker.addTo(map);
            } else {
                // Use standard marker for track points
                L.marker([lat, lng]).addTo(map);
            }
            
            bounds = L.latLngBounds([lat, lng]);
            hasTrackPoint = true;
        } else if (geometry.type === 'LineString' && geometry.coordinates) {
            var latlngs = geometry.coordinates.map(function(coord) {
                return [coord[1], coord[0]];
            });
            var polyline = L.polyline(latlngs, {
                color: 'blue'
            }).addTo(map);

            if (latlngs.length > 0) {
                var startPoint = latlngs[0];
                var endPoint = latlngs[latlngs.length - 1];
                var epsilon = 1e-6;
                var isLoopTrack = Math.abs(startPoint[0] - endPoint[0]) < epsilon && Math.abs(startPoint[1] - endPoint[1]) < epsilon;

                if (isLoopTrack) {
                    // Loop track: single marker split in half (start + end in same point).
                    var loopIcon = L.divIcon({
                        className: 'wm-loop-start-end-marker',
                        html: '<span style=\"display:block;width:16px;height:16px;border:2px solid #ffffff;border-radius:50%;background:linear-gradient(90deg,#2e7d32 0 50%,#c62828 50% 100%);\"></span>',
                        iconSize: [20, 20],
                        iconAnchor: [10, 10]
                    });
                    L.marker(startPoint, { icon: loopIcon }).addTo(map);
                } else {
                    // Start marker (green)
                    L.circleMarker(startPoint, {
                        radius: 7,
                        color: '#ffffff',
                        weight: 2,
                        fillColor: '#2e7d32',
                        fillOpacity: 1
                    }).addTo(map);

                    // End marker (red)
                    L.circleMarker(endPoint, {
                        radius: 7,
                        color: '#ffffff',
                        weight: 2,
                        fillColor: '#c62828',
                        fillOpacity: 1
                    }).addTo(map);
                }
            }

            bounds = polyline.getBounds();
            hasTrackBounds = true;
        } else if (geometry.type === 'Polygon' && geometry.coordinates) {
            var latlngs = geometry.coordinates[0].map(function(coord) {
                return [coord[1], coord[0]];
            });
            var polygon = L.polygon(latlngs, {
                color: 'blue'
            }).addTo(map);
            bounds = polygon.getBounds();
            hasTrackBounds = true;
        }

        var hasPoiBounds = false;
        if (relatedPoisJson) {
            var relatedPois = relatedPoisJson;
            if (typeof relatedPoisJson === 'string') {
                try {
                    relatedPois = JSON.parse(relatedPoisJson);
                } catch (e) {
                    relatedPois = null;
                }
            }

            var poiCollection = null;
            if (Array.isArray(relatedPois)) {
                poiCollection = {
                    type: 'FeatureCollection',
                    features: relatedPois
                };
            } else if (relatedPois && relatedPois.type === 'FeatureCollection') {
                poiCollection = relatedPois;
            } else if (relatedPois && relatedPois.type === 'Feature') {
                poiCollection = {
                    type: 'FeatureCollection',
                    features: [relatedPois]
                };
            }

            if (poiCollection && Array.isArray(poiCollection.features) && poiCollection.features.length) {
                var poiLayer = L.geoJSON(poiCollection, {
                    pointToLayer: function(feature, latlng) {
                        return createPoiMarker(feature, latlng, defaultImgUrl);
                    },
                    onEachFeature: function(feature, layer) {
                        var name = null;
                        var poiUrl = null;
                        if (feature && feature.properties && feature.properties.name) {
                            if (typeof feature.properties.name === 'string') {
                                name = feature.properties.name;
                            } else if (feature.properties.name.it) {
                                name = feature.properties.name.it;
                            } else {
                                for (var key in feature.properties.name) {
                                    if (Object.prototype.hasOwnProperty.call(feature.properties.name, key) && feature.properties.name[key]) {
                                        name = feature.properties.name[key];
                                        break;
                                    }
                                }
                            }
                        }
                        if (feature && feature.properties && feature.properties.wm_poi_url) {
                            poiUrl = feature.properties.wm_poi_url;
                        }
                        if (name) {
                            if (poiUrl) {
                                var popupContent = \"<a href='\" + poiUrl + \"'>\" + name + \"</a>\";
                                layer.bindPopup(popupContent);
                            } else {
                                layer.bindPopup(name);
                            }
                        }
                    }
                });

                var poiLayerForBounds = poiLayer;
                if (typeof L.markerClusterGroup === 'function') {
                    var poiCluster = L.markerClusterGroup({
                        showCoverageOnHover: false,
                        maxClusterRadius: 60
                    });
                    poiCluster.addLayer(poiLayer);
                    poiCluster.addTo(map);
                    poiLayerForBounds = poiCluster;
                } else {
                    poiLayer.addTo(map);
                }

                if (poiLayerForBounds && poiLayerForBounds.getBounds) {
                    var poiBounds = poiLayerForBounds.getBounds();
                    if (poiBounds && poiBounds.isValid && poiBounds.isValid()) {
                        hasPoiBounds = true;
                        if (bounds) {
                            bounds.extend(poiBounds);
                        } else {
                            bounds = poiBounds;
                        }
                    }
                }
            }
        }

        if (bounds && (hasTrackBounds || hasPoiBounds)) {
            map.fitBounds(bounds, { padding: [20, 20] });
        }
    }
    ";

    wp_add_inline_script('leaflet-js', $script, 'before');
}
add_action('wp_enqueue_scripts', 'wm_package_enqueue_leaflet_map_script', 20);

/**
 * Enqueue default CSS for WM Package shortcodes
 * Allows theme to override by creating wm-package-custom.css
 */
function wm_package_enqueue_styles()
{
    global $post;

    // Check if we're on a single POI or Track page, or if shortcodes are used
    $should_enqueue = false;

    if (is_singular(['poi', 'track'])) {
        $should_enqueue = true;
    } elseif ($post && (has_shortcode($post->post_content, 'wm_single_poi') || has_shortcode($post->post_content, 'wm_single_track') || has_shortcode($post->post_content, 'wm_grid_track') || has_shortcode($post->post_content, 'wm_grid_poi'))) {
        $should_enqueue = true;
    }

    if (!$should_enqueue) {
        return;
    }

    $plugin_url = plugin_dir_url(dirname(__FILE__));
    $plugin_version = '2.0';

    // First, try to load custom CSS from theme (child theme first, then parent theme)
    $theme_css = locate_template('wm-package-custom.css');

    if ($theme_css) {
        // Get the URL of the theme CSS file
        $theme_css_url = get_stylesheet_directory_uri() . '/wm-package-custom.css';
        if (is_child_theme() && file_exists(get_stylesheet_directory() . '/wm-package-custom.css')) {
            $theme_css_url = get_stylesheet_directory_uri() . '/wm-package-custom.css';
        } elseif (file_exists(get_template_directory() . '/wm-package-custom.css')) {
            $theme_css_url = get_template_directory_uri() . '/wm-package-custom.css';
        }

        wp_enqueue_style(
            'wm-package-custom',
            $theme_css_url,
            array(),
            filemtime($theme_css)
        );
    }

    // Always enqueue default CSS (theme CSS will override if it exists)
    wp_enqueue_style(
        'wm-package-default',
        $plugin_url . 'assets/css/wm-package-default.css',
        array(),
        $plugin_version
    );

    // Primary color from wm_default_config.json (WORDPRESS.primary) overrides --wm-primary-color; fallback #0073AA is in CSS
    $config = function_exists('wm_get_default_config') ? wm_get_default_config() : false;
    $primary_color = !empty($config['WORDPRESS']['primary']) ? trim($config['WORDPRESS']['primary']) : '';
    if ($primary_color !== '') {
        $primary_css = ':root { --wm-primary-color: ' . esc_attr($primary_color) . ';';
        // RGB components for rgba() (e.g. focus box-shadow)
        if (preg_match('/^#?([a-fA-F0-9]{2})([a-fA-F0-9]{2})([a-fA-F0-9]{2})$/', $primary_color, $m)) {
            $primary_css .= ' --wm-primary-color-rgb: ' . (int) hexdec($m[1]) . ', ' . (int) hexdec($m[2]) . ', ' . (int) hexdec($m[3]) . ';';
        }
        $primary_css .= ' }';
        wp_add_inline_style('wm-package-default', $primary_css);
    }
}
add_action('wp_enqueue_scripts', 'wm_package_enqueue_styles');

/**
 * Safely render SVG icon code
 * Allows SVG tags and attributes for rendering icons from API
 */
function wm_render_svg_icon($svg_code)
{
    if (empty($svg_code)) {
        return '';
    }

    // Define allowed SVG tags and attributes
    $allowed_svg_tags = array(
        'svg' => array(
            'xmlns' => true,
            'viewbox' => true,
            'viewBox' => true,
            'width' => true,
            'height' => true,
            'class' => true,
            'id' => true,
            'style' => true,
        ),
        'circle' => array(
            'fill' => true,
            'cx' => true,
            'cy' => true,
            'r' => true,
            'stroke' => true,
            'stroke-width' => true,
        ),
        'g' => array(
            'fill' => true,
            'transform' => true,
            'class' => true,
            'id' => true,
        ),
        'path' => array(
            'd' => true,
            'fill' => true,
            'stroke' => true,
            'stroke-width' => true,
            'class' => true,
        ),
        'rect' => array(
            'x' => true,
            'y' => true,
            'width' => true,
            'height' => true,
            'fill' => true,
            'stroke' => true,
            'stroke-width' => true,
        ),
        'polygon' => array(
            'points' => true,
            'fill' => true,
            'stroke' => true,
            'stroke-width' => true,
        ),
        'polyline' => array(
            'points' => true,
            'fill' => true,
            'stroke' => true,
            'stroke-width' => true,
        ),
        'line' => array(
            'x1' => true,
            'y1' => true,
            'x2' => true,
            'y2' => true,
            'stroke' => true,
            'stroke-width' => true,
        ),
        'ellipse' => array(
            'cx' => true,
            'cy' => true,
            'rx' => true,
            'ry' => true,
            'fill' => true,
            'stroke' => true,
            'stroke-width' => true,
        ),
    );

    // Use wp_kses to filter SVG code
    return wp_kses($svg_code, $allowed_svg_tags);
}

define('WM_CONFIG_SOURCE_OPTION', 'wm_config_source');
define('WM_CACHED_API_CONFIG_OPTION', 'wm_cached_api_config');

/**
 * Build the config API URL for the given shard and app id.
 *
 * Patterns:
 * - geohub: {awsApi}/conf/{app_id}.json
 *   e.g. https://wmfe.s3.eu-central-1.amazonaws.com/geohub/conf/49.json
 * - other shards: {awsApi}/{app_id}/config.json
 *   e.g. https://wmfe.s3.eu-central-1.amazonaws.com/osm2cai2/2/config.json
 *
 * @param string $shard Shard name (e.g. osm2cai2, osm2cai2dev, geohub)
 * @param string $app_id App configuration ID
 * @return string|null Full URL or null if shard config not available
 */
function wm_get_config_api_url($shard, $app_id)
{
    if (!function_exists('wm_get_shards_config')) {
        return null;
    }
    $shards = wm_get_shards_config();
    if (empty($shard) || empty($app_id) || !isset($shards[$shard]['awsApi'])) {
        return null;
    }
    $aws_api = rtrim($shards[$shard]['awsApi'], '/');

    if ($shard === 'geohub') {
        return $aws_api . '/conf/' . $app_id . '.json';
    }

    return $aws_api . '/' . $app_id . '/config.json';
}

/**
 * Fetch remote config from API (no cache). Used only when saving from dashboard.
 *
 * @param string $url Full config.json URL
 * @return array|null Decoded config array or null on failure
 */
function wm_fetch_remote_config($url)
{
    $response = wp_remote_get($url, [
        'timeout' => 8,
        'sslverify' => true,
    ]);

    if (is_wp_error($response)) {
        return null;
    }
    if (wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    return is_array($data) ? $data : null;
}

/**
 * Get WM default configuration.
 * Base is always wm_default_config.json. If the cached API response contains WORDPRESS
 * and/or APP, those sections override the local ones; missing sections in the API use local.
 * APP.geohubId is always set to current app_configuration_id.
 *
 * @return array|false Configuration array or false on error
 */
function wm_get_default_config()
{
    $app_id = get_option('app_configuration_id');
    if (!is_numeric($app_id) || empty($app_id)) {
        $app_id = '49';
    }

    $config_file = plugin_dir_path(dirname(__FILE__)) . 'config/wm_default_config.json';
    if (!file_exists($config_file)) {
        return false;
    }

    $config_content = file_get_contents($config_file);
    if ($config_content === false) {
        return false;
    }

    $config = json_decode($config_content, true);
    if ($config === null) {
        return false;
    }

    $cached = get_option(WM_CACHED_API_CONFIG_OPTION);
    if (is_array($cached)) {
        if (isset($cached['WORDPRESS']) && is_array($cached['WORDPRESS'])) {
            $config['WORDPRESS'] = $cached['WORDPRESS'];
        }
        if (isset($cached['APP']) && is_array($cached['APP'])) {
            $config['APP'] = $cached['APP'];
        }
    }

    if (!isset($config['APP'])) {
        $config['APP'] = [];
    }
    $config['APP']['geohubId'] = (int) $app_id;

    return $config;
}

/**
 * Update the geohubId field in wm_default_config.json file (APP section)
 * Called when app_configuration_id is saved in admin_page.php
 *
 * @param int|string $app_id The app configuration ID to save
 * @return bool True on success, false on failure
 */
function wm_update_config_id($app_id)
{
    $config_file = plugin_dir_path(dirname(__FILE__)) . 'config/wm_default_config.json';

    if (!file_exists($config_file)) {
        return false;
    }

    $config_content = file_get_contents($config_file);
    if ($config_content === false) {
        return false;
    }

    $config = json_decode($config_content, true);
    if ($config === null) {
        return false;
    }

    // Update APP.geohubId
    if (!isset($config['APP'])) {
        $config['APP'] = [];
    }
    $config['APP']['geohubId'] = (int) $app_id;

    // Write back to file with pretty print
    $updated_content = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($updated_content === false) {
        return false;
    }

    $result = file_put_contents($config_file, $updated_content);
    return $result !== false;
}
