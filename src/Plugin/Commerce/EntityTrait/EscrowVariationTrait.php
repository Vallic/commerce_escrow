<?php

namespace Drupal\commerce_escrow\Plugin\Commerce\EntityTrait;

use Drupal\commerce_escrow\Entity\EscrowItem;
use Drupal\commerce_escrow\Entity\EscrowItemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce\Plugin\Commerce\EntityTrait\EntityTraitBase;
use Drupal\entity\BundleFieldDefinition;
use Drupal\commerce\Attribute\CommerceEntityTrait;

/**
 * Provides the "purchasable_entity_escrow_item" trait.
 */
#[CommerceEntityTrait(
  id: "purchasable_entity_escrow_item",
  label: new TranslatableMarkup("Escrow Item"),
  entity_types: ["commerce_product_variation"]
)]
class EscrowVariationTrait extends EntityTraitBase {

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions(): array {
    $fields = [];
    $fields['escrow_type'] = BundleFieldDefinition::create('list_string')
      ->setLabel(t('Escrow item type'))
      ->setDescription(t('The item type. Goods or services that the buyer is purchasing. Currently the only item types that can have multiple schedules are milestone and domain_name_holding'))
      ->setRequired(TRUE)
      ->setCardinality(1)
      ->setSetting('allowed_values', EscrowItem::getEscrowTypePurchase())
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['inspection_period'] = BundleFieldDefinition::create('integer')
      ->setLabel(t('Inspection period'))
      ->setDescription(t('The length of the inspection period in days. Minimum of 1 and maximum 30 days allowed.'))
      ->setSetting('display_description', TRUE)
      ->setSetting('min', 1)
      ->setSetting('max', 30)
      ->setDefaultValue(7)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['escrow_fee'] = BundleFieldDefinition::create('list_string')
      ->setLabel(t('Escrow Fee'))
      ->setRequired(TRUE)
      ->setCardinality(1)
      ->setDescription(t('This field is used to set who should pay the escrow fee. Default is buyer.'))
      ->setSetting('display_description', TRUE)
      ->setSetting('allowed_values', EscrowItem::getEscrowSplitAmounts())
      ->setDefaultValue(EscrowItemInterface::ESCROW_FEE_PAYS_BUYER)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['brokered'] = BundleFieldDefinition::create('boolean')
      ->setLabel(t('Brokered'))
      ->setDescription(t('Mark that domain sold is being brokered for another seller (owner of product variation) and buyer.'))
      ->setSetting('display_description', TRUE)
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 3,
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['broker_fee'] = BundleFieldDefinition::create('integer')
      ->setLabel(t('Broker Fee percentage'))
      ->setDescription(t('Enter a percentage which broker earns if the domain is brokered between another seller and buyer.'))
      ->setSetting('display_description', TRUE)
      ->setDefaultValue(15)
      ->setSetting('max', 100)
      ->setSetting('suffix', '%')
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['broker_fee_split'] = BundleFieldDefinition::create('list_string')
      ->setLabel(t('Broker Fee split'))
      ->setDescription(t('This field is used to set who should pay the broker fee. Seller part of broker fee is deducted from the price. The buyer one is added on top of the existing price.'))
      ->setSetting('display_description', TRUE)
      ->setSetting('allowed_values', EscrowItem::getEscrowSplitAmounts())
      ->setDefaultValue(EscrowItemInterface::ESCROW_FEE_PAYS_SELLER)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['extra_attributes'] = BundleFieldDefinition::create('list_string')
      ->setLabel(t('Extra attributes fees'))
      ->setRequired(FALSE)
      ->setCardinality(1)
      ->setDescription(t('You can enable one of the fees to be required. The concierge can be used with domains. The other one with physical goods.'))
      ->setSetting('display_description', TRUE)
      ->setSetting('allowed_values', EscrowItem::getEscrowExtraFees())
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 6,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['single_item'] = BundleFieldDefinition::create('boolean')
      ->setLabel(t('Single item'))
      ->setDescription(t('This variation has always quantity of 1.'))
      ->setSetting('display_description', TRUE)
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 7,
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['in_stock'] = BundleFieldDefinition::create('boolean')
      ->setLabel(t('In stock'))
      ->setDescription(t('Determine if product is available or not. In combination with Single item field, it marks variation out of stock after order placement if there is order placed with this variation.'))
      ->setSetting('display_description', TRUE)
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 8,
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['display_fee'] = BundleFieldDefinition::create('boolean')
      ->setLabel(t('Display Escrow fee information'))
      ->setDescription(t('Informational estimates about potential escrow fees information within Drupal order based of split logic who pays which fee.'))
      ->setSetting('display_description', TRUE)
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 9,
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);

    return $fields;
  }

}
