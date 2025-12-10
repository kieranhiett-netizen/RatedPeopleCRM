<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\ORM\EntityManager;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\Error;

class AccountSubscription extends \Espo\Core\Controllers\Base
{
    private EntityManager $entityManager;
    
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }
    
    public function getActionRead(Request $request, Response $response): array
    {
        $accountId = $request->getRouteParam('id');
        
        if (!$accountId) {
            return [
                'error'     => 'No account ID provided',
                'accountId' => null,
            ];
        }

        try {
            $account = $this->entityManager->getEntity('Account', $accountId);

            if (!$account) {
                throw new NotFound("Account not found.");
            }

            $zuoraAccountId = $account->get('cZuoraAccountId');

            // If this account isn't linked to Zuora yet, return an empty list.
            if (!$zuoraAccountId) {
                return [
                    'accountId'         => $accountId,
                    'zuoraAccountId'    => null,
                    'subscriptionCount' => 0,
                    'subscriptions'     => [],
                    'timestamp'         => date('Y-m-d H:i:s'),
                ];
            }

            // Fetch from Zuora
            $subscriptions = $this->fetchZuoraSubscriptions($zuoraAccountId);

            return [
                'accountId'         => $accountId,
                'zuoraAccountId'    => $zuoraAccountId,
                'subscriptionCount' => count($subscriptions),
                'subscriptions'     => $subscriptions,
                'timestamp'         => date('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            return [
                'error'     => 'Zuora subscription fetch failed',
                'message'   => $e->getMessage(),
                'accountId' => $accountId,
            ];
        }
    }

    /**
     * Fetch subscriptions from Zuora for a given account.
     *
     * This assumes an endpoint like:
     *   GET {zuoraApiUrl}/v1/subscriptions/accounts/{zuoraAccountId}
     *
     * Adjust the path/fields if your Zuora tenant differs.
     */
    private function fetchZuoraSubscriptions(string $zuoraAccountId): array
    {
        $token   = $this->getZuoraAccessToken();
        $config  = $this->getConfig();
        $baseUrl = rtrim($config->get('zuoraApiUrl'), '/');

        $url = $baseUrl . '/v1/subscriptions/accounts/' . urlencode($zuoraAccountId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ]);

        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new Error('Zuora request failed: ' . $err);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new Error('Zuora response is not valid JSON.');
        }

        if ($status >= 400) {
            // You can dump $data to logs if needed
            throw new Error('Zuora returned HTTP ' . $status);
        }

        // Adjust this depending on exact Zuora payload: some tenants use "subscriptions"
        $list = $data['subscriptions'] ?? $data;

        $subscriptions = [];

        foreach ($list as $row) {
            // Map Zuora fields to the ones used in your panel
            $subscriptions[] = [
                // These IDs are used by the Change/Cancel actions
                'id'                    => $row['id'] ?? null,
                'zuora_subscription_id' => $row['id'] ?? null,

                // Display fields
                'name'       => $row['name']          ?? '',
                'start_date' => $row['termStartDate'] ?? null,
                'end_date'   => $row['termEndDate']   ?? null,
                'status'     => $row['status']        ?? '',
                'created_at' => $row['createdDate']   ?? null,
            ];
        }

        return $subscriptions;
    }

    /**
     * Get OAuth access token from Zuora using client credentials.
     *
     * Uses:
     *   zuoraApiUrl        (e.g. https://rest.sandbox.eu.zuora.com)
     *   zuoraClientId
     *   zuoraClientSecret
     */
    private function getZuoraAccessToken(): string
    {
        $config       = $this->getConfig();
        $baseUrl      = rtrim($config->get('zuoraApiUrl'), '/');
        $clientId     = $config->get('zuoraClientId');
        $clientSecret = $config->get('zuoraClientSecret');

        if (!$clientId || !$clientSecret) {
            throw new Error('Zuora clientId/clientSecret not configured.');
        }

        $url = $baseUrl . '/oauth/token';

        $payload = json_encode([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'grant_type'    => 'client_credentials',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
        ]);

        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new Error('Zuora OAuth request failed: ' . $err);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new Error('Zuora OAuth response not valid JSON.');
        }

        if ($status >= 400 || !isset($data['access_token'])) {
            throw new Error('Zuora OAuth error (HTTP ' . $status . ').');
        }

        return $data['access_token'];
    }
}
