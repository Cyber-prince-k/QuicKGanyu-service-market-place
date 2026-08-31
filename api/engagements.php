<?php
// Customer/provider negotiation, booking, and PayChangu payment flow.
// Flow:
//   1. Customer starts a request (create)
//   2. Both sides chat freely (message)
//   3. Provider sets the price with /price command (propose_price — provider only)
//   4. Customer accepts the price (accept_price — customer only)
//   5. Customer clicks Pay → server creates a PayChangu checkout, returns redirect URL
//   6. Customer is redirected to PayChangu hosted checkout page
//   7. On return, check_payment verifies with PayChangu and marks request as booked
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

// ── Helpers ───────────────────────────────────────────────────────────────────

function engagement_request(PDO $pdo, int $requestId, int $userId): ?array {
    $stmt = $pdo->prepare(
        'SELECT sr.*, c.full_name AS customer_name, p.full_name AS provider_name
           FROM service_requests sr
           JOIN users c ON c.id = sr.customer_id
           JOIN users p ON p.id = sr.provider_id
          WHERE sr.id = ? AND (sr.customer_id = ? OR sr.provider_id = ?)'
    );
    $stmt->execute([$requestId, $userId, $userId]);
    return $stmt->fetch() ?: null;
}

function engagement_messages(PDO $pdo, int $requestId): array {
    $stmt = $pdo->prepare(
        'SELECT m.id, m.sender_id, m.message, m.message_type, m.created_at,
                u.full_name AS sender_name
           FROM service_request_messages m
           JOIN users u ON u.id = m.sender_id
          WHERE m.request_id = ?
          ORDER BY m.created_at ASC, m.id ASC'
    );
    $stmt->execute([$requestId]);
    return $stmt->fetchAll();
}

/**
 * Call the PayChangu REST API.
 * Throws RuntimeException on any failure so callers can catch cleanly.
 */
function paychangu_request(string $path, string $method, ?array $payload = null): array {
    $secret = PAYCHANGU_SECRET_KEY;
    if ($secret === '') {
        throw new RuntimeException(
            'Payments are not configured yet. Set PAYCHANGU_SECRET_KEY on the server.'
        );
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for payments.');
    }

    $curl = curl_init('https://api.paychangu.com' . $path);
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $secret,
    ];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false, // XAMPP dev — enable in production
    ]);
    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $body   = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error  = curl_error($curl);
    curl_close($curl);

    if ($body === false) {
        throw new RuntimeException('Could not reach PayChangu: ' . $error);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('PayChangu returned an invalid response.');
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException(
            $data['message'] ?? ('PayChangu error (HTTP ' . $status . ').')
        );
    }
    return $data;
}

// ── Base URL helper (used for PayChangu return/cancel URLs) ──────────────────
function site_base(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Strip /api from the script path to get the site root
    $root   = rtrim(str_replace('/api', '', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $scheme . '://' . $host . $root;
}

// ── Main handler ──────────────────────────────────────────────────────────────
try {
    $pdo  = db();
    $user = current_user($pdo);

    if ($user === null || !in_array($user['role'], ['client', 'provider'], true)) {
        respond(['ok' => false, 'error' => 'Please log in to access messages.'], 403);
    }
    $userId = (int) $user['id'];

    // ── GET: list all conversations for this user ─────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare(
            'SELECT sr.*,
                    c.full_name AS customer_name,
                    p.full_name AS provider_name,
                    (SELECT status FROM service_payments sp
                      WHERE sp.request_id = sr.id
                      ORDER BY sp.id DESC LIMIT 1) AS payment_status
               FROM service_requests sr
               JOIN users c ON c.id = sr.customer_id
               JOIN users p ON p.id = sr.provider_id
              WHERE sr.customer_id = ? OR sr.provider_id = ?
              ORDER BY sr.updated_at DESC'
        );
        $stmt->execute([$userId, $userId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['messages'] = engagement_messages($pdo, (int) $row['id']);
        }
        unset($row);

        respond(['ok' => true, 'requests' => $rows]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $data   = request_input();
    $action = str_field($data, 'action');

    // ── CREATE: customer starts a new service request ─────────────────────
    if ($action === 'create') {
        if ($user['role'] !== 'client') {
            respond(['ok' => false, 'error' => 'Only customers can start a service request.'], 403);
        }
        $providerId = (int) ($data['provider_id'] ?? 0);
        $title      = str_field($data, 'service_title');
        $message    = str_field($data, 'message');

        if ($providerId < 1 || mb_strlen($title) < 3 || mb_strlen($message) < 3) {
            respond(['ok' => false, 'error' => 'Enter the service needed and a message.'], 422);
        }

        $provider = fetch_user_by_id($pdo, $providerId);
        if (!$provider || $provider['role'] !== 'provider' || $provider['status'] !== 'active') {
            respond(['ok' => false, 'error' => 'This provider is not available.'], 422);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO service_requests (customer_id, provider_id, service_title, details)
                 VALUES (?, ?, ?, ?)'
            )->execute([$userId, $providerId, $title, $message]);
            $requestId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO service_request_messages (request_id, sender_id, message, message_type)
                 VALUES (?, ?, ?, "text")'
            )->execute([$requestId, $userId, $message]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        respond([
            'ok'         => true,
            'message'    => 'Request sent! You can now chat and negotiate with the provider.',
            'request_id' => $requestId,
        ], 201);
    }

    // All remaining actions require a valid request_id
    $requestId = (int) ($data['request_id'] ?? 0);
    $request   = engagement_request($pdo, $requestId, $userId);
    if (!$request) {
        respond(['ok' => false, 'error' => 'Service request not found.'], 404);
    }

    // ── MESSAGE: send a plain chat message ────────────────────────────────
    if ($action === 'message') {
        $msg = str_field($data, 'message');
        if (mb_strlen($msg) < 1 || mb_strlen($msg) > 2000) {
            respond(['ok' => false, 'error' => 'Messages must be 1–2,000 characters.'], 422);
        }
        if (in_array($request['status'], ['booked', 'cancelled'], true)) {
            respond(['ok' => false, 'error' => 'This conversation is closed.'], 409);
        }

        $pdo->prepare(
            'INSERT INTO service_request_messages (request_id, sender_id, message, message_type)
             VALUES (?, ?, ?, "text")'
        )->execute([$requestId, $userId, $msg]);

        $pdo->prepare(
            'UPDATE service_requests SET updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        )->execute([$requestId]);

        respond(['ok' => true, 'messages' => engagement_messages($pdo, $requestId)]);
    }

    // ── PROPOSE_PRICE: PROVIDER sets the price ────────────────────────────
    // Only the provider on this request may propose a price.
    // Triggered by typing /price 500 in the message box.
    if ($action === 'propose_price') {
        if ($user['role'] !== 'provider' || (int) $request['provider_id'] !== $userId) {
            respond([
                'ok'    => false,
                'error' => 'Only the provider can set the price. '
                         . 'Use /price followed by the amount, e.g. /price 5000',
            ], 403);
        }
        if (in_array($request['status'], ['booked', 'cancelled'], true)) {
            respond(['ok' => false, 'error' => 'This request is closed.'], 409);
        }

        $amount = filter_var($data['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($amount === false || $amount <= 0) {
            respond(['ok' => false, 'error' => 'Enter a valid price amount.'], 422);
        }
        $amount = number_format((float) $amount, 2, '.', '');

        $pdo->beginTransaction();
        try {
            // Update the proposed amount and move status to negotiating
            $pdo->prepare(
                'UPDATE service_requests SET proposed_amount = ?, status = "negotiating" WHERE id = ?'
            )->execute([$amount, $requestId]);

            // Insert a special price-offer bubble into the message stream
            $pdo->prepare(
                'INSERT INTO service_request_messages
                   (request_id, sender_id, message, message_type)
                 VALUES (?, ?, ?, "price_offer")'
            )->execute([$requestId, $userId, $amount]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        respond(['ok' => true, 'message' => 'Price offer sent to the customer.']);
    }

    // ── ACCEPT_PRICE: customer agrees on the proposed price ───────────────
    if ($action === 'accept_price') {
        if ($user['role'] !== 'client' || (int) $request['customer_id'] !== $userId) {
            respond(['ok' => false, 'error' => 'Only the customer can accept the price.'], 403);
        }
        if ((float) $request['proposed_amount'] <= 0) {
            respond(['ok' => false, 'error' => 'The provider has not set a price yet.'], 422);
        }
        if ($request['status'] !== 'negotiating') {
            respond(['ok' => false, 'error' => 'No pending price offer to accept.'], 409);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE service_requests
                    SET agreed_amount = proposed_amount, status = "awaiting_payment"
                  WHERE id = ?'
            )->execute([$requestId]);

            $pdo->prepare(
                'INSERT INTO service_request_messages
                   (request_id, sender_id, message, message_type)
                 VALUES (?, ?, ?, "price_accepted")'
            )->execute([
                $requestId,
                $userId,
                number_format((float) $request['proposed_amount'], 2),
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $base = site_base();
        respond([
            'ok'           => true,
            'message'      => 'Price accepted! Proceed to payment.',
            'redirect_url' => $base . '/html/payment.html?request_id=' . $requestId,
        ]);
    }

    // ── START_PAYMENT: initiate PayChangu direct mobile-money charge ──────
    // Collects: payment_method (airtel|tnm|card), phone (for mobile money),
    //           card_number, card_expiry, card_cvv (for card).
    if ($action === 'start_payment') {
        if ($user['role'] !== 'client' || (int) $request['customer_id'] !== $userId) {
            respond(['ok' => false, 'error' => 'Only the customer can pay for this booking.'], 403);
        }
        if ($request['status'] !== 'awaiting_payment' || (float) $request['agreed_amount'] <= 0) {
            respond(['ok' => false, 'error' => 'Accept the provider\'s price before paying.'], 409);
        }

        $method   = str_field($data, 'payment_method'); // airtel | tnm | card
        $phone    = str_field($data, 'phone');
        $chargeId = 'QG-' . $requestId . '-' . bin2hex(random_bytes(6));
        $base     = site_base();
        $nameParts = preg_split('/\s+/', trim($user['full_name']), 2);
        $amount   = (float) $request['agreed_amount'];

        // Build the PayChangu payload — supports both mobile money and card
        $payload = [
            'amount'       => number_format($amount, 2, '.', ''),
            'currency'     => 'MWK',
            'email'        => $user['email'],
            'first_name'   => $nameParts[0],
            'last_name'    => $nameParts[1] ?? '',
            'tx_ref'       => $chargeId,
            'callback_url' => $base . '/html/payment.html?request_id=' . $requestId . '&payment=return',
            'return_url'   => $base . '/html/payment.html?request_id=' . $requestId . '&payment=return',
            'cancel_url'   => $base . '/html/payment.html?request_id=' . $requestId . '&payment=cancel',
            'customization' => [
                'title'       => 'QuickGanyu – ' . $request['service_title'],
                'description' => 'Payment for: ' . $request['service_title'],
                'logo'        => $base . '/css/logo.png',
            ],
        ];

        // Attach method-specific details
        if (in_array($method, ['airtel', 'tnm'], true)) {
            if (mb_strlen($phone) < 9) {
                respond(['ok' => false, 'errors' => ['phone' => 'Enter a valid mobile number.']], 422);
            }
            $payload['mobile_money'] = [
                'network' => strtoupper($method), // AIRTEL or TNM
                'phone'   => preg_replace('/\D/', '', $phone),
            ];
        } elseif ($method === 'card') {
            $cardNumber = preg_replace('/\s/', '', str_field($data, 'card_number'));
            $cardExpiry = str_field($data, 'card_expiry');  // MM/YY
            $cardCvv    = str_field($data, 'card_cvv');
            $cardName   = str_field($data, 'card_name') ?: $user['full_name'];

            if (strlen($cardNumber) < 13 || strlen($cardCvv) < 3 || strlen($cardExpiry) < 4) {
                respond(['ok' => false, 'errors' => ['card_number' => 'Enter valid card details.']], 422);
            }
            [$expM, $expY] = array_pad(explode('/', $cardExpiry), 2, '');
            $payload['card'] = [
                'number'      => $cardNumber,
                'cvv'         => $cardCvv,
                'expiry_month'=> ltrim($expM, '0') ?: '1',
                'expiry_year' => strlen($expY) === 2 ? '20' . $expY : $expY,
                'name'        => $cardName,
            ];
        } else {
            respond(['ok' => false, 'errors' => ['payment_method' => 'Select a payment method.']], 422);
        }

        // Call PayChangu
        $gateway = paychangu_request('/payment', 'POST', $payload);

        // PayChangu may return a checkout URL (hosted) or a direct charge status
        $checkoutUrl = $gateway['data']['checkout_url']
            ?? $gateway['checkout_url']
            ?? $gateway['data']['link']
            ?? $gateway['link']
            ?? null;

        $directStatus = strtolower((string)(
            $gateway['data']['status'] ?? $gateway['status'] ?? ''
        ));

        // Record the pending payment
        $pdo->prepare(
            'INSERT INTO service_payments (request_id, charge_id, amount, phone, gateway_data)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $requestId,
            $chargeId,
            $request['agreed_amount'],
            $phone ?: '',
            json_encode($gateway),
        ]);

        $pdo->prepare(
            'UPDATE service_requests SET status = "payment_pending" WHERE id = ?'
        )->execute([$requestId]);

        // If it's a direct charge that already succeeded, mark it immediately
        if (in_array($directStatus, ['success', 'successful', 'paid'], true)) {
            $pdo->prepare('UPDATE service_payments SET status = "successful" WHERE charge_id = ?')
                ->execute([$chargeId]);
            $pdo->prepare('UPDATE service_requests SET status = "booked" WHERE id = ?')
                ->execute([$requestId]);
            $pdo->prepare(
                'INSERT INTO service_request_messages (request_id, sender_id, message, message_type)
                 VALUES (?, ?, ?, "payment_confirmed")'
            )->execute([$requestId, $userId, number_format($amount, 2)]);

            respond([
                'ok'      => true,
                'status'  => 'successful',
                'message' => 'Payment confirmed! Your booking is complete.',
            ]);
        }

        // Mobile money — customer must approve prompt on their phone; poll for result
        if ($checkoutUrl) {
            respond(['ok' => true, 'status' => 'pending', 'checkout_url' => $checkoutUrl,
                     'message' => 'Redirecting to secure payment page…']);
        }

        respond([
            'ok'      => true,
            'status'  => 'pending',
            'charge_id' => $chargeId,
            'message' => 'Payment initiated. Please approve the prompt on your phone.',
        ]);
    }

    // ── CHECK_PAYMENT: verify with PayChangu after customer returns ────────
    if ($action === 'check_payment') {
        if ($user['role'] !== 'client' || (int) $request['customer_id'] !== $userId) {
            respond(['ok' => false, 'error' => 'Only the customer can check this payment.'], 403);
        }

        $payment = $pdo->prepare(
            'SELECT * FROM service_payments WHERE request_id = ? ORDER BY id DESC LIMIT 1'
        );
        $payment->execute([$requestId]);
        $payment = $payment->fetch();

        if (!$payment) {
            respond(['ok' => false, 'error' => 'No payment found for this request.'], 404);
        }

        // Already confirmed — no need to hit PayChangu again
        if ($payment['status'] === 'successful') {
            respond([
                'ok'      => true,
                'status'  => 'successful',
                'message' => 'Payment confirmed. Your booking is complete!',
            ]);
        }

        // Verify with PayChangu
        $gateway   = paychangu_request('/verify-payment/' . rawurlencode($payment['charge_id']), 'GET');
        $rawStatus = strtolower((string) ($gateway['data']['status'] ?? $gateway['status'] ?? 'pending'));

        $successful = in_array($rawStatus, ['success', 'successful', 'paid'], true);
        $failed     = in_array($rawStatus, ['failed', 'failure', 'cancelled', 'canceled'], true);
        $newStatus  = $successful ? 'successful' : ($failed ? 'failed' : 'pending');

        $pdo->prepare(
            'UPDATE service_payments SET status = ?, gateway_data = ? WHERE id = ?'
        )->execute([$newStatus, json_encode($gateway), (int) $payment['id']]);

        if ($successful) {
            $pdo->prepare(
                'UPDATE service_requests SET status = "booked" WHERE id = ?'
            )->execute([$requestId]);

            $pdo->prepare(
                'INSERT INTO service_request_messages
                   (request_id, sender_id, message, message_type)
                 VALUES (?, ?, ?, "payment_confirmed")'
            )->execute([
                $requestId,
                $userId,
                number_format((float) $payment['amount'], 2),
            ]);
        }

        respond([
            'ok'      => true,
            'status'  => $newStatus,
            'message' => $successful
                ? 'Payment confirmed. Your booking is complete!'
                : ($failed
                    ? 'Payment failed or was cancelled. You can try again.'
                    : 'Payment is still being processed. Please wait a moment.'),
        ]);
    }

    respond(['ok' => false, 'error' => 'Unknown action.'], 422);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('QuickGanyu engagements error: ' . $e->getMessage());
    respond([
        'ok'    => false,
        'error' => $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Could not process the request. Please try again.',
    ], 500);
}
