<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

function requireAllPHPFilesInDirectory($directoryPath){
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
    $title = str_replace("’", '', $title);
    $title = preg_replace('!\s+!', ' ', $title);
    $slug = sanitize_title_with_dashes($title);
    return $slug;
}


// Add custom script to change menu link based on device
function add_custom_menu_script()
{
$hrefdefault = get_option("default_app_url");
$hrefios = get_option("ios_app_url") ?: $hrefdefault;
$hrefandroid = get_option("android_app_url") ?: $hrefdefault;
$hrefNomobile = get_option("website_url") ?: $hrefdefault;
?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var userAgent = navigator.userAgent || navigator.vendor || window.opera;
            var menuLink = document.querySelector('.menu-item-90 a, .menu-item-93 a');

            if (menuLink) {
                if (/iPad|iPhone|iPod/.test(userAgent) && !window.MSStream) {
                    // iOS
                    menuLink.href = "<?php echo $hrefios; ?>";
                } else if (/android/i.test(userAgent)) {
                    // Android
                    menuLink.href = "<?php echo $hrefandroid; ?>";
                } else {
                    // Not mobile
                    menuLink.href = "<?php echo $hrefNomobile; ?>";
                }
                menuLink.target = "_blank";
            }
        });
    </script>
<?php
}



add_action('wp_footer', 'add_custom_menu_script');