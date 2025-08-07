<?php

namespace Drupal\commerce_escrow\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Implements the Payment offsite form.
 */
class PaymentOffsiteForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;

    /** @var \Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway\EscrowGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

    try {
      $escrow_pay = $payment_gateway_plugin->initiateEscrow($payment->getOrder());
    }
    catch (\Exception $e) {
      throw new PaymentGatewayException($e->getMessage());
    }

    $payment_gateway_plugin->createPayment($payment, $escrow_pay);

    return $this->buildRedirectForm($form, $form_state, $escrow_pay['landing_page'] ?? $escrow_pay['make_offer_page'], [], 'get');
  }

}
