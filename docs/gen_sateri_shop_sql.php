<?php
// php docs/gen_sateri_shop_sql.php > docs/sateri_shop_chatbot_workflows.sql
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Libraries\WorkflowGraph;

const PH = '+91 89990 87682';

function q(string $s): string { return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $s) . "'"; }
function js($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

function txt(string $id, string $text): array
{
    return ['id' => $id, 'type' => 'action', 'x' => 0, 'y' => 0, 'data' => ['action_type' => 'send_text', 'text' => $text, 'label' => $id]];
}
function ask(string $id, string $text, array $opts, string $type): array
{
    return ['id' => $id, 'type' => 'action', 'x' => 0, 'y' => 0, 'data' => [
        'action_type' => 'ask_question', 'text' => $text, 'reply_type' => $type, 'options' => implode("\n", $opts),
        'list_button' => 'View options', 'max_retries' => 1, 'timeout_minutes' => 1440, 'label' => $id,
    ]];
}
function edge(string $f, string $t, string $p = 'out'): array { return ['from' => $f, 'to' => $t, 'port' => $p]; }

$welcome = "Hello! Welcome to Sateri Shop.\nThank you for connecting with us.\nWe offer premium Konkan Mango Pulp and Konkan Cashews.\nWhat are you interested in?\nPlease select an option to explore our products.";

$orderText = function (string $what): string {
    return "You can place your order online or request a {$what} sample using our website.\n\nSample Policy:\nSamples are chargeable and are not free. Once you place a bulk order, the sample charges can be adjusted against your next order by providing additional product of equivalent value, subject to confirmation.\n\nFor assistance, contact our team:\nCall / WhatsApp: " . PH;
};

$mangoWholesale = "Thank you for your interest in our Mango Pulp products.\nWholesale pricing (per tin):\n\n🥭 RASRAJ PURE HAPUS MANGO PULP\nMP-01 – 850 gm\n• 12 tins: ₹325/tin\n• 120 tins: ₹275/tin\n• Above 120 tins: ₹225/tin\nMP-02 – 3.1 kg\n• 6 tins: ₹800/tin\n• 60 tins: ₹700/tin\n• Above 60 tins: ₹600/tin\n\n🥭 MYKO SWEETENED MIX MANGO PULP\nMP-03 – 850 gm\n• 12 tins: ₹275/tin\n• 120 tins: ₹225/tin\n• Above 120 tins: ₹175/tin\nMP-04 – 3.1 kg\n• 6 tins: ₹800/tin\n• 60 tins: ₹600/tin\n• Above 60 tins: ₹500/tin\n\n🥭 MYKO TOTAPURI MANGO PULP\nMP-05 – 3.1 kg\n• 6 tins: ₹250/tin\n• 60 tins: ₹200/tin\n• Above 60 tins: ₹165/tin\n\nGST: 5% extra\nCourier charges: Extra, as per delivery location\n\nFor bulk orders, quotations or further assistance, contact our sales team.\nCall / WhatsApp: " . PH;

$grades = ['W180', 'W210', 'W240', 'W320', 'W400', 'Wmini Big', 'Wmini Small', 'Big Tukda', 'Small Tukda', 'Pakali', 'Kani BB'];
$cashewWholesale = "Thank you for your interest in Sateri Cashews.\nWe offer wholesale and bulk purchasing options across our cashew grades:\n\n" . implode("\n", array_map(fn ($g, $i) => ($i + 1) . '. ' . $g, $grades, array_keys($grades))) . "\n\nFor the wholesale price list, customised quotations or bulk orders, contact our sales team.\nCall / WhatsApp: " . PH;

$mangoProducts = [
    ['MP-01', 'MP-01 Rasraj 850 gm', "🥭 RASRAJ PURE HAPUS MANGO PULP\nProduct Code: MP-01\n\nProduct Details:\n• Pack Size: 850 gm\n• Case Pack: 12 tins\n• Net Case Weight: 10.2 kg\n• MRP: ₹550 per tin\n\nB2B Pricing:\n• 12 tins: ₹325 per tin\n• 120 tins: ₹275 per tin\n• Above 120 tins: ₹225 per tin\n\nGST: 5% applicable.\nCourier Charges: Extra as per delivery location.\n\nWhat would you like to do next?"],
    ['MP-02', 'MP-02 Rasraj 3.1 kg', "🥭 RASRAJ PURE HAPUS MANGO PULP\nProduct Code: MP-02\nPack Size: 3.1 kg\n\nB2B Pricing:\n• 6 tins: ₹800 per tin\n• 60 tins: ₹700 per tin\n• Above 60 tins: ₹600 per tin\n\nGST: 5% applicable.\nCourier Charges: Extra as per delivery location.\n\nWhat would you like to do next?"],
    ['MP-03', 'MP-03 MyKo Mix 850 gm', "🥭 MYKO SWEETENED MIX MANGO PULP\nProduct Code: MP-03\nPack Size: 850 gm\n\nB2B Pricing:\n• 12 tins: ₹275 per tin\n• 120 tins: ₹225 per tin\n• Above 120 tins: ₹175 per tin\n\nGST: 5% applicable.\nCourier Charges: Extra as per delivery location.\n\nWhat would you like to do next?"],
    ['MP-04', 'MP-04 MyKo Mix 3.1 kg', "🥭 MYKO SWEETENED MIX MANGO PULP\nProduct Code: MP-04\nPack Size: 3.1 kg\n\nB2B Pricing:\n• 6 tins: ₹800 per tin\n• 60 tins: ₹600 per tin\n• Above 60 tins: ₹500 per tin\n\nGST: 5% applicable.\nCourier Charges: Extra as per delivery location.\n\nWhat would you like to do next?"],
    ['MP-05', 'MP-05 Totapuri 3.1 kg', "🥭 MYKO TOTAPURI MANGO PULP\nProduct Code: MP-05\nPack Size: 3.1 kg\n\nB2B Pricing:\n• 6 tins: ₹250 per tin\n• 60 tins: ₹200 per tin\n• Above 60 tins: ₹165 per tin\n\nGST: 5% applicable.\nCourier Charges: Extra as per delivery location.\n\nWhat would you like to do next?"],
];

$oP = $orderText('product');
$oC = $orderText('cashew');
/** Mango subgraph. Returns [nodes, edges, entryId]. */
function mango(string $p, array $products, string $orderText, string $wholesale): array
{
    $n = [];
    $e = [];
    $n[] = txt("{$p}m_first", "Thank you for your interest in Sateri Mango Pulp!\nWelcome to our Mango Pulp collection.\nWe offer different varieties and pack sizes suitable for retailers, wholesalers, distributors, hotels, restaurants and food businesses.");
    $n[] = ask("{$p}m_list", "🥭 OUR MANGO PULP COLLECTION\nPlease select the Mango Pulp product you would like to explore.", array_column($products, 1), 'list');
    $n[] = txt("{$p}m_order", $orderText);
    $n[] = txt("{$p}m_whole", $wholesale);
    $e[] = edge("{$p}m_first", "{$p}m_list");
    foreach ($products as $i => $pr) {
        $id = "{$p}m_p" . ($i + 1);
        $n[] = ask($id, $pr[2], ['Order / Sample', 'Wholesale / Bulk', 'Other Products'], 'buttons');
        $e[] = edge("{$p}m_list", $id, 'opt_' . ($i + 1));
        $e[] = edge($id, "{$p}m_order", 'opt_1');
        $e[] = edge($id, "{$p}m_whole", 'opt_2');
        $e[] = edge($id, "{$p}m_list", 'opt_3');
    }

    return [$n, $e, "{$p}m_first"];
}

/** Cashew subgraph (WhatsApp lists hold 10 rows, so the 11 grades are split over two pages). */
function cashew(string $p, array $grades, string $orderText, string $wholesale): array
{
    $n = [];
    $e = [];
    $page1 = array_merge(array_slice($grades, 0, 9), ['More grades >>']);
    $page2 = array_merge(array_slice($grades, 9), ['<< Previous grades']);
    $n[] = txt("{$p}c_first", "Thank you for your interest in Sateri Cashews!\nWelcome to our Cashew collection.\nWe offer different cashew grades suitable for retailers, wholesalers, distributors, supermarkets, hotels, restaurants and food businesses.");
    $n[] = ask("{$p}c_l1", "🥜 OUR CASHEW COLLECTION\nPlease select the cashew grade you would like to explore.", $page1, 'list');
    $n[] = ask("{$p}c_l2", "🥜 MORE CASHEW GRADES\nPlease select the cashew grade you would like to explore.", $page2, 'list');
    $n[] = txt("{$p}c_order", $orderText);
    $n[] = txt("{$p}c_whole", $wholesale);
    $e[] = edge("{$p}c_first", "{$p}c_l1");
    $e[] = edge("{$p}c_l1", "{$p}c_l2", 'opt_10');
    $e[] = edge("{$p}c_l2", "{$p}c_l1", 'opt_3');
    foreach ($grades as $i => $g) {
        $id = "{$p}c_g" . ($i + 1);
        $n[] = ask($id, "🥜 SATERI CASHEWS – {$g}\nProduct Code: {$g}\n\nFor today's B2B price, pack size and availability of this grade, choose Wholesale / Bulk below or contact our team: " . PH . "\nGST and courier charges are extra as applicable.\n\nWhat would you like to do next?", ['Order / Sample', 'Wholesale / Bulk', 'Other Grades'], 'buttons');
        if ($i < 9) {
            $e[] = edge("{$p}c_l1", $id, 'opt_' . ($i + 1));
        } else {
            $e[] = edge("{$p}c_l2", $id, 'opt_' . ($i - 8));
        }
        $e[] = edge($id, "{$p}c_order", 'opt_1');
        $e[] = edge($id, "{$p}c_whole", 'opt_2');
        $e[] = edge($id, "{$p}c_l1", 'opt_3');
    }

    return [$n, $e, "{$p}c_first"];
}

// ---- keyword sets ------------------------------------------------------------------------------
$kw = [
    'welcome' => ['Hi', 'Hello', 'Hey', 'Hii', 'Good morning', 'Good afternoon', 'Good evening', 'Start', 'Start shopping', 'Welcome', 'Menu', 'Main menu', 'Products', 'Product', 'Catalogue', 'Catalog', 'Price', 'Pricing', 'Enquiry', 'Inquiry', 'Order', 'Shopping', 'Buy', 'Sateri', 'Sateri Shop', 'नमस्कार', 'हॅलो', 'नमस्ते', 'Start Again', 'Restart', 'Home', 'Back', 'Go Back', 'Reset', 'Other Products', 'More Products', 'Explore Products', 'View Products', 'Product List', 'Change Product', 'Another Product', 'Back to Products'],
    'mango' => ['Mango', 'Mango Pulp', 'Mango Puree', 'Hapus', 'Alphonso', 'Alphonso Mango', 'Hapus Mango', 'Mango Tin', 'Mango Products', 'Mango Pulp Price', 'Mango Pulp Catalogue', 'Rasraj', 'MyKo', 'Totapuri', 'Sweetened Mango Pulp', 'आम', 'आंबा', 'आंबा पल्प', 'हापूस', 'आंबा प्युरी', 'MP-01', 'MP-02', 'MP-03', 'MP-04', 'MP-05'],
    'cashew' => ['Cashew', 'Cashews', 'Kaju', 'Kaju Nuts', 'Cashew Nuts', 'Cashew Kernel', 'Cashew Products', 'Cashew Price', 'Cashew Catalogue', 'W180', 'W210', 'W240', 'W320', 'W400', 'Wmini Big', 'Wmini Small', 'Big Tukda', 'Small Tukda', 'Pakali', 'Kani BB', 'काजू', 'काजू बी', 'काजू दर'],
    'wholesale' => ['Wholesale', 'Bulk', 'Bulk Order', 'Wholesale Price', 'B2B', 'Distributor', 'Distribution', 'Dealer', 'Retailer', 'Business Enquiry', 'Bulk Purchase', 'Bulk Pricing', 'Wholesale Catalogue', 'Mango Pulp Wholesale', 'Mango Pulp Bulk', 'Cashew Wholesale', 'Cashew Bulk', 'थोक', 'होलसेल', 'मोठी ऑर्डर', 'काजू होलसेल'],
    'sample' => ['Sample', 'Request Sample', 'Product Sample', 'Sample Order', 'Sample Request', 'Trial Sample', 'Testing Sample', 'नमुना', 'सॅम्पल', 'Buy Online', 'Online Order', 'Place Order', 'Order Now', 'Purchase', 'Website Order', 'ऑर्डर', 'ऑनलाइन ऑर्डर'],
    'contact' => ['Contact', 'Contact Us', 'Sales', 'Talk to Sales', 'Sales Team', 'Call', 'WhatsApp', 'Customer Support', 'Help', 'Assistance', 'Representative', 'सेल्स', 'संपर्क', 'मदत'],
];
// every keyword belongs to one workflow only (welcome wins; Mango/Cashew stay with their own flow)
$norm = fn (string $s) => mb_strtolower(trim($s));
$kw['welcome'] = array_values(array_filter($kw['welcome'], fn ($k) => ! in_array($norm($k), ['mango', 'cashew'], true)));
$taken = array_map($norm, $kw['welcome']);
foreach (['mango', 'cashew', 'wholesale', 'sample', 'contact'] as $k) {
    $kw[$k] = array_values(array_filter($kw[$k], function ($w) use (&$taken, $norm) {
        $x = $norm($w);
        if (in_array($x, $taken, true)) {
            return false;
        }
        $taken[] = $x;

        return true;
    }));
}

// ---- workflows ---------------------------------------------------------------------------------
$wf = [];

// 1 Welcome (full journey)
[$mn, $me, $mEntry] = mango('', $mangoProducts, $oP, $mangoWholesale);
[$cn, $ce, $cEntry] = cashew('', $grades, $oC, $cashewWholesale);
$nodes = array_merge([ask('w_ask', $welcome, ['Mango Pulp', 'Cashew'], 'buttons')], $mn, $cn);
$edges = array_merge([edge('trigger', 'w_ask'), edge('w_ask', $mEntry, 'opt_1'), edge('w_ask', $cEntry, 'opt_2')], $me, $ce);
$wf[] = ['Sateri Shop - 1 Welcome menu', 'welcome', $nodes, $edges];

// 2 Mango, 3 Cashew
$wf[] = ['Sateri Shop - 2 Mango Pulp', 'mango', $mn, array_merge([edge('trigger', $mEntry)], $me)];
$wf[] = ['Sateri Shop - 3 Cashew', 'cashew', $cn, array_merge([edge('trigger', $cEntry)], $ce)];

// 4 Wholesale: pick category
$wf[] = ['Sateri Shop - 4 Wholesale enquiry', 'wholesale', [
    ask('ws_ask', "Thank you for your interest in our wholesale / bulk options.\nWhich category would you like the wholesale details for?", ['Mango Pulp', 'Cashew'], 'buttons'),
    txt('ws_m', $mangoWholesale), txt('ws_c', $cashewWholesale),
], [edge('trigger', 'ws_ask'), edge('ws_ask', 'ws_m', 'opt_1'), edge('ws_ask', 'ws_c', 'opt_2')]];

// 5 Sample / online order
$wf[] = ['Sateri Shop - 5 Order or sample', 'sample', [txt('s1', $oP)], [edge('trigger', 's1')]];

// 6 Contact
$wf[] = ['Sateri Shop - 6 Contact sales', 'contact', [txt('ct1', "Please contact our sales team:\nCall / WhatsApp: " . PH . "\nhttps://wa.me/918999087682")], [edge('trigger', 'ct1')]];

// ---- SQL ---------------------------------------------------------------------------------------
$now = date('Y-m-d H:i:s');
$g   = new WorkflowGraph();
echo "-- Sateri Shop chatbot workflows (generated {$now}). Import into the CLIENT (tenant) database only.\n";
echo "-- Re-runnable: removes workflows with ids 9101-9106 first. Needs tables: automations, automation_rules.\n";
echo "SET NAMES utf8mb4;\nSTART TRANSACTION;\n";
echo "DELETE FROM automation_rules WHERE automation_id BETWEEN 9101 AND 9106;\nDELETE FROM automations WHERE id BETWEEN 9101 AND 9106;\n\n";

foreach ($wf as $i => [$name, $key, $ns, $es]) {
    $aid = 9101 + $i;
    $tc  = ['keyword' => implode(', ', $kw[$key]), 'match_type' => 'exact'];
    $graph = [
        'nodes' => array_merge([['id' => 'trigger', 'type' => 'trigger', 'x' => 40, 'y' => 40, 'data' => ['trigger_type' => 'keyword', 'label' => 'Keyword matched'] + $tc]], $ns),
        'edges' => $es,
        'normalize_version' => 5,
        'source' => 'sateri_shop_sql',
    ];
    $errs = $g->validate($graph);
    if ($errs) {
        fwrite(STDERR, $name . ': ' . implode('; ', $errs) . "\n");
    }
    $rules = $g->toRules($graph);
    echo "-- {$name}\n";
    echo "INSERT INTO automations (id,name,trigger_type,trigger_config,flow_graph,is_active,priority,created_by,created_at,updated_at) VALUES ({$aid}," . q($name) . ",'keyword'," . q(js($tc)) . ',' . q(js($graph)) . ",1,5,NULL,'{$now}','{$now}');\n";
    $base = $aid * 100;
    foreach ($rules as $r) {
        $cfg = is_array($r['config']) ? js($r['config']) : (string) $r['config'];
        $nt  = $r['next_on_true'] ?? null;
        $nf  = $r['next_on_false'] ?? null;
        echo 'INSERT INTO automation_rules (id,automation_id,step_order,rule_type,action_type,config,next_on_true,next_on_false,created_at,updated_at) VALUES ('
            . ($base + (int) $r['step_order']) . ",{$aid}," . (int) $r['step_order'] . ',' . q((string) $r['rule_type']) . ',' . q((string) $r['action_type']) . ',' . q($cfg) . ','
            . ($nt === null ? 'NULL' : (int) $nt) . ',' . ($nf === null ? 'NULL' : (int) $nf) . ",'{$now}','{$now}');\n";
    }
    echo "\n";
}
echo "COMMIT;\n";
