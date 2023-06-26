<?php
class Importer
{
    private $credentials;
    private $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->setAccountsCredentials();
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

    public function syncAdwert($advert_details)
    {

        $result = [];
        $created_adverts = [];
        foreach ($advert_details as $advert) {
            if ($this->post_exists_by_id($advert_details['id'])) {
                // Update post
                $post_id = wp_update_post([
                    'ID' => $advert['id'],
                    'post_title' => OtomotoAPI::scrapeTitleFromURL($advert['url']),
                    'post_content' => $advert['description'],
                    'post_status' => $advert['status'] === 'active' ? 'publish' : 'draft',
                    'post_author' => 1,
                    'post_type' => 'samochod',
                ]);
                $result['updated_adverts'][] = $post_id;

                MetaBoxTools::update_custom_table_fields($post_id, $this->wpdb->prefix . 'car_details', [
                    'cena' => $advert["price"]["1"] . $advert["price"]["currency"],
                    'kategoria' => $advert[''],
                    'marka_pojazdu' => $advert[''],
                    'model_pojazdu' => $advert[''],
                    'wersja' => $advert[''],
                    'rok_produkcji' => $advert[''],
                    'przebieg' => $advert[''],
                    'pojemnosc_skokowa' => $advert[''],
                    'rodzaj_paliwa' => $advert[''],
                    'moc' => $advert[''],
                    'skrzynia_biegow' => $advert[''],
                    'naped' => $advert[''],
                    'typ_nadwozia' => $advert[''],
                    'liczba_drzwi' => $advert[''],
                    'liczba_miejsc' => $advert[''],
                    'kolor' => $advert[''],
                    'rodzaj_kolor' => $advert[''],
                    'okres_gwarancji_producenta' => $advert[''],
                    'lub_do_przebieg_km' => $advert[''],
                    'kraj_pochodzenia' => $advert[''],
                    'pierwsza_rejestracja' => $advert[''],
                    'zarejestrowany_w_polsce' => $advert[''],
                    'bezwypadkowe' => $advert[''],
                    'serwisowany_w_aso' => $advert[''],
                    'stan' => $advert[''],
                    'image_udzmispjpxj' => $advert[''],
                ]);

                MetaBoxTools::update_custom_table_fields($post_id, $this->wpdb->prefix . 'car_details', [
                    'audio_i_multimedia' => $advert[''],
                    'komfort_i_dodatki' => $advert[''],
                    'systemy_wspomagania_kierowcy' => $advert[''],
                    'bezpieczenstwo' => $advert[''],
                    'user_f2dtz1xd5ft' => $advert[''],
                ]);
            } else {
                // Create post
                $post_id = wp_insert_post([
                    'ID' => $advert['id'],
                    'post_title' => $advert['title'],
                    'post_content' => $advert['description'],
                    'post_status' => 'publish',
                    'post_author' => 1,
                    'post_type' => 'samochod',
                ]);
                $result['created_adverts'][] = $post_id;

                MetaBoxTools::update_custom_table_fields($post_id, $this->wpdb->prefix . 'otomoto_adverts', $advert);
            }
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

    static function createNewAdvert($advert)
    {
        // Check if the advert has all the necessary details
        if (isset($advert['id'], $advert['title'], $advert['description'])) {
            if($advert['status'] === "active"){
                $otomoto_title = OtomotoAPI::scrapeTitleFromURL($advert['url']);
                $title = $otomoto_title ? $otomoto_title : null;
            }
            
            $post_id = wp_insert_post([
                'post_title' => ($title !== null) ? $title : $advert['title'],
                'post_content' => $advert['description'],
                'post_status' => $advert['status'] === 'active' ? 'publish' : 'draft',
                'post_author' => 1,
                'post_type' => 'samochod',
            ]);

            // You can extend this part to add more metadata or taxonomy related details to your post.
            // For example, adding metadata like this:
            // add_post_meta($post_id, 'meta_key', 'meta_value', true);
            
            // And then return the post ID
            return $post_id;
        } else {
            throw new Exception('Advert details are incomplete.');
        }
    }
}

$importer = new Importer();
// var_dump($importer->getCredentials());
