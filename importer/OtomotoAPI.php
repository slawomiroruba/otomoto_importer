<?php
class OtomotoAPI
{
  private $client_id = '1366';
  private $client_secret = 'adba5927f32c63d184b08b9a3faa7ba6';
  private $username = '';
  public $log_result = '';
  private $password = '';
  private $expires_timestamp = 0;
  public $access_token = '';
  private $refresh_token = '';
  private $user_agent = 'bok@proformat.pl';
  private $api_base_url = 'https://www.otomoto.pl/api/open/';

  public function __construct($username, $password)
  {
    $this->username = $username;
    $this->password = $password;
    $this->authenticate();
  }

  private function authenticate()
  {

    //1. Pobierz z bazy danych czy dla tego użytkownika jest już zapisany token
    $all_credentials = getCredentials();

    $credential_data = array_filter($all_credentials, function ($credential) {
      return $credential['login'] === $this->username;
    });

    // Sprawdzamy czy użytownik ma już zapisany access_token
    if (isset($credential_data['access_token']) && isset($credential_data['refresh_token'])) {
      // Check if token is not expired
      $expires_timestamp = $credential_data['expires_timestamp'] ?? 0;
      if ($expires_timestamp > 0) {
        $this->expires_timestamp = $expires_timestamp;
        $current_time = time();
        if ($expires_timestamp > $current_time) {
          $this->access_token = $credential_data['access_token'];
          $this->refresh_token = $credential_data['refresh_token'];
        } else {
          $this->refreshAccessToken($credential_data['refresh_token']);
        }
      }
    } else {
      $this->getAccessToken();
    }
  }

  public function getAccessToken()
  {
    $curl = curl_init();

    curl_setopt_array(
      $curl,
      array(
        CURLOPT_URL => $this->api_base_url . 'oauth/token',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => array(
          'client_id' => $this->client_id,
          'client_secret' => $this->client_secret,
          'grant_type' => 'password',
          'username' => $this->username,
          'password' => $this->password,
        ),
        CURLOPT_HTTPHEADER => array(
          'User-Agent: ' . $this->user_agent,
        ),
      )
    );

    $response = curl_exec($curl);
    $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $response_data = json_decode($response, true);


    $this->log_result = $response_data;

    if ($httpcode >= 200 && $httpcode < 300) {
      // Kod odpowiedzi jest w zakresie 200-299, co oznacza, że operacja zakończyła się sukcesem
      if (isset($response_data['access_token'])) {
        $this->access_token = $response_data['access_token'];
        $this->refresh_token = $response_data['refresh_token'];
        $this->expires_timestamp = time() + $response_data['expires_in'];
        // Zapisz tokeny do bazy danych
        global $wpdb;
        $table_name = $wpdb->prefix . 'otomoto_credentials';
        $wpdb->update($table_name, array(
          'access_token' => $this->access_token,
          'refresh_token' => $this->refresh_token,
          'expires_timestamp' => $this->expires_timestamp,
        ), array(
          'login' => $this->username,
        )
        );
      }
    }
    curl_close($curl);
  }

  public function refreshAccessToken($refresh_token)
  {
    $curl = curl_init();

    curl_setopt_array(
      $curl,
      array(
        CURLOPT_URL => $this->api_base_url . 'oauth/token',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => array(
          'client_id' => $this->client_id,
          'client_secret' => $this->client_secret,
          'grant_type' => 'refresh_token',
          'refresh_token' => $refresh_token,
        ),
        CURLOPT_HTTPHEADER => array(
          'User-Agent: ' . $this->user_agent,
        ),
      )
    );

    $response = curl_exec($curl);
    curl_close($curl);

    $json_response = json_decode($response, true);
    if (isset($json_response['access_token'])) {
      $this->access_token = $json_response['access_token'];

      // Zapisz tokeny do bazy danych
      global $wpdb;
      $table_name = $wpdb->prefix . 'otomoto_credentials';
      $wpdb->update($table_name, array(
        'access_token' => $this->access_token,
        'refresh_token' => $this->refresh_token,
        // Expires in seconds
        'expires_timestamp' => time() + $json_response['expires_in'],

      ), array(
        'login' => $this->username,
      )
      );

      return $json_response['access_token'];
    } else {
      throw new Exception('Nie można odświeżyć tokena dostępu.');
    }
  }

  public function getAllUserAdverts($page = null, $limit = null)
  {
    if (!$this->access_token) {
      throw new Exception('Access token is required. Please authenticate first.');
    }

    $curl = curl_init();

    $url = $this->api_base_url . 'account/adverts';
    if ($page !== null || $limit !== null) {
      $url .= '?';
      if ($page !== null) {
        $url .= 'page=' . $page;
      }
      if ($limit !== null) {
        $url .= ($page !== null ? '&' : '') . 'limit=' . $limit;
      }
    }

    curl_setopt_array(
      $curl,
      array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
          'User-Agent: ' . $this->user_agent,
          'Content-Type: application/json',
          'Authorization: Bearer ' . $this->access_token,
        ),
      )
    );

    $response = curl_exec($curl);
    curl_close($curl);

    return json_decode($response, true);
  }

  public function isUserAuthenticated()
  {
    if ($this->access_token !== '') {
      return $this->log_result;
    } else {
      return false;
    }
  }

  public function getActiveAdverts()
  {
    $allAdverts = $this->getAllUserAdverts();
    $adverts = $allAdverts['results'];
    $active_adverts = [];
    foreach ($adverts as $advert) {
      if ($advert['status'] === 'active') {
        $advert['category_name'] = $this->getCategoryNameById($advert['category_id']);
        $active_adverts[] = $advert;
      }
    }
    return $active_adverts;
  }

  public function getAdvertDetails($advert_id)
  {
    $url = $this->api_base_url . 'account/adverts/' . $advert_id;

    $curl = curl_init();

    curl_setopt_array(
      $curl,
      array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
          'User-Agent: ' . $this->username,
          'Content-Type: application/json',
          'Authorization: Bearer ' . $this->access_token
        ),
      )
    );

    $response = curl_exec($curl);
    curl_close($curl);

    $advert_details = json_decode($response, true);

    return $advert_details;
  }

  public function getCategoryNameById($category_id)
  {
    $url = $this->api_base_url . 'categories/' . $category_id;

    $curl = curl_init();

    curl_setopt_array(
      $curl,
      array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
          'User-Agent: ' . $this->username,
          'Content-Type: application/json',
          'Authorization: Bearer ' . $this->access_token
        ),
      )
    );

    $response = curl_exec($curl);
    curl_close($curl);

    $response = json_decode($response, true);

    return $response['names']['pl'] ?? null;

  }

  public function getAllCategories(int $category_id){
    $url = $this->api_base_url . 'categories/' . $category_id;

    $curl = curl_init();

    curl_setopt_array(
      $curl,
      array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
          'User-Agent: ' . $this->username,
          'Content-Type: application/json',
          'Authorization: Bearer ' . $this->access_token
        ),
      )
    );

    $response = curl_exec($curl);
    curl_close($curl);

    $response = json_decode($response, true);

    return $response ?? null;
  }

  //Read all versions from model
  public function getAllVersionFromModel(int $category_id, string $brand_code, string $model_code){
    // /categories/:category_id/models/:brand_code/versions/:model_code
    $url = $this->api_base_url . "categories/$category_id/models/$brand_code/versions/$model_code";
    $curl = curl_init();

    curl_setopt_array(
      $curl,
      array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
          'User-Agent: ' . $this->username,
          'Content-Type: application/json',
          'Authorization: Bearer ' . $this->access_token
        ),
      )
    );

    $response = curl_exec($curl);
    curl_close($curl);

    $response = json_decode($response, true);

    return $response ?? null;
  }


  public function getSalesManager($user_id)
  {

    $user_id = (int) $user_id;

    // Check if $user_id is not null and is a number if not return null
    if ($user_id === null || !is_numeric($user_id)) {
      return null;
    }



    $url = $this->api_base_url . 'account/status';

    $curl = curl_init();

    curl_setopt_array(
      $curl,
      array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
          'User-Agent: ' . $this->user_agent,
          'Content-Type: application/json',
          'Authorization: Bearer ' . $this->access_token
        ),
        CURLOPT_POSTFIELDS => json_encode(
          array(
            'user_id' => $user_id
          )
        )
      )
    );

    $response = curl_exec($curl);
    // var_dump($response);
    curl_close($curl);

    $response_data = json_decode($response, true);

    if (isset($response_data['salesManager'])) {
      return $response_data['salesManager'];
    } else {
      return null;
    }
  }

  static function scrapeTitleFromURL($url)
  {
    if (!function_exists('str_get_html')) {
      require_once OTOMOTO_IMPORTER_PLUGIN_DIR . 'importer/simple_html_dom.php';
    }

    $html_code = HttpClient::getRequest($url);
    if (!$html_code)
      return null;

    $html = str_get_html($html_code);
    if (!$html)
      return null;

    $titleElement = $html->find('span.offer-title', 0);
    if (!$titleElement)
      return null;

    if ($tags = $titleElement->find('div.tags', 0)) {
      $tags->outertext = '';
    }

    return ($title = trim($titleElement->plaintext)) ? $title : null;
  }
}

// $otomoto = new OtomotoAPI('wrobud@kiauzywane.pl', 'Uzyw@ne2022!!!');
// var_dump($otomoto->refreshAccessToken("ebc541561debf63c104be02c879a2fbf0f7e4f84"));
// saveJsonToFile
?>