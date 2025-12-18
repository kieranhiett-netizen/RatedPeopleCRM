<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Exceptions\Forbidden;

class ZuoraPayment extends \Espo\Core\Controllers\Base
{
    protected function checkAccess(): bool
    {
        if (!$this->getAcl()->checkScope('Account', 'edit')) {
            throw new Forbidden();
        }
        return true;
    }

    /**
     * List payments for a Zuora Account Id (UUID).
     * POST /api/v1/ZuoraPayment/action/list
     *
     * Payload:
     *  - accountId? (Espo Account id)
     *  - zuoraAccountId? (Zuora Account Id UUID)
     */
    public function postActionList($params, $data, $request): array
    {
        $this->checkAccess();

        $accountId      = $data->accountId      ?? null;
        $zuoraAccountId = $data->zuoraAccountId ?? null;

        if ($accountId && !$zuoraAccountId) {
            $zuoraAccountId = $this->resolveZuoraAccountIdFromAccount($accountId);
        }

        if (!$zuoraAccountId) {
            return [
                'success'  => true,
                'payments' => [],
                'message'  => 'No Zuora Account ID resolved; nothing to fetch.',
            ];
        }

        $payments = $this->fetchPaymentsFromZuora($zuoraAccountId);

        return [
            'success'  => true,
            'payments' => $payments,
            'message'  => 'Zuora payments fetch for account ' . $zuoraAccountId,
        ];
    }

    /**
     * Fetch payments + enrich with payment method info (cardholder/masked/expiry).
     */
    protected function fetchPaymentsFromZuora(string $zuoraAccountId): array
{
    $config  = $this->getConfig();
    $baseUrl = rtrim((string) $config->get('zuoraApiUrl'), '/');
    if (!$baseUrl) return [];

    $accessToken = $this->getZuoraAccessToken();
    if (!$accessToken) return [];

    // ✅ Preferred endpoint: GET /v1/payments/accounts/{account-key}
    $payResp = $this->zuoraRequest(
        'GET',
        $baseUrl . '/v1/payments/accounts/' . rawurlencode($zuoraAccountId),
        $accessToken,
        null,
        ['Zuora-Version: 211.0']
    );

    if (!$payResp['ok']) {
        // Temporary debug to see what Zuora said (remove later)
        return [];
    }

    $json = json_decode($payResp['body'], true);
    if (!is_array($json)) return [];

    // Zuora often returns { success: true, payments: [...] }
    $records = [];
    if (!empty($json['payments']) && is_array($json['payments'])) {
        $records = $json['payments'];
    } elseif (!empty($json['records']) && is_array($json['records'])) {
        $records = $json['records'];
    }

    if (empty($records)) {
        return [];
    }

    // Collect payment method ids
    $paymentMethodIds = [];
    foreach ($records as $p) {
        if (is_array($p) && !empty($p['paymentMethodId'])) {
            $paymentMethodIds[(string)$p['paymentMethodId']] = true;
        }
    }
    $paymentMethodIds = array_keys($paymentMethodIds);

    // Fetch payment methods once
    $paymentMethodsById = [];
    foreach ($paymentMethodIds as $pmId) {
        $pmResp = $this->zuoraRequest(
            'GET',
            $baseUrl . '/v1/payment-methods/' . rawurlencode($pmId),
            $accessToken,
            null,
            ['Zuora-Version: 211.0']
        );
        if (!$pmResp['ok']) continue;

        $pmJson = json_decode($pmResp['body'], true);
        if (is_array($pmJson)) {
            $paymentMethodsById[$pmId] = $pmJson;
        }
    }

    // Normalise
    $out = [];
    foreach ($records as $p) {
        if (!is_array($p)) continue;

        $pmId = (string)($p['paymentMethodId'] ?? '');
        $pm   = $pmId && isset($paymentMethodsById[$pmId]) ? $paymentMethodsById[$pmId] : null;

        $out[] = [
            'payment'    => $p['paymentNumber'] ?? ($p['id'] ?? null),
            'cardholder' => $this->pickCardholderName($pm),
            'amount'     => $p['amount'] ?? null,
            'gateway'    => $this->summariseGateway($p['gatewayResponse'] ?? null),
            'status'     => $p['status'] ?? null,
            'dateIso'    => $this->toIsoDate($p['effectiveDate'] ?? null) ?: $this->toIsoDate($p['createdDate'] ?? null),
            'method'     => $this->formatPaymentMethodLabel($pm),
            'expiration' => $this->formatCardExpiry($pm),

            'zuoraPaymentId'       => $p['id'] ?? null,
            'zuoraPaymentMethodId' => $pmId ?: null,
        ];
    }

    // Sort newest first (server-side)
    usort($out, function ($a, $b) {
        return strcmp((string)($b['dateIso'] ?? ''), (string)($a['dateIso'] ?? ''));
    });

    return $out;
}


    /**
     * Attempt to extract a clean "Gateway" summary from GatewayResponse.
     * Keep it simple for now: show "Approved" / error code-ish snippets.
     */
    protected function summariseGateway($gatewayResponse): ?string
    {
        if (!$gatewayResponse) {
            return null;
        }

        // Sometimes this is a JSON string; sometimes a plain string.
        if (is_string($gatewayResponse)) {
            $trim = trim($gatewayResponse);

            // If JSON, try parse and pick common fields
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    // Common patterns: "decision", "result", "message", "code"
                    foreach (['decision', 'result', 'message', 'code', 'gatewayResponseCode', 'processorResponse'] as $k) {
                        if (!empty($decoded[$k]) && is_string($decoded[$k])) {
                            return $this->truncate($decoded[$k], 40);
                        }
                    }
                }
            }

            // Non-JSON: take a short snippet
            return $this->truncate($trim, 40);
        }

        return null;
    }

    protected function pickCardholderName($paymentMethod): ?string
    {
        if (!is_array($paymentMethod)) {
            return null;
        }

        // Zuora payment method shapes vary; these are the most common fields
        foreach (['creditCardHolderName', 'cardHolderName', 'holderName', 'name'] as $k) {
            if (!empty($paymentMethod[$k]) && is_string($paymentMethod[$k])) {
                return $paymentMethod[$k];
            }
        }

        return null;
    }

    protected function formatPaymentMethodLabel($paymentMethod): ?string
    {
        if (!is_array($paymentMethod)) {
            return null;
        }

        // Identify type + mask + last4
        $type = null;
        foreach (['type', 'paymentMethodType', 'methodType'] as $k) {
            if (!empty($paymentMethod[$k]) && is_string($paymentMethod[$k])) {
                $type = $paymentMethod[$k];
                break;
            }
        }

        $ccType = null;
        foreach (['creditCardType', 'cardType'] as $k) {
            if (!empty($paymentMethod[$k]) && is_string($paymentMethod[$k])) {
                $ccType = $paymentMethod[$k];
                break;
            }
        }

        $mask = null;
        foreach (['creditCardMaskNumber', 'maskNumber', 'maskedNumber', 'cardMaskNumber'] as $k) {
            if (!empty($paymentMethod[$k]) && is_string($paymentMethod[$k])) {
                $mask = $paymentMethod[$k];
                break;
            }
        }

        // If we have a mask like "************6078"
        if ($mask) {
            $labelType = $ccType ?: ($type ?: 'Payment Method');
            return 'Credit Card ' . $labelType . ' ' . $mask;
        }

        if ($ccType || $type) {
            return trim(($type ?: 'Payment Method') . ' ' . ($ccType ?: ''));
        }

        return null;
    }

    protected function formatCardExpiry($paymentMethod): ?string
    {
        if (!is_array($paymentMethod)) {
            return null;
        }

        $month = null;
        $year  = null;

        foreach (['creditCardExpirationMonth', 'expirationMonth', 'expMonth'] as $k) {
            if (!empty($paymentMethod[$k])) {
                $month = (string) $paymentMethod[$k];
                break;
            }
        }

        foreach (['creditCardExpirationYear', 'expirationYear', 'expYear'] as $k) {
            if (!empty($paymentMethod[$k])) {
                $year = (string) $paymentMethod[$k];
                break;
            }
        }

        if (!$month || !$year) {
            return null;
        }

        // Ensure MM
        $month = str_pad(preg_replace('/\D+/', '', $month), 2, '0', STR_PAD_LEFT);
        $year  = preg_replace('/\D+/', '', $year);

        // Display like 03/2030
        return $month . '/' . $year;
    }

    protected function truncate(string $s, int $max): string
    {
        $s = trim($s);
        if (mb_strlen($s) <= $max) return $s;
        return mb_substr($s, 0, $max - 1) . '…';
    }

    protected function toIsoDate($date): ?string
    {
        if (!$date || !is_string($date)) return null;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $m)) {
            return $m[1];
        }
        return null;
    }

    protected function resolveZuoraAccountIdFromAccount($accountId): ?string
    {
        if (!$accountId) return null;
        $em = $this->getEntityManager();
        $acc = $em->getEntity('Account', $accountId);
        return $acc ? ($acc->get('cZuoraAccountId') ?: null) : null;
    }

    protected function zuoraRequest(string $method, string $url, string $accessToken, ?string $body = null, array $extraHeaders = []): array
    {
        $headers = array_merge([
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ], $extraHeaders);

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ];

        if ($method === 'GET') {
            // nothing
        } else {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = $body;
            }
        }

        curl_setopt_array($ch, $opts);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok'     => ($errno === 0 && $raw !== false && $status >= 200 && $status < 300),
            'status' => $status,
            'error'  => $errno ? $err : null,
            'body'   => $raw ?: '',
        ];
    }

    // Reuse your token helper style (client_credentials)
    protected function getZuoraAccessToken(): ?string
    {
        $config       = $this->getConfig();
        $baseUrl      = rtrim((string) $config->get('zuoraApiUrl'), '/');
        $clientId     = $config->get('zuoraClientId');
        $clientSecret = $config->get('zuoraClientSecret');

        if (!$baseUrl || !$clientId || !$clientSecret) return null;

        $url     = $baseUrl . '/oauth/token';
        $payload = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) return null;

        $data = json_decode($raw, true);
        return (is_array($data) && !empty($data['access_token'])) ? $data['access_token'] : null;
    }

    protected function getEntityManager()
    {
        return $this->getContainer()->get('entityManager');
    }

    protected function getConfig()
    {
        return $this->getContainer()->get('config');
    }
}
