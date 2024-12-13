<?php 
function import_otomoto_adverts() {
    $blockSize = 1;
    $offset = 0;
    $importedCount = 0;
    $added = 0;
    $deleted = 0;
    $updated = 0;
    $ajaxurl = 'https://motoryzacja.wrobud.pl/wp-admin/admin-ajax.php';
    // Początkowy POST do pobrania liczby ogłoszeń
    $initialData = get_adverts_from_otomoto_and_save_json(false);
    process_otomoto_adverts();
    if ($initialData['all_adverts']) {
        $totalPosts = $initialData['all_adverts'];
        while ($importedCount < $totalPosts) {
            $data = import_otomoto_adverts_by_packages($blockSize, $offset, false);
            if ($data['processed_adverts']) {
                $processedAdverts = $data['processed_adverts'];
                $importedCount += $processedAdverts;
                $added += $data['added'];
                $deleted += $data['deleted'];
                $updated += $data['updated'];
                $offset += $processedAdverts;
            } else {
                // Wysłanie maila w przypadku błędu
                $to = 'bok@proformat.pl';
                $subject = 'Błąd importu ogłoszeń';
                $message = 'Wystąpił błąd podczas importu ogłoszeń z Otomoto.';
                $headers = 'From: your_email@example.com' . "\r\n" .
                    'Reply-To: your_email@example.com' . "\r\n";

                mail($to, $subject, $message, $headers);
                break;
            }
        }
    }
}

// Dodanie zadania CRON
if ( ! wp_next_scheduled( 'import_otomoto_adverts' ) ) {
    wp_schedule_event( time(), 'hourly', 'import_otomoto_adverts' );
}

add_action( 'import_otomoto_adverts', 'import_otomoto_adverts' );

?>