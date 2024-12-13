<?php

use function AC\Vendor\DI\value;

class Importer
{
    private $credentials;
    private $wpdb;
    public $details_table_name;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->setAccountsCredentials();
    }

    public function get_or_create_media_by_url($image_url)
    {
        // Sprawdzenie, czy "media" istnieje na podstawie 'origin_url'
        error_log("Próba pobrania obrazu z URL: {$image_url}");
        $existing_attachment = $this->get_attachment_by_origin_url($image_url);
        error_log("Znaleziono załącznik: " . print_r($existing_attachment, true));
        die;
        if ($existing_attachment) {
            // Zwrócenie id, jeżeli "media" już istnieje
            return $existing_attachment->ID;
        }

        // Próba pobrania obrazu, jeżeli nie istnieje
        $image_data = @file_get_contents($image_url);

        if ($image_data) {
            // Uzyskanie ścieżki do katalogu uploadów
            $upload_dir = wp_upload_dir();
            $image_name = basename($image_url);
            $unique_image_name = wp_unique_filename($upload_dir['path'], $image_name);
            $image_path = $upload_dir['path'] . '/' . $unique_image_name;

            if (wp_mkdir_p($upload_dir['path'])) {
                // Zapisanie pobranego obrazu w katalogu uploadów
                file_put_contents($image_path, $image_data);

                $file_type = wp_check_filetype($image_path, null);

                if ($file_type['type'] !== 'image/jpeg') {
                    // Konwersja obrazu na format JPEG, jeżeli nie jest już w tym formacie
                    $image = imagecreatefromstring($image_data);
                    imagejpeg($image, $image_path, 90);
                    imagedestroy($image);
                }

                // Utworzenie załącznika dla obrazu w bazie danych
                $attachment = array(
                    'post_mime_type' => 'image/jpeg',
                    'post_title' => sanitize_file_name($image_name),
                    'post_content' => '',
                    'post_status' => 'inherit'
                );

                $attach_id = wp_insert_attachment($attachment, $image_path);
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $attach_data = wp_generate_attachment_metadata($attach_id, $image_path);
                wp_update_attachment_metadata($attach_id, $attach_data);

                // Przypisanie URL obrazu do pola niestandardowego "origin_url"
                update_post_meta($attach_id, 'origin_url', $image_url);

                // Zwrócenie id utworzonego "media"
                return $attach_id;
            }
        }

        // Zwrócenie 0 w przypadku błędu
        return 0;
    }


    public function import_images_to_media_library()
{
    // Wymagane pliki do obsługi funkcji importowania mediów
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    // Pobranie wszystkich wierszy z 'images' jako meta_key
    $results = $this->wpdb->get_results("SELECT * FROM {$this->wpdb->postmeta} WHERE meta_key = 'images'", OBJECT);

    if ($results) {
        foreach ($results as $row) {
            $post_id = (int) $row->post_id;
            $image_url = $row->meta_value;

            if ($image_url) {
                // Sprawdzenie, czy obraz istnieje już w bibliotece multimediów na podstawie pola niestandardowego "origin_url"
                $existing_attachment = self::get_attachment_by_origin_url($image_url);

                if ($existing_attachment) {
                    // Obraz już istnieje w bibliotece multimediów, dodaj jego ID do 'photos'
                    add_post_meta($post_id, 'photos', $existing_attachment->ID, false);
                    continue;
                }

                // Pobranie danych obrazu z URL
                $image_data = @file_get_contents($image_url);

                if ($image_data) {
                    // Uzyskanie ścieżki do katalogu uploadów
                    $upload_dir = wp_upload_dir();
                    $image_name = basename($image_url);
                    $unique_image_name = wp_unique_filename($upload_dir['path'], $image_name);
                    $image_path = $upload_dir['path'] . '/' . $unique_image_name;

                    if (wp_mkdir_p($upload_dir['path'])) {
                        // Zapisanie pobranego obrazu w katalogu uploadów
                        file_put_contents($image_path, $image_data);

                        $file_type = wp_check_filetype($image_path, null);

                        if ($file_type['type'] !== 'image/jpeg') {
                            // Konwersja obrazu na format JPEG, jeśli nie jest już w tym formacie
                            $image = imagecreatefromstring($image_data);
                            imagejpeg($image, $image_path, 90);
                            imagedestroy($image);
                        }

                        // Utworzenie załącznika dla obrazu w bazie danych
                        $attachment = array(
                            'post_mime_type' => 'image/jpeg',
                            'post_title'     => sanitize_file_name($image_name),
                            'post_content'   => '',
                            'post_status'    => 'inherit'
                        );

                        $attach_id = wp_insert_attachment($attachment, $image_path, $post_id);

                        // Sprawdzenie, czy załącznik został prawidłowo utworzony
                        if (is_wp_error($attach_id) || !$attach_id) {
                            error_log("Błąd przy dodawaniu załącznika dla obrazu {$image_url} do posta {$post_id}.");
                            continue;
                        }

                        $attach_id = (int) $attach_id;

                        $attach_data = wp_generate_attachment_metadata($attach_id, $image_path);
                        wp_update_attachment_metadata($attach_id, $attach_data);

                        // Przypisanie oryginalnego URL do pola niestandardowego "origin_url"
                        update_post_meta($attach_id, 'origin_url', $image_url);

                        // Dodanie ID załącznika do meta 'photos'
                        add_post_meta($post_id, 'photos', $attach_id, false);

                        // Ustawienie pierwszego obrazu jako "featured image" jeśli brak miniatury
                        if (!has_post_thumbnail($post_id)) {
                            set_post_thumbnail($post_id, $attach_id);
                        }
                    }
                }
            }
        }
    }

    // Usuwanie obrazów, które istnieją w bibliotece multimediów, ale nie mają odpowiadającego pola "origin_url"
    $this->delete_orphaned_attachments();
}



    /**
     * Pobiera załącznik na podstawie oryginalnego URL-a.
     * Teraz jako metoda statyczna.
     */
    public static function get_attachment_by_origin_url($origin_url)
    {
        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'any',
            'meta_query' => array(
                array(
                    'key' => 'origin_url',
                    'value' => $origin_url,
                    'compare' => '='
                )
            )
        );

        $attachments = get_posts($args);

        if ($attachments) {
            return $attachments[0];
        }

        return null;
    }

    public static function updateAdvert($advert, $post_data, $meta_data)
    {
        global $wpdb;
    
        // Sprawdzenie, czy ogłoszenie istnieje w bazie danych
        $post_id = Advert::existsInDatabase($advert['id']);
    
        if (!$post_id) {
            error_log("[updateAdvert] Ogłoszenie o ID {$advert['id']} nie istnieje w bazie danych.");
            throw new Exception("Ogłoszenie o ID {$advert['id']} nie istnieje w bazie danych.");
        }
    
        // Ustawiamy ID posta do zaktualizowania
        $post_data['ID'] = $post_id;
    
        // Aktualizacja posta
        $updated_post_id = wp_update_post($post_data, true);
    
        if (is_wp_error($updated_post_id)) {
            $error_string = $updated_post_id->get_error_message();
            error_log("[updateAdvert] Błąd przy aktualizacji wpisu ID {$post_id}: {$error_string}");
            throw new Exception("Błąd przy aktualizacji wpisu: $error_string");
        }
    
        // Aktualizacja metadanych
        foreach ($meta_data as $key => $value) {
            if ($key !== 'features' && $key !== 'photos') {
                // Aktualizacja standardowych meta
                $result = rwmb_set_meta($updated_post_id, $key, $value);
                if ($result === false) {
                    error_log("[updateAdvert] Nie udało się zaktualizować meta '{$key}' dla posta ID {$updated_post_id}.");
                }
            } else {
                // Usuń stare wartości dla 'features' lub 'photos'
                $deleted = delete_post_meta($updated_post_id, $key);
                if (!$deleted && get_post_meta($updated_post_id, $key, true)) {
                    error_log("[updateAdvert] Nie udało się usunąć starego meta '{$key}' dla posta ID {$updated_post_id}.");
                }
    
                // Dodaj nowe wartości
                if (is_array($value)) {
                    foreach ($value as $item) {
                        if ($key === 'photos') {
                            $image_id = (int)$item;
                            if ($image_id > 0) {
                                $inserted = add_post_meta($updated_post_id, $key, $image_id, false); // Append
                                if (!$inserted) {
                                    error_log("[updateAdvert] Nie udało się dodać meta 'photos' z ID '{$image_id}' dla posta ID {$updated_post_id}.");
                                }
                            } else {
                                error_log("[updateAdvert] Nieprawidłowe ID zdjęcia '{$item}' dla posta ID {$updated_post_id}.");
                            }
                        } else {
                            // Dla 'features'
                            $inserted = add_post_meta($updated_post_id, $key, $item, false); // Append
                            if (!$inserted) {
                                error_log("[updateAdvert] Nie udało się dodać meta '{$key}' z wartością '{$item}' dla posta ID {$updated_post_id}.");
                            }
                        }
                    }
                } else {
                    error_log("[updateAdvert] Meta '{$key}' dla posta ID {$updated_post_id} nie jest tablicą.");
                }
            }
        }
    
        error_log("[updateAdvert] Przetwarzanie ogłoszenia ID: {$advert['id']} zakończone.");
    
        // Ustawianie zdjęcia wyróżniającego
        if (!empty($meta_data['photos']) && is_array($meta_data['photos']) && isset($meta_data['photos'][0])) {
            $image_id = (int)$meta_data['photos'][0];
            error_log("Ustawiam thumbnail dla posta {$updated_post_id} z obrazem ID {$image_id}.");
    
            // Sprawdź czy załącznik istnieje i jest typu 'attachment'
            $attachment_post = get_post($image_id);
            if ($attachment_post && $attachment_post->post_type === 'attachment') {
                $result = set_post_thumbnail($updated_post_id, $image_id);
    
                if ($result === false) {
                    error_log("Nie udało się ustawić miniatury dla posta {$updated_post_id}. Sprawdź czy obraz ID {$image_id} istnieje i jest poprawny.");
                } else {
                    error_log("Miniatura ustawiona dla posta {$updated_post_id} z obrazem ID {$image_id}.");
                }
            } else {
                error_log("Załącznik z ID {$image_id} nie istnieje lub nie jest załącznikiem.");
            }
        } else {
            error_log("[updateAdvert] Brak meta 'photos' lub nie jest tablicą dla posta ID {$updated_post_id}.");
        }
    
        // Opcjonalne: Odświeżenie posta
        wp_update_post(array(
            'ID' => $updated_post_id,
        ));
    
        return $updated_post_id;
    }
    
    private function delete_orphaned_attachments()
    {
        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'any',
            'posts_per_page' => -1
        );

        $attachments = get_posts($args);

        if ($attachments) {
            foreach ($attachments as $attachment) {
                $origin_url = get_post_meta($attachment->ID, 'origin_url', true);

                // Jeśli pole "origin_url" jest puste, pomijamy ten obraz
                if (empty($origin_url)) {
                    continue;
                }

                // Sprawdzamy, czy URL istnieje w bazie danych
                $count = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->wpdb->postmeta} WHERE meta_key = 'photos' AND meta_value = %s",
                    $origin_url
                ));

                // Jeśli URL nie istnieje w bazie danych, usuwamy załącznik
                if ($count == 0) {
                    wp_delete_attachment($attachment->ID, true);
                }
            }
        }
    }

    private function setAccountsCredentials()
    {
        $credentials_result = $this->wpdb->get_results("SELECT * FROM wp_otomoto_credentials", ARRAY_A);
        $credentials_result = array_map(function ($row) {
            return [
                'login' => $row['login'],
                'password' => $row['password']
            ];
        }, $credentials_result);
        $this->credentials = $credentials_result;
    }

    public function getCredentials()
    {
        return $this->credentials;
    }

    public static function createAdvert($advert, $post_data, $meta_data)
    {
        global $wpdb;

        // Utwórz nowy wpis przy użyciu funkcji wp_insert_post
        $post_id = wp_insert_post($post_data);

        // Jeśli wpis został pomyślnie utworzony
        if ($post_id) {
            // Ustaw metadane dla wpisu
            foreach ($meta_data as $key => $value) {
                if ($key != 'features' && $key != 'photos') {
                    rwmb_set_meta($post_id, $key, $value);
                } else {
                    // Dla 'features' czy 'photos', dodaj bezpośrednio do tabeli postmeta
                    if (is_array($value)) {
                        foreach ($value as $feature_or_image) {
                            $wpdb->insert(
                                $wpdb->postmeta,
                                array(
                                    'post_id' => $post_id,
                                    'meta_key' => $key,
                                    'meta_value' => $feature_or_image
                                ),
                                array('%d', '%s', '%s')
                            );
                        }
                    }
                }
            }

            // Ustaw pierwsze zdjęcie jako featured image (jeśli istnieje)
            if (!empty($meta_data['photos']) && is_array($meta_data['photos']) && isset($meta_data['photos'][0])) {
                $image_id = (int)$meta_data['photos'][0];
                $result = set_post_thumbnail($post_id, $image_id);

                if ($result === false) {
                    error_log("Failed to set post thumbnail for post {$post_id}. Check if the image ID {$image_id} exists and is valid.");
                }
            }

            // Odśwież posta
            wp_update_post(array(
                'ID' => $post_id,
            ));
            return $post_id;
        } else {
            throw new Exception('Błąd przy tworzeniu wpisu');
        }
    }

    private function post_exists_by_id($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id > 0) {
            $post = get_post($post_id);
            if ($post) {
                return true;
            }
        }
        return false;
    }

    // Save data to json file
    static function saveData($data)
    {
        $file = fopen(OTOMOTO_IMPORTER_PLUGIN_DIR . 'data.json', 'w');
        fwrite($file, json_encode($data));
        fclose($file);
    }
}
