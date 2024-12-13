<?php

function getCredentials()
{
    global $wpdb;

    $results = $wpdb->get_results("SELECT * FROM wp_otomoto_credentials", ARRAY_A);

    $credentials = [];

    foreach ($results as $row) {
        $credentials[] = [
            'login' => $row['login'] ?? '',
            'password' => $row['password'] ?? '',
            'access_token' => $row['access_token'] ?? '',
            'refresh_token' => $row['refresh_token'] ?? '',
            'expires_in' => $row['expires_in'] ?? '',
        ];
    }

    return $credentials;
}

function getMakeName(string $make, string $lang = "pl") {
    $files = glob(OTOMOTO_IMPORTER_PLUGIN_DIR . "/importer/categories/*.json");
    
    foreach ($files as $file) {
        $json = file_get_contents($file);
        $data = json_decode($json, true);

        if (!isset($data['parameters'])) {
            continue; // Przejdź do następnego pliku, jeśli "parameters" nie istnieje
        }

        $makeObject = array_filter($data['parameters'], function($parameter) {
            return $parameter['code'] === 'make';
        });

        // Pobierz pierwszy element
        $makeObject = array_shift($makeObject);

        if ($makeObject && isset($makeObject['options'])) {
            $options = $makeObject['options'];
            
            if (isset($options[$make])) {
                return $options[$make][$lang] ?? $make; // Zwróć nazwę w danym języku lub $make, jeśli nie istnieje
            }
        }
    }
    
    return $make; // Zwróć $make, jeśli nie znaleziono żadnej pasującej nazwy
}

function getModelName(string $model, string $lang = "pl") {
    $files = glob(OTOMOTO_IMPORTER_PLUGIN_DIR . "/importer/categories/*.json");
    
    foreach ($files as $file) {
        $json = file_get_contents($file);
        $data = json_decode($json, true);

        if (!isset($data['parameters'])) {
            continue; // Przejdź do następnego pliku, jeśli "parameters" nie istnieje
        }

        $modelObject = array_filter($data['parameters'], function($parameter) {
            return $parameter['code'] === 'model';
        });

        // Pobierz pierwszy element
        $modelObject = array_shift($modelObject);

        if ($modelObject && isset($modelObject['options'])) {
            $options = $modelObject['options'];
            
            if (isset($options[$model])) {
                return $options[$model][$lang] ?? $model; // Zwróć nazwę w danym języku lub $make, jeśli nie istnieje
            }
        }
    }
    
    return $model; // Zwróć $make, jeśli nie znaleziono żadnej pasującej nazwy
}


function otomoto_importer_get_saved_credentials()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'otomoto_credentials';
    $results = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);

    $credentials = array();
    if ($results) {
        foreach ($results as $row) {
            $credentials[] = array(
                'id' => esc_html($row['id']),
                'login' => esc_html($row['login']),
                'password' => esc_html($row['password']),
            );
        }
        return $credentials;
    } else {
        return [];
    }
}

function otomoto_importer_show_saved_credentials()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'otomoto_credentials';
    $results = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);

    echo '<h2>Zapisane dane logowania OtoMoto</h2>';

    if ($results) {
        echo '<table class="wp-list-table widefat fixed striped table-view-list">';
        echo '<thead><tr><th>Login</th><th>Hasło</th><th>Akcje</th></tr></thead><tbody>';

        foreach ($results as $row) {
            $salesman_id = $row['salesman_id'] ?? '';
            $salesman_name = $salesman_id ? get_the_title($salesman_id) : '';
            echo '<tr>';
            echo '<td>' . esc_html($row['login']) . '</td>';
            echo '<td>' . substr($row['password'], 0, 3) . str_repeat('*', strlen($row['password']) - 3) . '</td>';
            echo '<td><button type="button" class="button-link delete-olx-credentials" data-id="' . esc_attr($row['id']) . '">Usuń</button></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    } else {
        echo '<p>Brak zapisanych danych logowania OtoMoto.</p>';
    }
}

function displayVehicleFeatures()
{
    // 1. Pobierz listę wykluczonych slugów z ustawień wtyczki
    $excludedSlugs = rwmb_meta('lista_wykluczen', ['object_type' => 'setting'], 'ustawienia-wtyczki');

    // 1a. Jeśli lista wykluczeń jest pusta, to zwróć pustą tablicę
    if (empty($excludedSlugs)) {
        $excludedSlugs = [];
    } else {
        // Jeśli lista wykluczeń to string w którym są slugi oddzielone przecinkami, to zamień na tablicę
        // oraz usuń spacje z początku i końca każdego elementu
        if (is_string($excludedSlugs)) {
            $excludedSlugs = explode(',', $excludedSlugs);
            $excludedSlugs = array_map('trim', $excludedSlugs);
        }
    }

    // 2. Pobierz listę jak wyświetlać cechy z ustawień wtyczki
    $displaySettings = [];
    $groups = rwmb_meta('group_njhipzoymwd', ['object_type' => 'setting'], 'ustawienia-wtyczki');
    foreach ($groups as $group) {
        // Dodaj do $displaySettings z kluczem jako 'klucz' a wartością jako 'wyswietl_jako'
        $displaySettings[$group['klucz']] = $group['wyswietl_jako'];
    }

    // 3. Pobierz listę cech z bazy danych i przypisz do zmiennej $features
    // Tu trzeba użyć odpowiedniego zapytania do bazy danych
    $field = rwmb_get_field_settings('features');
    $options = $field['options'];
    $values = rwmb_meta('features');
    $features = array();
    foreach ($values as $value) {
        if (isset($options[$value])) {
            $features[] = $options[$value];
        } else {
            $features[] = $value;
        }
    }
    // 4. Ze zmiennej $features usuń cechy, które są na liście wykluczonych slugów
    $filteredFeatures = array_diff($features, $excludedSlugs);

    // 5. W zmiennej $features zamień slugi na nazwy cech zgodnie z ustawieniami wtyczki
    $displayFeatures = array_map(function ($feature) use ($displaySettings) {
        if (isset($displaySettings[$feature])) {
            $feature = $displaySettings[$feature];
        }
        return $feature;
    }, $filteredFeatures);

    // 7. Usuń duplikaty z listy cech
    $uniqueFeatures = array_unique($displayFeatures);

    // Usuń puste elementy lub "0" z tablicy
    $uniqueFeatures = array_filter($uniqueFeatures, function ($feature) {
        return !empty($feature) && $feature !== '0';
    });

    echo '<div class="features-container">'; //Dodajemy kontener dla cech (features)
    // 8. Wyświetl cechy w formie checkboxów (bez duplikatów)
    foreach ($uniqueFeatures as $feature) {
        echo '<div class="feature-item"><p class="features"><img src="'.get_home_url().'/wp-content/uploads/check-mark.svg" alt="check-mark" width="20"> ' . $feature . '</p></div>';
    }
    echo '</div>'; //Zamykamy kontener
}

function saveToOptionsAllFeaturesTranlations()
{
    // 0. Utwórz zmienną $features i przypisz do niej pustą tablicę
    $features = [];
    // 1. Pobierz listę cech każdego pojazdu z bazy danych i przypisz do zmiennej $features 
    // wartości pobrane z bazy danych jeżeli nie występują w tablicy $features
    // 1a. Pobierz wszystkie posty typu 'vehicle'
    $args = array(
        'post_type' => 'samochod',
        'posts_per_page' => -1,
    );

    $query = new WP_Query($args);
    // Show count of posts
    echo '<p>Count of posts: ' . $query->found_posts . '</p>';
    // 1b. Dla każdego posta pobierz cechy i dodaj do tablicy $features
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();

            $values = rwmb_meta('features');
            // 1c. Dla każdej cechy dodaj do tablicy $features jeżeli nie występuje
            foreach ($values as $value) {
                if (!in_array($value, $features) && $value !== '0') {
                    $features[] = $value;
                }
            }

        }
    }
    wp_reset_postdata();

    echo '<p>Count of features: ' . count($features) . '</p>';
    // Wyświetl listę cech oddzielonych przecinkami
    echo '<p>Features: ' . implode(', ', $features) . '</p>';

}