<?php

// ================= CONFIG =================
$skroutzWebhookFileUrl = 'https://www.bebemama.info/skroutz_orders.txt';
$mappingCsvUrl = 'http://bebemama.info/Reports/SkroutzReport.csv';

$shopifyStore = 'bebemama-com';
$shopifyAccessToken = getenv('SHOPIFY_TOKEN');
$shopifyApiVersion = '2022-01';

$ftpHost = getenv('FTP_HOST');
$ftpUser = getenv('FTP_USER');
$ftpPass = getenv('FTP_PASS');

$processedFile = __DIR__ . '/processed_skroutz_orders.txt';
$processedRemoteFile = '/processed_skroutz_orders.txt';

// ================= HELPERS =================

function httpGet($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        throw new Exception(curl_error($ch));
    }

    curl_close($ch);
    return $response;
}

function downloadProcessedFile($ftpHost, $ftpUser, $ftpPass, $remoteFile, $localFile) {
    $conn = ftp_connect($ftpHost);

    if (!$conn) {
        throw new Exception("Cannot connect to FTP");
    }

    if (!ftp_login($conn, $ftpUser, $ftpPass)) {
        ftp_close($conn);
        throw new Exception("FTP login failed");
    }

    ftp_pasv($conn, true);

    if (!@ftp_get($conn, $localFile, $remoteFile, FTP_ASCII)) {
        file_put_contents($localFile, '');
        echo "Processed file not found on FTP. Created empty local file.\n";
    }

    ftp_close($conn);
}

function uploadProcessedFile($ftpHost, $ftpUser, $ftpPass, $remoteFile, $localFile) {
    $conn = ftp_connect($ftpHost);

    if (!$conn) {
        throw new Exception("Cannot connect to FTP");
    }

    if (!ftp_login($conn, $ftpUser, $ftpPass)) {
        ftp_close($conn);
        throw new Exception("FTP login failed");
    }

    ftp_pasv($conn, true);

    if (!ftp_put($conn, $remoteFile, $localFile, FTP_ASCII)) {
        ftp_close($conn);
        throw new Exception("Failed uploading processed file to FTP");
    }

    ftp_close($conn);
}

function loadProcessedOrders($file) {
    if (!file_exists($file)) {
        return [];
    }

    return array_filter(array_map('trim', file($file)));
}

function saveProcessedOrder($file, $orderCode) {
    file_put_contents($file, $orderCode . PHP_EOL, FILE_APPEND);
}

function loadVariantMapping($csvUrl) {
    $csv = httpGet($csvUrl);
    $lines = preg_split('/\r\n|\r|\n/', trim($csv));

    $mapping = [];

    foreach ($lines as $line) {
        $cols = str_getcsv($line);

        if (count($cols) < 2) {
            continue;
        }

        $shopUid = trim($cols[0]);
        $variantId = trim($cols[1]);

        if ($shopUid !== '' && $variantId !== '') {
            $mapping[$shopUid] = $variantId;
        }
    }

    return $mapping;
}

function extractJsonOrdersFromWebhookFile($content) {
    $orders = [];

    preg_match_all('/\{(?:[^{}]|(?R))*\}/s', $content, $matches);

    foreach ($matches[0] as $json) {
        $data = json_decode($json, true);

        if (
            json_last_error() === JSON_ERROR_NONE &&
            isset($data['event_type']) &&
            isset($data['order'])
        ) {
            $orders[] = $data;
        }
    }

    return $orders;
}

function postToShopify($store, $token, $apiVersion, $payload) {
    $url = "https://{$store}.myshopify.com/admin/api/{$apiVersion}/orders.json";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$token}"
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        throw new Exception(curl_error($ch));
    }

    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception("Shopify error {$httpCode}: {$response}");
    }

    return json_decode($response, true);
}

function buildShopifyOrderPayload($skroutzEvent, $variantMapping)
{
    $order = $skroutzEvent['order'];
    $customer = $order['customer'];
    $address = $customer['address'];

    $feesTotal =
        (float)($order['fees']['handling_fee'] ?? 0) +
        (float)($order['fees']['plus_user_fee'] ?? 0);

    $lineItems = [];
    $totalAmount = 0;

    foreach ($order['line_items'] as $item) {
        $shopUid = $item['shop_uid'];

        if (!isset($variantMapping[$shopUid])) {
            echo "\n========================================\n";
            echo "MISSING VARIANT MAPPING\n";
            echo "ORDER: " . $order['code'] . "\n";
            echo "SKU: " . $shopUid . "\n";
            echo "PRODUCT: " . $item['product_name'] . "\n";
            echo "========================================\n\n";

            return null;
        }

        $variantId = (int)$variantMapping[$shopUid];
        $qty = (int)$item['quantity'];

        $price = round(
            (float)$item['unit_price'] - (float)$item['commission'],
            2
        );

        $lineTotal = round($price * $qty, 2);
        $totalAmount += $lineTotal;

        $lineItems[] = [
            'variant_id' => $variantId,
            'title' => $item['product_name'],
            'name' => $item['product_name'],
            'quantity' => $qty,
            'price' => number_format($price, 2, '.', ''),
            'fulfillment_service' => 'manual',
            'variant_inventory_management' => 'shopify',
            'tax_lines' => []
        ];
    }

    $shopifyTransactionAmount = max(
        0,
        $totalAmount - $feesTotal
    );

    $totalTax = round(
        $shopifyTransactionAmount - ($shopifyTransactionAmount / 1.20),
        2
    );

    $distributedTaxTotal = 0;
    $lastLineItemIndex = count($lineItems) - 1;

    foreach ($lineItems as $index => &$lineItem) {
        $lineGross = round(
            (float)$lineItem['price'] * (int)$lineItem['quantity'],
            2
        );

        if ($totalAmount > 0) {
            $lineTax = round(
                ($lineGross / $totalAmount) * $totalTax,
                2
            );
        } else {
            $lineTax = 0;
        }

        if ($index === $lastLineItemIndex) {
            $lineTax = round($totalTax - $distributedTaxTotal, 2);
        }

        $distributedTaxTotal += $lineTax;

        $lineItem['tax_lines'] = [
            [
                'price' => number_format($lineTax, 2, '.', ''),
                'rate' => 0.20,
                'title' => 'ДДС'
            ]
        ];
    }
    unset($lineItem);

    return [
        'order' => [
            'checkout_id' => abs(crc32($order['code'])),
            'source_identifier' => $order['code'],
            'inventory_behaviour' => 'decrement_ignoring_policy',
            'created_at' => $order['created_at'],
            'currency' => 'EUR',
            'send_receipt' => false,
            'send_fulfillment_receipt' => false,
            'phone' => '',
            'email' => '',
            'note' => $order['comments'] ?? '',

            'note_attributes' => [
                [
                    'name' => 'Skroutz Order ID',
                    'value' => $order['code']
                ]
            ],

            'discount_codes' => [
                [
                    'code' => 'Fees',
                    'amount' => number_format($feesTotal, 2, '.', ''),
                    'type' => 'amount'
                ]
            ],

            'tags' => 'Skroutz',
            'taxes_included' => true,
            'financial_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'total_tax' => number_format($totalTax, 2, '.', ''),
            'line_items' => $lineItems,

            'customer' => [
                'first_name' => $customer['first_name'],
                'last_name' => $customer['last_name'],
                'email' => ''
            ],

            'billing_address' => [
                'first_name' => $customer['first_name'],
                'last_name' => $customer['last_name'],
                'address1' => trim($address['street_name'] . ' ' . $address['street_number']),
                'address2' => '',
                'phone' => '',
                'city' => $address['city'],
                'province' => $address['region'],
                'country' => $address['country_code'],
                'company' => '',
                'zip' => $address['zip']
            ],

            'shipping_address' => [
                'first_name' => $customer['first_name'],
                'last_name' => $customer['last_name'],
                'address1' => trim($address['street_name'] . ' ' . $address['street_number']),
                'address2' => '',
                'phone' => '',
                'city' => $address['city'],
                'province' => $address['region'],
                'country' => $address['country_code'],
                'zip' => $address['zip']
            ],

            'transactions' => [
                [
                    'kind' => 'capture',
                    'status' => 'success',
                    'currency' => 'EUR',
                    'gateway' => 'manual',
                    'amount' => number_format($shopifyTransactionAmount, 2, '.', '')
                ]
            ]
        ]
    ];
}

// ================= MAIN =================

try {
    if (!$shopifyAccessToken || !$ftpHost || !$ftpUser || !$ftpPass) {
        throw new Exception("Missing environment variables. Required: SHOPIFY_TOKEN, FTP_HOST, FTP_USER, FTP_PASS");
    }

    downloadProcessedFile(
        $ftpHost,
        $ftpUser,
        $ftpPass,
        $processedRemoteFile,
        $processedFile
    );

    $processedOrders = loadProcessedOrders($processedFile);
    $variantMapping = loadVariantMapping($mappingCsvUrl);

    $webhookContent = httpGet($skroutzWebhookFileUrl);
    $events = extractJsonOrdersFromWebhookFile($webhookContent);

    foreach ($events as $event) {
        if ($event['event_type'] !== 'new_order') {
            continue;
        }

        $orderCode = $event['order']['code'];

        if (in_array($orderCode, $processedOrders, true)) {
            echo "Skipping already processed order: {$orderCode}\n";
            continue;
        }

        $payload = buildShopifyOrderPayload($event, $variantMapping);

        if ($payload === null) {
            continue;
        }

        echo "\n========================================\n";
        echo "ORDER: {$orderCode}\n";
        echo "SHOPIFY PAYLOAD:\n";
        echo json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        echo "\n========================================\n\n";

        try {
            postToShopify(
                $shopifyStore,
                $shopifyAccessToken,
                $shopifyApiVersion,
                $payload
            );

            saveProcessedOrder($processedFile, $orderCode);

            uploadProcessedFile(
                $ftpHost,
                $ftpUser,
                $ftpPass,
                $processedRemoteFile,
                $processedFile
            );

            $processedOrders[] = $orderCode;

            echo "SUCCESS: {$orderCode}\n";

        } catch (Exception $e) {
            echo "\n========================================\n";
            echo "SHOPIFY IMPORT FAILED\n";
            echo "ORDER CODE: {$orderCode}\n";
            echo "CUSTOMER: " .
                $event['order']['customer']['first_name'] .
                " " .
                $event['order']['customer']['last_name'] .
                "\n";
            echo "STATE: " . $event['order']['state'] . "\n";
            echo "ERROR: " . $e->getMessage() . "\n";
            echo "========================================\n\n";

            continue;
        }
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
