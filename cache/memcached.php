<?php

class WC_Valutec_Cache {
  public function __construct() {
    $this->_mc = new Memcached();
    $this->_mc->addServer('localhost', 11211); 
  }

  public function get($str) {
    return $this->_mc->get($str);
  }

  public function set($str, $something, $timeout = 0) {
    return $this->_mc->set($str, $something, $timeout);
  }

  private $_mc;

  public static $type = "memcached";
}

?>
