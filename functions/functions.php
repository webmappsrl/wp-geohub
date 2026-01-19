<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

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
