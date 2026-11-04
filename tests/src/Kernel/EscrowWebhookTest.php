<?php

namespace Drupal\Tests\commerce_escrow\Kernel;

use Drupal\commerce_escrow\Controller\EscrowController;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormState;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the Escrow.com webhook.
 */
#[Group('commerce_escrow')]
#[RunTestsInSeparateProcesses]
class EscrowWebhookTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_log',
    'commerce_payment',
    'commerce_escrow',
    'commerce_escrow_test',
  ];

  /**
   * The Escrow gateways: one and two.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface[]
   */
  protected array $gateways = [];

  /**
   * The next transaction id.
   */
  protected int $transactionId = 100;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('commerce_log');
    $this->installConfig(['commerce_payment', 'commerce_escrow']);
    $this->container->get('router.builder')->rebuild();
    OrderType::load('default')->setWorkflowId('escrow_workflow')->save();
    foreach (['one', 'two'] as $id) {
      $gateway = PaymentGateway::create([
        'id' => $id,
        'label' => $id,
        'plugin' => 'test_escrow_pay',
        'status' => TRUE,
      ]);
      $gateway->save();
      $this->gateways[$id] = $gateway;
    }
  }

  /**
   * Tests that the gateways share one URL, ending in the secret.
   */
  public function testSharedUrl(): void {
    $one = $this->gateways['one']->getPlugin()->getWebhookUrl()->toString();
    $this->assertSame($one, $this->gateways['two']->getPlugin()->getWebhookUrl()->toString());
    $this->assertStringEndsWith('/payment/webhook/escrow/' . $this->secret(), $one);
    $this->assertSame(43, strlen($this->secret()));
    $this->assertEmpty($this->gateways['one']->getPluginConfiguration()['webhook_secret'] ?? '', 'The secret is not on the gateway.');
    $this->assertSame($this->secret(), $this->config('commerce_escrow.settings')->get('webhook_secret'));
  }

  /**
   * Tests a secret set in settings.php.
   */
  public function testSecretFromSettings(): void {
    [$order, $payment] = $this->orderOn($this->gateways['one']);
    $event = $this->event('payment_approved', $order, $payment);
    $secret = $this->container->get('commerce_escrow.webhook_secret');
    $plugin = $this->gateways['one']->getPlugin();

    $this->override('from-settings-php');
    $this->assertTrue($secret->isOverridden());
    $this->assertSame('from-settings-php', $secret->get());
    $this->assertStringEndsWith('/payment/webhook/escrow/from-settings-php', $plugin->getWebhookUrl()->toString());
    $this->assertSame('', (string) $this->container->get('config.factory')->getEditable('commerce_escrow.settings')->get('webhook_secret'), 'Nothing is stored.');
    $this->assertArrayNotHasKey('regenerate_webhook_secret', $plugin->buildConfigurationForm(['#parents' => []], new FormState()));
    $this->assertSame(200, $this->post('/payment/webhook/escrow/from-settings-php', $event, $this->secured())->getStatusCode());
    $this->assertSame('payment_approved', $this->reload($order)->getState()->getId());

    // A stored secret doesn't open the URL while it is overridden.
    $secret->regenerate();
    $stored = $this->container->get('config.factory')->getEditable('commerce_escrow.settings')->get('webhook_secret');
    $this->assertNotEmpty($stored);
    $this->assertFalse($secret->isValid($stored));
    $this->assertTrue($secret->isValid('from-settings-php'));
  }

  /**
   * Tests that an environment variable that isn't set closes the webhook.
   *
   * @param mixed $value
   *   What settings.php overrides the secret with; getenv() returns FALSE.
   */
  #[DataProvider('emptyOverrides')]
  public function testEmptyOverrideClosesWebhook(mixed $value): void {
    // A stored secret must not stand in for the missing one.
    $this->secret();
    $this->override($value);
    $secret = $this->container->get('commerce_escrow.webhook_secret');
    $plugin = $this->gateways['one']->getPlugin();

    $this->assertSame('', $secret->get());
    $this->assertFalse($secret->isValid(''));
    $this->assertNull($plugin->getWebhookUrl());
    $form = $plugin->buildConfigurationForm(['#parents' => []], new FormState());
    $this->assertStringContainsString('overridden with an empty value', (string) $form['webhook_url']['#plain_text']);
    $this->assertArrayNotHasKey('regenerate_webhook_secret', $form);
    [$order, $payment] = $this->orderOn($this->gateways['one']);
    $this->assertSame(403, $this->post('/payment/webhook/escrow/anything', $this->event('payment_approved', $order, $payment), $this->secured())->getStatusCode());
    $this->assertSame('validation', $this->reload($order)->getState()->getId());
  }

  /**
   * Returns the empty values an override can have.
   */
  public static function emptyOverrides(): array {
    return ['getenv() of a missing variable' => [FALSE], 'an empty string' => ['']];
  }

  /**
   * Tests that one URL updates orders of every gateway.
   */
  public function testHandlesOrdersOfEveryGateway(): void {
    foreach ($this->gateways as $gateway) {
      [$order, $payment] = $this->orderOn($gateway);
      $response = $this->post($this->url(), $this->event('payment_approved', $order, $payment), $this->secured());
      $this->assertSame(200, $response->getStatusCode());
      $this->assertSame('payment_approved', $this->reload($order)->getState()->getId());
      $this->assertSame('approved', $this->reload($payment)->getState()->getId());
    }
  }

  /**
   * Tests that only the secret opens the URL.
   */
  public function testWrongToken(): void {
    [$order, $payment] = $this->orderOn($this->gateways['one']);
    $this->secret();
    $event = $this->event('payment_approved', $order, $payment);
    $this->assertSame(403, $this->post('/payment/webhook/escrow/wrong', $event, $this->secured())->getStatusCode());
    $this->assertSame('validation', $this->reload($order)->getState()->getId());

    $old = $this->url();
    $this->container->get('commerce_escrow.webhook_secret')->regenerate();
    $this->assertSame(403, $this->post($old, $event, $this->secured())->getStatusCode());
    $this->assertSame(200, $this->post($this->url(), $event, $this->secured())->getStatusCode());
    $this->assertSame('payment_approved', $this->reload($order)->getState()->getId());
  }

  /**
   * Tests that the URL stays closed until a secret exists.
   */
  public function testNoSecretYet(): void {
    [$order, $payment] = $this->orderOn($this->gateways['one']);
    $this->assertSame(403, $this->post('/payment/webhook/escrow/anything', $this->event('payment_approved', $order, $payment), $this->secured())->getStatusCode());
    $this->assertFalse($this->container->get('commerce_escrow.webhook_secret')->isValid(''));
  }

  /**
   * Tests that the same event arriving again changes nothing.
   */
  public function testRepeatedEvent(): void {
    [$order, $payment] = $this->orderOn($this->gateways['one']);
    $event = $this->event('payment_approved', $order, $payment);
    for ($delivery = 0; $delivery < 3; $delivery++) {
      $this->assertSame(200, $this->post($this->url(), $event, $this->secured())->getStatusCode());
    }
    $this->assertSame('payment_approved', $this->reload($order)->getState()->getId());
    $this->assertSame('approved', $this->reload($payment)->getState()->getId());
    $this->assertCount(1, $this->logs($order));
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('commerce_payment')->loadMultipleByOrder($order));

    // A new event is logged. Only the latest entry is compared, so an earlier
    // event arriving late gets a line, and still changes no state.
    $this->post($this->url(), $this->event('payment_sent', $order, $payment), $this->secured());
    $this->assertCount(2, $this->logs($order));
    $this->post($this->url(), $event, $this->secured());
    $this->assertCount(3, $this->logs($order));
    $this->assertSame('approved', $this->reload($payment)->getState()->getId());
  }

  /**
   * Tests that a paid order isn't paid twice by a repeat.
   */
  public function testRepeatedPayment(): void {
    [$order, $payment] = $this->orderOn($this->gateways['one']);
    $transaction = $this->secured();
    $transaction['items'][0]['schedule'][0]['status']['disbursed_to_beneficiary'] = TRUE;
    $event = $this->event('payment_disbursed', $order, $payment);
    for ($delivery = 0; $delivery < 2; $delivery++) {
      $this->post($this->url(), $event, $transaction);
      $this->container->get('commerce_payment.order_updater')->updateOrders();
    }
    $this->assertEquals(new Price('100', 'USD'), $this->reload($order)->getTotalPaid());
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('commerce_payment')->loadMultipleByOrder($order));
  }

  /**
   * Tests that a late event doesn't move the payment back.
   */
  public function testLateEvent(): void {
    [$order, $payment] = $this->orderOn($this->gateways['one']);
    $this->post($this->url(), $this->event('payment_approved', $order, $payment), $this->secured());
    $this->post($this->url(), $this->event('payment_received', $order, $payment), $this->secured());
    $this->assertSame('approved', $this->reload($payment)->getState()->getId());
    $this->assertSame('payment_approved', $this->reload($order)->getState()->getId());

    // Moving on from a payment received still works.
    [$order, $payment] = $this->orderOn($this->gateways['one']);
    $this->post($this->url(), $this->event('payment_received', $order, $payment), $this->secured());
    $this->assertSame('received', $this->reload($payment)->getState()->getId());
    $this->post($this->url(), $this->event('payment_approved', $order, $payment), $this->secured());
    $this->assertSame('approved', $this->reload($payment)->getState()->getId());
  }

  /**
   * Tests waiting for another webhook of the same order.
   */
  public function testBusyOrder(): void {
    [$order, $payment] = $this->orderOn($this->gateways['one']);
    $event = $this->event('payment_approved', $order, $payment);
    $this->container->get('state')->set('commerce_escrow_test.transaction', $this->secured());
    $request = Request::create('/', 'POST', [], [], [], [], Json::encode($event));

    // Still locked after the wait: Escrow.com is to try again.
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(FALSE);
    $lock->expects($this->once())->method('wait')->with('commerce_escrow_webhook:' . $order->id());
    $lock->expects($this->never())->method('release');
    $this->container->set('lock', $lock);
    $this->assertSame(503, EscrowController::create($this->container)->webhook($request)->getStatusCode());
    $this->assertSame('validation', $this->reload($order)->getState()->getId());

    // Free after the wait: it goes ahead, and lets go of the lock.
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnOnConsecutiveCalls(FALSE, TRUE);
    $lock->expects($this->once())->method('wait');
    $lock->expects($this->once())->method('release')->with('commerce_escrow_webhook:' . $order->id());
    $this->container->set('lock', $lock);
    $this->assertSame(200, EscrowController::create($this->container)->webhook($request)->getStatusCode());
    $this->assertSame('payment_approved', $this->reload($order)->getState()->getId());
  }

  /**
   * Tests that the lock is let go of when handling fails.
   */
  public function testLockReleasedOnFailure(): void {
    [$order, $payment] = $this->orderOn($this->gateways['one']);
    $this->container->get('state')->set('commerce_escrow_test.transaction', $this->secured());
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $lock->expects($this->once())->method('release');
    $this->container->set('lock', $lock);
    $this->container->get('event_dispatcher')->addListener('escrow_webhook', static function (): void {
      throw new \RuntimeException('Failed.');
    });
    $request = Request::create('/', 'POST', [], [], [], [], Json::encode($this->event('payment_approved', $order, $payment)));
    $this->expectException(\RuntimeException::class);
    EscrowController::create($this->container)->webhook($request);
  }

  /**
   * Tests what is ignored or refused.
   */
  public function testIgnoredAndRefused(): void {
    [$order, $payment] = $this->orderOn($this->gateways['one']);
    $event = $this->event('payment_approved', $order, $payment);

    // Another site's order on the same Escrow.com account.
    $this->assertSame(200, $this->post($this->url(), ['reference' => 99999] + $event, $this->secured())->getStatusCode());

    // An order on another gateway.
    $order->set('payment_gateway', NULL)->save();
    $this->assertSame(200, $this->post($this->url(), $event, $this->secured())->getStatusCode());
    $this->assertSame('validation', $this->reload($order)->getState()->getId());
    $order->set('payment_gateway', 'one')->save();

    // A transaction that isn't this order's, and an event it doesn't confirm.
    $this->assertSame(400, $this->post($this->url(), ['transaction_id' => 5] + $event, $this->secured())->getStatusCode());
    $this->assertSame(400, $this->post($this->url(), $event, ['items' => []])->getStatusCode());
    $this->assertSame(400, $this->post($this->url(), ['event' => 'invented'] + $event, $this->secured())->getStatusCode());
    $this->assertSame('validation', $this->reload($order)->getState()->getId());
    $this->assertCount(0, $this->logs($order));
  }

  /**
   * Tests the webhook settings on the payment gateway form.
   */
  public function testGatewayForm(): void {
    $plugin = $this->gateways['one']->getPlugin();
    $submit = function (array $values) use ($plugin): void {
      $form_state = new FormState();
      $form = $plugin->buildConfigurationForm(['#parents' => []], $form_state);
      $form_state->setValues($values + [
        'mode' => 'test',
        'display_label' => 'Escrow',
        'payment_method_types' => [],
        'collect_billing_information' => TRUE,
        'api_key' => 'key',
        'email' => 'seller@example.com',
        'logging' => 0,
      ]);
      $plugin->submitConfigurationForm($form, $form_state);
    };

    // A gateway that isn't saved yet shows the URL too.
    $form = PaymentGateway::create(['id' => 'new', 'plugin' => 'test_escrow_pay'])->getPlugin()->buildConfigurationForm(['#parents' => []], new FormState());
    $this->assertSame($plugin->getWebhookUrl()->toString(), $form['webhook_url']['#plain_text']);
    $this->assertArrayNotHasKey('remove_gateway_webhook', $form);

    // Saving creates no secret of its own, and keeps the shared one.
    $secret = $this->secret();
    $submit([]);
    $this->assertSame('', $plugin->getConfiguration()['webhook_secret']);
    $this->assertSame($secret, $this->secret());

    // Regenerating changes it for every gateway.
    $submit(['regenerate_webhook_secret' => 1]);
    $this->assertNotSame($secret, $this->secret());
    $this->assertStringEndsWith($this->secret(), $this->gateways['two']->getPlugin()->getWebhookUrl()->toString());

    // The old URL's secret stays until it is removed.
    $plugin->setConfiguration(['webhook_secret' => 'old-secret'] + $plugin->getConfiguration());
    $this->assertArrayHasKey('remove_gateway_webhook', $plugin->buildConfigurationForm(['#parents' => []], new FormState()));
    $submit([]);
    $this->assertSame('old-secret', $plugin->getConfiguration()['webhook_secret']);
    $submit(['remove_gateway_webhook' => 1]);
    $this->assertSame('', $plugin->getConfiguration()['webhook_secret']);
  }

  /**
   * Tests the per gateway URL of 1.1.0, and retiring it.
   */
  public function testGatewayUrl(): void {
    $configuration = $this->gateways['one']->getPluginConfiguration();
    $this->gateways['one']->setPluginConfiguration(['webhook_secret' => 'old-secret'] + $configuration)->save();
    $old = '/payment/webhook/escrow/one/old-secret';

    // It handles its own gateway's orders, and leaves the others alone.
    [$other, $other_payment] = $this->orderOn($this->gateways['two']);
    $this->assertSame(200, $this->post($old, $this->event('payment_approved', $other, $other_payment), $this->secured())->getStatusCode());
    $this->assertSame('validation', $this->reload($other)->getState()->getId());

    // The same event on both URLs during the move counts once.
    [$order, $payment] = $this->orderOn($this->gateways['one']);
    $event = $this->event('payment_approved', $order, $payment);
    $this->assertSame(200, $this->post($old, $event, $this->secured())->getStatusCode());
    $this->assertSame(200, $this->post($this->url(), $event, $this->secured())->getStatusCode());
    $this->assertSame('payment_approved', $this->reload($order)->getState()->getId());
    $this->assertCount(1, $this->logs($order));

    // The other gateway's secret doesn't open this one's URL, nor does the
    // shared one.
    $this->assertSame(403, $this->post('/payment/webhook/escrow/two/old-secret', $event, $this->secured())->getStatusCode());
    $this->assertSame(403, $this->post('/payment/webhook/escrow/one/' . $this->secret(), $event, $this->secured())->getStatusCode());

    // Without its secret, it is closed.
    $this->gateways['one']->setPluginConfiguration(['webhook_secret' => ''] + $configuration)->save();
    $this->assertSame(403, $this->post($old, $event, $this->secured())->getStatusCode());
    $this->assertSame(403, $this->post('/payment/webhook/escrow/one/', $event, $this->secured())->getStatusCode());
  }

  /**
   * Creates a placed order with its payment on a gateway.
   *
   * @return array
   *   The order and the payment.
   */
  protected function orderOn(PaymentGatewayInterface $gateway): array {
    $item = OrderItem::create([
      'type' => 'test',
      'title' => 'example.com',
      'quantity' => 1,
      'unit_price' => new Price('100', 'USD'),
    ]);
    $item->save();
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'mail' => 'buyer@example.com',
      'state' => 'validation',
      'payment_gateway' => $gateway->id(),
      'order_items' => [$item],
    ]);
    $order->save();
    $payment = Payment::create([
      'type' => 'escrow_payment',
      'state' => 'new',
      'order_id' => $order->id(),
      'payment_gateway' => $gateway->id(),
      'amount' => new Price('100', 'USD'),
      'remote_id' => (string) ++$this->transactionId,
    ]);
    $payment->save();
    return [$order, $payment];
  }

  /**
   * Returns a webhook payload.
   */
  protected function event(string $event, OrderInterface $order, PaymentInterface $payment): array {
    return [
      'event' => $event,
      'event_type' => 'transaction',
      'reference' => $order->id(),
      'transaction_id' => (int) $payment->getRemoteId(),
    ];
  }

  /**
   * Returns a transaction whose money is received and secured.
   */
  protected function secured(): array {
    return [
      'items' => [
        [
          'type' => 'domain_name',
          'schedule' => [['status' => ['payment_sent' => TRUE, 'payment_received' => TRUE, 'secured' => TRUE]]],
        ],
      ],
    ];
  }

  /**
   * Sends a webhook through the router.
   */
  protected function post(string $path, array $payload, array $transaction): Response {
    $this->container->get('state')->set('commerce_escrow_test.transaction', $transaction);
    $request = Request::create($path, 'POST', [], [], [], [], Json::encode($payload));
    // A 403 page renders through a GET subrequest, which leaves the router's
    // context on GET. Each webhook is a new process on a real site.
    $this->container->get('router.no_access_checks')->getContext()->setMethod('POST');
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Overrides the secret as settings.php does.
   */
  protected function override(mixed $value): void {
    $GLOBALS['config']['commerce_escrow.settings']['webhook_secret'] = $value;
    $this->container->get('config.factory')->reset('commerce_escrow.settings');
  }

  /**
   * Returns the shared webhook path.
   */
  protected function url(): string {
    return '/payment/webhook/escrow/' . $this->secret();
  }

  /**
   * Returns the shared secret.
   */
  protected function secret(): string {
    return $this->container->get('commerce_escrow.webhook_secret')->get();
  }

  /**
   * Returns an entity as stored.
   */
  protected function reload(OrderInterface|PaymentInterface $entity): OrderInterface|PaymentInterface {
    return $this->container->get('entity_type.manager')->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
  }

  /**
   * Returns the Escrow log entries of an order.
   */
  protected function logs(OrderInterface $order): array {
    $logs = $this->container->get('entity_type.manager')->getStorage('commerce_log')->loadMultipleByEntity($order);
    return array_filter($logs, static fn($log) => $log->getTemplateId() === 'escrow_event');
  }

}
