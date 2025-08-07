Commerce Escrow
===============

CONTENTS OF THIS FILE
---------------------
* Introduction
* Requirements
* Configuration

INTRODUCTION
------------
This module integrates Drupal Commerce with Escrow.com
including the Escrow Pay [2] and the Escrow Offer [2].

1. https://www.escrow.com/pay/docs
2. https://www.escrow.com/offer/docs

## Features

* Both methods are implemented as offsite payment gateways.
* Webhook integration for real time updates.
* Payments in Drupal Commerce synchronized with Escrow.com.
* Supports voids through the order management interface
* Product variation bundle type EscrowItem with several escrow-specific fields.
* Order and payment workflow synchronized via webhook integration for automated workflow.


REQUIREMENTS
------------
This module should be added to your codebase via Composer

`composer require "drupal/commerce_escrow`

You must also have an Escrow account or developer access to the account
you intend to configure for your integration.

There are no other requirements than [Commerce Core 3](https://www.drupal.org/project/commerce)

You can sign up for one [here](https://www.escrow.com/integrations/signup).


CONFIGURATION
-------------

## Payment gateway configuration
Once you've installed the module, you must navigate to the Drupal Commerce
payment gateway configuration screen to define a payment gateway configuration.
This will require providing an API key, email of Escrow.com account holder and configuring the mode
(Live vs. Test).

## Order type configuration
Go to `Commerce => Configuration => Order Types` and edit a desired order type
which you want to use with Escrow integration and under workflow choose `Escrow Workflow`.

## Product variation configuration
Go to `Commerce => Configuration => Product variation types` and edit a desired product variation type
which you want to use with Escrow integration and under traits choose `Escrow Item`.

Now that product variation type will get several Escrow specific fields.
* Brokered: if you are brokering sale of this product between a seller and buyer.
* Broker Fee percentage: specify the fee which you will charge for brokering service.
* Broker Fee split: split the broke fee to a buyer or seller or equally to both sides.
* Escrow Fee: split the escrow fee to buyer or seller or equally to both sides.
* Escrow item type: defining the typo of goods to be sold via Escrow.com
* Inspection period: how long the Escrow offer is valid, between 1 and 30 days.
* Display Escrow fee information: you can during checkout show estimated fee to be charged on Escrow.com
* Extra attributes fees: adds additional fee to be charged on Escrow.com
* Single item: defines if the product variation is allowed only once to be sold.
* In stock: a flag to determine if product is in stock, depends on Single item logic.

## Webhook configuration
Go to Escrow.com and set webhook to `https://yourwebsite.com/payment/webhook/escrow`

## Custom configuration / modifications.
You don't need to use the provided order and payment workflow.
The provided product variation type `Escrow item` is a requirement to be used.

The automated order and payment states can be stopped via event subscriber
`\Drupal\commerce_escrow\Event\EscrowEvents::ESCROW_WEBHOOK` and `setStopWebhook` method.

You can enrich or alter transaction payload via event subscriber
`\Drupal\commerce_escrow\Event\EscrowEvents::ESCROW_ORDER_PAYLOAD`

The http client for communication with Escrow.com has all available methods
for interacting with Escrow.com API. If you want to use it in your custom code,
you can easily initiate it like this:

```php
$client = new EscrowClient('api_key', 'account_email', 'test');
$payload = [
  'currency' => 'usd',
  ......
];
$client->createTransaction($payload);
```


