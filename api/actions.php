<?php

/**
 * Opis działania funkcjonalności, która usuwa ogłoszenia z bazy danych, które nie istnieją w pliku JSON.
 * 
 * 1. Przydałaby się flaga, która określa, czy w prawidłowy sposób zalogowano się do OtoMoto, pobrano ogłoszenia
 * i zapisano je do pliku JSON ale w taki sposób, że jeśli po prostu zalogowano się do OtoMoto, ale nie ma żadnych
 * ogłoszeń, to flaga powinna być ustawiona na true. Na false flaga powinna być ustawiona, jeśli nie udało się
 * zalogować do OtoMoto lub nie udało się pobrać ogłoszeń.
 * 
 * 2. Powinien istnieć mechanizm który zanim wykona przetwarzanie jsona z ogłoszeniami z OtoMoto, to sprawdzi czy
 * flaga dla danego konta otomoto jest ustawiona na true. Jeśli tak to pobieramy wszystkie posty typu samochod, 
 * które mają w polu meta dane username zgodne z tym dla którego flaga jest ustawiona na true. Następnie dla każdego
 * postu typu samochod, którego otomoto_id nie ma w jsonie usuwamy z bazy danych a dla tych które są w jsonie sprawdzamy
 * czy status jest active, jeśli tak to aktualizujemy post, jeśli nie to zmieniamy status na draft.
 * 
 */

require_once OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/Logger.php';

define('OTOMOTO_USE_LOCAL_JSON', true); // Ustaw na true podczas programowania/testów, false w produkcji

/**
 * Pobiera flagi synchronizacji z bazy danych.
 *
 * @return array Tablica flag synchronizacji.
 */
function get_sync_flags()
{
    $flags = get_option('otomoto_importer_sync_flags', array());
    return is_array($flags) ? $flags : array();
}

/**
 * Ustawia flagę synchronizacji dla danego użytkownika.
 *
 * @param string $username Nazwa użytkownika OtoMoto.
 * @param bool   $status   Status synchronizacji (true lub false).
 */
function set_sync_flag($username, $status)
{
    $flags = get_sync_flags();
    $flags[$username] = $status;
    update_option('otomoto_importer_sync_flags', $flags);
}

add_action('wp_ajax_get_adverts_from_otomoto_and_save_json', 'get_adverts_from_otomoto_and_save_json');

/**
 * AJAX handler: Pobranie ogłoszeń z OtoMoto, zapis do JSON i zwrócenie informacji o ilości ogłoszeń.
 */
function get_adverts_from_otomoto_and_save_json()
{

    // Sprawdzenie czy zapytanie pochodzi z AJAXa poprzez nagłówek
    $ajax = isset($_SERVER['HTTP_X_MY_CUSTOM_HEADER']) && strtolower($_SERVER['HTTP_X_MY_CUSTOM_HEADER']) === 'fetch';

    // Wczytanie wymaganych klas jeśli jeszcze nie zostały załadowane
    if (!class_exists('OtomotoAPI')) {
        $otomoto_api_path = OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/OtomotoAPI.php';
        if (file_exists($otomoto_api_path)) {
            require_once $otomoto_api_path;
        } else {
            return_error('Brak pliku OtomotoAPI.php', $ajax);
        }
    }

    if (!class_exists('Advert')) {
        $advert_path = OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/Advert.php';
        if (file_exists($advert_path)) {
            require_once $advert_path;
        } else {
            return_error('Brak pliku Advert.php', $ajax);
        }
    }

    if (!class_exists('Importer')) {
        $importer_path = OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/Importer.php';
        if (file_exists($importer_path)) {
            require_once $importer_path;
        } else {
            return_error('Brak pliku Importer.php', $ajax);
        }
    }

    $importer = new Importer();

    // Pobieranie tablicy z danymi uwierzytelniającymi
    $credentials = otomoto_importer_get_saved_credentials();

    // Wyjątek jeśli nie ma zapisanych żadnych danych logowania
    if (empty($credentials)) {
        return_error('Brak zapisanych danych logowania OtoMoto.', $ajax);
    }

    $all_adverts   = array();
    $categories_ids = array();
    $sync_info      = array();

    if (OTOMOTO_USE_LOCAL_JSON) {
        $all_adverts_path = OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/all_adverts.json';
        if (file_exists($all_adverts_path)) {
            $all_adverts = json_decode(file_get_contents($all_adverts_path), true);
        }
    } else {
        foreach ($credentials as $credential) {
            $login    = $credential['login'] ?? '';
            $password = $credential['password'] ?? '';

            if (empty($login) || empty($password)) {
                log_incorrect_credentials($credential['login'] ?? 'nieznany');
                continue;
            }

            // Obiekt API OtoMoto dla danego użytkownika
            $otomoto = new OtomotoAPI($login, $password);

            // Sprawdź czy użytkownik jest zalogowany
            if ($otomoto->isUserAuthenticated()) {
                $userAdvertsArray = $otomoto->getAllUserAdverts();
                $userAdverts      = $userAdvertsArray['results'] ?? array();

                // Informacje o synchronizacji
                $sync_info[$login] = array(
                    'total'    => count($userAdverts),
                    'active'   => count(array_filter($userAdverts, static fn($a) => $a['status'] === 'active')),
                    'inactive' => count(array_filter($userAdverts, static fn($a) => $a['status'] !== 'active')),
                );

                // Przetworzenie ogłoszeń
                foreach ($userAdverts as &$advert) {
                    $category_id   = $advert['category_id'] ?? null;
                    $category_name = $category_id ? $otomoto->getCategoryNameById($category_id) : null;
                    $advert['category_name']  = $category_name;
                    $advert['username']       = $login;
                    $advert['credential_id']  = $credential['id'] ?? null;

                    if ($category_id && !in_array($category_id, $categories_ids, true)) {
                        $categories_ids[] = $category_id;
                    }
                }
                unset($advert); // czyszczenie referencji

                // Dodawanie do $all_adverts tylko unikalnych ogłoszeń
                foreach ($userAdverts as $newAdvert) {
                    $newAdvertId = $newAdvert['id'] ?? null;
                    if ($newAdvertId && !array_filter($all_adverts, fn($ea) => $ea['id'] === $newAdvertId)) {
                        $all_adverts[] = $newAdvert;
                    }
                }

                // Zapis sync_info do pliku JSON
                save_json_file($sync_info, OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/sync_info.json');
            } else {
                // Logowanie błędu z niepoprawnymi danymi uwierzytelniającymi
                log_incorrect_credentials($login);
            }
        }
    }



    if (empty($all_adverts)) {
        // Brak ogłoszeń
        handle_response($ajax, 0, 0);
        return; // zakończ funkcję po zwróceniu odpowiedzi
    }

    // Kategoria i zapis kategorii do plików JSON
    handle_categories($all_adverts, $categories_ids);

    // Aktualizacja parametrów wersji pojazdów i zliczanie aktywnych
    $all_adverts_active = process_active_adverts($all_adverts);

    // Zapis wszystkich ogłoszeń do JSON
    $all_adverts_path = OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/all_adverts.json';
    if (file_exists($all_adverts_path)) {
        unlink($all_adverts_path);
    }
    save_json_file($all_adverts, $all_adverts_path);

    // Zwrot odpowiedzi
    handle_response($ajax, $all_adverts_active, count($all_adverts));
}

/**
 * Zwraca błąd i kończy działanie w zależności od typu żądania (AJAX lub nie).
 */
function return_error(string $message, bool $ajax = false)
{
    if ($ajax) {
        wp_send_json_error(array('message' => $message));
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
    exit; // Kończymy wykonanie, bo to błąd krytyczny.
}

/**
 * Obsługuje zwrot danych dla żądania.
 */
function handle_response(bool $ajax, int $all_adverts_active, int $all_adverts_count)
{
    $response = array(
        'active_adverts' => $all_adverts_active,
        'all_adverts'    => $all_adverts_count,
    );

    if ($ajax) {
        wp_send_json_success($response);
    } else {
        return $response;
    }
}

/**
 * Loguje informację o niepoprawnych danych uwierzytelniających.
 */
function log_incorrect_credentials(string $login)
{
    $admin_email = get_option('otomoto_incorrect_email');
    if (!empty($admin_email)) {
        // Logowanie błędu do pliku w głównej lokalizacji wordpressa
        $log_file       = OTOMOTO_IMPORTER_PLUGIN_DIR . '/incorrect_credentials.log';
        $current_time   = date('Y-m-d H:i:s');
        $error_message  = "[$current_time] Błąd logowania dla użytkownika: {$login}\n";
        file_put_contents($log_file, $error_message, FILE_APPEND);
    }
}

/**
 * Zapisuje dane do pliku JSON.
 */
function save_json_file($data, string $path)
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    file_put_contents($path, $json);
}

/**
 * Obsługuje zapisywanie informacji o kategoriach i ich podkategoriach do plików JSON.
 */
function handle_categories(array $all_adverts, array $categories_ids)
{
    if (empty($categories_ids) || empty($all_adverts)) {
        return;
    }

    $first_advert = reset($all_adverts);
    if (empty($first_advert['username']) || empty($first_advert['credential_id'])) {
        return; // Brak możliwości ustalenia API
    }

    $otomoto = new OtomotoAPI($first_advert['username'], '');
    if (!$otomoto->isUserAuthenticated()) {
        return;
    }

    foreach ($categories_ids as $category_id) {
        if (!is_numeric($category_id)) {
            error_log("Invalid category ID: $category_id is not a number.");
            continue;
        }

        $category_id  = (int)$category_id;
        $allCategories = $otomoto->getAllCategories($category_id);
        save_json_file($allCategories, OTOMOTO_IMPORTER_PLUGIN_DIR . "/importer/categories/category_{$category_id}.json");
    }
}

/**
 * Przetwarza aktywne ogłoszenia, zamienia wersje na nazwy, zlicza aktywne ogłoszenia.
 */
function process_active_adverts(array &$all_adverts)
{
    $all_adverts_active = 0;

    $first_advert = reset($all_adverts);
    if (empty($first_advert['username'])) {
        return $all_adverts_active;
    }

    $otomoto = new OtomotoAPI($first_advert['username'], '');
    if (!$otomoto->isUserAuthenticated()) {
        return $all_adverts_active;
    }

    foreach ($all_adverts as $key => $advert) {
        if (($advert['status'] ?? '') === "active") {
            $category_id = $advert['category_id'] ?? null;
            if ($category_id) {
                $all_adverts[$key]['category_name'] = $otomoto->getCategoryNameById($category_id);
            }

            if (isset($advert['params']['version'], $advert['params']['make'], $advert['params']['model'])) {
                $make   = $advert['params']['make'];
                $model  = $advert['params']['model'];
                $cat_id = $advert['category_id'];

                $allVersions = $otomoto->getAllVersionFromModel($cat_id, $make, $model);
                $options     = $allVersions['options'] ?? null;

                if ($options && isset($options[$advert['params']['version']])) {
                    $all_adverts[$key]['params']['version'] = $options[$advert['params']['version']]['pl'] ?? $advert['params']['version'];
                }
            }
            $all_adverts_active++;
        }
    }

    return $all_adverts_active;
}


add_action('wp_ajax_import_otomoto_adverts_by_packages', 'import_otomoto_adverts_by_packages');

/**
 * Przetwarza ogłoszenia OtoMoto partiami i tworzy/aktualizuje/draftuje/usuwa je w WordPress.
 */
function import_otomoto_adverts_by_packages($chunk_size = 1, $offset = 0, $ajax = true)
{
    $added                = 0;
    $deleted              = 0;
    $updated              = 0;
    $untitled_but_active  = 0;
    $processed_adverts    = 0;

    // Sprawdzamy, czy parametry zostały przesłane przez POST
    if (!empty($_POST['chunk_size']) && !empty($_POST['offset'])) {
        $chunk_size = intval($_POST['chunk_size']);
        $offset     = intval($_POST['offset']);
    }

    $all_adverts_path = OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/all_adverts.json';
    if (!file_exists($all_adverts_path)) {
        return_error_or_response('Brak pliku all_adverts.json.', $ajax, array(), false);
    }

    $all_adverts = json_decode(file_get_contents($all_adverts_path), true);
    if (empty($all_adverts)) {
        return_error_or_response('Brak ogłoszeń do przetworzenia.', $ajax, array(), false);
    }

    $excludedSlugs = rwmb_meta('lista_wykluczen', ['object_type' => 'setting'], 'ustawienia-wtyczki');
    $excludedSlugs = prepare_excluded_slugs($excludedSlugs);

    $all_adverts_count = count($all_adverts);
    for ($i = $offset; $i < $all_adverts_count; $i++) {
        $advert = $all_adverts[$i];

        // Sprawdź czy ogłoszenie istnieje w bazie danych
        $advert_exist = Advert::existsInDatabase($advert['id']);

        // Jeśli ogłoszenie nie jest aktywne - usuń je wraz ze zdjęciami, jeśli istnieje.
        if ($advert['status'] !== "active") {
            if ($advert_exist) {
                handle_inactive_advert($advert_exist);
                $deleted++;
            }
            $processed_adverts++;
            if ($processed_adverts >= $chunk_size) {
                break;
            }
            continue;
        }

        // Ogłoszenie aktywne – przetwarzamy metadane i tytuł
        $meta_data = array();
        $features  = array();
        process_advert_params($advert, $excludedSlugs, $meta_data, $features);

        // Zbuduj tytuł na podstawie make/model/version
        $post_title = build_advert_title($meta_data);
        if (empty($post_title)) {
            $untitled_but_active++;
            $processed_adverts++;
            if ($processed_adverts >= $chunk_size) {
                break;
            }
            continue;
        }

        $post_data = array(
            'post_title'   => $post_title,
            'post_content' => $advert['description'] ?? '',
            'post_status'  => 'publish',
            'post_author'  => 1,
            'post_type'    => 'samochod',
        );

        // Przetwarzanie zdjęć
        if ($advert_exist || $advert['status'] === "active") {
            process_advert_images($advert, $advert_exist, $meta_data);
        }

        // Aktualizacja lub tworzenie ogłoszenia
        if ($advert_exist && $advert['status'] === "active") {
            Importer::updateAdvert($advert, $post_data, $meta_data);
            $updated++;
        } else if ($advert['status'] === "active") {
            $post_id = Importer::createAdvert($advert, $post_data, $meta_data);
            if ($post_id) {
                wp_set_object_terms($post_id, $advert['category_name'], 'category');
                $added++;
            }
        }

        $processed_adverts++;
        if ($processed_adverts >= $chunk_size) {
            break;
        }
    }

    $response = array(
        'added'               => $added,
        'deleted'             => $deleted,
        'updated'             => $updated,
        'untitled_but_active' => $untitled_but_active,
        'processed_adverts'   => $processed_adverts,
        'offset'              => $offset,
    );

    return_error_or_response('', $ajax, $response, true);
}

/**
 * Funkcja pomocnicza do zwracania błędów lub poprawnych odpowiedzi.
 */
function return_error_or_response($message, $ajax = false, $data = array(), $success = false)
{
    if ($ajax) {
        if ($success) {
            wp_send_json_success($data);
        } else {
            wp_send_json_error(array('message' => $message));
        }
        exit;
    }

    if (!$success) {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    return $data;
}

/**
 * Przygotowanie listy wykluczonych slugów.
 */
function prepare_excluded_slugs($excludedSlugs)
{
    if (empty($excludedSlugs)) {
        return array();
    }

    if (is_string($excludedSlugs)) {
        $excludedSlugs = explode(',', $excludedSlugs);
        $excludedSlugs = array_map('trim', $excludedSlugs);
    }

    return (array) $excludedSlugs;
}

/**
 * Obsługuje usuwanie nieaktywnych ogłoszeń wraz ze zdjęciami.
 */
function handle_inactive_advert($advert_exist)
{
    $old_images_ids = get_post_meta($advert_exist, 'photos', false);
    if ($old_images_ids) {
        foreach ($old_images_ids as $old_image_id) {
            $old_image_id = (int) $old_image_id;
            wp_delete_attachment($old_image_id, true);
        }
    }
    wp_delete_post($advert_exist, true);
}

/**
 * Przetwarza parametry ogłoszenia, wypełniając metadane i cechy.
 */
function process_advert_params($advert, $excludedSlugs, &$meta_data, &$features)
{
    $params = $advert['params'] ?? array();

    $new_used_value = $advert['new_used'] ?? '';
    if ($new_used_value === 'new') {
        $meta_data['new_used'] = 'Nowe';
    } elseif ($new_used_value === 'used') {
        $meta_data['new_used'] = 'Używane';
    }

    $meta_data['otomoto_id']    = $advert['id'] ?? '';
    $meta_data['otomoto_url']   = $advert['url'] ?? '';
    $meta_data['username']      = $advert['username'] ?? '';
    $meta_data['credential_id'] = $advert['credential_id'] ?? '';

    foreach ($params as $key => $value) {
        switch ($key) {
            case 'make':
                $meta_data['make'] = getMakeName($value);
                break;
            case 'model':
                $meta_data['model'] = getModelName($value);
                break;
            case 'version':
                $meta_data['version'] = $value;
                break;
            case 'price':
                $meta_data['cena'] = intval($value[1] ?? 0);
                break;
            default:
                if (in_array($key, $excludedSlugs, true) || in_array($value, $excludedSlugs, true)) {
                    $meta_data[$key] = $value;
                    break;
                }

                if ($value === "1") {
                    $features[$key] = $key;
                } elseif ($value !== "0") {
                    $features[$key] = $value;
                }
                $meta_data[$key] = $value;
                break;
        }
    }

    if (!empty($features)) {
        if (!isset($meta_data['features']) || !is_array($meta_data['features'])) {
            $meta_data['features'] = $features;
        } else {
            $meta_data['features'] = array_merge($meta_data['features'], $features);
        }
    }
}

/**
 * Buduje tytuł ogłoszenia na podstawie metadanych.
 */
function build_advert_title($meta_data)
{
    $make    = $meta_data['make'] ?? '';
    $model   = $meta_data['model'] ?? '';
    $version = $meta_data['version'] ?? '';
    $title   = trim("$make $model $version");
    return $title;
}

/**
 * Przetwarza zdjęcia ogłoszenia - pobiera je lub usuwa zbędne.
 */
function process_advert_images($advert, $advert_exist, &$meta_data)
{
    $images = $advert['photos'] ?? array();
    if (empty($images)) {
        return;
    }

    $importer     = new Importer();
    $images_ids   = array();
    $images_urls  = array();

    foreach ($images as $image) {
        if (!empty($image['1280x800'])) {
            $images_urls[] = $image['1280x800'];
        }
    }

    foreach ($images_urls as $url) {
        $images_ids[] = $importer->get_or_create_media_by_url($url);
    }

    if ($advert_exist) {
        $old_images_ids = get_post_meta($advert_exist, 'photos', false);
        if ($old_images_ids) {
            foreach ($old_images_ids as $old_image_id) {
                if (!in_array($old_image_id, $images_ids, true)) {
                    wp_delete_attachment((int)$old_image_id, true);
                }
            }
        }
    }

    $meta_data['photos'] = $images_ids;
}

add_action('wp_ajax_process_otomoto_adverts', 'process_otomoto_adverts');
function process_otomoto_adverts()
{
    import_otomoto_adverts_by_packages(1, 0, true);
}

/**
 * Shortcode do wylistowania nieużywanych zdjęć.
 */
function get_unused_images_links()
{
    $samochod_ids = get_posts(array(
        'post_type'   => 'samochod',
        'numberposts' => -1,
        'fields'      => 'ids',
    ));

    $used_images_ids = array();
    foreach ($samochod_ids as $samochod_id) {
        $images_ids    = get_post_meta($samochod_id, 'photos', false);
        if ($images_ids) {
            $used_images_ids = array_merge($used_images_ids, $images_ids);
        }
    }

    $all_images_ids = get_posts(array(
        'post_type'   => 'attachment',
        'numberposts' => -1,
        'fields'      => 'ids',
    ));

    $unused_images_ids = array_diff($all_images_ids, $used_images_ids);
    $unused_images_info = array();

    foreach ($unused_images_ids as $unused_image_id) {
        $attachment_post = get_post($unused_image_id);
        $unused_image_post_id = $attachment_post ? $attachment_post->post_parent : '';
        $unused_image_link    = wp_get_attachment_url($unused_image_id);

        $unused_images_info[] = array(
            'image_id'   => $unused_image_id,
            'image_link' => $unused_image_link,
            'car_id'     => $unused_image_post_id,
        );
    }

    echo '<pre>';
    print_r($unused_images_info);
    echo '</pre>';
}
add_shortcode('unused_images_links', 'get_unused_images_links');

/**
 * Przetwarza ogłoszenia OtoMoto, usuwając te, które nie istnieją w aktualnym pliku JSON.
 */
function process_otomoto_adverts_cleanup()
{
    $all_adverts_path = OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/all_adverts.json';
    $sync_info_path   = OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/sync_info.json';

    if (!file_exists($all_adverts_path) || !file_exists($sync_info_path)) {
        wp_send_json_error('Brak wymaganych plików JSON do przetworzenia ogłoszeń.');
    }

    $all_adverts_data = file_get_contents($all_adverts_path);
    $sync_info_data   = file_get_contents($sync_info_path);

    if ($all_adverts_data === false || $sync_info_data === false) {
        wp_send_json_error('Błąd odczytu plików z ogłoszeniami.');
    }

    $all_adverts = json_decode($all_adverts_data, true);
    $status_info = json_decode($sync_info_data, true);

    if (empty($all_adverts) || empty($status_info)) {
        wp_send_json_error('Brak danych w plikach JSON.');
    }

    $adverts_by_id = array_column($all_adverts, null, 'id');

    foreach ($status_info as $username => $info) {
        $args = array(
            'post_type'      => 'samochod',
            'meta_query'     => array(
                array(
                    'key'     => 'username',
                    'value'   => $username,
                    'compare' => '='
                )
            ),
            'posts_per_page' => -1,
            'fields'         => 'ids'
        );
        $samochod_posts = get_posts($args);

        if (!empty($samochod_posts)) {
            foreach ($samochod_posts as $post_id) {
                $otomoto_id = get_post_meta($post_id, 'otomoto_id', true);

                if (!isset($adverts_by_id[$otomoto_id])) {
                    wp_delete_post($post_id, true);
                    error_log('Usunięto post o ID: ' . $post_id . ' - nie znaleziono odpowiadającego ogłoszenia w JSON.');
                }
            }
        }
    }

    wp_send_json_success('Przetwarzanie ogłoszeń zakończone sukcesem.');
}

/**
 * Zapisuje błędny adres e-mail do opcji w bazie danych.
 */
function otomoto_importer_save_incorrect_email()
{
    check_ajax_referer('otomoto-importer', '_ajax_nonce');

    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $current_email = get_option('otomoto_incorrect_email');

    if (empty($email)) {
        wp_send_json_error(array('message' => 'Adres e-mail nie może być pusty.'));
    }

    if ($email === $current_email) {
        wp_send_json_success(array('message' => 'Adres e-mail został już zapisany.'));
    }

    $update_result = update_option('otomoto_incorrect_email', $email);

    if ($update_result || $email === $current_email) {
        wp_send_json_success(array('message' => 'Adres e-mail został zapisany poprawnie.'));
    } else {
        wp_send_json_error(array('message' => 'Wystąpił błąd podczas zapisu adresu e-mail.'));
    }
}

// Rejestracja akcji AJAX dla zalogowanych i niezalogowanych użytkowników
add_action('wp_ajax_otomoto_importer_save_incorrect_email', 'otomoto_importer_save_incorrect_email');
add_action('wp_ajax_nopriv_otomoto_importer_save_incorrect_email', 'otomoto_importer_save_incorrect_email');
