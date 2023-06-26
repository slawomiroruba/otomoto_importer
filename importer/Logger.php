<?php 
class Logger {
    public static function log($message) {
        $file = fopen(OTOMOTO_IMPORTER_PLUGIN_DIR . 'log.txt', 'a');
        fwrite($file, $message . "\n");
        fclose($file);
    }
}