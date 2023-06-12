<?php
/*
Plugin Name: OtoMoto Importer
Description: Prosty plugin do importu ofert z serwisu OtoMoto.pl
Version: 1.0
Author: Sławomir Oruba
*/

// Strona ustawień
require plugin_dir_path(__FILE__) . '/setup/setting-page.php';

require plugin_dir_path(__FILE__) . '/importer/MetaBoxTools.php';
require plugin_dir_path(__FILE__) . '/importer/OtomotoAPI.php';
require plugin_dir_path(__FILE__) . '/importer/Advert.php';
require plugin_dir_path(__FILE__) . '/importer/HttpClient.php';
require plugin_dir_path(__FILE__) . '/importer/Importer.php';

// Define plugin constants
define('OTOMOTO_IMPORTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OTOMOTO_IMPORTER_PLUGIN_URL', plugin_dir_url(__FILE__));