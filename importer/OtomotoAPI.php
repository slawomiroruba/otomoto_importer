<?php 
class OtomotoAPI
{
  private $client_id = '1366';
  private $client_secret = 'adba5927f32c63d184b08b9a3faa7ba6';
  private $username = '';
  private $password = '';
  private $access_token = '';
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
    $curl = curl_init();

    curl_setopt_array($curl, array(
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
    ));

    $response = curl_exec($curl);
    curl_close($curl);

    $response_data = json_decode($response, true);
    if (isset($response_data['access_token'])) {
      $this->access_token = $response_data['access_token'];
      $this->refresh_token = $response_data['refresh_token'];
    } else {
      return false;
    }
  }

  public function refreshAccessToken($refresh_token)
  {
    $curl = curl_init();

    curl_setopt_array($curl, array(
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
        'User-Agent: bok@proformat.pl',
      ),
    ));

    $response = curl_exec($curl);
    curl_close($curl);

    $json_response = json_decode($response, true);
    if (isset($json_response['access_token'])) {
      $this->access_token = $json_response['access_token'];
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

    curl_setopt_array($curl, array(
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CUSTOMREQUEST => 'GET',
      CURLOPT_HTTPHEADER => array(
        'User-Agent: ' . $this->user_agent,
        'Content-Type: application/json',
        'Authorization: Bearer ' . $this->access_token,
      ),
    ));

    $response = curl_exec($curl);
    curl_close($curl);

    return json_decode($response, true);
  }

  public function isUserAuthenticaded(){
    return $this->access_token !== '';
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

    curl_setopt_array($curl, array(
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
    ));

    $response = curl_exec($curl);
    curl_close($curl);

    $advert_details = json_decode($response, true);

    return $advert_details;
  }

  public function getCategoryNameById($category_id)
  {
    $url = $this->api_base_url . 'categories/' . $category_id;

    $curl = curl_init();

    curl_setopt_array($curl, array(
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
    ));

    $response = curl_exec($curl);
    curl_close($curl);

    $response = json_decode($response, true);

    return $response['names']['pl'] ?? null;

  }

  public function scrapeTitleFromURL($url)
  {
    $html_code = HttpClient::getRequest($url);
    $html = str_get_html($html_code);
    $titleElement = $html->find('span.offer-title', 0);

    // Usuwanie znacznika div o klasie "tags"
    $tags = $titleElement->find('div.tags', 0);
    if ($tags) {
      $tags->outertext = '';
    }

    // Zwracanie tekstu bez znacznika div o klasie "tags"
    $title = trim($titleElement->plaintext);
    return $title;
  }
}
function saveJsonToFile($json, $filename)
  {
    $file = fopen($filename, 'w');
    fwrite($file, $json);
    fclose($file);
  }

$otomoto = new OtomotoAPI('wrobud@kiauzywane.pl', 'Uzyw@ne2022!!!');
// var_dump($otomoto->getActiveAdverts());
// saveJsonToFile
?>