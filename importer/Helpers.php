<?php 

function getCredentials(){
    global $wpdb;

    $results = $wpdb->get_results("SELECT * FROM wp_otomoto_credentials", ARRAY_A);

    $credentials = [];

    foreach ($results as $row) {
        $credentials[] = [
            'login' => $row['login'],
            'password' => $row['password']
        ];
    }

    return $credentials;

}
