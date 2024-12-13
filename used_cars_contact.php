<?php

// Register sample shortcode
add_shortcode('used_cars_contact', function () {

    // Pobranie ID bieżącej strony
    $page_id = get_the_ID();

    // Pobranie typu posta dla bieżącego ID
    $post_type = get_post_type($page_id);

    // Sprawdzenie, czy typem posta jest 'samochod'
    if ($post_type === 'samochod') {

        // append element to array
        $primary_email = rwmb_meta('username');

        $args = array(
            'post_type' => 'handlowiec',
            // Określenie typu wpisu, który chcemy wyszukać

            // 'meta_query' pozwala na zaawansowane zapytania do pól meta
            'meta_query' => array(
                'relation' => 'OR',
                // Ustalamy relację między zapytaniami jako "OR"

                // Pierwsze zapytanie wyszukuje wpisy, gdzie 'email' równa się $primary_email
                array(
                    'key' => 'email',
                    'value' => $primary_email,
                    'compare' => '='
                ),

                // Drugie zapytanie sprawdza, czy $primary_email jest w tablicy 'maile_dodatkowe'
                // Użycie 'IN' oznacza, że chcemy znaleźć wpisy, w których 'maile_dodatkowe' zawiera $primary_email
                array(  
                    'key' => 'maile_dodatkowe',
                    'value' => $primary_email,
                    'compare' => 'LIKE'
                )
            )
        );

        // Zwróć uwagę na 'compare


        $query = new WP_Query($args);

        // Check if at least one post was found
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                echo do_shortcode('[breakdance_block blockId=14846]');
            }
        }
        wp_reset_postdata();
    } else {
        // Nic nie robimy, ponieważ typem posta nie jest 'samochod'
    }
});


?>