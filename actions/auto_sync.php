<?php
/**
 * Auto-sync (WP-Cron) for tracks and POIs.
 *
 * - Adds a custom cron schedule "wm_every_six_hours".
 * - Reconciles WP-Cron events on admin_init based on options
 *   (wm_auto_sync_tracks_enabled / _frequency, same for pois).
 * - On each cron tick processes a small batch (size 10) and re-schedules
 *   a single follow-up event in 30 seconds until the run is complete,
 *   to avoid PHP timeouts on long syncs.
 * - Reuses the same skip/update logic as the manual generate buttons:
 *   posts with up-to-date `updated_at` are skipped, newer ones are updated.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Allowed frequencies for auto-sync (ordered from fastest to slowest).
 */
function wm_auto_sync_allowed_frequencies()
{
    return [
        'wm_every_five_minutes'    => __('Every 5 minutes', 'wm-package'),
        'wm_every_fifteen_minutes' => __('Every 15 minutes', 'wm-package'),
        'wm_every_thirty_minutes'  => __('Every 30 minutes', 'wm-package'),
        'hourly'                   => __('Every hour', 'wm-package'),
        'wm_every_six_hours'       => __('Every 6 hours', 'wm-package'),
        'twicedaily'               => __('Twice a day', 'wm-package'),
        'daily'                    => __('Once a day', 'wm-package'),
    ];
}

/**
 * Register custom cron schedules used by auto-sync.
 */
add_filter('cron_schedules', 'wm_auto_sync_register_schedules');
function wm_auto_sync_register_schedules($schedules)
{
    if (!isset($schedules['wm_every_five_minutes'])) {
        $schedules['wm_every_five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __('Every 5 minutes', 'wm-package'),
        ];
    }
    if (!isset($schedules['wm_every_fifteen_minutes'])) {
        $schedules['wm_every_fifteen_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __('Every 15 minutes', 'wm-package'),
        ];
    }
    if (!isset($schedules['wm_every_thirty_minutes'])) {
        $schedules['wm_every_thirty_minutes'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display'  => __('Every 30 minutes', 'wm-package'),
        ];
    }
    if (!isset($schedules['wm_every_six_hours'])) {
        $schedules['wm_every_six_hours'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => __('Every 6 hours', 'wm-package'),
        ];
    }
    return $schedules;
}

/**
 * Reconcile WP-Cron events with current options.
 * - If auto-sync is enabled and no event is scheduled (or with wrong schedule), (re)schedule it.
 * - If auto-sync is disabled and an event is scheduled, unschedule it.
 *
 * Runs on admin_init so the user changing settings sees the effect immediately.
 */
add_action('admin_init', 'wm_auto_sync_reconcile_events');
function wm_auto_sync_reconcile_events()
{
    $allowed = array_keys(wm_auto_sync_allowed_frequencies());

    foreach (['tracks', 'pois'] as $type) {
        $hook    = "wm_auto_sync_{$type}";
        $enabled = (int) get_option("wm_auto_sync_{$type}_enabled", 0) === 1;
        $freq    = get_option("wm_auto_sync_{$type}_frequency", 'daily');
        if (!in_array($freq, $allowed, true)) {
            $freq = 'daily';
        }

        $current = wp_get_scheduled_event($hook);

        if ($enabled) {
            if (!$current || $current->schedule !== $freq) {
                if ($current) {
                    wp_clear_scheduled_hook($hook);
                }
                wp_schedule_event(time() + 60, $freq, $hook);
            }
        } else {
            if ($current) {
                wp_clear_scheduled_hook($hook);
            }
            wp_clear_scheduled_hook("{$hook}_continue");
            delete_option("wm_auto_sync_{$type}_state");
        }
    }
}

/**
 * Cron tick handlers (recurring + single follow-up share the same logic).
 */
add_action('wm_auto_sync_tracks',          'wm_auto_sync_tracks_tick');
add_action('wm_auto_sync_tracks_continue', 'wm_auto_sync_tracks_tick');
add_action('wm_auto_sync_pois',            'wm_auto_sync_pois_tick');
add_action('wm_auto_sync_pois_continue',   'wm_auto_sync_pois_tick');

function wm_auto_sync_tracks_tick()
{
    wm_auto_sync_run_tick('tracks');
}

function wm_auto_sync_pois_tick()
{
    wm_auto_sync_run_tick('pois');
}

/**
 * Run a single tick for the given type ('tracks' or 'pois').
 * Reads/updates the persistent state option and re-schedules a follow-up event
 * in 30 seconds when the run is not yet complete.
 */
function wm_auto_sync_run_tick($type)
{
    if ($type !== 'tracks' && $type !== 'pois') {
        return;
    }

    $state_key   = "wm_auto_sync_{$type}_state";
    $last_key    = "wm_auto_sync_{$type}_last_run";
    $remote_key  = "wm_auto_sync_{$type}_remote_state";
    $continue_h  = "wm_auto_sync_{$type}_continue";
    $batch_size  = 10;

    $state = get_option($state_key, null);
    $is_new_run = !is_array($state);
    if ($is_new_run) {
        $state = [
            'offset'                => 0,
            'started_at'            => time(),
            'cumulative_processed'  => 0,
            'cumulative_skipped'    => 0,
            'cumulative_errors'     => 0,
            'total'                 => 0,
            'pending_markers'       => null,
        ];
    }

    // Preliminary change detection (only on the very first batch of a run).
    // If nothing changed remotely, we skip the sync entirely.
    $offset = isset($state['offset']) ? (int) $state['offset'] : 0;
    if ($is_new_run && $offset === 0) {
        $url = ($type === 'tracks') ? get_option('tracks_list') : get_option('poi_url');
        if (!empty($url)) {
            $check = wm_auto_sync_check_remote_changes($type, $url);

            if (!empty($check['error'])) {
                update_option($last_key, [
                    'finished_at' => time(),
                    'started_at'  => time(),
                    'processed'   => 0,
                    'skipped'     => 0,
                    'errors'      => 1,
                    'total'       => 0,
                    'message'     => $check['error'],
                    'status'      => 'error',
                ]);
                return;
            }

            if (empty($check['changed'])) {
                // Remote unchanged: skip sync, refresh markers (in case ETag/Last-Modified changed format).
                if (!empty($check['markers'])) {
                    update_option($remote_key, $check['markers']);
                }
                update_option($last_key, [
                    'finished_at' => time(),
                    'started_at'  => time(),
                    'processed'   => 0,
                    'skipped'     => 0,
                    'errors'      => 0,
                    'total'       => (int) ($check['count'] ?? 0),
                    'message'     => 'no_changes',
                    'status'      => 'no_changes',
                ]);
                return;
            }

            // Remote changed: pre-populate the transient when we already downloaded the body,
            // so the first batch does not re-fetch. Markers will be persisted on completion.
            if (!empty($check['parsed'])) {
                set_transient("wm_auto_sync_{$type}_list", $check['parsed'], DAY_IN_SECONDS);
            }
            if (!empty($check['markers'])) {
                $state['pending_markers'] = $check['markers'];
            }
        }
    }

    if ($type === 'tracks') {
        $result = wm_auto_run_tracks_batch($offset, $batch_size);
    } else {
        $result = wm_auto_run_pois_batch($offset, $batch_size);
    }

    if (!empty($result['error'])) {
        delete_option($state_key);
        update_option($last_key, [
            'finished_at' => time(),
            'started_at'  => $state['started_at'],
            'processed'   => $state['cumulative_processed'],
            'skipped'     => $state['cumulative_skipped'],
            'errors'      => $state['cumulative_errors'],
            'total'       => $state['total'],
            'message'     => $result['error'],
            'status'      => 'error',
        ]);
        return;
    }

    $state['cumulative_processed'] += (int) ($result['processed'] ?? 0);
    $state['cumulative_skipped']   += (int) ($result['skipped']   ?? 0);
    $state['cumulative_errors']    += (int) ($result['errors']    ?? 0);
    $state['total']                 = (int) ($result['total']     ?? $state['total']);

    if (!empty($result['complete'])) {
        // Persist remote markers only after a successful complete run.
        if (!empty($state['pending_markers'])) {
            update_option($remote_key, $state['pending_markers']);
        }
        $payload = [
            'finished_at' => time(),
            'started_at'  => $state['started_at'],
            'processed'   => $state['cumulative_processed'],
            'skipped'     => $state['cumulative_skipped'],
            'errors'      => $state['cumulative_errors'],
            'total'       => $state['total'],
            'message'     => null,
            'status'      => 'ok',
        ];
        delete_option($state_key);
        update_option($last_key, $payload);
        // Also keep a copy of the last "real" sync (status=ok) so that when later
        // runs finish as "no_changes" we can still show the most recent counters.
        update_option("wm_auto_sync_{$type}_last_real_run", $payload);
        return;
    }

    $state['offset'] = (int) $result['next_offset'];
    update_option($state_key, $state);

    if (!wp_next_scheduled($continue_h)) {
        wp_schedule_single_event(time() + 30, $continue_h);
    }
}

/**
 * Preliminary "is there anything to sync?" check.
 *
 * Strategy:
 *  1) HEAD on the remote URL. If response has ETag or Last-Modified and they
 *     match the markers stored from a previous successful run -> no changes.
 *  2) Otherwise GET the body and compare an MD5 hash with the stored one.
 *     If equal -> no changes. Otherwise -> changed, return parsed body so
 *     the sync can reuse it without re-downloading.
 *
 * @param string $type 'tracks' or 'pois'
 * @param string $url  Source URL
 * @return array {
 *   changed: bool,
 *   markers?: array { etag?: string, last_modified?: string, hash?: string },
 *   parsed?: array,   // tracks: associative {id => updated_at}; pois: features array
 *   count?: int,
 *   error?: string
 * }
 */
function wm_auto_sync_check_remote_changes($type, $url)
{
    $remote_key = "wm_auto_sync_{$type}_remote_state";
    $stored = get_option($remote_key, []);
    if (!is_array($stored)) {
        $stored = [];
    }

    // 1) HEAD attempt (cheap).
    $head = wp_remote_head($url, ['timeout' => 15, 'redirection' => 5]);
    $head_etag = '';
    $head_lm   = '';
    if (!is_wp_error($head)) {
        $head_code = (int) wp_remote_retrieve_response_code($head);
        if ($head_code >= 200 && $head_code < 300) {
            $head_etag = trim((string) wp_remote_retrieve_header($head, 'etag'));
            $head_lm   = trim((string) wp_remote_retrieve_header($head, 'last-modified'));

            if ($head_etag !== '' && !empty($stored['etag']) && $head_etag === $stored['etag']) {
                return ['changed' => false, 'markers' => array_merge($stored, ['etag' => $head_etag])];
            }
            if ($head_lm !== '' && !empty($stored['last_modified']) && $head_lm === $stored['last_modified']) {
                return ['changed' => false, 'markers' => array_merge($stored, ['last_modified' => $head_lm])];
            }
        }
    }

    // 2) GET fallback (needed anyway when we need to download the body).
    $resp = wp_remote_get($url, ['timeout' => 60]);
    if (is_wp_error($resp)) {
        return ['changed' => true, 'error' => 'fetch_failed: ' . $resp->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) {
        return ['changed' => true, 'error' => 'http_status_' . $code];
    }

    $body = wp_remote_retrieve_body($resp);
    if ($body === '' || $body === null) {
        return ['changed' => true, 'error' => 'empty_body'];
    }
    $hash = md5($body);

    // ETag/Last-Modified can also come from GET response (some servers omit them in HEAD).
    $etag = $head_etag !== '' ? $head_etag : trim((string) wp_remote_retrieve_header($resp, 'etag'));
    $lm   = $head_lm   !== '' ? $head_lm   : trim((string) wp_remote_retrieve_header($resp, 'last-modified'));

    $markers = [];
    if ($etag !== '') {
        $markers['etag'] = $etag;
    }
    if ($lm !== '') {
        $markers['last_modified'] = $lm;
    }
    $markers['hash'] = $hash;

    if (!empty($stored['hash']) && $stored['hash'] === $hash) {
        return ['changed' => false, 'markers' => $markers];
    }

    // Changed: parse and return body so the sync reuses it.
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['changed' => true, 'error' => 'invalid_json', 'markers' => $markers];
    }

    if ($type === 'tracks') {
        // tracks_list returns associative {source_id => updated_at}.
        $parsed = $decoded;
        $count  = is_array($parsed) ? count($parsed) : 0;
    } else {
        if (empty($decoded['features']) || !is_array($decoded['features'])) {
            return ['changed' => true, 'error' => 'empty_features', 'markers' => $markers];
        }
        $parsed = $decoded['features'];
        $count  = count($parsed);
    }

    return [
        'changed' => true,
        'markers' => $markers,
        'parsed'  => $parsed,
        'count'   => $count,
    ];
}

/**
 * Headless tracks batch.
 * Mirrors the core logic of sync_tracks_action() (without AJAX/anti-stuck checks)
 * so it can be safely invoked from WP-Cron.
 *
 * @return array {
 *   processed: int, skipped: int, errors: int,
 *   total: int, next_offset: int, complete: bool,
 *   error?: string
 * }
 */
function wm_auto_run_tracks_batch($offset, $batch_size)
{
    $track_url       = get_option('track_url');
    $tracks_list     = get_option('tracks_list');
    $track_shortcode = get_option('track_shortcode');
    $default_lang    = apply_filters('wpml_default_language', null);

    if (empty($track_url) || empty($tracks_list) || empty($track_shortcode)) {
        return ['error' => 'missing_config', 'complete' => true, 'total' => 0, 'next_offset' => 0];
    }

    $list_transient = 'wm_auto_sync_tracks_list';

    if ($offset === 0) {
        // Reuse pre-loaded transient when the change-detection step already downloaded the list.
        $tracks = get_transient($list_transient);
        if ($tracks === false) {
            $response = wp_remote_get($tracks_list, ['timeout' => 60]);
            if (is_wp_error($response)) {
                return ['error' => 'fetch_failed: ' . $response->get_error_message(), 'complete' => true, 'total' => 0, 'next_offset' => 0];
            }
            $tracks = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($tracks) || empty($tracks)) {
                return ['error' => 'empty_or_invalid_list', 'complete' => true, 'total' => 0, 'next_offset' => 0];
            }
            set_transient($list_transient, $tracks, DAY_IN_SECONDS);
        }
    } else {
        $tracks = get_transient($list_transient);
        if ($tracks === false) {
            return ['error' => 'session_expired', 'complete' => true, 'total' => 0, 'next_offset' => 0];
        }
    }

    $tracks_array = [];
    foreach ($tracks as $source_id => $updated_at) {
        $tracks_array[] = ['id' => $source_id, 'updated_at' => $updated_at];
    }
    $total = count($tracks_array);
    $batch = array_slice($tracks_array, $offset, $batch_size);

    $processed = 0;
    $skipped   = 0;
    $errors    = 0;

    foreach ($batch as $track_item) {
        $source_id  = $track_item['id'];
        $updated_at = $track_item['updated_at'];

        $existing_posts = get_posts([
            'post_type'   => 'track',
            'meta_query'  => [['key' => 'wm_track_id', 'value' => $source_id]],
            'numberposts' => 1,
        ]);

        $existing_modified = $existing_posts ? strtotime(get_post_modified_time('Y-m-d H:i:s', false, $existing_posts[0]->ID, true)) : null;
        $new_modified      = strtotime($updated_at);

        if ($existing_posts && $new_modified <= $existing_modified) {
            $skipped++;
            continue;
        }

        $response = wp_remote_get($track_url . $source_id . '.json', ['timeout' => 30]);
        if (is_wp_error($response)) {
            $errors++;
            continue;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data)) {
            $errors++;
            continue;
        }

        $post_title = (isset($data['properties']['name'][$default_lang]) && $data['properties']['name'][$default_lang])
            ? $data['properties']['name'][$default_lang]
            : __('Track no title', 'wm-package') . ' ' . $source_id;
        $post_slug             = sanitize_title($post_title);
        $track_shortcode_final = str_replace('$1', $source_id, $track_shortcode);

        $post_data = [
            'post_title'   => $post_title,
            'post_name'    => $post_slug,
            'post_content' => $track_shortcode_final,
            'post_status'  => 'publish',
            'post_type'    => 'track',
        ];

        if ($existing_posts) {
            $post_data['ID'] = $existing_posts[0]->ID;
            $post_id         = wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data);
        }

        if (is_wp_error($post_id) || !$post_id) {
            $errors++;
            continue;
        }

        update_post_meta($post_id, 'wm_track_id', $source_id);
        $processed++;

        $wpml_element_type       = apply_filters('wpml_element_type', 'post_track');
        $original_language_info  = apply_filters('wpml_element_language_details', null, ['element_id' => $post_id, 'element_type' => $wpml_element_type]);
        $languages               = apply_filters('wpml_active_languages', null, 'orderby=id&order=desc');
        $default_lang_title      = $post_title;

        $valid_post_ids = [(int) $post_id];

        if (is_array($languages) && $original_language_info && isset($original_language_info->language_code)) {
            foreach ($languages as $lang_code => $lang_details) {
                if ($lang_code == $original_language_info->language_code) {
                    continue;
                }

                $tr_post_title = (isset($data['properties']['name'][$lang_code]) && $data['properties']['name'][$lang_code])
                    ? $data['properties']['name'][$lang_code]
                    : $default_lang_title;
                $tr_post_slug  = sanitize_title($tr_post_title);

                $translated_post_data = [
                    'post_title'   => $tr_post_title,
                    'post_content' => $track_shortcode_final,
                    'post_status'  => 'publish',
                    'post_author'  => 1,
                    'post_type'    => 'track',
                    'post_name'    => $tr_post_slug . '-' . $lang_code,
                ];

                $existing_tr_id = wm_find_translation_post_id($post_id, $wpml_element_type, $lang_code);
                if ($existing_tr_id > 0) {
                    $translated_post_data['ID'] = $existing_tr_id;
                    $translated_post_id = wp_update_post($translated_post_data);
                } else {
                    $translated_post_id = wp_insert_post($translated_post_data);
                }

                if (!is_wp_error($translated_post_id) && $translated_post_id) {
                    do_action('wpml_set_element_language_details', [
                        'element_id'           => $translated_post_id,
                        'element_type'         => $wpml_element_type,
                        'trid'                 => $original_language_info->trid,
                        'language_code'        => $lang_code,
                        'source_language_code' => $original_language_info->language_code,
                    ]);
                    update_post_meta($translated_post_id, 'wm_track_id', $source_id);
                    $valid_post_ids[] = (int) $translated_post_id;
                }
            }
        }

        if (function_exists('wm_trash_orphan_synced_posts')) {
            wm_trash_orphan_synced_posts('track', 'wm_track_id', $source_id, $valid_post_ids);
        }
    }

    $next_offset = $offset + $batch_size;
    $is_complete = ($next_offset >= $total);

    if ($is_complete) {
        delete_transient($list_transient);
    }

    return [
        'processed'   => $processed,
        'skipped'     => $skipped,
        'errors'      => $errors,
        'offset'      => $offset,
        'next_offset' => $next_offset,
        'total'       => $total,
        'complete'    => $is_complete,
    ];
}

/**
 * Headless POIs batch.
 * Mirrors the core logic of sync_pois_action().
 */
function wm_auto_run_pois_batch($offset, $batch_size)
{
    $poi_url       = get_option('poi_url');
    $poi_shortcode = get_option('poi_shortcode');
    $default_lang  = apply_filters('wpml_default_language', null);

    if (empty($poi_url) || empty($poi_shortcode)) {
        return ['error' => 'missing_config', 'complete' => true, 'total' => 0, 'next_offset' => 0];
    }

    $list_transient = 'wm_auto_sync_pois_list';

    if ($offset === 0) {
        // Reuse pre-loaded transient when the change-detection step already downloaded the list.
        $features = get_transient($list_transient);
        if ($features === false) {
            $response = wp_remote_get($poi_url, ['timeout' => 60]);
            if (is_wp_error($response)) {
                return ['error' => 'fetch_failed: ' . $response->get_error_message(), 'complete' => true, 'total' => 0, 'next_offset' => 0];
            }
            $pois_data = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($pois_data) || !is_array($pois_data) || empty($pois_data['features']) || !is_array($pois_data['features'])) {
                return ['error' => 'empty_or_invalid_list', 'complete' => true, 'total' => 0, 'next_offset' => 0];
            }
            set_transient($list_transient, $pois_data['features'], DAY_IN_SECONDS);
            $features = $pois_data['features'];
        }
    } else {
        $features = get_transient($list_transient);
        if ($features === false) {
            return ['error' => 'session_expired', 'complete' => true, 'total' => 0, 'next_offset' => 0];
        }
    }

    $total = count($features);
    $batch = array_slice($features, $offset, $batch_size);

    $processed = 0;
    $skipped   = 0;
    $errors    = 0;

    foreach ($batch as $data) {
        if (empty($data['properties']['id'])) {
            $errors++;
            continue;
        }
        $source_id  = $data['properties']['id'];
        $updated_at = $data['properties']['updated_at'] ?? null;

        $existing_posts = get_posts([
            'post_type'   => 'poi',
            'meta_query'  => [['key' => 'wm_poi_id', 'value' => $source_id]],
            'numberposts' => 1,
        ]);

        $existing_modified = $existing_posts ? strtotime(get_post_modified_time('Y-m-d H:i:s', false, $existing_posts[0]->ID, true)) : null;
        $new_modified      = $updated_at ? strtotime($updated_at) : time();

        if ($existing_posts && $new_modified <= $existing_modified) {
            $skipped++;
            continue;
        }

        $post_title = (isset($data['properties']['name'][$default_lang]) && $data['properties']['name'][$default_lang])
            ? $data['properties']['name'][$default_lang]
            : __('POI no title', 'wm-package') . ' ' . $source_id;
        $post_slug           = sanitize_title($post_title);
        $poi_shortcode_final = str_replace('$1', $source_id, $poi_shortcode);

        $post_data = [
            'post_title'   => $post_title,
            'post_name'    => $post_slug,
            'post_content' => $poi_shortcode_final,
            'post_status'  => 'publish',
            'post_type'    => 'poi',
        ];

        if ($existing_posts) {
            $post_data['ID'] = $existing_posts[0]->ID;
            $post_id         = wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data);
        }

        if (is_wp_error($post_id) || !$post_id) {
            $errors++;
            continue;
        }

        update_post_meta($post_id, 'wm_poi_id', $source_id);
        $processed++;

        $wpml_element_type      = apply_filters('wpml_element_type', 'post_poi');
        $original_language_info = apply_filters('wpml_element_language_details', null, ['element_id' => $post_id, 'element_type' => $wpml_element_type]);
        $languages              = apply_filters('wpml_active_languages', null, 'orderby=id&order=desc');
        $default_lang_title     = $post_title;

        $valid_post_ids = [(int) $post_id];

        if (is_array($languages) && $original_language_info && isset($original_language_info->language_code)) {
            foreach ($languages as $lang_code => $lang_details) {
                if ($lang_code == $original_language_info->language_code) {
                    continue;
                }

                $tr_post_title = (isset($data['properties']['name'][$lang_code]) && $data['properties']['name'][$lang_code])
                    ? $data['properties']['name'][$lang_code]
                    : $default_lang_title;
                $tr_post_slug  = sanitize_title($tr_post_title);

                $translated_post_data = [
                    'post_title'   => $tr_post_title,
                    'post_content' => $poi_shortcode_final,
                    'post_status'  => 'publish',
                    'post_author'  => 1,
                    'post_type'    => 'poi',
                    'post_name'    => $tr_post_slug . '-' . $lang_code,
                ];

                $existing_tr_id = wm_find_translation_post_id($post_id, $wpml_element_type, $lang_code);
                if ($existing_tr_id > 0) {
                    $translated_post_data['ID'] = $existing_tr_id;
                    $translated_post_id = wp_update_post($translated_post_data);
                } else {
                    $translated_post_id = wp_insert_post($translated_post_data);
                }

                if (!is_wp_error($translated_post_id) && $translated_post_id) {
                    do_action('wpml_set_element_language_details', [
                        'element_id'           => $translated_post_id,
                        'element_type'         => $wpml_element_type,
                        'trid'                 => $original_language_info->trid,
                        'language_code'        => $lang_code,
                        'source_language_code' => $original_language_info->language_code,
                    ]);
                    update_post_meta($translated_post_id, 'wm_poi_id', $source_id);
                    $valid_post_ids[] = (int) $translated_post_id;
                }
            }
        }

        if (function_exists('wm_trash_orphan_synced_posts')) {
            wm_trash_orphan_synced_posts('poi', 'wm_poi_id', $source_id, $valid_post_ids);
        }
    }

    $next_offset = $offset + $batch_size;
    $is_complete = ($next_offset >= $total);

    if ($is_complete) {
        delete_transient($list_transient);
    }

    return [
        'processed'   => $processed,
        'skipped'     => $skipped,
        'errors'      => $errors,
        'offset'      => $offset,
        'next_offset' => $next_offset,
        'total'       => $total,
        'complete'    => $is_complete,
    ];
}

/**
 * Helper for the admin UI: human readable info about next/last run for a given type.
 *
 * @param string $type 'tracks' or 'pois'
 * @return array { next: string, last: string, in_progress: bool }
 */
function wm_auto_sync_status_info($type)
{
    $hook        = "wm_auto_sync_{$type}";
    $hook_cont   = "wm_auto_sync_{$type}_continue";
    $state_key   = "wm_auto_sync_{$type}_state";
    $last_key    = "wm_auto_sync_{$type}_last_run";

    $next_ts = wp_next_scheduled($hook);
    if (!$next_ts) {
        $next_ts = wp_next_scheduled($hook_cont);
    }
    $next = $next_ts ? wp_date('Y-m-d H:i', $next_ts) : __('Not scheduled', 'wm-package');

    $state       = get_option($state_key, null);
    $in_progress = is_array($state) && isset($state['offset']) && (int) $state['offset'] > 0;

    $progress = null;
    if ($in_progress) {
        $offset = (int) ($state['offset'] ?? 0);
        $total  = (int) ($state['total']  ?? 0);
        $percent = $total > 0 ? min(100, (int) round(($offset / $total) * 100)) : 0;
        $progress = [
            'offset'    => $offset,
            'total'     => $total,
            'percent'   => $percent,
            'processed' => (int) ($state['cumulative_processed'] ?? 0),
            'skipped'   => (int) ($state['cumulative_skipped']   ?? 0),
            'errors'    => (int) ($state['cumulative_errors']    ?? 0),
            'started_at' => (int) ($state['started_at']          ?? 0),
        ];
    }

    $last_run      = get_option($last_key, null);
    $last_real_run = get_option("wm_auto_sync_{$type}_last_real_run", null);

    // Helper: format a "processed/skipped/errors / total" counters string.
    $format_counters = function ($run) {
        return sprintf(
            '%s: %d, %s: %d, %s: %d / %d',
            __('processed', 'wm-package'),
            (int) ($run['processed'] ?? 0),
            __('skipped', 'wm-package'),
            (int) ($run['skipped'] ?? 0),
            __('errors', 'wm-package'),
            (int) ($run['errors'] ?? 0),
            (int) ($run['total'] ?? 0)
        );
    };

    $last = '—';
    if (is_array($last_run) && !empty($last_run['finished_at'])) {
        $when   = wp_date('Y-m-d H:i', (int) $last_run['finished_at']);
        $status = $last_run['status'] ?? '';
        if ($status === 'no_changes') {
            $msg = sprintf('%s — %s', $when, __('no remote changes detected, sync skipped', 'wm-package'));
            if (is_array($last_real_run) && !empty($last_real_run['finished_at'])) {
                $real_when = wp_date('Y-m-d H:i', (int) $last_real_run['finished_at']);
                $msg .= ' — ' . sprintf(
                    /* translators: 1: timestamp of last real sync, 2: counters string */
                    __('last actual sync %1$s — %2$s', 'wm-package'),
                    $real_when,
                    $format_counters($last_real_run)
                );
            }
            $last = $msg;
        } elseif ($status === 'error') {
            $last = sprintf('%s — %s: %s', $when, __('error', 'wm-package'), esc_html((string) ($last_run['message'] ?? '')));
        } else {
            $last = sprintf('%s — %s', $when, $format_counters($last_run));
        }
    }

    return [
        'next'        => $next,
        'last'        => $last,
        'in_progress' => $in_progress,
        'progress'    => $progress,
    ];
}

/**
 * On plugin deactivation, clear scheduled events to avoid orphan jobs.
 */
register_deactivation_hook(dirname(__FILE__, 2) . '/index.php', 'wm_auto_sync_clear_on_deactivation');
function wm_auto_sync_clear_on_deactivation()
{
    foreach (['tracks', 'pois'] as $type) {
        wp_clear_scheduled_hook("wm_auto_sync_{$type}");
        wp_clear_scheduled_hook("wm_auto_sync_{$type}_continue");
        delete_option("wm_auto_sync_{$type}_state");
        delete_option("wm_auto_sync_{$type}_remote_state");
        delete_option("wm_auto_sync_{$type}_last_run");
        delete_option("wm_auto_sync_{$type}_last_real_run");
        delete_transient("wm_auto_sync_{$type}_list");
    }
}
