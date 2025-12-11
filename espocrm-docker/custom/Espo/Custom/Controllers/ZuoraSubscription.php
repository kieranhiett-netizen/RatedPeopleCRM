<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Exceptions\Forbidden;

class ZuoraSubscription extends \Espo\Core\Controllers\Base
{
    /**
     * Ensure user can edit Accounts.
     */
    protected function checkAccess(): bool
    {
        if (!$this->getAcl()->checkScope('Account', 'edit')) {
            throw new Forbidden();
        }

        return true;
    }

    /**
     * Change subscription(s) – STUB
     * POST /api/v1/ZuoraSubscription/action/change
     */
    public function postActionChange($params, $data, $request): array
    {
        $this->checkAccess();

        // Accept one or many subscription IDs
        $subscriptionIds = $data->subscriptionIds ?? [];
        if (is_string($subscriptionIds)) {
            $subscriptionIds = [$subscriptionIds];
        }
        if (!is_array($subscriptionIds)) {
            $subscriptionIds = [];
        }

        if (empty($subscriptionIds)) {
            return [
                'success' => false,
                'message' => 'No subscriptionIds provided from client.',
            ];
        }

        $zuoraSubscriptionIds = $data->zuoraSubscriptionIds ?? [];
        if (is_string($zuoraSubscriptionIds)) {
            $zuoraSubscriptionIds = [$zuoraSubscriptionIds];
        }
        if (!is_array($zuoraSubscriptionIds)) {
            $zuoraSubscriptionIds = [];
        }

        $planId          = $data->planId ?? null;
        $effectivePolicy = $data->effectivePolicy ?? 'Immediate';
        $accountId       = $data->accountId ?? null;
        $zuoraAccountId  = $data->zuoraAccountId ?? null;

        // Optional: resolve Zuora Account ID from Account if missing
        if ($accountId && !$zuoraAccountId) {
            $zuoraAccountId = $this->resolveZuoraAccountIdFromAccount($accountId);
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Change stub: %d subscription(s), plan=%s, policy=%s',
                count($subscriptionIds),
                (string) $planId,
                $effectivePolicy
            ),
            'data' => [
                'subscriptionIds'      => $subscriptionIds,
                'zuoraSubscriptionIds' => $zuoraSubscriptionIds,
                'planId'               => $planId,
                'effectivePolicy'      => $effectivePolicy,
                'accountId'            => $accountId,
                'zuoraAccountId'       => $zuoraAccountId,
            ],
        ];
    }

    /**
     * Cancel subscription(s) – STUB
     * POST /api/v1/ZuoraSubscription/action/cancel
     */
    public function postActionCancel($params, $data, $request): array
    {
        $this->checkAccess();

        $subscriptionIds = $data->subscriptionIds ?? [];
        if (is_string($subscriptionIds)) {
            $subscriptionIds = [$subscriptionIds];
        }
        if (!is_array($subscriptionIds)) {
            $subscriptionIds = [];
        }

        if (empty($subscriptionIds)) {
            return [
                'success' => false,
                'message' => 'No subscriptionIds provided from client.',
            ];
        }

        $zuoraSubscriptionIds = $data->zuoraSubscriptionIds ?? [];
        if (is_string($zuoraSubscriptionIds)) {
            $zuoraSubscriptionIds = [$zuoraSubscriptionIds];
        }
        if (!is_array($zuoraSubscriptionIds)) {
            $zuoraSubscriptionIds = [];
        }

        $cancelPolicy   = $data->cancelPolicy ?? 'EndOfTerm';
        $accountId      = $data->accountId ?? null;
        $zuoraAccountId = $data->zuoraAccountId ?? null;

        // Optional: resolve Zuora Account ID from Account if missing
        if ($accountId && !$zuoraAccountId) {
            $zuoraAccountId = $this->resolveZuoraAccountIdFromAccount($accountId);
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Cancel stub: %d subscription(s), policy=%s',
                count($subscriptionIds),
                $cancelPolicy
            ),
            'data' => [
                'subscriptionIds'      => $subscriptionIds,
                'zuoraSubscriptionIds' => $zuoraSubscriptionIds,
                'cancelPolicy'         => $cancelPolicy,
                'accountId'            => $accountId,
                'zuoraAccountId'       => $zuoraAccountId,
            ],
        ];
    }

    /**
     * Explicitly link an Espo Account to a Zuora Account ID.
     *
     * POST /api/v1/ZuoraSubscription/action/linkAccount
     *
     * Payload:
     *  - accountId      (required) : Espo Account ID
     *  - zuoraAccountId (required) : Zuora Account ID from Zuora
     */
    public function postActionLinkAccount($params, $data, $request): array
    {
        $this->checkAccess();

        $accountId      = $data->accountId      ?? null;
        $zuoraAccountId = $data->zuoraAccountId ?? null;

        if (!$accountId) {
            return [
                'success' => false,
                'message' => 'Missing accountId.',
            ];
        }

        if (!$zuoraAccountId) {
            return [
                'success' => false,
                'message' => 'Missing zuoraAccountId.',
            ];
        }

        $entityManager = $this->getEntityManager();
        $account = $entityManager->getEntity('Account', $accountId);

        if (!$account) {
            return [
                'success' => false,
                'message' => 'Account not found for id ' . $accountId,
            ];
        }

        if ($account->get('cZuoraAccountId') === $zuoraAccountId) {
            return [
                'success' => true,
                'message' => 'Account already linked to this Zuora Account ID.',
            ];
        }

        // TODO (optional): validate Zuora Account exists before saving.

        $account->set('cZuoraAccountId', $zuoraAccountId);
        $entityManager->saveEntity($account);

        return [
            'success' => true,
            'message' => sprintf(
                'Linked Espo Account %s to Zuora Account %s.',
                $accountId,
                $zuoraAccountId
            ),
            'data' => [
                'accountId'      => $accountId,
                'zuoraAccountId' => $zuoraAccountId,
            ],
        ];
    }

    /**
     * Fetch subscription(s) from Zuora
     * POST /api/v1/ZuoraSubscription/action/list
     */
    public function postActionList($params, $data, $request): array
    {
        $this->checkAccess();

        $accountId      = $data->accountId      ?? null;
        $zuoraAccountId = $data->zuoraAccountId ?? null;

        // If neither accountId nor zuoraAccountId was provided:
        if (!$accountId && !$zuoraAccountId) {
            return [
                'success'       => true,
                'subscriptions' => [],
                'message'       => 'No identifiers provided; skipping Zuora fetch.',
            ];
        }

        $entityManager = $this->getEntityManager();
        $account = null;

        // If we have accountId, try to load Account entity,
        // but don't throw if it fails – we can still use Zuora ID only.
        if ($accountId) {
            $account = $entityManager->getEntity('Account', $accountId);
        }

        // If we have accountId but no Zuora ID — pull it from the Account entity
        if (!$zuoraAccountId && $account) {
            $zuoraAccountId = $account->get('cZuoraAccountId');
        }

        // If still no Zuora ID, we cannot fetch subscriptions.
        if (!$zuoraAccountId) {
            return [
                'success'       => true,
                'subscriptions' => [],
                'message'       => 'No Zuora Account ID resolved; nothing to fetch.',
            ];
        }

        // If we have both account and Zuora ID and the field is out of sync,
        // update the Account so the link is persisted.
        if ($account && $account->get('cZuoraAccountId') !== $zuoraAccountId) {
            $account->set('cZuoraAccountId', $zuoraAccountId);
            $entityManager->saveEntity($account);
        }

        // At this point we always have a Zuora Account ID in $zuoraAccountId
        $subscriptions = $this->fetchSubscriptionsFromZuora($zuoraAccountId);

        return [
            'success'       => true,
            'subscriptions' => $subscriptions,
            'message'       => 'Zuora fetch for account ' . $zuoraAccountId,
        ];
    }

    /**
     * Resolve Zuora Account ID from an Espo Account’s cZuoraAccountId field.
     */
    protected function resolveZuoraAccountIdFromAccount($accountId)
    {
        if (!$accountId) {
            return null;
        }

        $entityManager = $this->getEntityManager();
        $account = $entityManager->getEntity('Account', $accountId);

        if (!$account) {
            return null;
        }

        return $account->get('cZuoraAccountId') ?: null;
    }

    /**
     * Get Zuora access token using client_credentials,
     * configured in data/config.php:
     *
     * 'zuoraApiUrl'       => 'https://rest.sandbox.eu.zuora.com',
     * 'zuoraClientId'     => '...',
     * 'zuoraClientSecret' => '...'
     */
    protected function getZuoraAccessToken()
    {
        $config = $this->getConfig();

        $baseUrl      = rtrim((string) $config->get('zuoraApiUrl'), '/');
        $clientId     = $config->get('zuoraClientId');
        $clientSecret = $config->get('zuoraClientSecret');

        if (!$baseUrl || !$clientId || !$clientSecret) {
            return null;
        }

        $url = $baseUrl . '/oauth/token';

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
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            // Optionally log error
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        return $data['access_token'];
    }

    /**
     * Call Zuora REST API to get subscriptions for an account.
     *
     * @param string $zuoraAccountId  Zuora Account ID or Account Number
     * @return array                  Normalised subscriptions for the panel
     */
    protected function fetchSubscriptionsFromZuora($zuoraAccountId)
    {
        if (!$zuoraAccountId) {
            return [];
        }

        $config = $this->getConfig();
        $baseUrl = rtrim((string) $config->get('zuoraApiUrl'), '/');

        if (!$baseUrl) {
            return [];
        }

        $accessToken = $this->getZuoraAccessToken();
        if (!$accessToken) {
            return [];
        }

        // GET /v1/subscriptions/accounts/{account-key}
        $url = $baseUrl . '/v1/subscriptions/accounts/' . rawurlencode($zuoraAccountId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false || $status >= 400) {
            // Optionally log: $error / $status
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        // Handle both "subscriptions" wrapper and bare array
        $subscriptionsRaw = [];
        if (isset($data['subscriptions']) && is_array($data['subscriptions'])) {
            $subscriptionsRaw = $data['subscriptions'];
        } elseif (isset($data[0]) || empty($data)) {
            $subscriptionsRaw = $data;
        }

        $result = [];

        foreach ($subscriptionsRaw as $sub) {
            if (!is_array($sub)) {
                continue;
            }

            // Map Zuora fields to our panel’s expected shape.
            $id        = $sub['id'] ?? ($sub['subscriptionNumber'] ?? null);
            $subNumber = $sub['subscriptionNumber'] ?? $id;
            $status    = $sub['status'] ?? ($sub['state'] ?? null);

            $startDate = $sub['termStartDate']
                ?? $sub['contractEffectiveDate']
                ?? $sub['subscriptionStartDate']
                ?? null;

            $endDate   = $sub['termEndDate'] ?? null;
            $createdAt = $sub['subscriptionStartDate'] ?? $startDate;

            // You can later swap this to product / rate plan names
            $name = $subNumber ?: 'Subscription';

            $result[] = [
                'id'                    => $id,
                'zuora_subscription_id' => $subNumber,
                'name'                  => $name,
                'status'                => $status,
                'start_date'            => $startDate,
                'end_date'              => $endDate,
                'created_at'            => $createdAt,
            ];
        }

        return $result;
    }

    /**
     * Helper: get entity manager from the DI container.
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
