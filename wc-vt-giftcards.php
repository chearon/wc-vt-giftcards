<?php
/*
Plugin Name: Valutec Gift Cards for WooCommerce
Description: Integrates the WooCommerce checkout with the Valutec payment API
Version:     1.0.2
Author:      Caleb Hearon
Author URI:  http://chearon.net/blog/
Text Domain: wc-valutec

WC requires at least: 2.3
WC tested up to: 3.3.3
*/

// TODO:
// * i18n
// * don't subract from the order total, just credit card charge total
// * customizable templates
defined('ABSPATH') or die('Nope');

// The card probably doesn't exist if there are many attempts to resolve it,
// so this should be adjusted based on how many times you think a user will
// keep trying to enter the same card
define('GIFTCARD_MAX_TRIES_PER_CARD', 3);

// This one is a way to prevent hackers from brute forcing the input and
// possibly causing you to lose your API keys temporarily. IPs are easy to
// switch so it doesn't prevent more than script kiddie attacks
define('GIFTCARD_MAX_TRIES_PER_IP', 10);

// How long to keep balances in the cache
define('GIFTCARD_CACHE_TIMEOUT', 60 * 5);

add_action('plugins_loaded', function () {
  if (!class_exists('WC_Integration')) {
    if (WP_DEBUG === true) error_log('giftcard: could not load because WooCommerce was not found');
    return;
  }

  // Cache layer - uses memcached or APC, or dumb implementation
  // -----------------------------------------------------------

  if (class_exists('Memcached')) {
    require_once('cache/memcached.php');
  } else if (extension_loaded('apc') && ini_get('apc.enabled')) {
    require_once('cache/apc.php');
  } else {
    require_once('cache/basic.php');
  }

  // WooCommerce Integration - the plugin frontend 
  // ---------------------------------------------

  class WC_Integration_Valutec extends WC_Integration {
    public function __construct() {
      $this->id = 'wc-valutec';
      $this->method_title = 'Valutec Gift Cards';
      $this->method_description = '<p>Integrates WooCommerce payments with '
        . 'Valutec\'s tender API. Just paste the API keys that Valutec gives '
        . 'you into the forms below. Customers can enter gift cards in the '
        . 'cart screen and the total will be adjusted and the card charged at '
        . 'checkout. Notes are added to your order in the backend so that you '
        . 'have the transaction IDs.</p>'
        . ''
        . '<p>Cache type: <code>' . WC_Valutec_Cache::$type . '</code></p>';


      $this->init_form_fields();
      add_action('woocommerce_update_options_integration_' .  $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields() {
      $this->form_fields = array(
        'client_key' => array(
          'title' => 'Client Key',
          'type' => 'text',
          'description' => 'Given to you by Valutec, should look like a GUID',
          'desc_tip' => true,
          'default' => ''
        ),
        'terminal_id' => array(
          'title' => 'Terminal ID',
          'type' => 'text',
          'description' => 'Should be six integers',
          'desc_tip' => true,
          'default' => ''
        )
      );
    }
  }

  add_filter('woocommerce_integrations', function ($integrations) {
    $integrations[] = 'WC_Integration_Valutec';
    return $integrations;
  });

  // Begin hooks
  // -----------

  add_action('woocommerce_init', function ($wc) {
    global $woocommerce;

    $vc = $woocommerce->integrations->integrations['wc-valutec'];

    $mc = new WC_Valutec_Cache();

    // Utility functions
    // -----------------

    $tid = function () {
      for ($i = 1, $s = rand() . ''; $i < 10; ++$i) $s = rand() . '';
      return $s;
    };

    $clog = function () {
      if (WP_DEBUG === true) {
        error_log('giftcard: ' . call_user_func_array('sprintf', func_get_args()));
      }
    };

    $is_gift_card = function ($code) {
      return preg_match('/7\d{18}/', $code) === 1;
    };

    $shorten = function ($code) {
      return substr($code, strlen($code) - 4, 4);
    };

    // Rate limiting
    // -------------

    $check_card_tries = function ($code) use ($mc) {
      return GIFTCARD_MAX_TRIES_PER_CARD >= (int)$mc->get('giftcard-card-tries-' . $code);
    };

    $check_ip_tries = function () use ($mc) {
      return GIFTCARD_MAX_TRIES_PER_IP >= (int)$mc->get('giftcard-ip-tries-' . $_SERVER['REMOTE_ADDR']);
    };

    $inc_tries = function ($code) use ($mc) {
      $card_tries = $mc->get('giftcard-card-tries-' . $code);
      $ip_tries = $mc->get('giftcard-ip-tries-' . $_SERVER['REMOTE_ADDR']);

      $card_tries = $card_tries === false ? 1 : (int)$card_tries;
      $ip_tries = $ip_tries === false ? 1 : (int)$ip_tries;

      $mc->set('giftcard-card-tries-' . $code, $card_tries + 1);
      $mc->set('giftcard-ip-tries-' . $_SERVER['REMOTE_ADDR'], $ip_tries + 1);
    };

    // Card transactions
    // -----------------

    $api_call = function ($method, $id, $card, $extra_args = array()) use ($vc) {
      $ch = curl_init();
      $args = array_merge(array(
        'ClientKey' => $vc->get_option('client_key'),
        'TerminalID' => $vc->get_option('terminal_id'),
        'ProgramType' => 'Gift',
        'CardNumber' => $card,
        'ServerID' => '',
        'Identifier' => $id
      ), $extra_args);

      $url = sprintf('https://ws.valutec.net/Valutec.asmx/%s', $method);

      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($args));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_URL, $url);

      if (($str = curl_exec($ch)) != false) {
        curl_close($ch);
        try {
          return new SimpleXMLElement($str);
        } catch (Exception $e) {
          return false;
        }
      } else {
        curl_close($ch);
        return false;
      }
    };

    // Hits the API and returns a float of the new balance, or false if there was 
    // an API error. To know if it was  successful, check the return value with
    // the $amount you passed in
    $api_do_sale = function ($code, $id, $amount, &$balance, &$auth_code) use ($clog, $api_call) {
      if ($xml = $api_call('Transaction_Sale', $id, $code, array('Amount' => $amount))) {
        $balance = (float)$xml->Balance;
        if ($xml->Authorized == 'false') {
          if ($xml->ErrorMsg == 'NSF') {
            $clog('insufficient funds charging %g to %s (new balance: %g)', $amount, $code, $balance);
          } else {
            $clog('unknown error charging %g to %s: %s (new balance: %g)', $amount, $code, $xml->ErrorMsg, $balance);
          }
          return false;
        } else {
          $auth_code = (string)$xml->AuthorizationCode;
          $clog('successfully charged %g to %s (auth %s, new balance: %g)', $amount, $code, $auth_code, $balance);
          return true;
        }
      } else {
        $clog('unknown error charging %g to %s (malformed response, cURL or connection error)', $amount, $code);
        $balance = null;
        return false;
      }
    };

    // Returns the auth code if the sale went through, false if not. Updates the
    // caching layer with the amount on the card according to the api response
    $do_sale = function ($code, $id, $amount) use ($mc, $api_do_sale) {
      $success = $api_do_sale($code, $id, $amount, $new_balance, $auth_code);
      if (!is_null($new_balance)) $mc->set('giftcard-balances-' . $code, serialize($new_balance), GIFTCARD_CACHE_TIMEOUT);
      return $success ? $auth_code : false;
    };

    $api_void_sale = function ($code, $id, $auth_code, &$balance) use ($clog, $api_call) {
      if ($xml = $api_call('Transaction_Void', $id, $code, array('RequestAuthCode' => $auth_code))) {
        $balance = (float)$xml->Balance;
        if ($xml->Authorized == 'false') {
          $clog('could not void %s on card %s (error: %s)', $auth_code, $code, $xml->ErrorMsg);
          return false;
        } else {
          $clog('voided %s on card %s (new balance: %g)', $auth_code, $code, $balance);
          return true;
        }
      } else {
        $clog('unknown error voiding %s on card %s (malformed response, cURL or connection error)', $auth_code, $code);
        $balance = null;
        return false;
      }
    };

    // 
    $void_sale = function ($code, $id, $auth_code) use ($mc, $api_void_sale) {
      $success = $api_void_sale($code, $id, $auth_code, $new_balance);
      if (!is_null($new_balance)) $mc->set('giftcard-balances-' . $code, serialize($new_balance), GIFTCARD_CACHE_TIMEOUT);
      return $success;
    };

    // Uses no caching. Hits the server unless the ip/card have exceeded their
    // limits. Updates the limits and returns either a float (the balance)
    // or one of:
    //
    // 'not_active' - the card has not been activated
    // 'not_found' - the card does not exist
    // 'unknown' - unknown error, or limits reached
    $api_get_balance = function ($code, $id) use ($clog, $check_card_tries, $check_ip_tries, $inc_tries, $api_call) {
      if (!$check_card_tries($code)) {
        $clog('max tries reached for gift card %s, blocked until cache reset', $code);
        return 'unknown';
      } else if (!$check_ip_tries()) {
        $clog('max tries reached for ip %s, blocked until cache reset', $_SERVER['REMOTE_ADDR']);
        return 'unknown';
      } else if ($xml = $api_call('Transaction_CardBalance', $id, $code)) {
        if ($xml->Authorized == 'false') {
          if ($xml->ErrorMsg == 'CARD NOT FOUND') {
            $clog('%s is not a valid gift card, counting it against request limits', $code);
            $inc_tries($code);
            return 'not_found';
          } else if ($xml->ErrorMsg == 'CARD NOT ACTIVE') {
            $clog('%s has not been activated', $code);
            return 'not_active';
          } else {
            $clog('unknown api error "%s" resolving %s', $xml->ErrorMsg, $code);
            return 'unknown';
          }
        } else {
          $clog('resolved gift card %s (it has a balance of %s)', $code, $xml->Balance);
          return (float)$xml->Balance;
        }
      } else {
        $clog('unknown error resolving %s (malformed response, cURL or connection error)', $code);
        return 'unknown';
      }
    };

    // Protects api_get_balance by memcached. Return values are the same as $api_get_balance()
    $get_balance = function ($code) use ($mc, $api_get_balance, $tid) {
      if (($balance = $mc->get('giftcard-balances-' . $code)) === false) {
        $balance = $api_get_balance($code, $tid());
        $mc->set('giftcard-balances-' . $code, serialize($balance), GIFTCARD_CACHE_TIMEOUT);
      } else {
        $balance = unserialize($balance);
      }

      return $balance;
    };

    // Woo integration - order
    // -----------------------

    // All cards must be refunded sometimes. The two cases as of this writing are
    // when one of the cards fails and we have to rollback the whole order, or 
    // when WC payment fails (credit card, paypal, etc...)
    $refund_cards = function ($order_id) use ($tid, $void_sale) {
      $transaction = get_post_meta($order_id, '_wc_valutec_transactions', true) ?: array();
      $order = wc_get_order($order_id);

      foreach ($transaction as $code => $t) {
        if ($void_sale($code, $transaction_id = $tid(), $t['auth_code'])) {
          $note = sprintf('Rolling back %s on gift card %s (tid: %s)', $t['auth_code'], $code, $transaction_id);
        } else {
          $note = sprintf('Couldn\'t roll back %s on gift card %s (tid: %s). You need to do this manually.', $auth_code, $code, $transaction_id);
        }

        $order->add_order_note($note);
      }
    };

    // Charge the gift card before other payments
    add_action('woocommerce_checkout_order_processed', function ($id, $posted) use ($do_sale, $refund_cards, $tid) {
      $cards = WC()->session->get('gift_cards') ?: array();

      if (empty($cards)) return;

      $failed = false;
      $order = wc_get_order($id);
      $transactions = array();

      // Charge each card
      foreach ($cards as $code => $charge_amount) {
        $transaction_id = $tid();

        if (($auth_code = $do_sale($code, $transaction_id, $charge_amount)) === false) {
          $order->add_order_note(sprintf('Failed to charge %g to card %s (tid: %s)', $charge_amount, $code, $transaction_id));
          $failed = true;
          break;
        } else {
          $order->add_order_note(sprintf('Charged %g to gift card %s (tid: %s, auth: %s)', $charge_amount, $code, $transaction_id, $auth_code));
          $transactions[$code] = array('auth_code' => $auth_code, 'amount' => $charge_amount);
        }
      }

      add_post_meta($id, '_wc_valutec_transactions', $transactions, true);

      // If any cards failed we have to roll back the ones that succeeded
      // and throw an error to stop the payment gateways from going through.
      if ($failed) {
        $refund_cards($id);
        WC()->session->refresh_totals = true;
        throw new Exception('Your gift card balance has changed, please review the totals again');
      }
    }, 10, 2);
    
    // If the order failed, restore the gift card
    add_action('woocommerce_order_status_changed', function ($id, $old_status, $new_status) use ($clog, $refund_cards) {
      $cards = WC()->session->get('gift_cards') ?: array();

      if (count($cards) > 0) {
        $clog('order went from %s to %s', $old_status, $new_status);

        if ($new_status === 'failed' || $new_status === 'cancelled') {
          $refund_cards($id);
        }

        // Payment complete, gift cards are now in the DB
        if ($new_status === 'processing') {
          WC()->session->set('gift_cards', array());
        }
      }
    }, 10, 3);

    // Woo integration - payment calculations
    // --------------------------------------

    add_filter('woocommerce_calculated_total', function ($total) use ($get_balance) {
      $cards = WC()->session->get('gift_cards') ?: array();
      $charge_amount_total = 0;

      foreach ($cards as $code => $charge_amount) {
        if (!is_numeric($balance = $get_balance($code))) {
          $balance = 0;
        }

        $charge_amount = min($balance, $total);

        $cards[$code] = $charge_amount;

        $total -= $charge_amount;
      }

      WC()->session->set('gift_cards', $cards);

      return $total;
    });

    // Woo integration - gift card output
    // ----------------------------------

    $add_gift_card_lines = function () use ($get_balance, $shorten) { 
      $cards = WC()->session->get('gift_cards') ?: array();
      $url = is_checkout() ? WC()->cart->get_checkout_url() : WC()->cart->get_cart_url();

      if (count($cards) > 0) {
        ?><tr>
          <th>Total before gift card<?php echo count($cards) > 1 ? 's' : ''?></th>
          <td><?php echo wc_price(WC()->cart->total + array_sum($cards)); ?></td>
        </tr><?php

        foreach ($cards as $code => $charge_amount) :
          $remove_a = '';
        ?><tr>
            <th>Gift card ending in <?php echo $shorten($code) ?></th>
            <td>
              -<?php echo wc_price($charge_amount); ?>
              <a href="<?php echo esc_url(add_query_arg('remove_gift_card', urlencode($code), $url)) ?>" class="remove-gift-card">[Remove]</a>
            </td>
          </tr>
        <?php endforeach;
      }
    };

    add_action('woocommerce_cart_totals_before_order_total', $add_gift_card_lines);
    add_action('woocommerce_review_order_before_order_total', $add_gift_card_lines);

    add_filter('woocommerce_get_order_item_totals', function ($rows, $order) use ($shorten) {
      $transactions = get_post_meta($order->id, '_wc_valutec_transactions', true) ?: array();
      $coupon_entries = array();

      foreach ($transactions as $code => $t) {
        $coupon_entries['giftcard-' . $code] = array(
          'label' => 'Gift card ending in ' . $shorten($code),
          'value' => '-' . wc_price($t['amount'])
        );
      }

      return array_slice($rows, 0, count($rows) - 1, true)
        + $coupon_entries
        + array_slice($rows, count($rows) - 1, 1, true);
    }, 10, 2);

    // Woo integration - gift card form
    // --------------------------------

    add_action('woocommerce_after_cart_totals', function () { // TODO what if plugin is deactivated during order
      if (WC()->cart->needs_payment()) {
        ?><form action="<?php echo esc_url( WC()->cart->get_cart_url() ); ?>" method="post" class="apply-gift-card">
          <input type="text" name="gift_card_code" value="" placeholder="Gift card number"/>
          <input type="submit" class="apply-gift-card button" name="update_cart" value="Apply gift card" />
          <?php wp_nonce_field( 'woocommerce-cart' ); ?>
        </form><?php
      }
    });

    // It looks terrible to do this in wp_loaded with no regard to what page we're
    // on or anything, but that's actually how class-wc-form-handler.php does, so
    // whatever. It doesn't provide enough hooks for modifying the cart so it is
    // done this way
    add_action('wp_loaded', function () use ($is_gift_card, $get_balance) {
      if (!empty($_POST['update_cart']) && !empty($_POST['gift_card_code'])) {
        if ($is_gift_card($code = $_POST['gift_card_code'])) {
          $cards = WC()->session->get('gift_cards') ?: array();
          if (isset($cards[$code])) {
            wc_add_notice('That gift card has already been applied to the cart.');
          } else {
            $balance = $get_balance($code);

            if ($balance === 'not_active') {
              $msg = 'The gift card you entered has not been activated';
            } else if ($balance === 'not_found') {
              $msg = 'The gift card you entered does not exist';
            } else if ($balance === 'unknown') {
              $msg = 'There was an unknown error checking the balance of your gift card';
            } else if ($balance === 0.0) {
              $msg = 'The gift card you entered is empty';
            }

            if (!isset($msg)) {
              $cards[$code] = 0; // charge amount will be determined in woocommerce_calculated_total
              WC()->session->set('gift_cards', $cards);
              wc_add_notice('Gift card applied successfully! It will be charged at checkout');
            } else {
              wc_add_notice($msg, 'error');
            }
          }
        } else {
          wc_add_notice('That does not appear to be a valid gift card!', 'error');
        }
      }

      if (!empty($_GET['remove_gift_card'])) {
        $cards = WC()->session->get('gift_cards') ?: array();
        $code = $_GET['remove_gift_card'];
        if (isset($cards[$code])) {
          unset($cards[$code]);
          wc_add_notice('Gift card removed');
          WC()->session->set('gift_cards', $cards);
        }
      }
    });
  });
});

?>
