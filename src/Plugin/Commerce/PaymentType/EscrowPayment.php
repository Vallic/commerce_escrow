<?php

namespace Drupal\commerce_escrow\Plugin\Commerce\PaymentType;

use Drupal\commerce_payment\Plugin\Commerce\PaymentType\PaymentTypeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_payment\Attribute\CommercePaymentType;
use Drupal\entity\BundleFieldDefinition;

/**
 * Provides the Escrow payment type.
 */
#[CommercePaymentType(
  id: "escrow_payment",
  label: new TranslatableMarkup("Escrow payment"),
  workflow: "escrow_payment"
)]
class EscrowPayment extends PaymentTypeBase {

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions() {
    $fields = [];

    $fields['token'] = BundleFieldDefinition::create('string')
      ->setLabel($this->t('Token'))
      ->setDescription($this->t('The remote token.'))
      ->setRequired(TRUE);

    $fields['auction_id'] = BundleFieldDefinition::create('string')
      ->setLabel($this->t('Auction ID'))
      ->setDescription($this->t('The auction ID.'))
      ->setRequired(FALSE);

    return $fields;

  }

}
