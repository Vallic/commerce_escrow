<?php

namespace Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_escrow\PluginForm\OffsiteRedirect\PaymentOffsiteForm;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides the Escrow Offer offsite gateway.
 */
#[CommercePaymentGateway(
  id: "escrow_offer",
  label: new TranslatableMarkup("Escrow Offer"),
  display_label: new TranslatableMarkup("Escrow Offer"),
  forms: [
    "offsite-payment" => PaymentOffsiteForm::class,
  ],
  payment_type: "escrow_payment",
  requires_billing_information: TRUE,
)]
class EscrowOffer extends OffsitePaymentGatewayBase implements EscrowGatewayInterface {

  use EscrowTrait;

  /**
   * {@inheritdoc}
   */
  public function initiateEscrow(OrderInterface $order): array {
    $payload = $this->buildOrderPayload($order);
    $payload = ['transaction' => $payload];
    return $this->escrowClient->createEscrowAuction($payload);
  }

}
