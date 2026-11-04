<?php

/**
 * @file
 * Post update functions for Commerce Escrow.
 */

/**
 * Remove the webhook secrets each Escrow payment gateway had in 1.1.
 */
function commerce_escrow_post_update_remove_gateway_webhook_secrets(): void {
  $config_factory = \Drupal::configFactory();
  foreach ($config_factory->listAll('commerce_payment.commerce_payment_gateway.') as $name) {
    // Editable configuration, as saving a loaded gateway would write any
    // overrides from settings.php, such as its API key, into the configuration.
    $config = $config_factory->getEditable($name);
    if (in_array($config->get('plugin'), ['escrow_pay', 'escrow_offer'], TRUE) && $config->get('configuration.webhook_secret') !== NULL) {
      $config->clear('configuration.webhook_secret')->save();
    }
  }
}
