<?php

namespace Drupal\commerce_escrow_test\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway\EscrowPay;
use Drupal\commerce_escrow\PluginForm\OffsiteRedirect\PaymentOffsiteForm;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * An Escrow Pay gateway that reads transactions from State.
 *
 * Set the State key commerce_escrow_test.transaction to what Escrow.com holds.
 */
#[CommercePaymentGateway(
  id: 'test_escrow_pay',
  label: new TranslatableMarkup('Test Escrow Pay'),
  display_label: new TranslatableMarkup('Test Escrow Pay'),
  forms: [
    'offsite-payment' => PaymentOffsiteForm::class,
  ],
  payment_type: 'escrow_payment',
)]
class TestEscrowPay extends EscrowPay {

  /**
   * {@inheritdoc}
   */
  public function getTransaction(int $transaction_id): array {
    return \Drupal::state()->get('commerce_escrow_test.transaction', []) + ['id' => $transaction_id];
  }

}
