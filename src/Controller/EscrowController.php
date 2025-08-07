<?php

namespace Drupal\commerce_escrow\Controller;

use Drupal\commerce_escrow\Entity\EscrowItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_escrow\Event\EscrowEvents;
use Drupal\commerce_escrow\Event\EscrowWebhookEvent;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\commerce_order\OrderRefreshInterface;

class EscrowController extends ControllerBase {

  protected EventDispatcherInterface $eventDispatcher;

  protected OrderRefreshInterface $orderRefresh;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->eventDispatcher = $container->get('event_dispatcher');
    $instance->orderRefresh = $container->get('commerce_order.order_refresh');
    return $instance;
  }

  /**
   * Process Escrow webhooks.
   *
   * @see https://www.escrow.com/api/docs/webhooks
   */
  public function webhook(Request $request): JsonResponse {
    $body = $request->getContent();
    $payload = Json::decode($body);

    if (!isset($payload['event'], $payload['event_type'], $payload['transaction_id'], $payload['reference'])) {
      return new JsonResponse(['error' => 'invalid payload'], 400);
    }

    $order_storage = $this->entityTypeManager->getStorage('commerce_order');

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $order_storage->load($payload['reference']);
    if (!$order) {
      return new JsonResponse(['error' => 'invalid order'], 400);
    }

    // When we're dealing with an offer order type, we don't get redirected
    // back. So we need to unlock order and place it.
    if ($order->getState()->getId() === 'draft') {
      $order->getState()->applyTransitionById('place');
      $order->unlock();
      $order->save();
    }

    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $order->get('payment_gateway')->entity;
    $escrow_offer = $payment_gateway->getPluginId() === 'escrow_offer';

    /** @var \Drupal\commerce_payment\PaymentStorageInterface $payment_storage */
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');

    $payment = $payment_storage->loadByRemoteId($payload['transaction_id']);
    if (!$payment) {
      // Special case for escrow offer.
      if ($payload['event_type'] === 'create' && $escrow_offer) {
        $payments = $payment_storage->loadMultipleByOrder($order);
        foreach ($payments as $payment) {
          $payment->setRemoteId($payload['transaction_id']);
          $payment->save();
        }
      }
      $payment = $payment_storage->create([
        'state' => 'new',
        'amount' => $order->getBalance(),
        'payment_gateway' => $order->get('payment_gateway')->entity->id(),
        'order_id' => $order->id(),
        'remote_id' => $payload['transaction_id'],
        'remote_state' => 'create',
      ]);

      $payment->save();
    }

    $payment->setRemoteState($payload['event']);
    $payment->save();

    // During escrow offer, once it's accepted, we need to
    // update Drupal order to match the new amount.
    if ($escrow_offer && $payload['event'] === 'payment_sent') {
      $transaction = $payment_gateway->getPlugin()->getEscrowByOder($order);
      $order_items_amounts = [];
      $payment_amount = new Price('0', $order->getTotalPrice()->getCurrencyCode());
      foreach ($transaction['items'] as $key => $item) {
        $item_amount = new Price('0', $order->getTotalPrice()->getCurrencyCode());
        foreach ($item['schedule'] as $schedule) {
          $item_amount = $item_amount->add(new Price((string) $schedule['amount'], $order->getTotalPrice()->getCurrencyCode()));
        }

        $order_items_amounts[$key] = $item_amount;
        $payment_amount = $payment_amount->add($item_amount);
      }

      foreach ($order->getItems() as $key => $item) {
        $item->setUnitPrice($order_items_amounts[$key], TRUE);
        $item->save();
      }
      $this->orderRefresh->refresh($order);
      $order->save();
      $payment->setAmount($payment_amount);
      $payment->save();
    }

    // Trigger event to react on Escrow webhook updates.
    $event = new EscrowWebhookEvent($order, $payload);
    $this->eventDispatcher->dispatch($event, EscrowEvents::ESCROW_WEBHOOK);
    $payload = $event->getPayload();

    // Flag to stop the webhook if someone want's to build different logic.
    if ($event->stopWebhook()) {
      return new JsonResponse([], 200);
    }

    $event = $payload['event'];

    $log_storage = $this->entityTypeManager->getStorage('commerce_log');

    $log_storage->generate($order, 'escrow_event', [
      'description' => EscrowItemInterface::ESCROW_TRANSACTION_EVENTS[$payload['event']] ?? '',
      'transaction_id' => $payload['transaction_id'],
    ])->save();

    $transitions = $order->getState()->getTransitions();

    if (isset($transitions[$event]) && $order->getState()->isTransitionAllowed($event)) {
      $order->getState()->applyTransitionById($event);
      $order->save();
    }

    $payment_transitions = $payment->getState()->getTransitions();
    if (isset($payment_transitions[$event]) && $payment->getState()->isTransitionAllowed($event)) {
      $payment->getState()->applyTransitionById($event);
      $payment->save();
    }

    return new JsonResponse([]);
  }

}
