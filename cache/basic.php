<?php

class WC_Valutec_Cache {
  public function __construct() {
    if ($session = WC()->session) {
      if ($values = $session->get('valutec_cache')) {
        $this->_values = $values;
      }

      add_action('shutdown', function () use ($session) {
        $session->set('valutec_cache', $this->_values);
      });
    }
  }

  public function get($str) {
    if (array_key_exists($str, $this->_values)) {
      return $this->_values[$str];
    } else {
      return FALSE;
    }
  }

  public function set($str, $something, $timeout = NULL) {
    $this->_values[$str] = $something;
  }

  private $_values = [];

  public static $type = "basic";
}

?>
