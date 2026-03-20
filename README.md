# NoPayn Payment Module for Magento 1.9

Accept Credit/Debit Cards, Apple Pay, Google Pay, and Vipps MobilePay in your Magento 1.9 (OpenMage LTS) store via NoPayn.

## Requirements

- Magento 1.9.x / OpenMage LTS 20+
- PHP 7.4+
- A NoPayn merchant account ([manage.nopayn.io](https://manage.nopayn.io/))

## Installation

1. Copy the contents of `app/` into your Magento root `app/` directory:
   ```bash
   cp -r app/* /path/to/magento/app/
   ```
2. Clear the Magento cache:
   ```bash
   rm -rf var/cache/*
   ```
3. Log in to the Magento admin panel
4. Go to **System > Configuration > Sales > Payment Methods**
5. Configure the **NoPayn Payment Gateway** API key
6. Enable the payment methods you want to offer

## Configuration

1. Enter your **API Key** under the "NoPayn Payment Gateway" section (found at [manage.nopayn.io](https://manage.nopayn.io/) → Settings → API Key)
2. Enable individual payment methods (Credit / Debit Card, Apple Pay, Google Pay, Vipps MobilePay)
3. Optionally restrict by country
4. Save configuration

## Payment Methods

| Checkout Name       | Admin Label                  | NoPayn Identifier  |
|---------------------|------------------------------|---------------------|
| Credit / Debit Card | NoPayn Credit / Debit Card   | `credit-card`       |
| Apple Pay           | NoPayn Apple Pay             | `apple-pay`         |
| Google Pay          | NoPayn Google Pay            | `google-pay`        |
| Vipps MobilePay     | NoPayn Vipps MobilePay       | `vipps-mobilepay`   |

## Payment Flow

1. Customer selects a payment method at checkout and places the order
2. Order is created with status **Pending Payment**
3. Customer is redirected to the NoPayn secure payment page
4. After payment:
   - **Success**: customer returns, status verified via API, order set to **Processing**
   - **Cancelled**: customer returns, order set to **Canceled**
   - **Expired** (5 min timeout): webhook fires, order set to **Canceled**
5. NoPayn sends a webhook for asynchronous status confirmation

## Order Status Mapping

| NoPayn Status | Magento Order State | Magento Order Status |
|---------------|---------------------|----------------------|
| `new`         | pending_payment     | Pending Payment      |
| `processing`  | pending_payment     | Pending Payment      |
| `completed`   | processing          | Processing           |
| `cancelled`   | canceled            | Canceled             |
| `expired`     | canceled            | Canceled             |
| `error`       | canceled            | Canceled             |

## Webhook

The module registers a webhook endpoint at `/nopayn/payment/webhook`. This URL is automatically sent to NoPayn when creating orders. The webhook always verifies the order status via the NoPayn API before updating the Magento order.

## Database

The module creates a `nopayn_transactions` table to track payment transactions.

## License

MIT

## Support

- **Developer**: [Cost+](https://costplus.io)
- **NoPayn API docs**: [dev.nopayn.io](https://dev.nopayn.io/)
- **Merchant portal**: [manage.nopayn.io](https://manage.nopayn.io/)
