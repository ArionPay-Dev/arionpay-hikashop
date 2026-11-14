# ArionPay for HikaShop

Crypto payments (Bitcoin, USDT, Litecoin, Ethereum, TRON and more) for [HikaShop](https://www.hikashop.com) on Joomla 4 and 5, through [ArionPay](https://arionpay.com). The customer pays on the ArionPay payment page and the money goes straight to your own wallet: ArionPay never holds it.

Tested on HikaShop Starter 6.8.2, Joomla 5.4.10 and PHP 8.3, against a real ArionPay server, with 25 checks: checkout, coming back before and after paying, a lost webhook, paying later, payment tolerance, late payments, a wrong amount, double payments and webhook security. It works with all HikaShop editions (Starter, Essential, Business).

## Install

1. In Joomla, go to **System > Install > Extensions** and upload `plg_hikashoppayment_arionpay-1.0.0.zip`.
2. In HikaShop, go to **System > Payment methods**, click **New** and choose **ArionPay**. (Or open **HikaShop > System > Payment methods > ArionPay > New**.)
3. Fill in the fields below, set **Published** to **Yes** and save.

| Field | Where in the ArionPay dashboard | Starts with |
| --- | --- | --- |
| API key | Open your store, **API key** | `pk_` |
| API secret | Open your store, **API secret** (asks for your authenticator code) | `sk_` |
| Webhook signing secret | **Webhooks** page, **signing secret** | `whsec_` |

- **Test mode** is on by default: invoices are test invoices and no real money moves. Switch it off for real payments.
- **ArionPay address**: leave it empty.
- Nothing to paste into ArionPay: every invoice tells ArionPay where to send its payment updates (shown on the settings page). In your ArionPay store settings, check that **Old ArionPay (v1) integration compatibility** is off.
- The secrets are never shown again after saving. Leave a secret field empty to keep the saved one. A key that does not look right is not saved.

ArionPay prices invoices in USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK and ZAR. HikaShop offers the payment method only for orders in these currencies.

## How orders change

- At checkout the customer chooses **Crypto (Bitcoin, USDT and more)**, clicks **Finish** and goes to the ArionPay payment page. The order waits with the status you chose for **Order status while waiting for payment** (default `created`).
- When ArionPay confirms the full payment, the order gets the status you chose for **Order status when paid** (default `confirmed`), and the customer gets HikaShop's usual status email. The order history names the ArionPay invoice, the coin and notes such as "paid late".
- When the customer comes back, the plugin asks ArionPay directly, so a lost webhook is caught there too. Then HikaShop's thank-you page shows where the payment stands.
- A customer who left without paying sees a **Pay with crypto** button. It opens the same ArionPay invoice, or a new one when it expired. (HikaShop's own "pay unpaid orders" button needs HikaShop Essential or Business; the plugin's button works on every edition.)
- "Payment seen", Payment Radar and "expired" updates are written to the order history without changing the status. A payment that is not exact goes to **Payment Radar** in your ArionPay dashboard: a small difference inside your store's payment tolerance (default 1%) is accepted automatically, anything else you decide. If you accept it, the order is confirmed here too.
- An order is confirmed only by a signed update for its own invoice, test or live like the payment method, with the same amount and currency. Anything else is written to the order history (and emailed to the address in **HikaShop > Configuration > Payment notification email**) and never confirms the order. A second paid invoice for the same order is flagged as a possible double payment.

## Good to know

- Guest checkout is a HikaShop Essential/Business feature; with HikaShop Starter customers create an account or log in, as usual.
- Refunds are made from your own wallet; record them in HikaShop by hand.
- The plugin sends ArionPay the order number, total and currency. It does not send the customer's name or address.
- Turn on **DEBUG** in the payment method to write details to HikaShop's payment log. Keys and secrets are never logged.
- You can change the "redirecting" page by copying `arionpay_end.php` to `templates/<your template>/hikashoppayment/arionpay_end.php`.

## License

MIT
