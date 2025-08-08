<?php

namespace Drupal\commerce_escrow\Plugin\Commerce\Condition;

use Drupal\commerce_escrow\Entity\EscrowItemInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce\Attribute\CommerceCondition;
use Drupal\commerce\Plugin\Commerce\Condition\ConditionBase;

/**
 * Provides condition for order which has brokered product variation.
 */
#[CommerceCondition(
  id: "order_brokered_variation",
  label: new TranslatableMarkup("Order contains brokered product variation"),
  entity_type: "commerce_order",
  display_label: new TranslatableMarkup("Order contains brokered product variation"),
  category: new TranslatableMarkup("Products"),
)]
class BrokeredOrderVariation extends ConditionBase {

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $entity): bool {
    $this->assertEntity($entity);
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $entity;
    foreach ($order->getItems() as $order_item) {
      $purchased_entity = $order_item->getPurchasedEntity();
      if ($purchased_entity instanceof EscrowItemInterface && $purchased_entity->isBrokered()) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
