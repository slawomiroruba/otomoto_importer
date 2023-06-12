<?php
// // Wyświetlanie błędów
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);


require_once __DIR__ . '/simple_html_dom.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/OtomotoAPI.php';
require_once __DIR__ . '/Advert.php';

use simplehtmldom\HtmlWeb;
use simplehtmldom\simple_html_dom;



$otomoto = new OtomotoAPI($username, $password);

$results = $otomoto->getActiveAdverts();
// $response = $otomoto->getAllUserAdverts();
// $all_results = $response['results'];
// foreach ($all_results as $key => $advert) {
//   if ($advert['url'] != '' && $advert['status'] == 'active') {
//     echo $key; // $single_advert = $otomoto->getAdvertDetails($advert['id']);
//     $title = $otomoto->scrapeTitleFromURL($advert['url']);
//     echo $title . PHP_EOL;
//     echo '<br>';
//     // sleep(1);
//   }
// }

// $title = $otomoto->scrapeTitleFromURL($single_advert['url']);


Header('Content-Type: application/json');
echo json_encode($results);

// Save json to file
// file_put_contents(__DIR__ . '/single.json', json_encode($single_advert));
