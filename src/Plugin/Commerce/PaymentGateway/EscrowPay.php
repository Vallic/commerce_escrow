<?php

namespace Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_escrow\PluginForm\OffsiteRedirect\PaymentOffsiteForm;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides the Escrow Pay offsite gateway.
 */
#[CommercePaymentGateway(
  id: "escrow_pay",
  label: new TranslatableMarkup("Escrow Pay"),
  display_label: new TranslatableMarkup("Escrow Pay"),
  forms: [
    "offsite-payment" => PaymentOffsiteForm::class,
  ],
  payment_type: "escrow_payment",
  requires_billing_information: TRUE,
)]
class EscrowPay extends OffsitePaymentGatewayBase implements EscrowGatewayInterface {

  use EscrowTrait;

  /**
   * {@inheritdoc}
   */
  public function initiateEscrow(OrderInterface $order): array {
    $payload = $this->buildOrderPayload($order);
    return $this->escrowClient->createEscrowPay($payload);
  }

  /**
   * {@inheritdoc}
   */
  public function isOrderTransaction(OrderInterface $order, array $transaction): bool {
    $transaction_id = (string) ($transaction['id'] ?? '');
    if ($transaction_id === '') {
      return FALSE;
    }

    // Escrow Pay transaction is created during checkout and its id
    // is stored as the remote id of the payment.
    foreach ($this->loadOrderPayments($order) as $payment) {
      if ((string) $payment->getRemoteId() === $transaction_id) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
