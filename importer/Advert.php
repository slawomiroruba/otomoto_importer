<?php
class Advert
{

  private $json;

  public function __construct($json)
  {
    $this->json = $json;
  }

  public function getID()
  {
    return $this->json['id'];
  }

  static function existsInDatabase($otomoto_id)
  {
    // Zapytanie do postmeta dla postów z określonym 'otomoto_id'
    $args = array(
      'post_type' => 'samochod',
      // Zmień na typ postów, który chcesz przeszukać
      'meta_key' => 'otomoto_id',
      // Klucz meta, który chcesz znaleźć
      'meta_value' => $otomoto_id, // Wartość, której szukasz
    );

    // Wykonanie zapytania
    $posts = get_posts($args);

    // Jeżeli jakiekolwiek posty są zwracane, zwracamy ID pierwszego. W przeciwnym wypadku zwracamy 0.
    return $posts ? $posts[0]->ID : 0;
  }

}

