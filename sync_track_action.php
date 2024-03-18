<?php

function sync_tracks_action() {
    $track_url = get_option('track_url');
    $tracks_list = get_option('tracks_list');
    $track_shortcode = get_option('track_shortcode');
    $log_file_path = plugin_dir_path(__FILE__) . 'logs/geohub-log.txt';
    if (!empty($track_url) && !empty($tracks_list) && !empty($track_shortcode)) {

        // The message you want to log
        $log_message = "Hello World PEDRAM - " . current_time('Y-m-d H:i:s') . "\n";

        $tracks = wp_remote_get($tracks_list);
        if (is_wp_error($tracks)) {
            set_transient('geohub_sync_tracks_notification', 'API track list non valida o non disponibile.', 60);
            return new WP_Error('invalid_api', 'API track list non valida o non disponibile.');
        }
        $tracks = json_decode(wp_remote_retrieve_body($tracks),true);
        // Controlla se la lista dei track è valida e non vuota.
        if (empty($tracks) || !is_array($tracks)) {
            set_transient('geohub_sync_tracks_notification', 'Nessun track fornito o formato non valido.', 60);
            return new WP_Error('invalid_input', 'Nessun track fornito o formato non valido.');
        }

        // Cicla attraverso gli ID dei track e raccogli i loro titoli e contenuti.
        foreach ($tracks as $geohub_id => $updated_at) {
            
             // Effettua una chiamata API per ottenere i dettagli del track
             $response = wp_remote_get($track_url.$geohub_id);
             if (is_wp_error($response)) {
                 continue; // Salta al prossimo ID se la chiamata API fallisce
             }
             $body = wp_remote_retrieve_body($response);
             $data = json_decode($body, true); // true converte l'oggetto in un array associativo
             if (empty($data)) {
                 continue; // Salta al prossimo ID se la risposta è vuota o non valida
             }
 
             // Preparazione dei dati del post
             $post_title = $data['properties']['name']['it'] ?? 'Track senza titolo'; // Utilizza 'Track senza titolo' se il nome non è disponibile
             $post_slug = sanitize_title($post_title);
 
             // Cerca un post esistente con il geohub_id corrispondente
             $existing_posts = get_posts([
                 'post_type' => 'track',
                 'meta_query' => [
                     [
                         'key' => 'geohub_group_geohub_id',
                         'value' => $geohub_id,
                     ],
                 ],
                 'numberposts' => 1,
             ]);
 
             if ($existing_posts) {
                 // Se il track esiste già, aggiornalo
                 $post_id = $existing_posts[0]->ID;

                 // Recupera la data di ultima modifica del post esistente.
                $existing_post_modified_time = strtotime(get_post_modified_time('Y-m-d H:i:s', false, $post_id, true));
                $new_updated_time = strtotime($updated_at);

                // Confronta le date di aggiornamento.
                if ($new_updated_time > $existing_post_modified_time) {
                    // La data di aggiornamento del nuovo track è più recente, quindi aggiorna il post.
                    wp_update_post([
                        'ID'         => $post_id,
                        'post_title' => $post_title,
                        'post_name'  => $post_slug, // Aggiornamento dello slug
                        // Aggiungi altri campi qui se necessario.
                    ]);
                }
             } else {
                 // Altrimenti, crea un nuovo track
                $track_shortcode = str_replace('$1', $geohub_id, $track_shortcode);
                try {
                    $post_id = wp_insert_post([
                        'post_title'   => $post_title,
                        'post_name'    => $post_slug, // Impostazione dello slug
                        'post_content' => $track_shortcode, // Aggiungi contenuto se necessario
                        'post_status'  => 'publish',
                        'post_type'    => 'track',
                    ]);
                } catch (Exception $e) {
                    $log_message = $post_title . ' - ' . $e->getMessage() . "\n";
                    error_log('Errore durante l\'inserimento del track: ' . $e->getMessage());
                    continue;
                }
                 if (!is_wp_error($post_id)) {
                    update_field('geohub_group_geohub_id', $geohub_id, $post_id);
                }
             }
        }

        if (false === file_put_contents($log_file_path, $log_message, FILE_APPEND | LOCK_EX)) {
            error_log("Unable to write to log file: $log_file_path");
        }
    } else {
    }
    set_transient('geohub_sync_tracks_notification', 'Tracks synchronized successfully.', 60); 
}

