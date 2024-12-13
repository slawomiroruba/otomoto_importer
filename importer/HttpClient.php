<?php 
class HttpClient
{
    private static $max_attempts = 30;  // Maksymalna liczba prób

    public static function getRequest($url)
    {
        if (empty($url)) {
            throw new InvalidArgumentException("Url cannot be empty");
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        // Ustawienie nagłówka "User-Agent" na popularną wartość
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/74.0.3729.131 Safari/537.3"
        ]);

        $attempts = 0;
        do {
            $response = curl_exec($ch);
            $error = curl_errno($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (!$error && $http_code < 400) {
                // Jeśli żądanie jest pomyślne, kończymy próby i zwracamy odpowiedź
                curl_close($ch);
                return $response;
            }

            // Jeśli wystąpił błąd lub kod odpowiedzi HTTP jest >= 400, zwiększamy liczbę prób
            $attempts++;
        } while ($attempts <= self::$max_attempts);

        $error_msg = curl_error($ch);
        curl_close($ch);

        // Jeśli po wielu próbach żądanie nadal nie jest pomyślne, zwracamy błąd
        // Error log 
        error_log("Error while making request to $url. Error message: $error_msg");
        return false;
    }
}

?>