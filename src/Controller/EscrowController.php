<?php

namespace Drupal\commerce_escrow\Controller;

use Drupal\commerce_escrow\Entity\EscrowItemInterface;
use Drupal\commerce_escrow\EscrowWebhookSecret;
use Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway\EscrowGatewayInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_escrow\Event\EscrowEvents;
use Drupal\commerce_escrow\Event\EscrowWebhookEvent;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Lock\LockBackendInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\commerce_order\OrderRefreshInterface;

class EscrowController extends ControllerBase {

  /**
   * Transaction item types which are fees, not goods sold to the buyer.
   */
  protected const array FEE_ITEM_TYPES = ['broker_fee', 'partner_fee', 'shipping_fee'];

  /**
   * Seconds the lock on an order lasts if a webhook dies holding it.
   */
  protected const float LOCK_TIMEOUT = 30.0;

  /**
   * Seconds to wait for another webhook of the same order.
   *
   * Short of the 10 seconds Escrow.com waits for an answer.
   */
  protected const int LOCK_WAIT = 5;

  protected EventDispatcherInterface $eventDispatcher;

  protected OrderRefreshInterface $orderRefresh;

  protected LockBackendInterface $lock;

  protected EscrowWebhookSecret $webhookSecret;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->eventDispatcher = $container->get('event_dispatcher');
    $instance->orderRefresh = $container->get('commerce_order.order_refresh');
    $instance->lock = $container->get('lock');
    $instance->webhookSecret = $container->get('commerce_escrow.webhook_secret');
    return $instance;
  }

  /**
   * Checks the secret token of the webhook URL.
   */
  public function access(string $token): AccessResultInterface {
    return AccessResult::allowedIf($this->webhookSecret->isValid($token))->setCacheMaxAge(0);
  }

  /**
   * Handles the webhook shared by all Escrow gateways, one at a time per order.
   *
   * Escrow.com retries a webhook that fails or takes over 10 seconds, so the
   * same event can arrive again, even while the first is still being handled.
   * The one that waits then finds the order already updated.
   */
  public function webhook(Request $request): JsonResponse {
    $payload = Json::decode($request->getContent());

    if (!$this->isValidPayload($payload)) {
      return new JsonResponse(['error' => 'invalid payload'], 400);
    }

    $lock_name = 'commerce_escrow_webhook:' . $payload['reference'];
    if (!$this->lock->acquire($lock_name, self::LOCK_TIMEOUT)) {
      $this->lock->wait($lock_name, self::LOCK_WAIT);
      if (!$this->lock->acquire($lock_name, self::LOCK_TIMEOUT)) {
        // Escrow.com sends it again later.
        return new JsonResponse(['error' => 'busy'], 503);
      }
    }

    try {
      return $this->processWebhook($payload);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Processes an Escrow webhook.
   *
   * Escrow.com does not sign webhooks, so the payload is only used to know
   * which transaction changed. The transaction is fetched from the API and
   * must belong to the order and confirm the event before anything changes.
   *
   * @see https://www.escrow.com/api/docs/webhooks
   */
  protected function processWebhook(array $payload): JsonResponse {
    $transaction_id = (int) $payload['transaction_id'];
    $logger = $this->getLogger('commerce_escrow');
    $order_storage = $this->entityTypeManager->getStorage('commerce_order');

    // Unchanged, as a webhook may just have updated it.
    /** @var \Drupal\commerce_order\Entity\OrderInterface|null $order */
    $order = $order_storage->loadUnchanged($payload['reference']);

    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface|null $commerce_payment_gateway */
    $commerce_payment_gateway = $order?->get('payment_gateway')->entity;
    $payment_gateway_plugin = $commerce_payment_gateway?->getPlugin();

    // Escrow.com sends events of all transactions on the account to every
    // webhook, ignore orders which are not paid through an Escrow gateway.
    if (!$payment_gateway_plugin instanceof EscrowGatewayInterface) {
      return new JsonResponse([]);
    }

    try {
      $transaction = $payment_gateway_plugin->getTransaction($transaction_id);
      $is_order_transaction = (int) ($transaction['id'] ?? 0) === $transaction_id && $payment_gateway_plugin->isOrderTransaction($order, $transaction);
    }
    catch (\Exception $exception) {
      $logger->error('Unable to verify Escrow.com transaction @transaction_id for order @order_id: @message', [
        '@transaction_id' => $transaction_id,
        '@order_id' => $order->id(),
        '@message' => $exception->getMessage(),
      ]);
      return new JsonResponse(['error' => 'invalid transaction'], 400);
    }

    if (!$is_order_transaction) {
      $logger->warning('Escrow.com transaction @transaction_id does not belong to order @order_id.', [
        '@transaction_id' => $transaction_id,
        '@order_id' => $order->id(),
      ]);
      return new JsonResponse(['error' => 'invalid transaction'], 400);
    }

    if (!$this->isEventConfirmed($payload['event'], $transaction)) {
      $logger->warning('Escrow.com transaction @transaction_id does not confirm the "@event" event for order @order_id.', [
        '@transaction_id' => $transaction_id,
        '@event' => $payload['event'],
        '@order_id' => $order->id(),
      ]);
      return new JsonResponse(['error' => 'invalid event'], 400);
    }

    // When we're dealing with an offer order type, we don't get redirected
    // back. So we need to unlock order and place it.
    if ($order->getState()->getId() === 'draft') {
      $order->getState()->applyTransitionById('place');
      $order->unlock();
      $order->save();
    }

    $escrow_offer = $commerce_payment_gateway->getPluginId() === 'escrow_offer';

    /** @var \Drupal\commerce_payment\PaymentStorageInterface $payment_storage */
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');

    $payments = $payment_gateway_plugin->loadOrderPayments($order);
    $payment = NULL;
    foreach ($payments as $order_payment) {
      if ((string) $order_payment->getRemoteId() === (string) $transaction_id) {
        $payment = $order_payment;
      }
    }

    if (!$payment) {
      // Special case for escrow offer. We may have a payment in the system,
      // but without remote id.
      if ($payload['event'] === 'create' && $escrow_offer && $payments) {
        foreach ($payments as $payment) {
          $payment->setRemoteId($transaction_id);
          $payment->save();
        }
      }
      else {
        $payment = $payment_storage->create([
          'state' => 'new',
          'amount' => $order->getBalance(),
          'payment_gateway' => $commerce_payment_gateway->id(),
          'order_id' => $order->id(),
          'remote_id' => $transaction_id,
          'remote_state' => 'create',
        ]);

        $payment->save();
      }
    }

    $payment->setRemoteState($payload['event']);
    $payment->save();

    // During escrow offer, once it's accepted, we need to
    // update Drupal order to match the new amount.
    if ($escrow_offer && $payload['event'] === 'payment_sent') {
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
    $event = new EscrowWebhookEvent($order, $payload, $transaction);
    $this->eventDispatcher->dispatch($event, EscrowEvents::ESCROW_WEBHOOK);
    $payload = $event->getPayload();

    // Flag to stop the webhook if someone want's to build different logic.
    if ($event->stopWebhook()) {
      return new JsonResponse([], 200);
    }

    $event = $payload['event'];

    if (!$this->isLastLoggedEvent($order, $event, $transaction_id)) {
      $this->entityTypeManager->getStorage('commerce_log')->generate($order, 'escrow_event', [
        'description' => EscrowItemInterface::ESCROW_TRANSACTION_EVENTS[$payload['event']] ?? '',
        'transaction_id' => $transaction_id,
        'event' => $event,
      ])->save();
    }

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

  /**
   * Determines whether the order's latest Escrow log entry is this event.
   *
   * It then arrived again, and the log needs no second line for it.
   */
  protected function isLastLoggedEvent(OrderInterface $order, string $event, int $transaction_id): bool {
    /** @var \Drupal\commerce_log\LogStorageInterface $log_storage */
    $log_storage = $this->entityTypeManager->getStorage('commerce_log');
    $logs = array_filter($log_storage->loadMultipleByEntity($order), static fn($log) => $log->getTemplateId() === 'escrow_event');
    if (!$logs) {
      return FALSE;
    }
    ksort($logs);
    $params = end($logs)->getParams();
    return ($params['event'] ?? NULL) === $event && (int) ($params['transaction_id'] ?? 0) === $transaction_id;
  }

  /**
   * Validate the structure of the webhook payload.
   */
  protected function isValidPayload(mixed $payload): bool {
    if (!is_array($payload) || !isset($payload['event'], $payload['event_type'], $payload['transaction_id'], $payload['reference'])) {
      return FALSE;
    }

    return is_string($payload['event'])
      && isset(EscrowItemInterface::ESCROW_TRANSACTION_EVENTS[$payload['event']])
      && filter_var($payload['transaction_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== FALSE
      && (is_int($payload['reference']) || is_string($payload['reference']));
  }

  /**
   * Determine if the transaction on Escrow.com confirms the webhook event.
   *
   * Status flags only change forward, so delayed webhooks still match.
   *
   * @see https://www.escrow.com/api/docs/reference
   */
  protected function isEventConfirmed(string $event, array $transaction): bool {
    $items = array_filter($transaction['items'] ?? [], static function ($item) {
      return is_array($item) && !in_array($item['type'] ?? '', self::FEE_ITEM_TYPES, TRUE);
    });

    $item_statuses = [];
    $schedule_statuses = [];
    foreach ($items as $item) {
      $item_statuses[] = $item['status'] ?? [];
      foreach ($item['schedule'] ?? [] as $schedule) {
        $schedule_statuses[] = $schedule['status'] ?? [];
      }
    }

    $cancelled = !empty($transaction['is_cancelled']);

    return match ($event) {
      // Nothing to compare with, the transaction itself is verified already.
      'create', 'party_verification_submitted', 'party_verification_approved', 'party_verification_rejected' => TRUE,
      'agree' => $this->allFlagged($transaction['parties'] ?? [], 'agreed'),
      'offer_accepted' => empty($transaction['is_draft']) && !$cancelled,
      'payment_sent' => $this->anyFlagged($schedule_statuses, 'payment_sent', 'payment_received', 'secured'),
      'payment_received' => $this->anyFlagged($schedule_statuses, 'payment_received', 'secured'),
      'payment_approved' => $this->anyFlagged($schedule_statuses, 'secured'),
      'payment_rejected' => !$this->anyFlagged($schedule_statuses, 'secured'),
      'payment_refunded' => $this->anyFlagged($schedule_statuses, 'disbursed_to_payer', 'refund_resolved'),
      'payment_disbursed' => $this->anyFlagged($schedule_statuses, 'disbursed_to_beneficiary'),
      'refund_resolved' => $this->anyFlagged($schedule_statuses, 'refund_resolved'),
      'refund_rejected' => $this->anyFlagged($schedule_statuses, 'refund_rejected'),
      'ship' => $this->anyFlagged($item_statuses, 'shipped'),
      'receive' => $this->anyFlagged($item_statuses, 'received'),
      'accept' => $this->anyFlagged($item_statuses, 'accepted'),
      'reject' => $this->anyFlagged($item_statuses, 'rejected'),
      'ship_return' => $this->anyFlagged($item_statuses, 'shipped_returned'),
      'receive_return' => $this->anyFlagged($item_statuses, 'received_returned'),
      'accept_return' => $this->anyFlagged($item_statuses, 'accepted_returned'),
      'reject_return' => $this->anyFlagged($item_statuses, 'rejected_returned'),
      'complete' => !empty($transaction['close_date']) && !$cancelled,
      'cancel' => $cancelled || $this->allFlagged($item_statuses, 'canceled'),
      default => FALSE,
    };
  }

  /**
   * Determine if every entry has the flag set.
   */
  protected function allFlagged(array $entries, string $flag): bool {
    return $entries && count(array_filter(array_column($entries, $flag))) === count($entries);
  }

  /**
   * Determine if any entry has any of the flags set.
   */
  protected function anyFlagged(array $entries, string ...$flags): bool {
    foreach ($flags as $flag) {
      if (array_filter(array_column($entries, $flag))) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
