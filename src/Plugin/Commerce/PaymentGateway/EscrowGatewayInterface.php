<?php

namespace Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsVoidsInterface;
use Drupal\Core\Url;

interface EscrowGatewayInterface extends SupportsVoidsInterface {

  /**
   * Create Escrow Pay via API.
   */
  public function initiateEscrow(OrderInterface $order): array;

  /**
   * Create new Drupal payment based of initial response from Escrow.
   */
  public function createPayment(PaymentInterface $payment, array $escrow_response): void;

  /**
   * Fetch remote by order.
   */
  public function getEscrowByOder(OrderInterface $order): array;

  /**
   * Fetch remote transaction by id.
   */
  public function getTransaction(int $transaction_id): array;

  /**
   * Determine if the remote transaction was created for the order.
   *
   * Escrow.com does not sign webhooks, so the transaction must be matched
   * against data stored during checkout, not against the webhook payload.
   */
  public function isOrderTransaction(OrderInterface $order, array $transaction): bool;

  /**
   * Load payments of the order made through this gateway.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface[]
   *   The payments.
   */
  public function loadOrderPayments(OrderInterface $order): array;

  /**
   * Get the webhook URL to register on Escrow.com.
   */
  public function getWebhookUrl(): ?Url;

  /**
   * Validate the secret token from the webhook URL.
   */
  public function isValidWebhookToken(string $token): bool;

}
