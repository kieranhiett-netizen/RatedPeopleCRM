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
     * List payments for an account using ZOQL:
     * POST /api/v1/ZuoraPayment/action/list
     *
     * Payload:
     *  - accountId (optional)
     *  - zuoraAccountId (preferred: Zuora Account UUID)
     *  - debug (optional boolean) -> returns request/response diagnostics
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

        // ✅ IMPORTANT: ZOQL does NOT support ORDER BY.
        // Sort in JS (you already do).
        $safeId = str_replace("'", "\\'", (string) $zuoraAccountId);

        $queryString =
            "select Id, PaymentNumber, Amount, Status, EffectiveDate, CreatedDate, PaymentMethodId, GatewayResponse " .
            "from Payment where AccountId = '" . $safeId . "'";

        $url  = $zuoraApiUrl . '/v1/action/query';
        $body = ['queryString' => $queryString];

        $resp = $this->zuoraRequest('POST', $url, $accessToken, $body);

        $raw = (string) ($resp['body'] ?? '');
        $json = json_decode($raw, true);

        if ($debug) {
            return [
                'success'  => true,
                'payments' => [],
                'message'  => 'Zuora payments debug (ZOQL) for account ' . $zuoraAccountId,
                'debug'    => [
                    'request' => [
                        'method'     => 'POST',
                        'url'        => $url,
                        'queryString'=> $queryString,
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

        // Transport failure
        if (!$resp['ok'] || !is_array($json)) {
            return [
                'success'  => false,
                'payments' => [],
                'message'  => 'Zuora payments query failed (HTTP ' . (int) $resp['httpStatus'] . ').',
            ];
        }

        // ZOQL can still return faults; if "FaultCode" exists, treat as error
        if (!empty($json['FaultCode'])) {
            return [
                'success'  => false,
                'payments' => [],
                'message'  => 'Zuora query error: ' . ($json['FaultMessage'] ?? $json['FaultCode']),
            ];
        }

        // ZOQL query response typically has "records"
        $records = $json['records'] ?? [];
        if (!is_array($records)) {
            $records = [];
        }

        // Build output for your panel
        $payments = [];
        foreach ($records as $p) {
            if (!is_array($p)) continue;

            $payments[] = [
                'payment'      => $p['PaymentNumber'] ?? ($p['Id'] ?? null),
                'cardholder'   => null, // optional later (from PaymentMethod lookup)
                'amount'       => $p['Amount'] ?? null,
                'gateway'      => $p['GatewayResponse'] ?? null,
                'status'       => $p['Status'] ?? null,
                'dateIso'      => $this->toIsoDate($p['EffectiveDate'] ?? ($p['CreatedDate'] ?? null)),
                'method'       => $p['PaymentMethodId'] ?? null, // optional later format
                'expiration'   => null, // optional later
                'zuoraPaymentId' => $p['Id'] ?? null,
            ];
        }

        return [
            'success'  => true,
            'payments' => $payments,
            'message'  => 'Zuora payments fetch for account ' . $zuoraAccountId,
        ];
    }

    protected function resolveZuoraAccountIdFromAccount($accountId)
    {
        if (!$accountId) return null;

        $em = $this->getEntityManager();
        $account = $em->getEntity('Account', $accountId);

        if (!$account) return null;

        return $account->get('cZuoraAccountId') ?: null;
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

        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
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
