<?php 
class Advert {

    private $json;
  
    public function __construct($json)
    {
      $this->json = $json;
    }
  
    public function getID()
    {
      return $this->json['id'];
    }
    
  }
