<?php

namespace Drupal\commerce_escrow\EventSubscriber;

use Drupal\commerce_escrow\Entity\EscrowItemInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderPlaceSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [
      'commerce_order.place.post_transition' => ['inStockEscrow', -30],
    ];
    return $events;
  }

  /**
   * Mark a single item purchased entity as out of stock.
   */
  public function inStockEscrow(WorkflowTransitionEvent $event): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();

    foreach ($order->getItems() as $item) {
      $purchased_entity = $item->getPurchasedEntity();
      if ($purchased_entity instanceof EscrowItemInterface && $purchased_entity->isSingleItem()) {
        $purchased_entity->set('in_stock', 0);
        $purchased_entity->save();
      }
    }
  }

}
