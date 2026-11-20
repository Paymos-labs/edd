<?php

declare(strict_types=1);

use PaymosEasyDigitalDownloads\Gateway;

function test_edd_gateway_creates_invoice_stores_snapshot_and_redirects()
{
    paymos_edd_reset_test_state();
    paymos_edd_write_generated_config("array(
        'environments' => array(
            'sandbox' => array(
                'api_key' => 'pk_test_123',
                'api_secret' => 'sk_test_123',
                'project_id' => 'prj_123',
                'webhook_secret' => 'whsec_sandbox',
                'base_url' => 'https://api.paymos.test',
            ),
        ),
    )");

    $invoices = new FakePaymosInvoices();
    Gateway::set_client_factory_for_tests(static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    Gateway::process_payment(paymos_edd_purchase());

    assertSameValue(1, count($invoices->payloads), 'One Paymos invoice should be created.');
    assertSameValue('100', $invoices->payloads[0]['amount'], 'Invoice amount should match purchase.');
    assertSameValue('USD', $invoices->payloads[0]['currency'], 'Invoice currency should match EDD currency.');
    assertTrueValue((bool) preg_match('/^edd_100_[a-f0-9]{32}$/', $invoices->payloads[0]['external_order_id']), 'External order id must be opaque and bound to EDD payment id.');

    assertSameValue('inv_123', $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_invoice_id'], 'Invoice id should be stored.');
    assertSameValue('sandbox', $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_environment'], 'Environment should be stored.');
    assertSameValue('100', $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_invoice_amount'], 'Amount snapshot should be stored.');
    assertSameValue(array(0),$GLOBALS['paymos_edd_locks_at_success_exit'],'Order mutex must be released before production redirect can exit.');
    assertSameValue('https://checkout.paymos.io/pay/inv_123', end($GLOBALS['paymos_edd_redirects']), 'Customer should redirect to Paymos.');
}

if (!function_exists('get_post')) {
    function get_post($postId = null)
    {
        $map = isset($GLOBALS['paymos_edd_test_posts']) && is_array($GLOBALS['paymos_edd_test_posts'])
            ? $GLOBALS['paymos_edd_test_posts']
            : array();
        $id = (int) $postId;
        return array_key_exists($id, $map) ? $map[$id] : null;
    }
}

function paymos_edd_configure_sandbox_for_gateway_tests()
{
    paymos_edd_write_generated_config("array(
        'environments' => array(
            'sandbox' => array(
                'api_key' => 'pk_test_123',
                'api_secret' => 'sk_test_123',
                'project_id' => 'prj_123',
                'webhook_secret' => 'whsec_sandbox',
                'base_url' => 'https://api.paymos.test',
            ),
        ),
    )");
}

function test_edd_gateway_sends_single_and_multi_item_cart_with_all_or_nothing_fallback()
{
    paymos_edd_reset_test_state();
    paymos_edd_configure_sandbox_for_gateway_tests();
    $GLOBALS['paymos_edd_test_posts'] = array(
        10 => (object) array('ID' => 10, 'post_excerpt' => "<p>Studio &quot;Pro&quot;\\v1<br>Line 2 &mdash; Ω</p>", 'post_content' => ''),
        11 => (object) array('ID' => 11, 'post_excerpt' => '&nbsp;', 'post_content' => ''),
    );

    // 1. Single product with post excerpt.
    $single = new FakePaymosInvoices();
    Gateway::set_client_factory_for_tests(static function () use ($single) { return new FakePaymosClient($single); });
    Gateway::process_payment(paymos_edd_purchase(array(
        'price' => '47.00',
        'cart_details' => array(
            array('id' => 10, 'name' => '<b>Canvas</b> &middot; Blue', 'quantity' => 2, 'item_price' => '24.50'),
        ),
    )));
    assertSameValue('47', $single->payloads[0]['amount'], 'Native grand total stays distinct from item subtotal.');
    assertSameValue(array(
        array(
            'title' => 'Canvas · Blue',
            'quantity' => 2,
            'unit_amount' => '24.50',
            'description' => "Studio \"Pro\"\\v1\nLine 2 — Ω",
        ),
    ), $single->payloads[0]['products'], 'Single EDD download must normalize title and description.');
    $rawMeta = $GLOBALS['paymos_edd_payment_meta'][100][Gateway::CREATION_PRODUCTS_META];
    assertTrueValue(is_string($rawMeta) && preg_match('/\A[A-Za-z0-9+\/=]+\z/', $rawMeta) === 1, 'EDD must persist _paymos_creation_products as pure ASCII base64.');
    assertSameValue($single->payloads[0]['products'], Gateway::decodeCreationProducts($rawMeta), 'Base64 round-trip must preserve quotes, backslashes, newlines, and Unicode.');

    // 2. Multi-item cart (including item without optional fields) + non-product adjustments excluded.
    paymos_edd_reset_test_state();
    paymos_edd_configure_sandbox_for_gateway_tests();
    $GLOBALS['paymos_edd_test_posts'] = array(
        10 => (object) array('ID' => 10, 'post_excerpt' => 'Fine print', 'post_content' => ''),
        11 => (object) array('ID' => 11, 'post_excerpt' => '&nbsp;', 'post_content' => ''),
    );
    $cart = new FakePaymosInvoices();
    Gateway::set_client_factory_for_tests(static function () use ($cart) { return new FakePaymosClient($cart); });
    Gateway::process_payment(paymos_edd_purchase(array(
        'price' => '42.50',
        'fees' => array('handling' => array('amount' => '5.00', 'label' => 'Handling')),
        'cart_details' => array(
            array('id' => 10, 'name' => 'Canvas', 'quantity' => 2, 'item_price' => '15.00'),
            array('id' => 11, 'name' => 'Notebook', 'quantity' => 1, 'item_price' => '12.50'),
            array('name' => 'Shipping', 'quantity' => 1, 'item_price' => '5.00', 'type' => 'shipping'),
            array('name' => 'Discount', 'quantity' => 1, 'item_price' => '-5.00', 'type' => 'discount'),
        ),
    )));
    assertSameValue('42.5', $cart->payloads[0]['amount'], 'Multi-item native grand total preserved.');
    assertSameValue(array(
        array(
            'title' => 'Canvas',
            'quantity' => 2,
            'unit_amount' => '15.00',
            'description' => 'Fine print',
        ),
        array(
            'title' => 'Notebook',
            'quantity' => 1,
            'unit_amount' => '12.50',
        ),
    ), $cart->payloads[0]['products'], 'Multi-item cart must omit blank descriptions and skip non-product lines.');

    // 3. All-or-nothing fallback on 51 items and fractional quantity.
    foreach (array(
        'over50' => array_fill(0, 51, array('id' => 1, 'name' => 'Item', 'quantity' => 1, 'item_price' => '1.00')),
        'fractional' => array(
            array('id' => 1, 'name' => 'Valid', 'quantity' => 1, 'item_price' => '20.00'),
            array('id' => 2, 'name' => 'Fractional', 'quantity' => 1.5, 'item_price' => '10.00'),
        ),
    ) as $case => $details) {
        paymos_edd_reset_test_state();
        paymos_edd_configure_sandbox_for_gateway_tests();
        $inv = new FakePaymosInvoices();
        Gateway::set_client_factory_for_tests(static function () use ($inv) { return new FakePaymosClient($inv); });
        Gateway::process_payment(paymos_edd_purchase(array('price' => '35.00', 'cart_details' => $details)));
        assertSameValue(false, array_key_exists('products', $inv->payloads[0]), $case . ': must fall back all-or-nothing to amount-only.');
        assertSameValue('', $GLOBALS['paymos_edd_payment_meta'][100][Gateway::CREATION_PRODUCTS_META], $case . ': meta must be empty on amount-only fallback.');
    }

    unset($GLOBALS['paymos_edd_test_posts']);
}

function test_edd_gateway_retry_preserves_encoded_products_clears_stale_on_replacement_and_allows_legacy()
{
    // 1. Strict decoder behavior and special character round-trip.
    assertSameValue(null, Gateway::decodeCreationProducts(''), 'Empty meta returns null (legacy amount-only).');
    assertSameValue(null, Gateway::decodeCreationProducts('{"not":"base64"}'), 'Raw JSON must be rejected by strict base64 decoder.');
    assertSameValue(null, Gateway::decodeCreationProducts("YWJj\n"), 'Whitespace inside base64 must be rejected.');
    assertSameValue(null, Gateway::decodeCreationProducts(base64_encode('[{"title":"Bad","quantity":1.5,"unit_amount":"1.00"}]')), 'Invalid item schema must be rejected.');

    assertSameValue(null, Gateway::decodeCreationProducts(base64_encode('[{"title":"A","quantity":1,"unit_amount":"1.00","image_url":"legacy","unknown_field":true}]')), 'Ignoring retired images must not allow any other unknown field.');

    // 2. Lost response on first create persists _paymos_creation_products before POST;
    //    resumed retry with changed cart replays exact original products[] A.
    paymos_edd_reset_test_state();
    paymos_edd_configure_sandbox_for_gateway_tests();
    $attempts = array();
    $lost = true;
    $invoices = new class($attempts, $lost) extends FakePaymosInvoices {
        private $attempts;
        private $lost;
        public function __construct(&$attempts, &$lost) { parent::__construct(); $this->attempts = &$attempts; $this->lost = &$lost; }
        public function create(array $payload)
        {
            $this->attempts[] = $payload;
            if ($this->lost) {
                throw new RuntimeException('Timeout after server accepted create.');
            }
            return array('invoice_id' => 'inv_recovered', 'payment_url' => 'https://checkout.paymos.io/pay/inv_recovered', 'status' => 'awaiting_client');
        }
    };
    Gateway::set_client_factory_for_tests(static function () use ($invoices) { return new FakePaymosClient($invoices); });

    $originalPurchase = paymos_edd_purchase(array(
        'price' => '42.50',
        'cart_details' => array(
            array(
                'id' => 10,
                'name' => 'Edition "Alpha" \\ &lt;1&gt;',
                'description' => "Line 1\nLine 2 — Ω 🚀",
                'quantity' => 2,
                'item_price' => '15.00',
            ),
            array('id' => 11, 'name' => 'Notebook', 'quantity' => 1, 'item_price' => '12.50'),
        ),
    ));
    Gateway::process_payment($originalPurchase);
    assertSameValue('pending', $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_creation_intent'], 'Failed response keeps pending state.');
    $savedMeta = $GLOBALS['paymos_edd_payment_meta'][100][Gateway::CREATION_PRODUCTS_META];
    assertTrueValue($savedMeta !== '', 'Encoded products must be persisted before POST.');

    $historicalProducts = json_decode(base64_decode($savedMeta, true), true);
    $historicalProducts[0]['image_url'] = 'https://shop.example/legacy.webp';
    $historicalProducts[1]['image_url'] = null;
    $savedMeta = base64_encode(json_encode($historicalProducts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $GLOBALS['paymos_edd_payment_meta'][100][Gateway::CREATION_PRODUCTS_META] = $savedMeta;

    // Resume payment 100 with a totally different cart (same total 42.50): retry must send exact original products[] A.
    $GLOBALS['paymos_edd_resume_id'] = 100;
    $GLOBALS['paymos_edd_errors'] = array();
    $lost = false;
    Gateway::process_payment(paymos_edd_purchase(array(
        'price' => '42.50',
        'cart_details' => array(
            array('id' => 99, 'name' => 'Changed Product', 'quantity' => 1, 'item_price' => '42.50'),
        ),
    )));
    assertSameValue(2, count($attempts), 'Resumed retry sends one idempotent POST.');
    assertSameValue($attempts[0], $attempts[1], 'Retry POST must replay exact original payload despite changed cart_details.');
    assertSameValue($savedMeta, $GLOBALS['paymos_edd_payment_meta'][100][Gateway::CREATION_PRODUCTS_META], 'Ordinary retry must not rewrite historical base64 products meta.');
    assertSameValue('Edition "Alpha" \\ <1>', $attempts[1]['products'][0]['title'], 'Special characters must round-trip intact.');
    assertSameValue("Line 1\nLine 2 — Ω 🚀", $attempts[1]['products'][0]['description'], 'Newlines and Unicode must round-trip intact.');

    // 3. Replacement when amount changes with an invalid/empty cart must clear stale _paymos_creation_products.
    $GLOBALS['paymos_edd_errors'] = array();
    $replaced = new FakePaymosInvoices(array('invoice_id' => 'inv_replaced', 'payment_url' => 'https://checkout.paymos.io/pay/inv_replaced', 'status' => 'awaiting_client'));
    Gateway::set_client_factory_for_tests(static function () use ($replaced) { return new FakePaymosClient($replaced); });
    Gateway::process_payment(paymos_edd_purchase(array(
        'price' => '55.00',
        'cart_details' => array(
            array('id' => 50, 'name' => 'Broken Qty', 'quantity' => 1.5, 'item_price' => '55.00'),
        ),
    )));
    assertSameValue(false, array_key_exists('products', $replaced->payloads[0]), 'Replacement with invalid cart falls back to amount-only.');
    assertSameValue('', $GLOBALS['paymos_edd_payment_meta'][100][Gateway::CREATION_PRODUCTS_META], 'Stale _paymos_creation_products must be cleared on replacement without a valid cart.');

    // 4. Legacy bound payment without _paymos_creation_products replays amount-only safely.
    paymos_edd_reset_test_state();
    paymos_edd_seed_payment();
    $GLOBALS['paymos_edd_resume_id'] = 100;
    $legacy = new FakePaymosInvoices();
    Gateway::set_client_factory_for_tests(static function () use ($legacy) { return new FakePaymosClient($legacy); });
    Gateway::process_payment(paymos_edd_purchase());
    assertSameValue(false, array_key_exists('products', $legacy->payloads[0]), 'Legacy payment without _paymos_creation_products must stay amount-only.');

    // 5. Terminal renewal with empty cart clears stale _paymos_creation_products from an expired generation.
    paymos_edd_reset_test_state();
    paymos_edd_seed_payment();
    $GLOBALS['paymos_edd_resume_id'] = 100;
    $GLOBALS['paymos_edd_payment_meta'][100][Gateway::CREATION_PRODUCTS_META] = $savedMeta;
    $renewalInvoices = new FakeEddRenewalInvoices(array(
        paymos_edd_checkout_invoice('inv_123', 'expired'),
        paymos_edd_checkout_invoice('inv_renewed', 'awaiting_client'),
    ));
    Gateway::set_client_factory_for_tests(static function () use ($renewalInvoices) { return new FakePaymosClient($renewalInvoices); });
    Gateway::process_payment(paymos_edd_purchase(array('price' => '100.00', 'cart_details' => array())));
    assertSameValue(2, count($renewalInvoices->attempts), 'Expired invoice triggers replacement POST.');
    assertTrueValue(array_key_exists('products', $renewalInvoices->attempts[0]), 'Initial check of expired intent replays stored products.');
    assertSameValue(false, array_key_exists('products', $renewalInvoices->attempts[1]), 'Terminal renewal without valid cart clears products from replacement POST.');
    assertSameValue('', $GLOBALS['paymos_edd_payment_meta'][100][Gateway::CREATION_PRODUCTS_META], 'Terminal renewal without valid cart clears stale _paymos_creation_products.');
}

function test_edd_decoder_projects_only_historical_images_without_normalizing_frozen_values()
{
    $historical = array(array(
        'title' => '  <b>Frozen title</b>  ',
        'description' => "  Line 1\nLine 2 — Ω 🚀  ",
        'image_url' => 'https://shop.example/legacy.webp',
        'quantity' => 2,
        'unit_amount' => '12345678901234567890.123456789012345678',
    ));
    $encoded = base64_encode(json_encode($historical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $expected = $historical;
    unset($expected[0]['image_url']);
    assertSameValue($expected, Gateway::decodeCreationProducts($encoded), 'Decoder must remove only image_url without trimming text, stripping markup or changing exact unit_amount.');
}

function test_edd_changed_purchase_recovers_historical_products_before_refusing_open_invoice_replacement()
{
    paymos_edd_reset_test_state();
    paymos_edd_seed_payment();
    $historical = array(array(
        'title' => '  Frozen original  ',
        'description' => "  Line 1\nLine 2 — Ω 🚀  ",
        'image_url' => 'https://shop.example/legacy.webp',
        'quantity' => 2,
        'unit_amount' => '12345678901234567890.123456789012345678',
    ));
    $encoded = base64_encode(json_encode($historical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_invoice_id'] = '';
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_creation_intent'] = 'pending';
    $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_client_id'] = '77';
    $GLOBALS['paymos_edd_payment_meta'][100][Gateway::CREATION_PRODUCTS_META] = $encoded;
    $GLOBALS['paymos_edd_resume_id'] = 100;
    $invoices = new class extends FakePaymosInvoices {
        public function __construct()
        {
            parent::__construct(
                array('invoice_id' => 'inv_original', 'status' => 'awaiting_payment', 'payment_url' => 'https://checkout.paymos.io/pay/inv_original'),
                array('invoice_id' => 'inv_original', 'status' => 'confirming')
            );
        }
        public function cancel($invoiceId, $reason) { throw new RuntimeException('Original invoice is still payable.'); }
    };
    Gateway::set_client_factory_for_tests(static function () use ($invoices) { return new FakePaymosClient($invoices); });

    Gateway::process_payment(paymos_edd_purchase(array(
        'price' => '120.00',
        'cart_details' => array(array('id' => 99, 'name' => 'Changed cart', 'quantity' => 1, 'item_price' => '120.00')),
    )));

    $expectedProducts = $historical;
    unset($expectedProducts[0]['image_url']);
    assertSameValue(array(array(
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'external_order_id' => 'edd_100_abc123',
        'client_id' => '77',
        'products' => $expectedProducts,
    )), $invoices->payloads, 'prepareIntent must recover the original identity and frozen product values before refusing replacement of a payable invoice.');
    assertSameValue($encoded, $GLOBALS['paymos_edd_payment_meta'][100][Gateway::CREATION_PRODUCTS_META], 'Blocked replacement must leave the historical encoded products unchanged.');
    assertSameValue('edd_100_abc123', $GLOBALS['paymos_edd_payment_meta'][100]['_paymos_external_order_id'], 'An open original invoice must not receive a new external identity.');
}
