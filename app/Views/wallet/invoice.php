<?php
/**
 * Printable invoice for an online wallet recharge.
 *
 * @var array<string, mixed> $p       wallet_payments row
 * @var array<string, string> $seller  platform company name, address, GSTIN
 * @var string $client
 * @var bool $autoPrint
 */
$cur = (string) ($p['currency'] ?? \App\Libraries\Currency::base());
$sym = \App\Libraries\WhatsAppCountries::symbolOf((string) $cur);
$m   = static fn ($n): string => number_format((float) $n, 2);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invoice <?= esc((string) $p['invoice_no']) ?></title>
<style>
    body { font-family: Arial, Helvetica, sans-serif; color: #1c2530; margin: 0; padding: 32px; background: #fff; }
    .inv { max-width: 760px; margin: 0 auto; }
    .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; border-bottom: 2px solid #1c2530; padding-bottom: 16px; }
    h1 { margin: 0; font-size: 26px; letter-spacing: 0.04em; }
    .muted { color: #667085; font-size: 12px; line-height: 1.5; white-space: pre-line; }
    .meta { text-align: right; font-size: 13px; line-height: 1.6; }
    .parties { display: flex; justify-content: space-between; gap: 24px; margin: 22px 0; }
    .parties h3 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: #667085; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th, td { padding: 10px 8px; border-bottom: 1px solid #e4e7ec; text-align: left; }
    th { background: #f2f4f7; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
    .num { text-align: right; }
    .total td { font-weight: 700; font-size: 16px; border-top: 2px solid #1c2530; border-bottom: 0; }
    .paid { display: inline-block; margin-top: 18px; padding: 4px 12px; border: 2px solid #16a34a; color: #16a34a; font-weight: 700; border-radius: 6px; letter-spacing: 0.08em; }
    .foot { margin-top: 28px; font-size: 12px; color: #667085; }
    .actions { max-width: 760px; margin: 0 auto 16px; text-align: right; }
    .actions button { padding: 8px 16px; border: 0; border-radius: 8px; background: #e11d48; color: #fff; font-weight: 700; cursor: pointer; }
    @media print { .actions { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="actions"><button type="button" onclick="window.print()">Print / Save as PDF</button></div>
<div class="inv">
    <div class="top">
        <div>
            <h1>TAX INVOICE</h1>
            <div style="margin-top:8px;font-weight:700"><?= esc($seller['name'] !== '' ? $seller['name'] : 'Sateri Connect') ?></div>
            <div class="muted"><?= esc($seller['address']) ?></div>
            <?php if ($seller['gstin'] !== ''): ?><div class="muted">GSTIN: <?= esc($seller['gstin']) ?></div><?php endif; ?>
        </div>
        <div class="meta">
            <div><strong>Invoice no:</strong> <?= esc((string) $p['invoice_no']) ?></div>
            <div><strong>Date:</strong> <?= esc(date('d M Y', strtotime((string) $p['paid_at']))) ?></div>
            <div><strong>Payment ref:</strong> <?= esc((string) $p['payment_id']) ?></div>
        </div>
    </div>

    <div class="parties">
        <div><h3>Billed to</h3><div style="font-weight:700"><?= esc($client) ?></div></div>
        <div style="text-align:right"><h3>Payment status</h3><span class="paid">PAID</span></div>
    </div>

    <table>
        <thead><tr><th>Description</th><th class="num">Amount</th></tr></thead>
        <tbody>
            <tr><td>WhatsApp wallet recharge (prepaid credit)</td><td class="num"><?= $sym . $m($p['credit_amount']) ?></td></tr>
            <tr><td>GST @ <?= esc(rtrim(rtrim(number_format((float) $p['tax_percent'], 2, '.', ''), '0'), '.')) ?>%</td><td class="num"><?= $sym . $m($p['tax_amount']) ?></td></tr>
            <tr class="total"><td>Total paid</td><td class="num"><?= $sym . $m($p['total_amount']) ?></td></tr>
        </tbody>
    </table>

    <div class="foot">This is a computer generated invoice. The recharge amount is added to your WhatsApp wallet and is used to pay for WhatsApp messages.</div>
</div>
<?php if (! empty($autoPrint)): ?><script>window.addEventListener('load', function () { window.print(); });</script><?php endif; ?>
</body>
</html>
