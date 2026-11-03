<?php

namespace Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_escrow\PluginForm\OffsiteRedirect\PaymentOffsiteForm;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
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

  /**
   * {@inheritdoc}
   */
  public function isOrderTransaction(OrderInterface $order, array $transaction): bool {
    $auction_id = (string) ($transaction['auction_id'] ?? '');
    if ($auction_id === '') {
      return FALSE;
    }

    // Offers are made by buyers on Escrow.com, so the transaction is only
    // known through the auction created during checkout.
    foreach ($this->loadOrderPayments($order) as $payment) {
      if ($this->getAuctionId($payment) === $auction_id) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Get the id of the auction created during checkout for the payment.
   */
  protected function getAuctionId(PaymentInterface $payment): ?string {
    if (!$payment->hasField('auction_id') || !$payment->hasField('token')) {
      return NULL;
    }

    if (!$payment->get('auction_id')->isEmpty()) {
      return (string) $payment->get('auction_id')->value;
    }

    // Creating an auction returns only the token, resolve the id once.
    $token = $payment->get('token')->value;
    if (empty($token)) {
      return NULL;
    }

    $auction = $this->escrowClient->getEscrowAuction($token);
    if (empty($auction['id'])) {
      return NULL;
    }

    $payment->set('auction_id', (string) $auction['id']);
    $payment->save();

    return (string) $auction['id'];
  }

}
