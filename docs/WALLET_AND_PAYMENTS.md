# WhatsApp wallet and online payments

## How a message is priced
`price = Meta rate (receiver country + category) x (1 + markup%) x (1 + GST%)`, converted to the wallet currency.
The client only sees the final price. `wallet_transactions.base_amount` (Meta cost) and `tax_amount` keep what is needed
for the platform margin report (Platform > Wallet report). Refunds copy both, so margin nets out.

Per client (Platform > client > Wallet): on/off, low-balance alert, daily and monthly spending limit, markup %, GST %,
low-balance top-up amount. A limit of 0 means none. When a limit is reached sends fail with "Spending limit reached"
(not retried; they are requeued with the other wallet failures after a recharge).

## Razorpay recharge (one platform account, all clients)
1. Platform > Online payments: paste the Razorpay **test** key id and secret, enable it, set min/max, and the
   invoice company details. "Test saved keys" checks the keys.
2. Razorpay Dashboard > Webhooks: add `https://<your-domain>/webhooks/razorpay`, events `payment.captured`,
   `order.paid`, `payment_link.paid`; paste the same secret into the Webhook secret field. The webhook credits a wallet even
   if the client closes the browser after paying. It needs a public HTTPS URL, so use a tunnel for local testing.
3. A client with an INR wallet sees "Add money" on the Wallet page (needs `settings.edit`). The wallet is credited with the
   amount; GST is added at payment (it is the **GST % of that client's wallet**, the same one added to message prices; 0 = no GST) and shown on the invoice (Wallet page > Online recharges and invoices).
4. Go live: swap in the **live** key id and secret and the live webhook secret.

Safety: the checkout signature and the webhook signature are verified before anything is credited; a payment is credited
once (idempotent on the Razorpay payment id); a webhook whose amount does not match the order is refused; non-INR
wallets are not offered online recharge.

Low-balance top-up link: set "Low-balance top-up amount" for a client. When the low-balance alert fires, the in-app
notification and the email include a Razorpay payment link for that amount. (A true automatic card debit needs Razorpay
mandates and is not built.)

## Mswipe Pay By Link (second gateway)
Source: https://docs.mswipe.com ("Getting Started"). Platform > Online payments > Mswipe: environment (UAT or Live),
customer code (`custcode`), user id, client id, password, and a fallback 10 digit mobile (Mswipe needs a customer mobile;
the paying user's own number is used first).

Flow: token (`CreatePBLAuthToken`) -> payment link (`MswipePayment`, our order id as `invoice_id`) -> the client pays on
Mswipe and returns to `/wallet/recharge/return?order=...` -> we ask Mswipe (`getPBLTransactionDetails`, id = the link's
`TransID`) and credit only when `Payment_Status` is 1 and the amount and order id match. Mswipe's back-post
(`/webhooks/mswipe`, ask Mswipe to register it) is **unsigned**, so it is only a trigger to re-check; it can never credit
by itself. `php spark payments:reconcile` (cron every 10 minutes) credits links whose customer closed the page.
Clients choose Razorpay or Mswipe on the Wallet page when both are enabled. Both gateways share the client's wallet GST %, the min/max and the invoices.

## Cron
```
* * * * *    php spark queue:process / campaigns:process / automations:process / queue:retry
*/5 * * * *  php spark stats:refresh        # All clients page cache
*/10 * * * * php spark payments:reconcile    # Mswipe links paid but not returned
0 2 * * *    php spark wallet:settle         # refund charges for messages never delivered in 30 days
```
Windows Task Scheduler: run `php.exe spark stats:refresh` from the project folder every 5 minutes, and `wallet:settle` daily.

## All clients page
Search, filters, sorting and paging run in SQL on `tenant_stats_cache` (master DB). Rows are refreshed when older than
5 minutes (a few per page view) and by `stats:refresh`. Wallet actions and country changes refresh that client at once.
The Refresh button recomputes every client.

## Tests
`php tests/WalletPaymentsUnitTest.php` (price maths, totals, signatures, invoice numbers, country to currency).
