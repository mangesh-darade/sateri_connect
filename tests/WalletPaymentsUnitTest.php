<?php

/**
 * Pure-logic checks for the WhatsApp wallet: price maths, Razorpay signatures and totals, invoice numbers,
 * country to currency. No database and no network.
 *
 * Run: php tests/WalletPaymentsUnitTest.php
 */

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Libraries\MswipeService;
use App\Libraries\RazorpayService;
use App\Libraries\WalletService;
use App\Libraries\WhatsAppCountries;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

function near(float $a, float $b, float $eps = 0.00011): bool
{
    return abs($a - $b) <= $eps;
}

echo "=== Wallet + payments unit test ===\n\n";

// ---- price: Meta price + markup, then GST on that
$p = WalletService::price(0.8631, 0, 0);
check('no markup, no GST passes the Meta price', near($p['amount'], 0.8631) && near($p['tax'], 0));
$p = WalletService::price(0.8631, 20, 18);
check('20% markup then 18% GST', near($p['amount'], round(0.8631 * 1.2 * 1.18, 4)));
check('GST is charged on the marked-up price', near($p['tax'], round(0.8631 * 1.2 * 0.18, 4)));
check('base stays the Meta price', near($p['base'], 0.8631));
check('margin = amount - tax - base = markup', near($p['amount'] - $p['tax'] - $p['base'], 0.8631 * 0.2, 0.0002));
$p = WalletService::price(1.0, -50, -10);
check('negative markup / GST are ignored', near($p['amount'], 1.0));
check('zero price stays zero', WalletService::price(0.0, 30, 18)['amount'] === 0.0);

// ---- recharge totals
$t = RazorpayService::totals(1000, 18);
check('1000 + 18% GST = 1180', $t['credit'] === 1000.0 && $t['tax'] === 180.0 && $t['total'] === 1180.0);
check('paise is total x 100', $t['paise'] === 118000);
$t = RazorpayService::totals(333.33, 18);
check('rounds to paise, no float drift', $t['tax'] === 60.0 && $t['paise'] === (int) round($t['total'] * 100));
check('0% GST', RazorpayService::totals(500, 0)['total'] === 500.0);
check('negative GST ignored', RazorpayService::totals(500, -5)['total'] === 500.0);

// ---- signatures
$secret = 'test_secret_123';
$sig = hash_hmac('sha256', 'order_ABC|pay_XYZ', $secret);
check('valid checkout signature accepted', RazorpayService::verifyCheckoutSignature('order_ABC', 'pay_XYZ', $sig, $secret));
check('wrong payment id rejected', ! RazorpayService::verifyCheckoutSignature('order_ABC', 'pay_OTHER', $sig, $secret));
check('wrong order id rejected', ! RazorpayService::verifyCheckoutSignature('order_OTHER', 'pay_XYZ', $sig, $secret));
check('wrong secret rejected', ! RazorpayService::verifyCheckoutSignature('order_ABC', 'pay_XYZ', $sig, 'nope'));
check('empty signature rejected', ! RazorpayService::verifyCheckoutSignature('order_ABC', 'pay_XYZ', '', $secret));
check('empty secret rejected', ! RazorpayService::verifyCheckoutSignature('order_ABC', 'pay_XYZ', $sig, ''));

$body = '{"event":"payment.captured","payload":{}}';
$wsig = hash_hmac('sha256', $body, 'whsec');
check('valid webhook signature accepted', RazorpayService::verifyWebhookSignature($body, $wsig, 'whsec'));
check('tampered body rejected', ! RazorpayService::verifyWebhookSignature($body . ' ', $wsig, 'whsec'));
check('webhook with no secret configured rejected', ! RazorpayService::verifyWebhookSignature($body, $wsig, ''));

// ---- invoice number
$inv = RazorpayService::invoiceNumber('acme_corp', 42, '2026-10-26 10:00:00');
check('invoice number format', $inv === 'INV-ACMECO-202610-00042');
check('invoice number is stable', $inv === RazorpayService::invoiceNumber('acme_corp', 42, '2026-10-26 23:59:59'));
check('invoice numbers differ per payment', $inv !== RazorpayService::invoiceNumber('acme_corp', 43, '2026-10-26 10:00:00'));
check('odd tenant key is cleaned', str_starts_with(RazorpayService::invoiceNumber('a b-c!', 1, '2026-01-01 00:00:00'), 'INV-ABC-'));

// ---- country to currency / timezone
check('India -> INR', WhatsAppCountries::currencyOf('91') === 'INR');
check('UAE -> AED', WhatsAppCountries::currencyOf('971') === 'AED');
check('UK -> GBP', WhatsAppCountries::currencyOf('44') === 'GBP');
check('unlisted market -> USD', WhatsAppCountries::currencyOf('999') === 'USD');
check('India has a timezone', WhatsAppCountries::timezoneOf('91') !== '');
check('country names resolve', WhatsAppCountries::nameOf('91') !== '');

// ---- mobile number per country (create client screen)
$ph = static fn (string $code, string $in): array => WhatsAppCountries::validatePhone($code, $in);
check('India: 10 digits starting 6-9 accepted', $ph('91', '98765 43210')['ok'] && $ph('91', '98765 43210')['full'] === '919876543210');
check('India: +91 prefix typed in full is accepted', $ph('91', '+91 98765-43210')['national'] === '9876543210');
check('India: 91 prefix without plus accepted', $ph('91', '919876543210')['national'] === '9876543210');
check('India: leading 0 / 0091 accepted', $ph('91', '09876543210')['national'] === '9876543210' && $ph('91', '00919876543210')['national'] === '9876543210');
check('India: starts with 5 rejected', ! $ph('91', '5876543210')['ok']);
check('India: 9 digits rejected', ! $ph('91', '987654321')['ok']);
check('India: 11 digits rejected', ! $ph('91', '98765432101')['ok']);
check('India: letters / empty rejected', ! $ph('91', 'abcdefghij')['ok'] && ! $ph('91', '')['ok']);
check('India error names the country and the rule', str_contains($ph('91', '123')['message'], 'India') && str_contains($ph('91', '123')['message'], '10 digits'));
check('UAE: 9 digit mobile ok, 8 digit ok, 7 rejected', $ph('971', '501234567')['ok'] && $ph('971', '41234567')['ok'] && ! $ph('971', '1234567')['ok']);
check('UK: trunk 0 stripped, 10 digits', $ph('44', '07911 123456')['national'] === '7911123456' && $ph('44', '07911 123456')['full'] === '447911123456');
check('US/Canada: 10 digits, first digit 2-9', $ph('1', '(415) 555-2671')['ok'] && ! $ph('1', '0155552671')['ok'] && ! $ph('1', '1155552671')['ok']);
check('Singapore: exactly 8 digits', $ph('65', '91234567')['ok'] && ! $ph('65', '9123456')['ok'] && ! $ph('65', '912345678')['ok']);
check('country without a specific rule uses 6 to 14 digits (Albania)', $ph('355', '123456')['ok'] && ! $ph('355', '12345')['ok'] && ! $ph('355', '123456789012345')['ok']);
check('"other" / unknown country rejected', ! $ph('other', '9876543210')['ok'] && ! $ph('99999', '9876543210')['ok']);
check('same number is not valid for another country rule', ! $ph('65', '9876543210')['ok']);
check('rules exported for the browser include every country', count(WhatsAppCountries::phoneRules()) === count(WhatsAppCountries::all()));

check('split: stored India number back into code + national', WhatsAppCountries::splitPhone('919876543210', '91') === ['code' => '91', 'national' => '9876543210']);
check('split: another country than the client\'s', WhatsAppCountries::splitPhone('971501234567', '91') === ['code' => '971', 'national' => '501234567']);
check('split: UK number', WhatsAppCountries::splitPhone('447911123456') === ['code' => '44', 'national' => '7911123456']);
check('split: empty keeps the preferred country', WhatsAppCountries::splitPhone('', '91') === ['code' => '91', 'national' => '']);
check('split then validate round-trips', (function () { $s = WhatsAppCountries::splitPhone('919876543210', '91'); return WhatsAppCountries::validatePhone($s['code'], $s['national'])['full'] === '919876543210'; })());

// ---- Mswipe pay by link
check('status true (JSON boolean) is true', MswipeService::truthy(true));
check('status "True" / "true" / "1" are true', MswipeService::truthy('True') && MswipeService::truthy('true') && MswipeService::truthy('1'));
check('status false / "False" / empty are false', ! MswipeService::truthy(false) && ! MswipeService::truthy('False') && ! MswipeService::truthy('') && ! MswipeService::truthy(null));
check('status response with boolean true parses', MswipeService::parseStatus(['Status' => true, 'Data' => [['Payment_Status' => 1, 'Amount' => 5]]])['state'] === 'paid');
check('amount is rupees with two decimals', MswipeService::amountString(1180) === '1180.00' && MswipeService::amountString(0.5) === '0.50');
check('amount rounds to paise', MswipeService::amountString(333.333) === '333.33');
$oid = MswipeService::newOrderId();
check('order id is letters and digits, short', preg_match('/^[A-Z0-9]{8,24}$/', $oid) === 1);
check('order ids are unique', $oid !== MswipeService::newOrderId());
check('TransID read from the link', MswipeService::transIdFromLink('https://dcuat.mswipetech.co.in/pay-by-link/payment-options?TransID=NUs1Qit6OVROZ2tKYStEejNtNkpoZz09') === 'NUs1Qit6OVROZ2tKYStEejNtNkpoZz09');
check('link without TransID gives empty', MswipeService::transIdFromLink('https://x.test/pay') === '');
$mk = static fn (int|string $code, string $status = 'True'): array => ['Status' => $status, 'Data' => [['Payment_Status' => $code, 'Amount' => 1180.00, 'Order_Id' => 'WABC', 'IPG_ID' => 'IPG1', 'Payment_Id' => 'RRN1']]];
check('status 1 = paid', MswipeService::parseStatus($mk(1))['state'] === 'paid');
check('status 0 = failed', MswipeService::parseStatus($mk(0))['state'] === 'failed');
check('status 2 = pending', MswipeService::parseStatus($mk(2))['state'] === 'pending');
check('unexpected status code = unknown, never paid', MswipeService::parseStatus($mk(7))['state'] === 'unknown');
check('Status False = unknown even with data', MswipeService::parseStatus($mk(1, 'False'))['state'] === 'unknown');
check('"No Data Found" reply = unknown', MswipeService::parseStatus(['ResponseMessage' => 'No Data Found', 'ResponseCode' => '30'])['state'] === 'unknown');
check('empty reply = unknown', MswipeService::parseStatus([])['state'] === 'unknown');
$st = MswipeService::parseStatus($mk(1));
check('status fields parsed', $st['amount'] === 1180.0 && $st['order_id'] === 'WABC' && $st['ipg_id'] === 'IPG1' && $st['rrn'] === 'RRN1');

// ---- currency symbols follow the client's currency
check('INR symbol', WhatsAppCountries::symbolOf('INR') === '₹');
check('USD / EUR / GBP symbols', WhatsAppCountries::symbolOf('USD') === '$' && WhatsAppCountries::symbolOf('EUR') === '€' && WhatsAppCountries::symbolOf('GBP') === '£');
check('AED has no common symbol, so it shows the code and a space', WhatsAppCountries::symbolOf('AED') === 'AED ');
check('symbol lookup ignores case and spaces', WhatsAppCountries::symbolOf(' inr ') === '₹');
check('unknown or empty currency', WhatsAppCountries::symbolOf('XYZ') === 'XYZ ' && WhatsAppCountries::symbolOf('') === '');

echo "\nPassed: {$pass}  Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
