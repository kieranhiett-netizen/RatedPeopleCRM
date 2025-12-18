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
     * List payments for an account (ZOQL) and enrich with PaymentMethod details.
     *
     * POST /api/v1/ZuoraPayment/action/list
     *
     * Payload:
     *  - accountId (optional, Espo Account id)
     *  - zuoraAccountId (preferred: Zuora Account UUID)
     *  - debug (optional boolean) -> includes request/response diagnostics
     */
    public function postActionList($params, $data, $request): array
    {
        $this->checkAccess();

        $accountId      = $data->accountId ?? null;
        $zuoraAccountId = $data->zuoraAccountId ?? null;
        $debug          = !empty($data->debug);

        // Resolve Zuora Account Id from Account.cZuoraAccountId if missing
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

        // ---- 1) Fetch Payments (ZOQL) ----
        $safeAccountId = $this->escapeZoqlString((string) $zuoraAccountId);

        // NOTE: Your Zuora UI accepted ORDER BY, but we can still sort client-side anyway.
        // Keep query minimal + reliable.
        $paymentQuery =
            "select Id, PaymentNumber, Amount, Status, EffectiveDate, CreatedDate, PaymentMethodId, GatewayResponse " .
            "from Payment where AccountId = '" . $safeAccountId . "'";

        $paymentsUrl  = $zuoraApiUrl . '/v1/action/query';
        $paymentsBody = ['queryString' => $paymentQuery];

        $paymentsResp = $this->zuoraRequest('POST', $paymentsUrl, $accessToken, $paymentsBody);
        $paymentsRaw  = (string) ($paymentsResp['body'] ?? '');
        $paymentsJson = json_decode($paymentsRaw, true);

        // Transport-level failure
        if (!$paymentsResp['ok'] || !is_array($paymentsJson)) {
            return [
                'success'  => false,
                'payments' => [],
                'message'  => 'Zuora payments query failed (HTTP ' . (int) $paymentsResp['httpStatus'] . ').',
                'debug'    => $debug ? $this->buildDebugBlock('POST', $paymentsUrl, $paymentQuery, $paymentsResp, $paymentsRaw) : null,
            ];
        }

        // Fault-level failure
        if (!empty($paymentsJson['FaultCode'])) {
            return [
                'success'  => false,
                'payments' => [],
                'message'  => 'Zuora query error: ' . ($paymentsJson['FaultMessage'] ?? $paymentsJson['FaultCode']),
                'debug'    => $debug ? $this->buildDebugBlock('POST', $paymentsUrl, $paymentQuery, $paymentsResp, $paymentsRaw) : null,
            ];
        }

        $records = $paymentsJson['records'] ?? [];
        if (!is_array($records)) {
            $records = [];
        }

        // ---- 2) Collect PaymentMethodIds ----
        $paymentMethodIds = [];
        foreach ($records as $p) {
            if (!is_array($p)) {
                continue;
            }
            if (!empty($p['PaymentMethodId'])) {
                $paymentMethodIds[] = (string) $p['PaymentMethodId'];
            }
        }
        $paymentMethodIds = array_values(array_unique($paymentMethodIds));

        // ---- 3) Fetch PaymentMethod details in bulk (ZOQL) ----
        $paymentMethodsById = [];
        $paymentMethodDebug = [];

        if (!empty($paymentMethodIds)) {
            $pmFetch = $this->fetchPaymentMethodsByIds($paymentMethodIds, $zuoraApiUrl, $accessToken, $debug);
            $paymentMethodsById = $pmFetch['map'];
            if ($debug && !empty($pmFetch['debug'])) {
                $paymentMethodDebug = $pmFetch['debug'];
            }
        }

        // ---- 4) Build output ----
        $payments = [];

        foreach ($records as $p) {
            if (!is_array($p)) {
                continue;
            }

            $pmId = $p['PaymentMethodId'] ?? null;
            $pm   = ($pmId && isset($paymentMethodsById[$pmId])) ? $paymentMethodsById[$pmId] : null;

            $payments[] = [
                'payment'         => $p['PaymentNumber'] ?? ($p['Id'] ?? null),
                'cardholder'      => $pm ? $this->extractPaymentMethodCardholder($pm) : null,
                'amount'          => $p['Amount'] ?? null,
                'gateway'         => $p['GatewayResponse'] ?? null,
                'status'          => $p['Status'] ?? null,
                'dateIso'         => $this->toIsoDate($p['EffectiveDate'] ?? ($p['CreatedDate'] ?? null)),
                'method'          => $pm ? $this->formatPaymentMethodDisplay($pm) : ($pmId ?: null),
                'expiration'      => $pm ? $this->formatPaymentMethodExpiry($pm) : null,

                // keep IDs for future expansion / debugging
                'zuoraPaymentId'  => $p['Id'] ?? null,
                'paymentMethodId' => $pmId,
            ];
        }

        $response = [
            'success'  => true,
            'payments' => $payments,
            'message'  => 'Zuora payments fetch for account ' . $zuoraAccountId,
        ];

        // ---- Debug block (includes BOTH payments + debug) ----
        if ($debug) {
            $response['debug'] = [
                'paymentsQuery' => $this->buildDebugBlock('POST', $paymentsUrl, $paymentQuery, $paymentsResp, $paymentsRaw),
                'paymentMethodQueries' => $paymentMethodDebug,
                'counts' => [
                    'payments' => count($records),
                    'paymentMethodIds' => count($paymentMethodIds),
                    'paymentMethodsFetched' => count($paymentMethodsById),
                ],
            ];
        }

        return $response;
    }

    protected function resolveZuoraAccountIdFromAccount($accountId)
    {
        if (!$accountId) {
            return null;
        }

        $em = $this->getEntityManager();
        $account = $em->getEntity('Account', $accountId);

        if (!$account) {
            return null;
        }

        return $account->get('cZuoraAccountId') ?: null;
    }

    /**
     * Bulk fetch PaymentMethods by ID using ZOQL.
     *
     * Returns:
     *  - map: [paymentMethodId => paymentMethodRecord]
     *  - debug: list of per-chunk debug blocks (if $debug true)
     */
    protected function fetchPaymentMethodsByIds(array $ids, string $zuoraApiUrl, string $accessToken, bool $debug = false): array
    {
        $map = [];
        $debugBlocks = [];

        // Chunk to avoid overly long query strings
        $chunks = array_chunk($ids, 50);

        foreach ($chunks as $chunk) {
            $safeIds = array_map(function ($id) {
                return "'" . $this->escapeZoqlString((string) $id) . "'";
            }, $chunk);

            // Field set is "best effort". If your tenant complains, paste the fault and we’ll narrow.
            $pmQuery =
                "select Id, Type, CreditCardType, CreditCardMaskNumber, " .
                "CreditCardExpirationMonth, CreditCardExpirationYear, " .
                "CreditCardHolderName, BankTransferAccountName " .
                "from PaymentMethod where Id in (" . implode(',', $safeIds) . ")";

            $url  = rtrim($zuoraApiUrl, '/') . '/v1/action/query';
            $body = ['queryString' => $pmQuery];

            $resp = $this->zuoraRequest('POST', $url, $accessToken, $body);
            $raw  = (string) ($resp['body'] ?? '');
            $json = json_decode($raw, true);

            if ($debug) {
                $debugBlocks[] = $this->buildDebugBlock('POST', $url, $pmQuery, $resp, $raw);
            }

            if (!$resp['ok'] || !is_array($json)) {
                continue;
            }

            if (!empty($json['FaultCode'])) {
                continue;
            }

            $records = $json['records'] ?? [];
            if (!is_array($records)) {
                continue;
            }

            foreach ($records as $pm) {
                if (!is_array($pm) || empty($pm['Id'])) {
                    continue;
                }
                $map[(string) $pm['Id']] = $pm;
            }
        }

        return [
            'map' => $map,
            'debug' => $debugBlocks,
        ];
    }

    protected function formatPaymentMethodDisplay(array $pm): ?string
    {
        // Most likely path: credit card
        $mask = $pm['CreditCardMaskNumber'] ?? null;
        $cardType = $pm['CreditCardType'] ?? null;

        if ($mask || $cardType) {
            $parts = [];
            $parts[] = 'Credit Card';
            if ($cardType) {
                $parts[] = (string) $cardType;
            }
            if ($mask) {
                $parts[] = (string) $mask;
            }
            return implode(' ', $parts);
        }

        // Fallback to Type if present
        if (!empty($pm['Type'])) {
            return (string) $pm['Type'];
        }

        return null;
    }

    protected function formatPaymentMethodExpiry(array $pm): ?string
    {
        $m = $pm['CreditCardExpirationMonth'] ?? null;
        $y = $pm['CreditCardExpirationYear'] ?? null;

        if ($m === null || $y === null || $m === '' || $y === '') {
            return null;
        }

        $mm = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
        return $mm . '/' . (string) $y;
    }

    protected function extractPaymentMethodCardholder(array $pm): ?string
    {
        if (!empty($pm['CreditCardHolderName'])) {
            return (string) $pm['CreditCardHolderName'];
        }
        if (!empty($pm['BankTransferAccountName'])) {
            return (string) $pm['BankTransferAccountName'];
        }
        return null;
    }

    /**
     * Escape for safe inclusion inside a ZOQL single-quoted string.
     */
    protected function escapeZoqlString(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }

    /**
     * Basic HTTP helper
     */
    protected function zuoraRequest(string $method, string $url, string $accessToken, ?array $body = null): array
    {
        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'Content-Type: application/json',
            'Zuora-Version: 211.0',
        ];

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
            'ok'         => ($errNo === 0) && ($status >= 200 && $status < 300),
            'httpStatus' => $status ?: 0,
            'curlErrNo'  => $errNo,
            'curlError'  => $errMsg ?: null,
            'body'       => ($raw === false ? null : $raw),
        ];
    }

    protected function buildDebugBlock(string $method, string $url, ?string $queryString, array $resp, string $raw): array
    {
        $block = [
            'request' => [
                'method' => $method,
                'url'    => $url,
            ],
            'response' => [
                'ok'         => $resp['ok'] ?? false,
                'httpStatus' => $resp['httpStatus'] ?? null,
                'curlErrNo'  => $resp['curlErrNo'] ?? null,
                'curlError'  => $resp['curlError'] ?? null,
                'bodyPreview'=> mb_substr($raw, 0, 4000),
            ],
        ];

        if ($queryString !== null) {
            $block['request']['queryString'] = $queryString;
        }

        return $block;
    }

    protected function toIsoDate($value): ?string
    {
        if (!$value || !is_string($value)) {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m)) {
            return $m[1];
        }
        return $value;
    }

    /**
     * OAuth client_credentials token (client id + secret) -> returns access_token
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
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false || $status >= 400) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['access_token'])) {
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
