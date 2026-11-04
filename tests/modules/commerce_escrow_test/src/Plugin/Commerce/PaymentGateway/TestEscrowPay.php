<?php

namespace Drupal\commerce_escrow_test\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway\EscrowPay;
use Drupal\commerce_escrow\PluginForm\OffsiteRedirect\PaymentOffsiteForm;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * The state holding the transaction.
   */
  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    /** @var static $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->state = $container->get('state');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getTransaction(int $transaction_id): array {
    return $this->state->get('commerce_escrow_test.transaction', []) + ['id' => $transaction_id];
  }

}
