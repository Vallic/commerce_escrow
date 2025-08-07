<?php

namespace Drupal\commerce_escrow\Event;

final class EscrowEvents {

  /**
   * Name of the event fired before the transaction payload is sent to Escrow.
   *
   * Allow altering the payload of transaction before is sent to Escrow.
   *
   * @Event
   *
   * @see \Drupal\commerce_escrow\Event\EscrowOrderPayloadEvent
   */
  const string ESCROW_ORDER_PAYLOAD = 'escrow_order_payload';

  /**
   * Name of the event fired during the webhook update.
   *
   * Allow reacting after Escrow sent webhook updates.
   *
   * @Event
   *
   * @see \Drupal\commerce_escrow\Event\EscrowWebhookEvent
   */
  const string ESCROW_WEBHOOK = 'escrow_webhook';

}
