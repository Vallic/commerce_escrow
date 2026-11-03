<?php

namespace Drupal\commerce_escrow\Event;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\EventDispatcher\Event;

class EscrowWebhookEvent extends Event {

  protected bool $result;

  /**
   * Constructs a new EscrowWebhookEvent object.
   */
  public function __construct(protected OrderInterface $order, protected array $payload, protected array $transaction = []) {}

  /**
   * Gets the webhook payload.
   *
   * @return array
   *   The payload.
   */
  public function getPayload(): array {
    return $this->payload;
  }

  /**
   * Gets the transaction fetched from Escrow.com, verified for the order.
   *
   * Prefer this over the payload, as webhooks are not signed by Escrow.com.
   */
  public function getTransaction(): array {
    return $this->transaction;
  }

  /**
   * Get the order.
   */
  public function getOrder(): OrderInterface {
    return $this->order;
  }

  /**
   * Set new payload.
   */
  public function setPayload(array $payload): self {
    $this->payload = $payload;
    return $this;
  }

  /**
   * Stop the webhook.
   */
  public function setStopWebhook(): self {
    $this->result = TRUE;
    return $this;
  }

  /**
   * Set new payload.
   */
  public function stopWebhook(): bool {
    return $this->result ?? FALSE;
  }

}
