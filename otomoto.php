<?php
/*
Plugin Name: OtoMoto Importer
Description: Prosty plugin do importu ofert z serwisu OtoMoto.pl
Version: 1.0
Author: Sławomir Oruba
*/

define('OTOMOTO_IMPORTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OTOMOTO_IMPORTER_PLUGIN_URL', plugin_dir_url(__FILE__));

require OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/Advert.php';
require OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/Helpers.php';
require OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/Logger.php';
require OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/MetaBoxTools.php';
require OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/HttpClient.php';
require OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/Importer.php';
require OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/OtomotoAPI.php';
require OTOMOTO_IMPORTER_PLUGIN_DIR . '/api/actions.php';
require OTOMOTO_IMPORTER_PLUGIN_DIR . '/used_cars_contact.php';

// Strona ustawień
require OTOMOTO_IMPORTER_PLUGIN_DIR . '/setup/setting-page.php';

require OTOMOTO_IMPORTER_PLUGIN_DIR . '/importer/CronImporter.php';

function map_fuel_type_choices($choices, $facet)
{
    // Sprawdzenie, czy jest to odpowiedni facet (filtr) na podstawie sluga
    if ('filtracja_paliwo' !== $facet['slug']) {

        return
            $choices;
    }

    // Modyfikowanie etykiet wyborów na podstawie wartości faceta

    return
        array_map(
            function ($choice) {
                switch ($choice->facet_value) {
                    case 'petrol':
                        $choice->facet_name = 'Benzyna';
                        break;
                    case 'diesel':
                        $choice->facet_name = 'Diesel';
                        break;
                    case 'electric':
                        $choice->facet_name = 'Elektryczny';
                        break;
                    case 'hybrid':
                        $choice->facet_name = 'Hybrydowy';
                        break;
                }

                return
                    $choice;
            },
            $choices
        );
}
add_filter('wp_grid_builder/facet/choices', 'map_fuel_type_choices', 10, 2);
