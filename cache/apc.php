<?php

class WC_Valutec_Cache {
  public function get($str) {
    return apc_fetch($str);
  }

  public function set($str, $something, $timeout = 0) {
    apc_store($str, $something, $timeout);
  }

  public static $type = "apc";
}

?>
