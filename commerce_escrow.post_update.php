<?php

/**
 * @file
 * Post update functions for Commerce Escrow.
 */

use Drupal\Component\Utility\Crypt;

/**
 * Generate secret webhook URLs for existing Escrow payment gateways.
 */
function commerce_escrow_post_update_webhook_secret(): string {
  $payment_gateway_storage = \Drupal::entityTypeManager()->getStorage('commerce_payment_gateway');
  /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface[] $payment_gateways */
  $payment_gateways = $payment_gateway_storage->loadByProperties(['plugin' => ['escrow_pay', 'escrow_offer']]);

  foreach ($payment_gateways as $payment_gateway) {
    $configuration = $payment_gateway->getPluginConfiguration();
    if (empty($configuration['webhook_secret'])) {
      $configuration['webhook_secret'] = Crypt::randomBytesBase64(32);
      $payment_gateway->setPluginConfiguration($configuration);
      $payment_gateway->save();
    }
  }

  return (string) t('The Escrow.com webhook URL changed and the old one no longer works. Copy the new URL from each Escrow payment gateway configuration and register it on Escrow.com.');
}
