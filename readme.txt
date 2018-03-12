=== WooCommerce Valutec Giftcards ===
Author: Caleb Hearon
Tags: woocommerce
Requires at least: 4.4
Tested up to: 4.9.4
Stable tag: 1.0.2

Allow people to pay with Valutec gift cards at the checkout screen

== Description ==

This plugin lets people pay for orders with a gift card from Valutec. They can enter one or more cards at the cart screen and it will be deducted from the order and charged after the payment screen. The last four digits of the gift card will show in the order details and receipts.

I will be primarily updating the readme in the main [repository on GitHub](https://github.com/chearon/wc-vt-giftcards)

== Changelog ==

= 1.0.2 =
* Update to use POST since Valutec is deprecating GET

= 1.0.1 =

= 1.0.0 =
* First released as open source plugin

== Screenshots ==

1. A new field is added to the cart where you can add a gift card 
2. Card is added to cart and can be removed just like a coupon
3. The order totals screen will show how much was charged to the card
4. The receipt shows how much was charged too
5. You will see messages on Woocommerce's Order Notes panel with transaction details including card number and transaction ID in case you need to do anything manually.
6. And all you need to do is enter your API keys!<Paste>

== Installation ==

**Only PHP 5.3 and newer is supported**

1. Extract the plugin folder (wc-vt-giftcards) into /wp-content/plugins just like you would any other plugin
2. Enter your Client ID and Terminal ID (given to you by Valutec) by visiting Woocommcerce > Settings > Integration > Valutec Gift Cards
3. Make sure that Valutec has the following features enabled on your account: Transaction_Void, Transaction_Sale, and Transaction_CardBalance. The balance is only checked to know how much to deduct, users won't see the total on their card unless the order exceeds that amount.
4. (optional) It is highly recommended to install either Memcached (preferred) or APC. This enables extra security features so that people can't try to guess gift cards or otherwise abuse the Valutec API and get your account disabled. You can check which caching layer the plugin is using by going to the settings screen described above and looking at where it says Cache type below the description. APC and Memcached are detected automatically and don't require any configuration

== FAQ ==

= Why does the order total get deducted instead of the gift card being a payment gateway? =

Ideally this plugin would function as a payment gateway, but if I did that, customers wouldn't be able to pay with both the gift card and a credit card. That's because WooCommerce doesn't support split tender yet.
