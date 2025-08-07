<?php

namespace Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsVoidsInterface;

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

}
