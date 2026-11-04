<?php

/**
 * @file
 * Post update functions for Commerce Escrow.
 */

/**
 * Formerly generated a secret webhook URL for each Escrow payment gateway.
 */
function commerce_escrow_post_update_webhook_secret(): void {
  // Superseded by the shared URL of the update below.
}

/**
 * Generate the webhook URL shared by all Escrow payment gateways.
 */
function commerce_escrow_post_update_shared_webhook(): string {
  \Drupal::service('commerce_escrow.webhook_secret')->get();
  return (string) t('All Escrow payment gateways now share one webhook URL. Copy it from any Escrow payment gateway and register it on Escrow.com, then delete any older Escrow webhooks of this site there. A URL per gateway from 1.1.0 keeps working until you stop accepting it on the payment gateway.');
}
