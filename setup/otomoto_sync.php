<?php
function otomoto_sync()
{



    $importer = new Importer();

    $credentials = otomoto_importer_get_saved_credentials();
    if (empty($credentials)) {
        echo '<div class="notice notice-error is-dismissible"><p>Brak zapisanych danych logowania OtoMoto.</p></div>';
        return;
    }

    if (!class_exists('OtomotoAPI')) {
        require plugin_dir_path(__FILE__) . '/importer/OtomotoAPI.php';
    }

    if (!class_exists('Advert')) {
        require plugin_dir_path(__FILE__) . '/importer/Advert.php';
    }

    $added = 0;
    $deleted = 0;
    $updated = 0;
    $counter = 0;
    $all_adverts = array();
    foreach ($credentials as $credential) {
        $otomoto = new OtomotoAPI($credential['login'], $credential['password']);
        $log_result = $otomoto->isUserAuthenticated();
        if ($log_result) {
            $userAdvertsArray = $otomoto->getAllUserAdverts();
            // Var dump number of adverts
            $userAdverts = $userAdvertsArray['results'];
            // Dodajemy 'category_name' do każdego ogłoszenia
            foreach ($userAdverts as &$advert) {
                $advert['category_name'] = $otomoto->getCategoryNameById($advert['category_id']) ?? null;
                
                $advert['credentials_id'] = $credential['id'];
            }
            $all_adverts = array_merge($all_adverts, $userAdverts);
        } else {
            $admin_email = 'jedrzej.kabarowski@wrobud.pl';
            $subject = 'Błąd danych logowania do OtoMoto';
            $message = 'Wystąpił błąd logowania do OtoMoto dla danych: ' . $credential['login'];
            //wp_mail($admin_email, $subject, $message);
        }
    }

    // Check if $all_adverts is not empty
    if (!empty($all_adverts)) {

        // Get the first element to extract the headers
        $firstAdvert = reset($all_adverts);
        $headers = array_keys($firstAdvert);


        // Policz wszystkie ogłoszenia o statusie active i zapisz do zmiennej $all_adverts_active
        $all_adverts_active = 0;
        foreach ($all_adverts as $advert) {
            if ($advert['status'] === "active") {
                $all_adverts_active++;
            }
        }

        // Przeiteruj po wszystkich ogłoszeniach
        foreach ($all_adverts as $advert) {

            error_log('[before check id and status] Przetwarzanie ogłoszenia ID: ' . $advert['id']);
            // Check if the advert has all the necessary details
            if (isset($advert['id']) && $advert['status'] === "active") {

                // Prepare data for wordpress post

                // $post_title = OtomotoAPI::scrapeTitleFromURL($advert['url']);
                // $post_title = 'test';
                // $title_from_data = $advert['title'];
                // Append to file with scraped titles


                // Go to next iteration

                $post_data = array(
                    'post_title' => $advert['title'],
                    'post_content' => $advert['description'],
                    'post_status' => 'publish',
                    'post_author' => 1,
                    'post_type' => 'samochod',
                );

                $meta_data = array();

                $shouldAdd = false; // Flag, kiedy dodać parametry do funkcji features

                // Utwórz tablicę features tylko jeżeli nie istnieje lub jest pusta
                if (!isset($meta_data['features']) || empty($meta_data['features'])) {
                    $features = array();
                } else {
                    $features = $meta_data['features'];
                }

                foreach ($advert['params'] as $key => $value) {

                    // Jeżeli klucz jest równy 'no_accident', dodaj do meta_data i ustaw flagę na true
                    if ($key === 'service_record') {
                        $meta_data[$key] = $value;
                        $shouldAdd = true;
                        continue;
                    }

                    // Jeżeli flaga jest ustawiona na true, dodaj parametr do tablicy features
                    if ($shouldAdd) {
                        // Jeżeli wartość parametru jest równa "1", zapisz wartość jako klucz
                        if ($value === "1") {
                            $features[$key] = $key;
                        } else {
                            $features[$key] = $value;
                        }
                        continue;
                    }

                    if ($key === 'price') {
                        $meta_data['cena'] = intval($value['1']);
                        continue;
                    }

                    $meta_data[$key] = $value;
                }

                // Dodaj features do meta_data, tylko jeżeli jest niepusty
                if (!empty($features)) {
                    $meta_data['features'] = $features;
                }



                $new_used_value = $advert['new_used'] ?? "";
                if ($new_used_value == 'new') {
                    $new_used_value = 'Nowe';
                } else if ($new_used_value == 'used') {
                    $new_used_value = 'Używane';
                }   
                $meta_data['new_used'] = $new_used_value;

                $meta_data['otomoto_id'] = $advert['id'] ?? "";

                $meta_data['credentials_id'] = $advert['credentials_id'] ?? "";

                // Post images

                if (isset($advert['photos'])) {
                    $images = $advert['photos'];
                }
                

                // Map images to array of urls
            
                $images_urls = array_map(function ($image) {
                    return $image['1280x800'];
                }, $images);

                $meta_data['photos'] = $images_urls ?? "";

                error_log('[updateAdvert] Przetwarzanie ogłoszenia ID: ' . $advert['id']);

                $advert_exist = Advert::existsInDatabase($advert['id']);
                if ($advert_exist) {
                    if ($advert['status'] !== "active") {
                        wp_delete_post($advert_exist, true);
                        $deleted++;
                    } else {
                        error_log('[updateAdvert] Przetwarzanie ogłoszenia ID: ' . $advert['id']);
                        Importer::updateAdvert($advert, $post_data, $meta_data);
                        $updated++;
                    }
                } else {
                    $post_id = Importer::createAdvert($advert, $post_data, $meta_data);
                    if ($post_id) {
                        // Dodajemy taksonomię 'category' do posta
                        wp_set_object_terms($post_id, $advert['category_name'], 'category');
                        $added++;
                    }
                }

                $counter++;

                // Sprawdź czy dodano już trzy ogłoszenia
                if ($counter >= 2) {
                    break; // Przerwij pętlę po dodaniu trzech ogłoszeń
                }
            }
        }
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>Brak ogłoszeń do zapisania.</p></div>';
    }
    // Informacja ile ogłoszeń pobrano łącznie z API, ile zostało dodanych, usuniętych i zaktualizowanych
    echo '<div class="notice notice-success is-dismissible"><p>Pobrano ' . count($all_adverts) . ' ogłoszeń z API (z czego ' . $all_adverts_active . ' na statusie "active"). Dodano ' . $added . ' ogłoszeń, usunięto ' . $deleted . ' ogłoszeń, zaktualizowano ' . $updated . ' ogłoszeń.</p></div>';


    $importer->import_images_to_media_library();
}
