<?php
function otomoto_admin_menu()
{
    add_menu_page(
        'OtoMoto Importer',
        'OtoMoto Importer',
        'manage_options',
        'otomoto-importer',
        'otomoto_importer_admin_page_content',
        'dashicons-download',
        999
    );
}
add_action('admin_menu', 'otomoto_admin_menu');

function otomoto_importer_admin_page_content()
{
    if (isset($_POST['import'])) {
        save_adverts_to_csv();
    }
?>
    <style>
        #wpcontent {
            padding-right: 20px;
        }
    </style>
    <div class="wrap">
        <h1>OtoMoto Importer</h1>
        <h2>Wykonaj synchronizację ogłoszeń z OtoMoto</h2>
        <form method="post" action="">
            <?php wp_nonce_field('otomoto-importer'); ?>
            <p>
                <input type="submit" name="import" class="button button-primary" value="Synchronizuj">
            </p>
        </form>
    </div>
    <h2>Dodaj dane logowania OtoMoto</h2>
    <div id="otomoto-credentials-form">
        <input type="text" id="otomoto-login" placeholder="Login" autocomplete="off">
        <input type="password" id="otomoto-password" placeholder="Hasło" autocomplete="off">
        <button type="button" id="add-otomoto-credentials" class="button button-secondary">Dodaj dane logowania</button>
    </div>
    <?php otomoto_importer_show_saved_credentials(); ?>
    <script>
        (function($) {
            $(document).ready(function() {
                $("#add-otomoto-credentials").on("click", function() {
                    let login = $("#otomoto-login").val();
                    let password = $("#otomoto-password").val();

                    if (login === '' || password === '') {
                        alert('Proszę wprowadzić login i hasło.');
                        return;
                    }

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'otomoto_importer_add_credentials',
                            _ajax_nonce: '<?php echo wp_create_nonce('otomoto-importer'); ?>',
                            login: login,
                            password: password
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload()
                                // Czyszczenie pól formularza
                                $("#otomoto-login").val('');
                                $("#otomoto-password").val('');
                            } else {
                                alert('Wystąpił błąd.');
                            }
                        },
                        error: function() {
                            alert('Wystąpił błąd podczas przetwarzania żądania.');
                        }
                    });
                });

                $(".delete-olx-credentials").on("click", function() {
                    if (!confirm("Czy na pewno chcesz usunąć te dane logowania?")) {
                        return;
                    }

                    let id = $(this).data("id");

                    $.ajax({
                        url: ajaxurl,
                        method: "POST",
                        dataType: "json",
                        data: {
                            action: "otomoto_importer_delete_credentials",
                            _ajax_nonce: '<?php echo wp_create_nonce("otomoto-importer"); ?>',
                            id: id,
                        },
                        success: function(response) {
                            if (response.success) {
                                alert(response.data.message);
                                location.reload();
                            } else {
                                alert("Wystąpił błąd.");
                            }
                        },
                        error: function() {
                            alert("Wystąpił błąd podczas przetwarzania żądania.");
                        },
                    });
                });
            });
        })(jQuery);
    </script>
<?php
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

function otomoto_importer_get_saved_credentials()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'otomoto_credentials';
    $results = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);

    $credentials = array();
    if ($results) {
        foreach ($results as $row) {
            $credentials[] = array(
                'login' => esc_html($row['login']),
                'password' => esc_html($row['password']),
            );
        }
        return $credentials;
    } else {
        return [];
    }
}


function save_adverts_to_csv()
{
    $credentials = otomoto_importer_get_saved_credentials();
    if (empty($credentials)) {
        echo '<div class="notice notice-error is-dismissible"><p>Brak zapisanych danych logowania OtoMoto.</p></div>';
        return;
    }

    if(!class_exists('OtomotoAPI')) {
        require plugin_dir_path(__FILE__) . '/importer/OtomotoAPI.php';
    }

    $all_adverts = array();
    // If are any credentials saved do foreach loop and create OtomotoAPI object for each credential
    foreach ($credentials as $credential) {
        var_dump($credential);
        $otomoto = new OtomotoAPI($credential['login'], $credential['password']);
        if($otomoto->isUserAuthenticaded()){
            $userAdvertsArray = $otomoto->getAllUserAdverts();
            $userAdverts = $userAdvertsArray['results'];
            $all_adverts = array_merge($all_adverts, $userAdverts);
        }
    }

    // Save $all_adverts to csv file
    $file = fopen(OTOMOTO_IMPORTER_PLUGIN_DIR . '/adverts.csv', 'w');

    // Check if $all_adverts is not empty
    if (!empty($all_adverts)) {
        // Get the first element to extract the headers
        $firstAdvert = reset($all_adverts);
        $headers = array_keys($firstAdvert);
        fputcsv($file, $headers);

        // Now loop through $all_adverts to write each advert data to the CSV file
        foreach ($all_adverts as $advert) {
            // Clean up the description field
            if (isset($advert['description'])) {
                // Replace new lines with | and commas with , 
                $advert['description'] = str_replace(array("\n", "\r", ","), array("|", "|", "%"), $advert['description']);
            }

            if (isset($advert['params'])) {
                if(isset($advert['params']['features'])){
                    
                }
                foreach ($advert['params'] as $key => $value) {
                    $advert[$key] = $value['value'];
                }
                
            }

            // If $advert value is an array, serialize it
            foreach ($advert as $key => $value) {
                if (is_array($value)) {
                    $advert[$key] = serialize($value);
                }
            }
            fputcsv($file, $advert);
        }
    }

    // Close the file after writing
    fclose($file);

    echo '<div class="notice notice-success is-dismissible"><pre>Adverts successfully saved to CSV.</pre></div>';
}


// Dodaj na końcu swojego pliku pluginu:
add_action('wp_ajax_otomoto_importer_add_credentials', 'otomoto_importer_add_credentials');
function otomoto_importer_add_credentials()
{
    // Sprawdź bezpieczeństwo żądania AJAX
    check_ajax_referer('otomoto-importer');

    global $wpdb;
    $table_name = $wpdb->prefix . 'otomoto_credentials';

    // Tworzenie tabeli, jeśli nie istnieje
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                login varchar(255) NOT NULL,
                password varchar(255) NOT NULL,
                PRIMARY KEY (id)
            ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Pobieranie danych z formularza
    $login = sanitize_text_field($_POST['login']);
    $password = sanitize_text_field($_POST['password']);

    // Dodawanie danych do bazy
    $wpdb->insert($table_name, array(
        'login' => $login,
        'password' => $password,
    ));

    // Wysyłanie odpowiedzi
    wp_send_json_success(array(
        'message' => 'Pomyślnie dodano dane.',
    ));

    wp_die();
}

add_action('wp_ajax_otomoto_importer_delete_credentials', 'otomoto_importer_delete_credentials');
function otomoto_importer_delete_credentials()
{
    check_ajax_referer('otomoto-importer');

    global $wpdb;
    $table_name = $wpdb->prefix . 'otomoto_credentials';

    $id = intval($_POST['id']);
    $result = $wpdb->delete($table_name, array('id' => $id));

    if ($result) {
        wp_send_json_success(array(
            'message' => 'Pomyślnie usunięto dane logowania.',
        ));
    } else {
        wp_send_json_error(array(
            'message' => 'Nie można usunąć danych logowania.',
        ));
    }

    wp_die();
}
