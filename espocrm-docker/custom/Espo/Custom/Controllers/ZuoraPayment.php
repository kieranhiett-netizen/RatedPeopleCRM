<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Exceptions\Forbidden;

class ZuoraPayment extends \Espo\Core\Controllers\Base
{
    protected function checkAccess(): bool
    {
        if (!$this->getAcl()->checkScope('Account', 'read')) {
            throw new Forbidden();
        }
        return true;
    }

    /**
     * POST /api/v1/ZuoraPayment/action/list
     *
     * Payload:
     *  - accountId (optional)
     *  - zuoraAccountId (preferred)
     *  - debug (optional boolean)
     */
    public function postActionList($params, $data, $request): array
    {
        $this->checkAccess();

        $accountId      = $data->accountId ?? null;
        $zuoraAccountId = $data->zuoraAccountId ?? null;
        $debug          = !empty($data->debug);

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

        // ✅ ZOQL query for payments by AccountId (Zuora UUID)
        // NOTE: field names are standard ZOQL; if your tenant differs, debug will show it.
        $queryString = sprintf(
            "select Id, PaymentNumber, Amount, Status, EffectiveDate, CreatedDate, PaymentMethodId, GatewayResponse " .
            "from Payment where AccountId = '%s' order by CreatedDate desc",
            str_replace("'", "\\'", (string) $zuoraAccountId)
        );

        $queryUrl = $zuoraApiUrl . '/v1/action/query';

        $resp = $this->zuoraRequest(
            'POST',
            $queryUrl,
            $accessToken,
            ['queryString' => $queryString]
        );

        $this->logDebug('Payments ZOQL query response', [
            'zuoraAccountId' => (string) $zuoraAccountId,
            'httpStatus'     => $resp['httpStatus'],
            'curlErrNo'      => $resp['curlErrNo'],
            'curlError'      => $resp['curlError'],
        ]);

        $rawBody = (string) ($resp['body'] ?? '');
        $json    = json_decode($rawBody, true);

        // Debug response: show what Zuora returned (preview)
        if ($debug) {
            $zuoraSuccess = is_array($json) && array_key_exists('success', $json) ? (bool) $json['success'] : null;

            return [
                'success'  => true,
                'payments' => [],
                'message'  => 'Zuora payments debug (ZOQL) for account ' . $zuoraAccountId,
                'debug'    => [
                    'request' => [
                        'method'     => $resp['method'],
                        'url'        => $resp['url'],
                        'queryString'=> $queryString,
                    ],
                    'response' => [
                        'ok'           => $resp['ok'],
                        'httpStatus'   => $resp['httpStatus'],
                        'curlErrNo'    => $resp['curlErrNo'],
                        'curlError'    => $resp['curlError'],
                        'zuoraSuccess' => $zuoraSuccess,
                        'reasons'      => is_array($json) ? ($json['reasons'] ?? null) : null,
                        'bodyPreview'  => mb_substr($rawBody, 0, 4000),
                    ],
                ],
            ];
        }

        // Network-level failure
        if (!$resp['ok']) {
            return [
                'success'  => false,
                'payments' => [],
                'message'  => 'Zuora payments query failed (HTTP ' . (int) $resp['httpStatus'] . '). See server logs.',
            ];
        }

        // JSON parse failure
        if (!is_array($json)) {
            return [
                'success'  => false,
                'payments' => [],
                'message'  => 'Zuora payments response was not valid JSON.',
            ];
        }

        // Zuora-level failure (can still be HTTP 200)
        if (isset($json['success']) && $json['success'] === false) {
            $reasonMsg = $json['reasons'][0]['message'] ?? 'Unknown Zuora error';
            $this->logDebug('Zuora returned success:false for payments query', [
                'zuoraAccountId' => (string) $zuoraAccountId,
                'reason'         => $reasonMsg,
                'reasons'        => $json['reasons'] ?? null,
            ]);

            return [
                'success'  => false,
                'payments' => [],
                'message'  => 'Zuora error: ' . $reasonMsg,
            ];
        }

        // Records can be in "records" (common) or sometimes "results"
        $records = [];
        if (!empty($json['records']) && is_array($json['records'])) {
            $records = $json['records'];
        } elseif (!empty($json['results']) && is_array($json['results'])) {
            $records = $json['results'];
        }

        if (empty($records)) {
            return [
                'success'  => true,
                'payments' => [],
                'message'  => 'Zuora payments fetch for account ' . $zuoraAccountId,
            ];
        }

        // Fetch payment methods to enrich cardholder/method/expiry
        $pmIds = [];
        foreach ($records as $p) {
            if (is_array($p) && !empty($p['PaymentMethodId'])) {
                $pmIds[(string) $p['PaymentMethodId']] = true;
            } elseif (is_array($p) && !empty($p['paymentMethodId'])) {
                // fallback if Zuora returns lower-cased keys
                $pmIds[(string) $p['paymentMethodId']] = true;
            }
        }
        $pmIds = array_keys($pmIds);

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

            $pmJson = json_decode((string) ($pmResp['body'] ?? ''), true);
            if (is_array($pmJson)) {
                $pmById[$pmId] = $pmJson;
            }
        }

        // Normalise for your UI
        $result = [];
        foreach ($records as $p) {
            if (!is_array($p)) continue;

            // Zuora can return keys as PaymentNumber/Amount or lower-case depending on endpoint
            $paymentNumber = $p['PaymentNumber'] ?? ($p['paymentNumber'] ?? ($p['Id'] ?? ($p['id'] ?? null)));
            $amount        = $p['Amount'] ?? ($p['amount'] ?? null);
            $status        = $p['Status'] ?? ($p['status'] ?? null);
            $effectiveDate = $p['EffectiveDate'] ?? ($p['effectiveDate'] ?? null);
            $createdDate   = $p['CreatedDate'] ?? ($p['createdDate'] ?? null);
            $pmId          = (string) ($p['PaymentMethodId'] ?? ($p['paymentMethodId'] ?? ''));

            $gatewayResponse = $p['GatewayResponse'] ?? ($p['gatewayResponse'] ?? null);

            $pm = ($pmId && isset($pmById[$pmId])) ? $pmById[$pmId] : null;

            $dateIso = $this->toIsoDate($effectiveDate) ?: $this->toIsoDate($createdDate);

            $result[] = [
                'payment'    => $paymentNumber,
                'cardholder' => $this->pickCardholderName($pm),
                'amount'     => $amount,
                'gateway'    => $this->summariseGateway($gatewayResponse),
                'status'     => $status,
                'dateIso'    => $dateIso,
                'method'     => $this->formatPaymentMethodLabel($pm),
                'expiration' => $this->formatCardExpiry($pm),

                // internal/debug
                'zuoraPaymentId'       => $p['Id'] ?? ($p['id'] ?? null),
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

    protected function resolveZuoraAccountIdFromAccount($accountId)
    {
        if (!$accountId) return null;

        $entityManager = $this->getEntityManager();
        $account       = $entityManager->getEntity('Account', $accountId);

        if (!$account) return null;

        return $account->get('cZuoraAccountId') ?: null;
    }

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

    protected function logDebug(string $message, array $context = []): void
    {
        try {
            $logger = $this->getContainer()->get('logger');
            $logger->info('[ZuoraPayment] ' . $message . ' ' . json_encode($context));
        } catch (\Throwable $e) {
            // swallow
        }
    }

    protected function toIsoDate($value): ?string
    {
        if (!$value) return null;

        if (is_string($value)) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
                return $m[1] . '-' . $m[2] . '-' . $m[3];
            }
            return $value;
        }

        return null;
    }

    protected function summariseGateway($gatewayResponse): ?string
    {
        if ($gatewayResponse === null) return null;

        if (is_string($gatewayResponse)) return $gatewayResponse;

        if (!is_array($gatewayResponse)) return null;

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
            $raw = json_encode($gatewayResponse);
            return $raw ? mb_substr($raw, 0, 300) : null;
        }

        return implode(' - ', $bits);
    }

    protected function pickCardholderName($pm): ?string
    {
        if (!is_array($pm)) return null;

        foreach (['creditCardHolderName', 'cardHolderName', 'holderName', 'name'] as $k) {
            if (!empty($pm[$k]) && is_string($pm[$k])) {
                return $pm[$k];
            }
        }

        return null;
    }

    protected function formatPaymentMethodLabel($pm): ?string
    {
        if (!is_array($pm)) return null;

        $type = $pm['type'] ?? $pm['paymentMethodType'] ?? null;

        $cardType = $pm['creditCardType'] ?? null;
        $last4    = $pm['creditCardNumber'] ?? $pm['creditCardMaskNumber'] ?? $pm['maskedNumber'] ?? null;

        if ($cardType || $last4) {
            $t = $cardType ?: 'Card';
            if (is_string($last4)) {
                $digits = preg_replace('/\D+/', '', $last4);
                if ($digits && strlen($digits) >= 4) {
                    $last4 = substr($digits, -4);
                }
            }
            return trim($t . ' •••• ' . (string) $last4);
        }

        if (is_string($type) && $type !== '') {
            return $type;
        }

        return null;
    }

    protected function formatCardExpiry($pm): ?string
    {
        if (!is_array($pm)) return null;

        $month = $pm['creditCardExpirationMonth'] ?? $pm['expirationMonth'] ?? null;
        $year  = $pm['creditCardExpirationYear'] ?? $pm['expirationYear'] ?? null;

        if ($month && $year) {
            $m = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
            return $m . '/' . (string) $year;
        }

        $expiry = $pm['creditCardExpirationDate'] ?? $pm['expirationDate'] ?? null;
        if (is_string($expiry) && $expiry !== '') {
            return $expiry;
        }

        return null;
    }

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

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false || $status >= 400) {
            $this->logDebug('Zuora token fetch failed', [
                'httpStatus' => $status,
                'curlErrNo'  => $errno,
                'curlError'  => $errMsg,
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

    protected function getEntityManager()
    {
        return $this->getContainer()->get('entityManager');
    }

    protected function getConfig()
    {
        return $this->getContainer()->get('config');
    }
}
