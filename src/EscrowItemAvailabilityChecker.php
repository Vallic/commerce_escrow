<?php

namespace Drupal\commerce_escrow;

use Drupal\commerce\Context;
use Drupal\commerce_escrow\Entity\EscrowItemInterface;
use Drupal\commerce_order\AvailabilityCheckerInterface;
use Drupal\commerce_order\AvailabilityResult;
use Drupal\commerce_order\Entity\OrderItemInterface;

/**
 * The availability checker.
 */
class EscrowItemAvailabilityChecker implements AvailabilityCheckerInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(OrderItemInterface $order_item): bool {
    $purchased_entity = $order_item->getPurchasedEntity();
    return $purchased_entity instanceof EscrowItemInterface;
  }

  /**
   * {@inheritdoc}
   */
  public function check(OrderItemInterface $order_item, Context $context): AvailabilityResult {
    $purchased_entity = $order_item->getPurchasedEntity();
    if (($purchased_entity instanceof EscrowItemInterface) && !$purchased_entity->inStock()) {
      return new AvailabilityResult(FALSE, $purchased_entity->isSingleItem() ? 'Item is either reserved or already purchased.' : 'Out of stock');
    }

    return AvailabilityResult::neutral();
  }

}
