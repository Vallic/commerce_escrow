<?php

namespace Drupal\Tests\commerce_escrow\Kernel;

use Drupal\commerce_checkout\Controller\CheckoutController;
use Drupal\commerce_escrow\Controller\EscrowController;
use Drupal\commerce_escrow\EscrowOfferOrders;
use Drupal\commerce_escrow\Form\EscrowSettingsForm;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormState;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Url;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;

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
    'commerce_cart',
    'commerce_checkout',
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
   * Tests the URL, ending in the secret, with nothing on the gateways.
   */
  public function testUrl(): void {
    $url = $this->container->get('commerce_escrow.webhook_secret')->url()->toString();
    $this->assertStringEndsWith('/payment/webhook/escrow/' . $this->secret(), $url);
    $this->assertSame(43, strlen($this->secret()));
    $this->assertSame($this->secret(), $this->config('commerce_escrow.settings')->get('webhook_secret'));
    $this->assertArrayNotHasKey('webhook_secret', $this->gateways['one']->getPluginConfiguration());
  }

  /**
   * Tests a secret set in settings.php.
   */
  public function testSecretFromSettings(): void {
    [$order, $payment] = $this->orderOn($this->gateways['one']);
    $event = $this->event('payment_approved', $order, $payment);
    $secret = $this->container->get('commerce_escrow.webhook_secret');

    $this->override('from-settings-php');
    $this->assertTrue($secret->isOverridden());
    $this->assertSame('from-settings-php', $secret->get());
    $this->assertStringEndsWith('/payment/webhook/escrow/from-settings-php', $secret->url()->toString());
    $this->assertSame('', (string) $this->container->get('config.factory')->getEditable('commerce_escrow.settings')->get('webhook_secret'), 'Nothing is stored.');
    $form = $this->settingsForm();
    $this->assertStringEndsWith('/payment/webhook/escrow/from-settings-php', (string) $form['webhook_url']['#plain_text']);
    $this->assertArrayNotHasKey('actions', $form, 'It can only be changed in settings.php.');
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

    $this->assertSame('', $secret->get());
    $this->assertFalse($secret->isValid(''));
    $this->assertNull($secret->url());
    $form = $this->settingsForm();
    $this->assertStringContainsString('overridden with an empty value', (string) $form['webhook_url']['#plain_text']);
    $this->assertArrayNotHasKey('actions', $form);
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
    $this->assertSame(200, $this->post($this->url(), $event, $this->secured())->getStatusCode());
    $all_logs = $this->container->get('entity_type.manager')->getStorage('commerce_log')->loadMultipleByEntity($order);
    for ($delivery = 0; $delivery < 2; $delivery++) {
      $this->assertSame(200, $this->post($this->url(), $event, $this->secured())->getStatusCode());
    }
    $this->assertCount(count($all_logs), $this->container->get('entity_type.manager')->getStorage('commerce_log')->loadMultipleByEntity($order), 'A repeat writes nothing.');
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
   * Tests that a verification leaves the payment alone.
   */
  public function testVerificationEvent(): void {
    [$order, $payment] = $this->orderOn($this->gateways['one']);
    $this->post($this->url(), $this->event('payment_approved', $order, $payment), $this->secured());
    $this->assertSame('payment_approved', $this->reload($payment)->getRemoteState());
    foreach (['party_verification_submitted', 'party_verification_approved'] as $event) {
      $this->assertSame(200, $this->post($this->url(), $this->event($event, $order, $payment), $this->secured())->getStatusCode());
    }
    $this->assertSame('payment_approved', $this->reload($payment)->getRemoteState());
    $this->assertSame('approved', $this->reload($payment)->getState()->getId());
    $this->assertSame('payment_approved', $this->reload($order)->getState()->getId());
  }

  /**
   * Tests the labels of the states a domain goes through.
   */
  public function testStateLabels(): void {
    [$order, $payment] = $this->orderOn($this->gateways['one']);
    $workflow = $order->getState()->getWorkflow();
    $this->assertSame('Seller sent goods', (string) $workflow->getState('shipped')->getLabel());
    $this->assertSame('Buyer received goods', (string) $workflow->getState('received')->getLabel());
    $this->assertSame('Buyer accepted goods', (string) $workflow->getState('accepted')->getLabel());
    $this->assertSame('Payment disbursed', (string) $payment->getState()->getWorkflow()->getTransition('payment_disbursed')->getLabel());
  }

  /**
   * Tests where Escrow.com sends the buyer of an offer back to.
   */
  public function testReturnUrl(): void {
    $offer_gateway = PaymentGateway::create([
      'id' => 'offer',
      'label' => 'Offer',
      'plugin' => 'escrow_offer',
      'status' => TRUE,
      'configuration' => ['api_key' => 'key', 'email' => 'seller@example.com', 'logging' => FALSE, 'mode' => 'test'],
    ]);
    $offer_gateway->save();
    [$order] = $this->orderOn($offer_gateway);
    $plugin = $offer_gateway->getPlugin();
    $payload = (new \ReflectionMethod($plugin, 'buildOrderPayload'))->invoke($plugin, $order);
    $this->assertSame(Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(), $payload['return_url'], 'An offer has no checkout to return to.');
  }

  /**
   * Tests an offer's order: a locked draft until the buyer makes the offer.
   */
  public function testOfferOrder(): void {
    $offers = $this->container->get('commerce_escrow.offer_orders');
    $variation = $this->offerVariation('offer.com');
    $buyer = $this->createUser(['access checkout']);
    $result = $offers->start($this->gateways['one'], $variation, new Price('2000', 'USD'), 'buyer@example.com', $buyer, $this->store, ['name' => 'Ana']);
    $order = $result['order'];
    $this->assertSame('https://escrow.test/offer?token=test', $result['response']['make_offer_page']);

    $this->assertSame('draft', $order->getState()->getId());
    $this->assertTrue($order->isLocked());
    $this->assertFalse((bool) $order->get('cart')->value);
    $this->assertEmpty($order->getOrderNumber());
    $this->assertEmpty($order->getPlacedTime());
    $this->assertEquals(new Price('2000', 'USD'), $order->getTotalPrice());
    $this->assertSame(['name' => 'Ana'], $order->getData(EscrowOfferOrders::DATA_KEY));
    $this->assertFalse($this->container->get('commerce_order.order_refresh')->needsRefresh($order), 'The price stays.');

    // The buyer can't reach it in checkout, even as its customer.
    $route_match = new RouteMatch('commerce_checkout.form', new Route('/checkout/{commerce_order}'), ['commerce_order' => $order], ['commerce_order' => $order->id()]);
    $this->assertFalse(CheckoutController::create($this->container)->checkAccess($route_match, $buyer)->isAllowed());

    /** @var \Drupal\commerce_payment\PaymentStorageInterface $payment_storage */
    $payment_storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment');
    $payments = $payment_storage->loadMultipleByOrder($order);
    $this->assertCount(1, $payments);
    $payment = reset($payments);

    // The first webhook means the offer was made, which is not a sale.
    $placed = [];
    $this->container->get('event_dispatcher')->addListener('commerce_order.place.post_transition', function () use (&$placed): void {
      $placed[] = TRUE;
    });
    $event = $this->event('create', $order, $payment);
    $this->assertSame(200, $this->post($this->url(), $event, $this->secured())->getStatusCode());
    $order = $this->reload($order);
    $this->assertSame('validation', $order->getState()->getId());
    $this->assertFalse($order->isLocked());
    $this->assertNotEmpty($order->getOrderNumber());
    $this->assertNotEmpty($order->getPlacedTime());

    $number = $order->getOrderNumber();
    $this->assertSame(200, $this->post($this->url(), $event, $this->secured())->getStatusCode());
    $this->assertSame($number, $this->reload($order)->getOrderNumber());
    $this->assertSame([], $placed);
    $this->assertCount(1, $payment_storage->loadMultipleByOrder($order));
  }

  /**
   * Tests that a refused auction leaves nothing behind.
   */
  public function testOfferOrderRefused(): void {
    $this->container->get('state')->set('commerce_escrow_test.refuse', TRUE);
    $manager = $this->container->get('entity_type.manager');
    try {
      $this->container->get('commerce_escrow.offer_orders')->start($this->gateways['one'], $this->offerVariation('refused.com'), new Price('2000', 'USD'), 'buyer@example.com', $this->createUser(), $this->store);
      $this->fail('It should throw.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Refused.', $e->getMessage());
    }
    $this->assertSame([], $manager->getStorage('commerce_order')->loadMultiple());
    $this->assertSame([], $manager->getStorage('commerce_order_item')->loadMultiple());
    $this->assertSame([], $manager->getStorage('commerce_payment')->loadMultiple());
  }

  /**
   * Tests deleting the drafts of buyers who never made their offer.
   */
  public function testDeleteAbandoned(): void {
    $offers = $this->container->get('commerce_escrow.offer_orders');
    $manager = $this->container->get('entity_type.manager');
    $account = $this->createUser();
    $start = fn(string $domain) => $offers->start($this->gateways['one'], $this->offerVariation($domain), new Price('2000', 'USD'), 'buyer@example.com', $account, $this->store)['order'];
    $old = $start('old.com');
    $recent = $start('recent.com');
    $made = $start('made.com');
    $offers->place($made);

    // A locked draft that isn't an offer, such as one left mid payment.
    [$other] = $this->orderOn($this->gateways['one']);
    $other->set('state', 'draft')->set('cart', FALSE)->lock()->save();

    $long_ago = $this->container->get('datetime.time')->getRequestTime() - 40 * 86400;
    $this->container->get('database')->update('commerce_order')
      ->fields(['changed' => $long_ago])
      ->condition('order_id', [$old->id(), $made->id(), $other->id()], 'IN')
      ->execute();
    $manager->getStorage('commerce_order')->resetCache();
    $payments = $manager->getStorage('commerce_payment');
    $old_payments = array_keys($payments->loadMultipleByOrder($old));
    $this->assertNotEmpty($old_payments);

    $this->assertSame(1, $offers->deleteAbandoned());
    $this->assertNull(Order::load($old->id()));
    $this->assertSame([], $payments->loadMultiple($old_payments));
    $this->assertNotNull(Order::load($recent->id()));
    $this->assertNotNull(Order::load($made->id()));
    $this->assertNotNull(Order::load($other->id()));
    $this->assertSame(0, $offers->deleteAbandoned());
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

    // A customer event, as Escrow.com sends it, has no order.
    $customer_event = [
      'event' => 'verification_approved',
      'event_type' => 'customer',
      'customer_id' => 1,
      'party_id' => 2,
      'transaction_id' => (int) $payment->getRemoteId(),
      'details' => [],
    ];
    $this->assertSame(200, $this->post($this->url(), $customer_event, $this->secured())->getStatusCode());
    $this->assertSame(403, $this->post('/payment/webhook/escrow/wrong', $customer_event, $this->secured())->getStatusCode());
    $this->assertSame('new', $this->reload($payment)->getState()->getId());

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
   * Tests the settings page.
   */
  public function testSettingsPage(): void {
    $link = $this->container->get('plugin.manager.menu.link')->getDefinition('commerce_escrow.settings');
    $this->assertSame('commerce_payment.configuration', $link['parent']);
    $url = Url::fromRoute('commerce_escrow.settings');
    $this->assertSame('/admin/commerce/config/payment/escrow', $url->toString());
    $this->assertTrue($url->access($this->createUser(['administer commerce_payment_gateway'])));
    $this->assertFalse($url->access($this->createUser(['access commerce administration pages'])));

    $secret = $this->secret();
    $form = $this->settingsForm();
    $this->assertStringEndsWith('/payment/webhook/escrow/' . $secret, (string) $form['webhook_url']['#plain_text']);
    $this->assertSame($secret, $this->secret(), 'Viewing the page keeps the secret.');

    $form_state = (new FormState())->setValues(['op' => 'Regenerate the webhook URL']);
    $this->container->get('form_builder')->submitForm(EscrowSettingsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertNotSame($secret, $this->secret());
    $this->assertStringEndsWith($this->secret(), (string) $this->settingsForm()['webhook_url']['#plain_text']);
  }

  /**
   * Tests the payment gateway form, which only points to the settings page.
   */
  public function testGatewayForm(): void {
    $plugin = $this->gateways['one']->getPlugin();
    $form_state = new FormState();
    $form = $plugin->buildConfigurationForm(['#parents' => []], $form_state);
    $this->assertStringContainsString('/admin/commerce/config/payment/escrow', (string) $form['webhook']['#markup']);
    $this->assertArrayNotHasKey('webhook_url', $form);
    $this->assertNull($this->config('commerce_escrow.settings')->get('webhook_secret') ?: NULL, 'Opening it creates no secret.');

    $form_state->setValues([
      'mode' => 'test',
      'display_label' => 'Escrow',
      'payment_method_types' => [],
      'collect_billing_information' => TRUE,
      'api_key' => 'key',
      'email' => 'seller@example.com',
      'logging' => 0,
    ]);
    $plugin->submitConfigurationForm($form, $form_state);
    $this->assertSame('key', $plugin->getConfiguration()['api_key']);
    $this->assertArrayNotHasKey('webhook_secret', $plugin->getConfiguration());
  }

  /**
   * Tests that the URLs per gateway from 1.1 are gone.
   */
  public function testGatewayUrlIsGone(): void {
    [$order, $payment] = $this->orderOn($this->gateways['one']);
    $event = $this->event('payment_approved', $order, $payment);
    $this->assertSame(404, $this->post('/payment/webhook/escrow/one/' . $this->secret(), $event, $this->secured())->getStatusCode());
    $this->assertSame('validation', $this->reload($order)->getState()->getId());
  }

  /**
   * Tests cleaning up the gateway settings no longer used.
   */
  public function testPostUpdate(): void {
    $name = 'commerce_payment.commerce_payment_gateway.old';
    $config_factory = $this->container->get('config.factory');
    $config_factory->getEditable($name)->setData([
      'id' => 'old',
      'label' => 'Old',
      'plugin' => 'escrow_offer',
      'configuration' => ['api_key' => 'stored', 'webhook_secret' => 'old-secret'],
    ])->save();
    $other = 'commerce_payment.commerce_payment_gateway.one';
    $other_data = $config_factory->getEditable($other)->getRawData();

    // An override from settings.php must not end up in the configuration.
    $GLOBALS['config'][$name]['configuration']['api_key'] = 'from-settings-php';
    $config_factory->reset($name);

    $this->container->get('module_handler')->loadInclude('commerce_escrow', 'php', 'commerce_escrow.post_update');
    commerce_escrow_post_update_unused_gateway_settings();

    $configuration = $config_factory->getEditable($name)->get('configuration');
    $this->assertArrayNotHasKey('webhook_secret', $configuration);
    $this->assertSame('stored', $configuration['api_key']);
    $this->assertSame($other_data, $config_factory->getEditable($other)->getRawData());
  }

  /**
   * Creates something to negotiate.
   */
  protected function offerVariation(string $sku): ProductVariationInterface {
    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => $sku,
      'title' => $sku,
      'price' => new Price('2000', 'USD'),
      'status' => TRUE,
    ]);
    $variation->save();
    Product::create([
      'type' => 'default',
      'title' => $sku,
      'stores' => [$this->store],
      'variations' => [$variation],
    ])->save();
    return ProductVariation::load($variation->id());
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
   * Returns the built settings form.
   */
  protected function settingsForm(): array {
    return $this->container->get('form_builder')->getForm(EscrowSettingsForm::class);
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
