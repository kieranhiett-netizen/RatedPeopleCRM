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
     *  - zuoraAccountId (preferred: Zuora Account UUID)
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

        // -----------------------------
        // 1) Query Payments (ZOQL)
        // -----------------------------
        $safeAccountId = $this->escapeZoqlString((string) $zuoraAccountId);

        $paymentQuery =
            "select Id, PaymentNumber, Amount, Status, EffectiveDate, CreatedDate, PaymentMethodId, GatewayResponse " .
            "from Payment where AccountId = '" . $safeAccountId . "'";

        $queryUrl  = $zuoraApiUrl . '/v1/action/query';
        $paymentResp = $this->zuoraRequest('POST', $queryUrl, $accessToken, [
            'queryString' => $paymentQuery,
        ]);

        $paymentRaw  = (string) ($paymentResp['body'] ?? '');
        $paymentJson = json_decode($paymentRaw, true);

        // If debug, we still want to continue so you can see method lookup too.
        $debugPayload = [
            'paymentQuery' => [
                'request' => [
                    'method'     => 'POST',
                    'url'        => $queryUrl,
                    'queryString'=> $paymentQuery,
                ],
                'response' => [
                    'ok'         => $paymentResp['ok'],
                    'httpStatus' => $paymentResp['httpStatus'],
                    'curlErrNo'  => $paymentResp['curlErrNo'],
                    'curlError'  => $paymentResp['curlError'],
                    'bodyPreview'=> mb_substr($paymentRaw, 0, 4000),
                ],
            ],
            'paymentMethodQueries' => [],
            'counts' => [
                'payments'            => 0,
                'paymentMethodIds'    => 0,
                'paymentMethodsFetched'=> 0,
            ],
        ];

        if (!$paymentResp['ok'] || !is_array($paymentJson) || !empty($paymentJson['FaultCode'])) {
            $msg = 'Zuora payments query failed.';
            if (!empty($paymentJson['FaultMessage'])) {
                $msg .= ' ' . $paymentJson['FaultMessage'];
            } else {
                $msg .= ' (HTTP ' . (int) $paymentResp['httpStatus'] . ').';
            }

            return [
                'success'  => false,
                'payments' => [],
                'message'  => $msg,
                'debug'    => $debug ? $debugPayload : null,
            ];
        }

        $records = $paymentJson['records'] ?? [];
        if (!is_array($records)) {
            $records = [];
        }

        $debugPayload['counts']['payments'] = count($records);

        // Collect PaymentMethodIds
        $pmIds = [];
        foreach ($records as $p) {
            if (!is_array($p)) continue;
            if (!empty($p['PaymentMethodId'])) {
                $pmIds[] = (string) $p['PaymentMethodId'];
            }
        }

        $pmIds = array_values(array_unique(array_filter($pmIds)));
        $debugPayload['counts']['paymentMethodIds'] = count($pmIds);

        // -----------------------------
        // 2) Fetch PaymentMethod details (ZOQL) — ONE ID AT A TIME
        //    (because Zuora ZOQL doesn't support IN (...))
        // -----------------------------
        $pmMap = [];
        foreach ($pmIds as $pmId) {
            $safePmId = $this->escapeZoqlString($pmId);

            // Try fuller field set first (some tenants may not allow all fields)
            $pmQuery1 =
                "select Id, Type, CreditCardType, CreditCardMaskNumber, CreditCardExpirationMonth, " .
                "CreditCardExpirationYear, CreditCardHolderName " .
                "from PaymentMethod where Id = '" . $safePmId . "'";

            $pmResp1 = $this->zuoraRequest('POST', $queryUrl, $accessToken, [
                'queryString' => $pmQuery1,
            ]);

            $pmRaw1  = (string) ($pmResp1['body'] ?? '');
            $pmJson1 = json_decode($pmRaw1, true);

            $debugPayload['paymentMethodQueries'][] = [
                'request' => [
                    'method'     => 'POST',
                    'url'        => $queryUrl,
                    'queryString'=> $pmQuery1,
                ],
                'response' => [
                    'ok'         => $pmResp1['ok'],
                    'httpStatus' => $pmResp1['httpStatus'],
                    'curlErrNo'  => $pmResp1['curlErrNo'],
                    'curlError'  => $pmResp1['curlError'],
                    'bodyPreview'=> mb_substr($pmRaw1, 0, 2500),
                ],
            ];

            $pmRecords = [];
            if ($pmResp1['ok'] && is_array($pmJson1) && empty($pmJson1['FaultCode'])) {
                $pmRecords = $pmJson1['records'] ?? [];
            }

            // Fallback: smaller field set if needed
            if (!is_array($pmRecords) || empty($pmRecords)) {
                $pmQuery2 =
                    "select Id, Type, CreditCardMaskNumber, CreditCardExpirationMonth, CreditCardExpirationYear " .
                    "from PaymentMethod where Id = '" . $safePmId . "'";

                $pmResp2 = $this->zuoraRequest('POST', $queryUrl, $accessToken, [
                    'queryString' => $pmQuery2,
                ]);

                $pmRaw2  = (string) ($pmResp2['body'] ?? '');
                $pmJson2 = json_decode($pmRaw2, true);

                $debugPayload['paymentMethodQueries'][] = [
                    'request' => [
                        'method'     => 'POST',
                        'url'        => $queryUrl,
                        'queryString'=> $pmQuery2,
                    ],
                    'response' => [
                        'ok'         => $pmResp2['ok'],
                        'httpStatus' => $pmResp2['httpStatus'],
                        'curlErrNo'  => $pmResp2['curlErrNo'],
                        'curlError'  => $pmResp2['curlError'],
                        'bodyPreview'=> mb_substr($pmRaw2, 0, 2500),
                    ],
                ];

                if ($pmResp2['ok'] && is_array($pmJson2) && empty($pmJson2['FaultCode'])) {
                    $pmRecords = $pmJson2['records'] ?? [];
                }
            }

            if (is_array($pmRecords) && !empty($pmRecords) && is_array($pmRecords[0])) {
                $pmMap[$pmId] = $pmRecords[0];
            }
        }

        $debugPayload['counts']['paymentMethodsFetched'] = count($pmMap);

        // -----------------------------
        // 3) Build output rows
        // -----------------------------
        $payments = [];
        foreach ($records as $p) {
            if (!is_array($p)) continue;

            $paymentMethodId = !empty($p['PaymentMethodId']) ? (string) $p['PaymentMethodId'] : null;
            $pm = ($paymentMethodId && isset($pmMap[$paymentMethodId])) ? $pmMap[$paymentMethodId] : null;

            $cardholder = $pm['CreditCardHolderName'] ?? null;

            $mask = $pm['CreditCardMaskNumber'] ?? null;
            $ccType = $pm['CreditCardType'] ?? null;

            $expMonth = $pm['CreditCardExpirationMonth'] ?? null;
            $expYear  = $pm['CreditCardExpirationYear'] ?? null;

            $methodText = null;
            if ($mask || $ccType) {
                $label = 'Credit Card';
                if (!empty($ccType)) $label .= ' ' . $ccType;
                if (!empty($mask))   $label .= ' ' . $mask;
                $methodText = $label;
            }

            $expirationText = null;
            if (!empty($expMonth) && !empty($expYear)) {
                $expirationText = str_pad((string) $expMonth, 2, '0', STR_PAD_LEFT) . '/' . (string) $expYear;
            }

            $dateIso = $this->toIsoDate($p['EffectiveDate'] ?? ($p['CreatedDate'] ?? null));

            $payments[] = [
                'payment'         => $p['PaymentNumber'] ?? ($p['Id'] ?? null),
                'cardholder'      => $cardholder,
                'amount'          => $p['Amount'] ?? null,
                'gateway'         => $p['GatewayResponse'] ?? null,
                'status'          => $p['Status'] ?? null,
                'dateIso'         => $dateIso,

                // what your UI shows:
                'method'          => $methodText ?: ($paymentMethodId ?: null),
                'expiration'      => $expirationText,

                // useful for debugging / later expansion:
                'zuoraPaymentId'  => $p['Id'] ?? null,
                'paymentMethodId' => $paymentMethodId,
            ];
        }

        $response = [
            'success'  => true,
            'payments' => $payments,
            'message'  => 'Zuora payments fetch for account ' . $zuoraAccountId,
        ];

        if ($debug) {
            $response['debug'] = $debugPayload;
        }

        return $response;
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
        // minimal safe escaping for single-quoted ZOQL strings
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
