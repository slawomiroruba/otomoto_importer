<?php
require_once OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/Helpers.php';

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

add_action('admin_enqueue_scripts', 'my_plugin_enqueue_scripts');

function my_plugin_enqueue_scripts($hook)
{
    if ($hook != 'toplevel_page_otomoto-importer') {
        return;
    }
    $script_version = filemtime(OTOMOTO_IMPORTER_PLUGIN_DIR . '/assets/js/setting-page.js');
    wp_enqueue_script(
        'my-plugin-script', // identyfikator skryptu
        OTOMOTO_IMPORTER_PLUGIN_URL . '/assets/js/setting-page.js',
        // ścieżka do skryptu
        array(),
        // skrypty, od których ten skrypt zależy
        $script_version,
        // wersja skryptu
        true // czy skrypt ma być dodany do sekcji <footer>
    );
}

function otomoto_importer_get_salesmans() {
    // Get posts with post type 'salesman'
    $salesmans = get_posts(array(
        'post_type' => 'handlowiec',
        'numberposts' => -1,
        'post_status' => 'publish',
    ));

    return array_map(function ($salesman) {
        return (object) array(
            'id' => $salesman->ID,
            'name' => $salesman->post_title,
        );
    }, $salesmans);

}

function otomoto_importer_admin_page_content()
{
    ?>
    <style>
        #wpcontent {
            padding-right: 20px;
        }

        #my-plugin-import-progress {
            margin-top: 16px;
        }
    </style>
    <div class="wrap">
        <h1>
            <?= esc_html(get_admin_page_title()); ?>
        </h1>
        <p>Komunikaty wysyłane będą na email administratora:
            <?php echo get_option('admin_email'); ?>
        </p>
        <button class="button button-primary" id="my-plugin-import-button">Rozpocznij Import</button>
        <progress id="my-plugin-import-progress" max="100" value="0" style="display: none;"></progress>
        <p id="my-plugin-import-status"></p>
    </div>
    <h2>E-mail do powiadomień o nieprawidłowym haśle</h2>
    <?php
    $incorrect_email = get_option('otomoto_incorrect_email');
    ?>
    <div id="otomoto-incorrect-credentials-notification">
        <input type="text" value="<?= $incorrect_email ?? ''  ?>" id="otomoto-incorrect-email" placeholder="E-mail" autocomplete="off">
        <button type="button" id="otomoto-incorrect-email-button" class="button button-secondary">Zatwierdź e-mail</button>
    </div>
    
    <h2>Dodaj dane logowania OtoMoto</h2>
    <div id="otomoto-credentials-form">
        <input type="text" id="otomoto-login" placeholder="Login" autocomplete="off">
        <input type="password" id="otomoto-password" placeholder="Hasło" autocomplete="off">
        <!-- Select with salesmans -->
        <select id="otomoto-salesman">
            <option value="">Wybierz sprzedawcę</option>
            <?php
            $salesmans = otomoto_importer_get_salesmans();
            foreach ($salesmans as $salesman) {
                echo '<option value="' . $salesman->id . '">' . $salesman->name . '</option>';
            }
            ?>
        </select>
        <button type="button" id="add-otomoto-credentials" class="button button-secondary">Dodaj dane logowania</button>
    </div>
    <?php otomoto_importer_show_saved_credentials(); ?>
    <script>
        (function ($) {
            $(document).ready(function () {
                $("#add-otomoto-credentials").on("click", function () {
                    let login = $("#otomoto-login").val();
                    let password = $("#otomoto-password").val();
                    let salesman_id = $("#otomoto-salesman").val();

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
                            password: password,
                            salesman_id: salesman_id
                        },
                        success: function (response) {
                            if (response.success) {
                                location.reload()
                                // Czyszczenie pól formularza
                                $("#otomoto-login").val('');
                                $("#otomoto-password").val('');
                                $("#otomoto-salesman").val('');
                            } else {
                                // alert z data message
                                alert(response.data.message);
                            }
                        },
                        error: function () {
                            alert('Wystąpił błąd podczas przetwarzania żądania.');
                        }
                    });
                });

                $("#otomoto-incorrect-email-button").on("click", function () {
                    let email = $("#otomoto-incorrect-email").val();

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'otomoto_importer_save_incorrect_email',
                            _ajax_nonce: '<?php echo wp_create_nonce('otomoto-importer'); ?>',
                            email: email
                        },
                        success: function (response) {
                            if (response.success) {
                                // alert z data message że zapisano
                                alert(response.data.message);
                                
                            } else {
                                // alert z data message
                                alert(response.data.message);
                                // wyczyść pole input
                                $("#otomoto-incorrect-email").val('');
                            }
                        },
                        error: function () {
                            alert('Wystąpił błąd podczas przetwarzania żądania.');
                        }
                    });
                });

                $(".delete-olx-credentials").on("click", function () {
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
                        success: function (response) {
                            if (response.success) {
                                alert(response.data.message);
                                location.reload();
                            } else {
                                alert("Wystąpił błąd.");
                            }
                        },
                        error: function () {
                            alert("Wystąpił błąd podczas przetwarzania żądania.");
                        },
                    });
                });

                // Salesman change event
                // 1. Add event listener on every salesman select
                $(".salesman-select").on("change", function () {
                    // 2. Get salesman id
                    let salesmanId = $(this).val();
                    let credentialsId = $(this).data("id");
                    // Show loading spinner on salesman select
                    $(this).after('<span class="spinner is-active"></span>');

                    // 9. Send AJAX request to save salesman
                    $.ajax({
                        url: ajaxurl,
                        method: "POST",
                        dataType: "json",
                        data: {
                            action: "otomoto_importer_save_salesman",
                            _ajax_nonce: '<?php echo wp_create_nonce("otomoto-importer"); ?>',
                            salesman_id: salesmanId,
                            credentials_id: credentialsId
                        },
                        success: function (response) {
                            if (response.success) {
                                // Remove spinner 
                                $(".spinner").remove();
                            } else {
                                alert("Wystąpił błąd.");
                            }
                        },
                        error: function () {
                            alert("Wystąpił błąd podczas przetwarzania żądania.");
                        },
                    });
                });
            });
        })(jQuery);
    </script>
    <?php
}


add_action('wp_ajax_otomoto_importer_save_salesman', 'otomoto_importer_save_salesman');
function otomoto_importer_save_salesman(){
    // 1. Pobierz nadchozące dane z formularza
    $salesman_id = intval($_POST['salesman_id']);
    $credentials_id = intval($_POST['credentials_id']);

    // Check nonce
    check_ajax_referer('otomoto-importer');

    // 2. Zapisz dane w bazie danych
    global $wpdb;

    $table_name = $wpdb->prefix . 'otomoto_credentials';
    
    $result = $wpdb->update(
        $table_name,
        array(
            'salesman_id' => $salesman_id,
        ),
        array(
            'id' => $credentials_id,
        )
    );

    // 3. Wyślij odpowiedź
    if ($result) {
        wp_send_json_success(
            array(
                'message' => 'Pomyślnie zapisano dane.',
            )
        );
    } else {
        wp_send_json_error(
            array(
                'message' => 'Nie można zapisać danych.',
            )
        );
    }
}


// Dodaj na końcu swojego pliku pluginu:
add_action('wp_ajax_otomoto_importer_add_credentials', 'otomoto_importer_add_credentials');
function otomoto_importer_add_credentials()
{
    // Sprawdź bezpieczeństwo żądania AJAX
    check_ajax_referer('otomoto-importer');

    global $wpdb;
    $table_name = $wpdb->prefix . 'otomoto_credentials';

    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            login varchar(255) NOT NULL,
            password varchar(255) NOT NULL,
            salesman_id varchar(255) NULL,
            access_token varchar(255) NULL,
            refresh_token varchar(255) NULL,
            expires_timestamp int(11) NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
        $table_details = $wpdb->get_results("SHOW COLUMNS FROM `$table_name`");
        $existing_columns = array();
        foreach ($table_details as $column) {
            $existing_columns[] = $column->Field;
        }
        if (!(in_array('login', $existing_columns) && in_array('password', $existing_columns) && in_array('access_token', $existing_columns) && in_array('refresh_token', $existing_columns) && in_array('expires_timestamp', $existing_columns))) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    } else {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Pobieranie danych z formularza
    $login = sanitize_text_field($_POST['login']);
    $password = sanitize_text_field($_POST['password']);
    $salesman_id = intval($_POST['salesman_id']);

    // Sprawdzenie, czy istnieje już użytkownik o tym loginie
    $existing_user = $wpdb->get_row("SELECT * FROM $table_name WHERE login = '$login'");

    if ($existing_user) {
        // Użytkownik o tym loginie już istnieje
        wp_send_json_error(
            array(
                'message' => 'Użytkownik o tym loginie już istnieje.',
            )
        );
    } else {
        // Dodawanie danych do bazy
        $wpdb->insert(
            $table_name,
            array(
                'login' => $login,
                'password' => $password,
                'salesman_id' => $salesman_id,
            )
        );

        // Wysyłanie odpowiedzi
        wp_send_json_success(
            array(
                'message' => 'Pomyślnie dodano dane.',
            )
        );
    }

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
        wp_send_json_success(
            array(
                'message' => 'Pomyślnie usunięto dane logowania.',
            )
        );
    } else {
        wp_send_json_error(
            array(
                'message' => 'Nie można usunąć danych logowania.',
            )
        );
    }

    wp_die();
}