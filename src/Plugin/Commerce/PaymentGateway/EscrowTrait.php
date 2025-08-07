<?php

namespace Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_escrow\Entity\EscrowItemInterface;
use Drupal\commerce_escrow\EscrowClient;
use Drupal\commerce_escrow\EscrowClientInterface;
use Drupal\commerce_escrow\Event\EscrowEvents;
use Drupal\commerce_escrow\Event\EscrowOrderPayloadEvent;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

trait EscrowTrait {

  use LoggerChannelTrait;

  protected EventDispatcherInterface $eventDispatcher;
  protected ModuleExtensionList $moduleExtensionList;
  protected EscrowClientInterface $escrowClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->minorUnitsConverter = $container->get('commerce_price.minor_units_converter');
    $instance->eventDispatcher = $container->get('event_dispatcher');
    $instance->moduleExtensionList = $container->get('extension.list.module');
    $instance->init();
    return $instance;
  }

  /**
   * Re-initializes the SDK after the plugin is unserialized.
   */
  public function __wakeup(): void {
    parent::__wakeup();
    $this->init();
  }

  /**
   * Initialize Escrow client library.
   */
  public function init(): void {
    $this->escrowClient = new EscrowClient($this->configuration['api_key'], $this->configuration['email'], $this->configuration['mode'], $this->configuration['logging']);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'api_key' => '',
      'email' => '',
      'logging' => FALSE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Api key'),
      '#default_value' => $this->configuration['api_key'],
      '#required' => TRUE,
    ];

    $form['email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Escrow email'),
      '#description' => $this->t('Enter the email address you use to login into Escrow.com.'),
      '#default_value' => $this->configuration['email'],
      '#required' => TRUE,
    ];

    $form['logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log API calls'),
      '#default_value' => $this->configuration['logging'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment): void {
    $this->assertPaymentState($payment, ['authorization']);
    $this->escrowClient->cancelTransaction($payment->getRemoteId());
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, array $escrow_response): void {
    $this->assertPaymentState($payment, ['new']);
    $order = $payment->getOrder();
    $payment->setAmount($order->getTotalPrice());
    $payment->set('order_id', $order->id());
    $payment->setRemoteState('create');
    $payment->set('token', $escrow_response['token']);

    if (isset($escrow_response['transaction_id'])) {
      $payment->setRemoteId($escrow_response['transaction_id']);
    }

    if (isset($escrow_response['auction_id'])) {
      $payment->setRemoteId($escrow_response['transaction_id']);
    }

    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['api_key'] = $values['api_key'];
      $this->configuration['email'] = $values['email'];
      $this->configuration['logging'] = $values['logging'];
    }
  }

  /**
   * Method to create payload for order to interact with Escrow API.
   */
  protected function buildOrderPayload(OrderInterface $order): array {
    $total_price = $order->getTotalPrice();
    $buyer = $order->getEmail();
    $seller = EscrowItemInterface::ESCROW_OWNER;
    $broker = EscrowItemInterface::ESCROW_OWNER;
    $is_order_brokered = FALSE;
    $payload = [
      'currency' => strtolower($total_price->getCurrencyCode()),
      'description' => '',
      'reference' => $order->id(),
      'return_url' => Url::fromRoute('commerce_payment.checkout.return', [
        'commerce_order' => $order->id(),
        'step' => 'payment',
      ], ['absolute' => TRUE])->toString(),
      'redirect_type' => 'manual',
    ];

    $escrow_offer = $this->getPluginId() === 'escrow_offer';

    // Offer initial call have slightly different payload.
    // @see https://www.escrow.com/offer/docs.
    if ($escrow_offer) {
      $payload['listing_reference'] = $order->id();
      $buyer = 'buyer_user';
    }

    $order_items = $order->getItems();

    foreach ($order_items as $order_item) {
      $purchased_entity = $order_item->getPurchasedEntity();
      if (!$purchased_entity instanceof EscrowItemInterface) {
        continue;
      }

      $payload['description'] .= $purchased_entity->label();

      if ($purchased_entity->isBrokered()) {
        $is_order_brokered = TRUE;
        $seller = $purchased_entity->getOwner()->getEmail();
        $broker_fee_split = $purchased_entity->getEscrowFeeSplit();
        $broker_fee = $purchased_entity->getBrokerFee($order_item->getTotalPrice());
        switch ($broker_fee_split) {
          case EscrowItemInterface::ESCROW_FEE_PAYS_BUYER:
            $payload['items'][] = [
              'type' => 'broker_fee',
              'schedule' => [
                'amount' => $broker_fee->getNumber(),
                'payer_customer' => $buyer,
                'beneficiary_customer' => $broker,
              ],
            ];
            break;

          case EscrowItemInterface::ESCROW_FEE_PAYS_SPLIT:
            $payload['items'][] = [
              'type' => 'broker_fee',
              'schedule' => [
                'amount' => $broker_fee->multiply($broker_fee_split)->getNumber(),
                'payer_customer' => $buyer,
                'beneficiary_customer' => $broker,
              ],
            ];
            $payload['items'][] = [
              'type' => 'broker_fee',
              'schedule' => [
                'amount' => $broker_fee->multiply($broker_fee_split)->getNumber(),
                'payer_customer' => $seller,
                'beneficiary_customer' => $broker,
              ],
            ];
            break;

          default:
            $payload['items'][] = [
              'type' => 'broker_fee',
              'schedule' => [
                'amount' => $broker_fee->getNumber(),
                'payer_customer' => $seller,
                'beneficiary_customer' => $broker,
              ],
            ];
        }
      }

      $item_data = [
        'type' => $purchased_entity->getEscrowType(),
        'title' => $purchased_entity->getTitle(),
        'description' => $purchased_entity->getSku(),
        'quantity' => (int) $order_item->getQuantity(),
        'inspection_period' => $purchased_entity->getInspectionPeriod(),
        'schedule' => [
          [
            'amount' => $order_item->getTotalPrice()->getNumber(),
            'payer_customer' => $buyer,
            'beneficiary_customer' => $seller,
          ],
        ],
      ];

      if ($extra_attribute = $purchased_entity->getExtraAttribute()) {
        $item_data['extra_attributes'][$extra_attribute] = TRUE;
      }

      $escrow_fee_split = $purchased_entity->getEscrowFeeSplit();

      switch ($escrow_fee_split) {
        case EscrowItemInterface::ESCROW_FEE_PAYS_SELLER:
          $item_data['fees'][] = [
            [
              'payer_customer' => $seller,
              'type' => 'escrow',
              'split' => $escrow_fee_split,
            ],
          ];
          break;

        case EscrowItemInterface::ESCROW_FEE_PAYS_SPLIT:
          $item_data['fees'] = [
            [
              'payer_customer' => $seller,
              'type' => 'escrow',
              'split' => $escrow_fee_split,
            ],
            [
              'payer_customer' => $buyer,
              'type' => 'escrow',
              'split' => $escrow_fee_split,
            ],
          ];
          break;

      }

      $payload['items'][] = $item_data;
    }

    if ($escrow_offer) {
      $buyer_party = [
        'customer' => 'buyer_user',
        'role' => 'buyer',
        'agreed' => TRUE,
      ];
    }
    else {
      $buyer_party = $this->getBuyerParty($order);
    }

    // There is always a buyer and seller.
    $payload['parties'] = [
      $buyer_party,
      [
        'role' => EscrowItemInterface::ESCROW_SELLER,
        'customer' => $seller,
        'agreed' => !$is_order_brokered,
        'initiator' => !$is_order_brokered,
      ],
    ];

    if ($is_order_brokered) {
      $payload['parties'][] = [
        'role' => EscrowItemInterface::ESCROW_BROKER,
        'customer' => $broker,
        'agreed' => TRUE,
        'initiator' => TRUE,
      ];
    }

    $event = new EscrowOrderPayloadEvent($order, $payload);
    $this->eventDispatcher->dispatch($event, EscrowEvents::ESCROW_ORDER_PAYLOAD);
    return $event->getPayload();
  }

  /**
   * Create buyer profile data.
   */
  protected function getBuyerParty(OrderInterface $order): array {
    $profile = $order->getBillingProfile();
    /** @var \Drupal\address\AddressInterface $address */
    $address = $profile->get('address')->first();
    $payload = [
      'first_name' => $address->getGivenName(),
      'last_name' => $address->getFamilyName(),
      'address' => [
        'city' => $address->getLocality(),
        'post_code' => $address->getPostalCode(),
        'country' => $address->getCountryCode(),
        'line1' => $address->getAddressLine1(),
        'state' => $address->getAdministrativeArea(),
      ],
      'customer' => $order->getEmail(),
      // By default, a buyer starts the process.
      // But API does not accept it as true.
      // The caller of the endpoint must be an initiator.
      'initiator' => FALSE,
      'agreed' => TRUE,
      'lock_email' => TRUE,
      'role' => EscrowItemInterface::ESCROW_BUYER,
    ];

    if (!empty($address->getAddressLine2())) {
      $payload['address']['line2'] = $address->getAddressLine2();
    }

    if ($profile->hasField('field_phone') && !$profile->get('field_phone')->isEmpty()) {
      $payload['phone_number'] = $profile->get('field_phone')->value;
    }

    return $payload;
  }

  /**
   * {@inheritdoc}
   */
  public function getEscrowByOder(OrderInterface $order): array {
    return $this->escrowClient->getTransactionByReference($order->id());
  }

}
