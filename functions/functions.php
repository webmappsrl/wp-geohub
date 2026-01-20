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

// Add custom script to change menu link based on device
function add_custom_menu_script()
{
    $hrefdefault = get_option("website_url");
    $hrefios = get_option("ios_app_url") ?: $hrefdefault;
    $hrefandroid = get_option("android_app_url") ?: $hrefdefault;
?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var userAgent = navigator.userAgent || navigator.vendor || window.opera;
            var menuLink = document.querySelector('.wm-custom-link > a');

            if (menuLink) {
                if (/iPad|iPhone|iPod/.test(userAgent) && !window.MSStream) {
                    // iOS
                    menuLink.href = "<?php echo $hrefios; ?>";
                } else if (/android/i.test(userAgent)) {
                    // Android
                    menuLink.href = "<?php echo $hrefandroid; ?>";
                } else {
                    // Not mobile
                    menuLink.href = "<?php echo $hrefdefault; ?>";
                }
                console.log(menuLink.href);
                menuLink.target = "_blank";
            }
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
                // Rendi l'elemento focusabile per l'accessibilità
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

                // Aggiungi l'event listener per il click
                element.addEventListener('click', copyText);
            });
        }

        function showCopySuccess(element) {
            // Aggiungi una classe per fornire feedback visivo
            element.classList.add('copied');

            // Rimuovi la classe dopo 2 secondi
            setTimeout(() => {
                element.classList.remove('copied');
            }, 2000);
        }

        function fallbackCopyText(text) {
            // Crea un'area di testo temporanea
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed'; // Evita lo scroll
            textarea.style.left = '-9999px'; // Nascondi l'area di testo
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();

            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    alert('Testo copiato negli appunti!');
                } else {
                    throw new Error('Comando di copia non riuscito');
                }
            } catch {
                alert('Impossibile copiare il testo. Per favore, copia manualmente.');
            }

            // Rimuovi l'area di testo temporanea
            document.body.removeChild(textarea);
        }

        // Inizializza la funzione al caricamento del DOM
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

    // Add inline script for Leaflet map initialization
    $script = "
    function wmInitLeafletMap(mapElementId, geometryJson) {
        var mapElement = document.getElementById(mapElementId);
        if (!mapElement || typeof L === 'undefined') {
            return;
        }

        var geometry = JSON.parse(geometryJson);
        var map = L.map(mapElement).setView([0, 0], 13);

        L.tileLayer('https://api.webmapp.it/tiles/{z}/{x}/{y}.png', {
            attribution: '&copy; Webmapp &copy; OpenStreetMap',
            maxZoom: 19
        }).addTo(map);

        // Remove default Leaflet attribution prefix
        map.attributionControl.setPrefix(false);

        // Add fullscreen control
        map.addControl(new L.control.fullscreen());

        if (geometry.type === 'Point' && geometry.coordinates) {
            var lat = geometry.coordinates[1];
            var lng = geometry.coordinates[0];
            map.setView([lat, lng], 15);
            L.marker([lat, lng]).addTo(map);
        } else if (geometry.type === 'LineString' && geometry.coordinates) {
            var latlngs = geometry.coordinates.map(function(coord) {
                return [coord[1], coord[0]];
            });
            var polyline = L.polyline(latlngs, {
                color: 'blue'
            }).addTo(map);
            map.fitBounds(polyline.getBounds());
        } else if (geometry.type === 'Polygon' && geometry.coordinates) {
            var latlngs = geometry.coordinates[0].map(function(coord) {
                return [coord[1], coord[0]];
            });
            var polygon = L.polygon(latlngs, {
                color: 'blue'
            }).addTo(map);
            map.fitBounds(polygon.getBounds());
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
    } elseif ($post && (has_shortcode($post->post_content, 'wm_single_poi') || has_shortcode($post->post_content, 'wm_single_track') || has_shortcode($post->post_content, 'wm_grid_track'))) {
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
