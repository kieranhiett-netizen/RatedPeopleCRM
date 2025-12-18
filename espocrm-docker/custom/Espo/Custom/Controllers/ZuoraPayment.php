<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Exceptions\Forbidden;

class ZuoraPayment extends \Espo\Core\Controllers\Base
{
    /**
     * Ensure user can read Accounts (or edit if you prefer stricter).
     */
    protected function checkAccess(): bool
    {
        // Read is enough to view panels; change to 'edit' if needed.
        if (!$this->getAcl()->checkScope('Account', 'read')) {
            throw new Forbidden();
        }

        return true;
    }

    /**
     * List payments for a Zuora account.
     * POST /api/v1/ZuoraPayment/action/list
     *
     * Payload:
     *  - accountId (optional)
     *  - zuoraAccountId (preferred; UUID like 8adc...)
     *  - debug (optional boolean) -> return debug block + body preview
     */
    public function postActionList($params, $data, $request): array
    {
        $this->checkAccess();

        $accountId      = $data->accountId ?? null;
        $zuoraAccountId = $data->zuoraAccountId ?? null;
        $debug          = !empty($data->debug);

        // If missing, try to resolve from Account.cZuoraAccountId
        if (!$zuoraAccountId && $accountId) {
            $zuoraAccountId = $this->resolveZuoraAccountIdFromAccount($accountId);
        }

        if (!$zuoraAccountId) {
            return [
                'success'  => true,
                'payments' => [],
                'message'  => 'No Zuora Account ID resolved; nothing to fetch.',
            ];
        }

        $config      = $this->getConfig();
        $zuoraApiUrl = rtrim((string) $config->get('zuoraApiUrl'), '/');

        if (!$zuoraApiUrl) {
            return [
                'success'  => false,
                'payments' => [],
                'message'  => 'Zuora API URL (zuoraApiUrl) is not configured.',
            ];
        }

        $accessToken = $this->getZuoraAccessToken();
        if (!$accessToken) {
            return [
                'success'  => false,
                'payments' => [],
                'message'  => 'Failed to obtain Zuora access token (check client id/secret).',
            ];
        }

        // ✅ Preferred endpoint for payments-by-account
        // GET /v1/payments/accounts/{account-key}
        $url  = $zuoraApiUrl . '/v1/payments/accounts/' . rawurlencode((string) $zuoraAccountId);
        $resp = $this->zuoraRequest('GET', $url, $accessToken, null);

        // Server-side log (never includes token)
        $this->logDebug('Payments fetch response', [
            'zuoraAccountId' => (string) $zuoraAccountId,
            'httpStatus'     => $resp['httpStatus'],
            'curlErrNo'      => $resp['curlErrNo'],
            'curlError'      => $resp['curlError'],
            'url'            => $resp['url'],
        ]);

        // Debug mode: return diagnostics + body preview
        if ($debug) {
            $raw = (string) ($resp['body'] ?? '');
            return [
                'success'  => true,
                'payments' => [],
                'message'  => 'Zuora payments debug for account ' . $zuoraAccountId,
                'debug'    => [
                    'request'  => [
                        'method' => $resp['method'],
                        'url'    => $resp['url'],
                    ],
                    'response' => [
                        'ok'         => $resp['ok'],
                        'httpStatus' => $resp['httpStatus'],
                        'curlErrNo'  => $resp['curlErrNo'],
                        'curlError'  => $resp['curlError'],
                        'bodyPreview'=> mb_substr($raw, 0, 4000),
                    ],
                ],
            ];
        }

        if (!$resp['ok']) {
            // Important: don’t silently show “no payments found” if Zuora errored
            return [
                'success'  => false,
                'payments' => [],
                'message'  => 'Zuora payments fetch failed (see server logs). HTTP ' . (int) $resp['httpStatus'],
            ];
        }

        $json = json_decode((string) $resp['body'], true);
        if (!is_array($json)) {
            return [
                'success'  => false,
                'payments' => [],
                'message'  => 'Zuora payments response was not valid JSON.',
            ];
        }

        // Zuora commonly returns: { "success": true, "payments": [ ... ] }
        $paymentsRaw = [];
        if (!empty($json['payments']) && is_array($json['payments'])) {
            $paymentsRaw = $json['payments'];
        } elseif (!empty($json['records']) && is_array($json['records'])) {
            // fallback for other shapes
            $paymentsRaw = $json['records'];
        }

        if (empty($paymentsRaw)) {
            return [
                'success'  => true,
                'payments' => [],
                'message'  => 'Zuora payments fetch for account ' . $zuoraAccountId,
            ];
        }

        // Collect payment method ids so we can enrich method/expiry/cardholder
        $pmIds = [];
        foreach ($paymentsRaw as $p) {
            if (is_array($p) && !empty($p['paymentMethodId'])) {
                $pmIds[(string) $p['paymentMethodId']] = true;
            }
        }
        $pmIds = array_keys($pmIds);

        // Fetch payment methods (small N) — good enough for now
        $pmById = [];
        foreach ($pmIds as $pmId) {
            $pmUrl  = $zuoraApiUrl . '/v1/payment-methods/' . rawurlencode((string) $pmId);
            $pmResp = $this->zuoraRequest('GET', $pmUrl, $accessToken, null);

            if (!$pmResp['ok']) {
                $this->logDebug('Payment method fetch failed', [
                    'paymentMethodId' => $pmId,
                    'httpStatus'      => $pmResp['httpStatus'],
                    'curlErrNo'       => $pmResp['curlErrNo'],
                    'curlError'       => $pmResp['curlError'],
                ]);
                continue;
            }

            $pmJson = json_decode((string) $pmResp['body'], true);
            if (is_array($pmJson)) {
                $pmById[$pmId] = $pmJson;
            }
        }

        $result = [];
        foreach ($paymentsRaw as $p) {
            if (!is_array($p)) {
                continue;
            }

            $pmId = (string) ($p['paymentMethodId'] ?? '');
            $pm   = ($pmId && isset($pmById[$pmId])) ? $pmById[$pmId] : null;

            $gatewayResponse = $p['gatewayResponse'] ?? null;
            $gatewaySummary  = $this->summariseGateway($gatewayResponse);

            // Dates: effectiveDate is usually best; fall back to createdDate
            $dateIso = $this->toIsoDate($p['effectiveDate'] ?? null)
                ?: $this->toIsoDate($p['createdDate'] ?? null);

            $result[] = [
                'payment'    => $p['paymentNumber'] ?? ($p['id'] ?? null),
                'cardholder' => $this->pickCardholderName($pm),
                'amount'     => $p['amount'] ?? null,
                'gateway'    => $gatewaySummary,
                'status'     => $p['status'] ?? null,
                'dateIso'    => $dateIso,

                'method'     => $this->formatPaymentMethodLabel($pm),
                'expiration' => $this->formatCardExpiry($pm),

                // debug/internal (optional)
                'zuoraPaymentId'       => $p['id'] ?? null,
                'zuoraPaymentMethodId' => $pmId ?: null,
            ];
        }

        // Sort newest first
        usort($result, function ($a, $b) {
            return strcmp((string) ($b['dateIso'] ?? ''), (string) ($a['dateIso'] ?? ''));
        });

        return [
            'success'  => true,
            'payments' => $result,
            'message'  => 'Zuora payments fetch for account ' . $zuoraAccountId,
        ];
    }

    /**
     * Resolve Zuora Account ID from Account.cZuoraAccountId.
     */
    protected function resolveZuoraAccountIdFromAccount($accountId)
    {
        if (!$accountId) {
            return null;
        }

        $entityManager = $this->getEntityManager();
        $account       = $entityManager->getEntity('Account', $accountId);

        if (!$account) {
            return null;
        }

        return $account->get('cZuoraAccountId') ?: null;
    }

    /**
     * Zuora request helper - captures status/body/curl errors (no token leakage).
     */
    protected function zuoraRequest(string $method, string $url, string $accessToken, ?array $body = null): array
    {
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'Content-Type: application/json',
            'Zuora-Version: 211.0',
        ];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw    = curl_exec($ch);
        $errNo  = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        return [
            'ok'        => ($errNo === 0) && ($status >= 200 && $status < 300),
            'httpStatus'=> $status ?: 0,
            'curlErrNo' => $errNo,
            'curlError' => $errMsg ?: null,
            'body'      => ($raw === false ? null : $raw),
            'url'       => $url,
            'method'    => strtoupper($method),
        ];
    }

    /**
     * Espo logger helper (safe).
     */
    protected function logDebug(string $message, array $context = []): void
    {
        try {
            $logger = $this->getContainer()->get('logger');
            $logger->info('[ZuoraPayment] ' . $message . ' ' . json_encode($context));
        } catch (\Throwable $e) {
            // never break API for logging
        }
    }

    /**
     * Convert various Zuora date formats to ISO-like YYYY-MM-DD (or null).
     */
    protected function toIsoDate($value): ?string
    {
        if (!$value) {
            return null;
        }

        if (is_string($value)) {
            // already yyyy-mm-dd?
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
                return $m[1] . '-' . $m[2] . '-' . $m[3];
            }
            return $value; // last resort
        }

        return null;
    }

    /**
     * Summarise gateway response in a human-friendly string.
     */
    protected function summariseGateway($gatewayResponse): ?string
    {
        if ($gatewayResponse === null) {
            return null;
        }

        if (is_string($gatewayResponse)) {
            return $gatewayResponse;
        }

        if (!is_array($gatewayResponse)) {
            return null;
        }

        $bits = [];

        if (!empty($gatewayResponse['responseCode'])) {
            $bits[] = 'Code ' . $gatewayResponse['responseCode'];
        }
        if (!empty($gatewayResponse['responseMessage'])) {
            $bits[] = $gatewayResponse['responseMessage'];
        }
        if (!empty($gatewayResponse['gatewayState'])) {
            $bits[] = $gatewayResponse['gatewayState'];
        }

        if (empty($bits)) {
            // last resort: JSON snippet
            $raw = json_encode($gatewayResponse);
            return $raw ? mb_substr($raw, 0, 300) : null;
        }

        return implode(' - ', $bits);
    }

    /**
     * Pick a cardholder / holder name from payment method response.
     */
    protected function pickCardholderName($pm): ?string
    {
        if (!is_array($pm)) {
            return null;
        }

        // Common keys across Zuora PM shapes
        foreach (['creditCardHolderName', 'cardHolderName', 'holderName', 'name'] as $k) {
            if (!empty($pm[$k]) && is_string($pm[$k])) {
                return $pm[$k];
            }
        }

        return null;
    }

    /**
     * Format payment method label: e.g. "Visa •••• 1234" or "Direct Debit".
     */
    protected function formatPaymentMethodLabel($pm): ?string
    {
        if (!is_array($pm)) {
            return null;
        }

        // Credit card style
        $type = $pm['type'] ?? $pm['paymentMethodType'] ?? null;

        $cardType = $pm['creditCardType'] ?? null;
        $last4    = $pm['creditCardNumber'] ?? $pm['creditCardMaskNumber'] ?? $pm['maskedNumber'] ?? null;

        if ($cardType || $last4) {
            $t = $cardType ?: 'Card';
            if (is_string($last4)) {
                // keep last 4 digits if mask contains more
                $digits = preg_replace('/\D+/', '', $last4);
                if ($digits && strlen($digits) >= 4) {
                    $last4 = substr($digits, -4);
                }
            }
            return trim($t . ' •••• ' . (string) $last4);
        }

        // Non-card methods
        if (is_string($type) && $type !== '') {
            return $type;
        }

        return null;
    }

    /**
     * Format expiry as MM/YYYY if present.
     */
    protected function formatCardExpiry($pm): ?string
    {
        if (!is_array($pm)) {
            return null;
        }

        $month = $pm['creditCardExpirationMonth'] ?? $pm['expirationMonth'] ?? null;
        $year  = $pm['creditCardExpirationYear'] ?? $pm['expirationYear'] ?? null;

        if ($month && $year) {
            $m = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
            return $m . '/' . (string) $year;
        }

        // Sometimes it's a combined field
        $expiry = $pm['creditCardExpirationDate'] ?? $pm['expirationDate'] ?? null;
        if (is_string($expiry) && $expiry !== '') {
            return $expiry;
        }

        return null;
    }

    /**
     * Get Zuora access token via client_credentials.
     */
    protected function getZuoraAccessToken()
    {
        $config       = $this->getConfig();
        $baseUrl      = rtrim((string) $config->get('zuoraApiUrl'), '/');
        $clientId     = $config->get('zuoraClientId');
        $clientSecret = $config->get('zuoraClientSecret');

        if (!$baseUrl || !$clientId || !$clientSecret) {
            return null;
        }

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
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $status= curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false || $status >= 400) {
            $this->logDebug('Zuora token fetch failed', [
                'httpStatus' => $status,
                'curlErrNo'  => $errno,
                'curlError'  => $err,
            ]);
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['access_token'])) {
            $this->logDebug('Zuora token response missing access_token', [
                'bodyPreview' => mb_substr((string) $raw, 0, 500),
            ]);
            return null;
        }

        return $data['access_token'];
    }

    /**
     * Helper: get entity manager from DI.
     */
    protected function getEntityManager()
    {
        return $this->getContainer()->get('entityManager');
    }

    /**
     * Helper: get config service.
     */
    protected function getConfig()
    {
        return $this->getContainer()->get('config');
    }
}
