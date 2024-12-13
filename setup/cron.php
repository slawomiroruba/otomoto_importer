<?php

// Plik blokady - ścieżka do pliku, który będzie używany do kontroli blokady
$lockFile = "cron.lock";

// Sprawdź, czy plik blokady istnieje - jeśli tak, to znaczy, że skrypt jest już uruchomiony
if (file_exists($lockFile)) {
    error_log("Skrypt jest już uruchomiony. Kończenie działania.");
    exit;
}

// Utwórz plik blokady
file_put_contents($lockFile, "locked");

// Wczytaj funckję synchronizacji
// require_once("otomoto_sync.php");

// otomoto_sync();

error_log("Skrypt został zakończony.");
// Usuń plik blokady na końcu skryptu
unlink($lockFile);