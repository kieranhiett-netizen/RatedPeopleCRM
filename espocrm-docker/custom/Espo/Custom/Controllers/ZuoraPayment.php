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

        // ---------------------------
        // 1) Payment query (works)
        // ---------------------------
        $safeAccountId = $this->escapeZoqlString((string) $zuoraAccountId);

        $paymentQuery =
            "select Id, PaymentNumber, Amount, Status, EffectiveDate, CreatedDate, PaymentMethodId, GatewayResponse " .
            "from Payment where AccountId = '" . $safeAccountId . "'";

        $queryUrl  = $zuoraApiUrl . '/v1/action/query';
        $queryBody = ['queryString' => $paymentQuery];

        $paymentResp = $this->zuoraRequest('POST', $queryUrl, $accessToken, $queryBody);
        $paymentRaw  = (string) ($paymentResp['body'] ?? '');
        $paymentJson = json_decode($paymentRaw, true);

        if (!$paymentResp['ok'] || !is_array($paymentJson) || !empty($paymentJson['FaultCode'])) {
            return [
                'success'  => false,
                'payments' => [],
                'message'  => 'Zuora Payment query failed.',
                'debug'    => [
                    'paymentQuery' => $this->buildDebugBlock('POST', $queryUrl, $paymentQuery, $paymentResp, $paymentRaw),
                ],
            ];
        }

        $paymentRecords = $paymentJson['records'] ?? [];
        if (!is_array($paymentRecords)) {
            $paymentRecords = [];
        }

        // Collect paymentMethodIds
        $paymentMethodIds = [];
        foreach ($paymentRecords as $p) {
            if (!is_array($p)) continue;
            if (!empty($p['PaymentMethodId'])) {
                $paymentMethodIds[] = (string) $p['PaymentMethodId'];
            }
        }
        $paymentMethodIds = array_values(array_unique($paymentMethodIds));

        // ---------------------------
        // 2) PaymentMethod enrichment
        // ---------------------------
        $pmMap = [];
        $pmDebug = [];

        if (!empty($paymentMethodIds)) {
            $pmFetch = $this->fetchPaymentMethodsByIdsResilient($paymentMethodIds, $zuoraApiUrl, $accessToken);
            $pmMap   = $pmFetch['map'];
            $pmDebug = $pmFetch['debug'];
        }

        // ---------------------------
        // 3) Map payments for UI
        // ---------------------------
        $payments = [];

        foreach ($paymentRecords as $p) {
            if (!is_array($p)) continue;

            $pmId = $p['PaymentMethodId'] ?? null;
            $pm   = ($pmId && isset($pmMap[$pmId])) ? $pmMap[$pmId] : null;

            $payments[] = [
                'payment'         => $p['PaymentNumber'] ?? ($p['Id'] ?? null),
                'cardholder'      => $pm ? $this->extractPaymentMethodCardholder($pm) : null,
                'amount'          => $p['Amount'] ?? null,
                'gateway'         => $p['GatewayResponse'] ?? null,
                'status'          => $p['Status'] ?? null,
                'dateIso'         => $this->toIsoDate($p['EffectiveDate'] ?? ($p['CreatedDate'] ?? null)),
                'method'          => $pm ? $this->formatPaymentMethodDisplay($pm) : ($pmId ?: null),
                'expiration'      => $pm ? $this->formatPaymentMethodExpiry($pm) : null,
                'zuoraPaymentId'  => $p['Id'] ?? null,
                'paymentMethodId' => $pmId,
            ];
        }

        $response = [
            'success'  => true,
            'payments' => $payments,
            'message'  => 'Zuora payments fetch for account ' . $zuoraAccountId,
        ];

        // Always include debug blocks if:
        // - debug requested, OR
        // - enrichment failed (so we can see why)
        $enrichmentLooksBroken = (!empty($paymentMethodIds) && empty($pmMap));

        if ($debug || $enrichmentLooksBroken) {
            $response['debug'] = [
                'paymentQuery' => $this->buildDebugBlock('POST', $queryUrl, $paymentQuery, $paymentResp, $paymentRaw),
                'paymentMethodQueries' => $pmDebug,
                'counts' => [
                    'payments' => count($paymentRecords),
                    'paymentMethodIds' => count($paymentMethodIds),
                    'paymentMethodsFetched' => count($pmMap),
                ],
            ];
        }

        return $response;
    }

    /**
     * Resilient PaymentMethod fetch:
     * - Try query with a richer field list
     * - If Zuora faults, fall back to a minimal field list
     */
    protected function fetchPaymentMethodsByIdsResilient(array $ids, string $zuoraApiUrl, string $accessToken): array
    {
        $debugBlocks = [];

        // Try "richer" field list first
        $attempt1 = $this->fetchPaymentMethodsByIds(
            $ids,
            $zuoraApiUrl,
            $accessToken,
            "select Id, Type, CreditCardType, CreditCardMaskNumber, CreditCardExpirationMonth, CreditCardExpirationYear, CreditCardHolderName from PaymentMethod where Id in (%s)",
            $debugBlocks
        );

        if (!empty($attempt1['map'])) {
            return ['map' => $attempt1['map'], 'debug' => $debugBlocks];
        }

        // If attempt1 failed (fault / empty due to unsupported fields), fall back
        $attempt2 = $this->fetchPaymentMethodsByIds(
            $ids,
            $zuoraApiUrl,
            $accessToken,
            "select Id, Type, CreditCardMaskNumber, CreditCardExpirationMonth, CreditCardExpirationYear from PaymentMethod where Id in (%s)",
            $debugBlocks
        );

        return ['map' => $attempt2['map'], 'debug' => $debugBlocks];
    }

    /**
     * Fetch PaymentMethods in chunks, using a query template.
     * Query template must contain "%s" placeholder for the IN (...) list.
     */
    protected function fetchPaymentMethodsByIds(
        array $ids,
        string $zuoraApiUrl,
        string $accessToken,
        string $queryTemplate,
        array &$debugBlocks
    ): array {
        $map = [];

        $chunks = array_chunk($ids, 50);
        $url = rtrim($zuoraApiUrl, '/') . '/v1/action/query';

        foreach ($chunks as $chunk) {
            $safeIds = array_map(function ($id) {
                return "'" . $this->escapeZoqlString((string) $id) . "'";
            }, $chunk);

            $queryString = sprintf($queryTemplate, implode(',', $safeIds));
            $body = ['queryString' => $queryString];

            $resp = $this->zuoraRequest('POST', $url, $accessToken, $body);
            $raw  = (string) ($resp['body'] ?? '');
            $json = json_decode($raw, true);

            $debugBlocks[] = $this->buildDebugBlock('POST', $url, $queryString, $resp, $raw);

            if (!$resp['ok'] || !is_array($json) || !empty($json['FaultCode'])) {
                continue;
            }

            $records = $json['records'] ?? [];
            if (!is_array($records)) continue;

            foreach ($records as $pm) {
                if (!is_array($pm) || empty($pm['Id'])) continue;
                $map[(string) $pm['Id']] = $pm;
            }
        }

        return ['map' => $map];
    }

    protected function formatPaymentMethodDisplay(array $pm): ?string
    {
        $mask = $pm['CreditCardMaskNumber'] ?? null;
        $cardType = $pm['CreditCardType'] ?? null;

        if ($mask || $cardType) {
            $parts = ['Credit Card'];
            if ($cardType) $parts[] = (string) $cardType;
            if ($mask) $parts[] = (string) $mask;
            return implode(' ', $parts);
        }

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
        return null;
    }

    protected function resolveZuoraAccountIdFromAccount($accountId)
    {
        if (!$accountId) return null;

        $em = $this->getEntityManager();
        $account = $em->getEntity('Account', $accountId);

        if (!$account) return null;

        return $account->get('cZuoraAccountId') ?: null;
    }

    protected function escapeZoqlString(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }

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
