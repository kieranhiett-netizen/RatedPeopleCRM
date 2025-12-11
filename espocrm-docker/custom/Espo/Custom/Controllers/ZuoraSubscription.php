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
     * Change subscription(s) – STUB (no real Zuora call yet)
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
     * Cancel subscription(s) – REAL Zuora call
     * POST /api/v1/ZuoraSubscription/action/cancel
     *
     * Expected payload from JS:
     *  - subscriptionIds:       [ ... ]                  // Espo internal ids (for reference)
     *  - zuoraSubscriptionIds:  [ "A-S00....", ... ]     // Zuora subscription numbers
     *  - cancelPolicy:          "Immediate" | "NextPayment" | "EndOfTerm"
     */
    public function postActionCancel($params, $data, $request): array
    {
        $this->checkAccess();

         // Account to attach the stream note to (if provided)
    $accountId = $data->accountId ?? null;

        // 1) Normalise arrays coming from JS
        $subscriptionIds = $data->subscriptionIds ?? [];
        if (is_string($subscriptionIds)) {
            $subscriptionIds = [$subscriptionIds];
        }
        if (!is_array($subscriptionIds)) {
            $subscriptionIds = [];
        }

        $zuoraSubscriptionIds = $data->zuoraSubscriptionIds ?? [];
        if (is_string($zuoraSubscriptionIds)) {
            $zuoraSubscriptionIds = [$zuoraSubscriptionIds];
        }
        if (!is_array($zuoraSubscriptionIds)) {
            $zuoraSubscriptionIds = [];
        }

        if (empty($zuoraSubscriptionIds)) {
            return [
                'success' => false,
                'message' => 'No Zuora subscription IDs provided; cannot cancel in Zuora.',
            ];
        }

        // 2) Map your UI options to Zuora cancellation policies
        $uiPolicy = $data->cancelPolicy ?? 'EndOfTerm';

        $zuoraPolicy   = 'EndOfCurrentTerm'; // default = at renewal
        $effectiveDate = null;

        switch ($uiPolicy) {
            case 'Immediate':
                // Cancel from "today"
                $zuoraPolicy   = 'SpecificDate';
                $effectiveDate = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->format('Y-m-d'); // Zuora expects yyyy-mm-dd
                break;

            case 'NextPayment':
                // End of current billing period / next payment date
                $zuoraPolicy = 'EndOfLastInvoicePeriod';
                break;

            case 'EndOfTerm':
            default:
                // End of current term
                $zuoraPolicy = 'EndOfCurrentTerm';
                break;
        }

        // 3) Zuora config & token
        $config      = $this->getConfig();
        $zuoraApiUrl = rtrim((string) $config->get('zuoraApiUrl'), '/');

        if (!$zuoraApiUrl) {
            return [
                'success' => false,
                'message' => 'Zuora API URL (zuoraApiUrl) is not configured.',
            ];
        }

        $accessToken = $this->getZuoraAccessToken();
        if (!$accessToken) {
            return [
                'success' => false,
                'message' => 'Failed to obtain Zuora access token (check client id/secret).',
            ];
        }

        // 4) Call Zuora cancel endpoint for each subscription
        //    Endpoint: PUT /v1/subscriptions/{subscription-key}/cancel
        $results        = [];
        $overallSuccess = true;

        foreach ($zuoraSubscriptionIds as $subKey) {
            $body = [
                'cancellationPolicy' => $zuoraPolicy,
            ];

            if ($effectiveDate !== null) {
                $body['cancellationEffectiveDate'] = $effectiveDate;
            }

            $ch = curl_init($zuoraApiUrl . '/v1/subscriptions/' . rawurlencode($subKey) . '/cancel');

            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => 'PUT',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Zuora-Version: 211.0',
                ],
                CURLOPT_POSTFIELDS     => json_encode($body),
            ]);

            $raw    = curl_exec($ch);
            $err    = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $decoded = json_decode($raw, true) ?: [];

            $successForThis = $status >= 200 && $status < 300 && !empty($decoded['success']);
            if (!$successForThis) {
                $overallSuccess = false;
            }

            $results[] = [
                'subscriptionKey' => $subKey,
                'httpStatus'      => $status,
                'success'         => $successForThis,
                'response'        => $decoded ?: $raw,
                'error'           => $err ?: null,
            ];
        }

               // 5) Friendly message for Espo toast (no PHP 8 match; simple if/else)
        $humanPolicy = 'at renewal (end of term)';
        if ($uiPolicy === 'Immediate') {
            $humanPolicy = 'immediately';
        } elseif ($uiPolicy === 'NextPayment') {
            $humanPolicy = 'at next payment date';
        }

        if ($overallSuccess) {
            $message = sprintf(
                'Cancelled %d subscription(s) in Zuora (%s).',
                count($zuoraSubscriptionIds),
                $humanPolicy
            );
        } else {
            $message = sprintf(
                'One or more Zuora cancellations failed; %d attempted.',
                count($zuoraSubscriptionIds)
            );
        }

        // --- NEW: log to Account stream if we know the account ---
        if ($overallSuccess && $accountId) {
            $this->logAccountStreamCancellation(
                $accountId,
                $zuoraSubscriptionIds,
                $uiPolicy,
                $humanPolicy
            );
        }

        return [
            'success' => $overallSuccess,
            'message' => $message,
            'data'    => [
                'subscriptionIds'      => $subscriptionIds,
                'zuoraSubscriptionIds' => $zuoraSubscriptionIds,
                'cancelPolicyUi'       => $uiPolicy,
                'zuoraPolicy'          => $zuoraPolicy,
                'effectiveDate'        => $effectiveDate,
                'results'              => $results,
            ],
        ];
    }

    /**
     * Explicitly link an Espo Account to a Zuora Account ID.
     *
     * POST /api/v1/ZuoraSubscription/action/linkAccount
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
        $account       = $entityManager->getEntity('Account', $accountId);

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

        if (!$accountId && !$zuoraAccountId) {
            return [
                'success'       => true,
                'subscriptions' => [],
                'message'       => 'No identifiers provided; skipping Zuora fetch.',
            ];
        }

        $entityManager = $this->getEntityManager();
        $account       = null;

        if ($accountId) {
            $account = $entityManager->getEntity('Account', $accountId);
        }

        if (!$zuoraAccountId && $account) {
            $zuoraAccountId = $account->get('cZuoraAccountId');
        }

        if (!$zuoraAccountId) {
            return [
                'success'       => true,
                'subscriptions' => [],
                'message'       => 'No Zuora Account ID resolved; nothing to fetch.',
            ];
        }

        if ($account && $account->get('cZuoraAccountId') !== $zuoraAccountId) {
            $account->set('cZuoraAccountId', $zuoraAccountId);
            $entityManager->saveEntity($account);
        }

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
        $account       = $entityManager->getEntity('Account', $accountId);

        if (!$account) {
            return null;
        }

        return $account->get('cZuoraAccountId') ?: null;
    }

    /**
     * Log a Zuora cancellation into the Account Stream.
     *
     * Appears on the Account "Stream" panel as a normal post.
     *
     * @param string $accountId
     * @param array  $zuoraSubscriptionIds
     * @param string $uiPolicy        "Immediate" | "NextPayment" | "EndOfTerm"
     * @param string $humanPolicyText e.g. "immediately"
     */
    protected function logAccountStreamCancellation(
        string $accountId,
        array $zuoraSubscriptionIds,
        string $uiPolicy,
        string $humanPolicyText
    ): void {
        $entityManager = $this->getEntityManager();

        // Create new Note entity (Stream entry)
        $note = $entityManager->getEntity('Note'); // new empty entity

        $note->set('parentType', 'Account');
        $note->set('parentId', $accountId);
        $note->set('type', 'Post');

        $subList = implode(', ', $zuoraSubscriptionIds);

        $postText = sprintf(
            'Cancelled Zuora subscription(s) %s %s.',
            $subList ?: '(unknown)',
            $humanPolicyText
        );

        // Text shown in Stream
        $note->set('post', $postText);

        // Optional: internal-only
        // $note->set('isInternal', true);

        // Attribute to current user if available
        $user = $this->getUser();
        if ($user) {
            $note->set('createdById', $user->id);
        }

        $entityManager->saveEntity($note);
    }
    /**
     * Get Zuora access token using client_credentials,
     * configured in data/config.php.
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
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
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

        $config  = $this->getConfig();
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
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false || $status >= 400) {
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

            // Core IDs
            $id        = $sub['id'] ?? ($sub['subscriptionNumber'] ?? null);
            $subNumber = $sub['subscriptionNumber'] ?? $id;
            $status    = $sub['status'] ?? ($sub['state'] ?? null);

            // Original subscription start
            $originalStart = $sub['subscriptionStartDate']
                ?? $sub['termStartDate']
                ?? $sub['contractEffectiveDate']
                ?? null;

            $termEnd = $sub['termEndDate'] ?? null;

            $created = $sub['createdDate'] ?? $originalStart;

            // Format dates as DD-MM-YYYY for Espo
            $startDate = $this->formatDateForEspo($originalStart);
            $endDate   = $this->formatDateForEspo($termEnd);
            $createdAt = $this->formatDateForEspo($created);

            // RATE PLAN / PRODUCT NAMES
            $planNames = [];

            if (!empty($sub['ratePlans']) && is_array($sub['ratePlans'])) {
                foreach ($sub['ratePlans'] as $rp) {
                    if (!is_array($rp)) {
                        continue;
                    }

                    $pieces = [];

                    if (!empty($rp['productName'])) {
                        $pieces[] = $rp['productName'];
                    }

                    if (!empty($rp['ratePlanName'])) {
                        $pieces[] = $rp['ratePlanName'];
                    }

                    if (!empty($pieces)) {
                        $planNames[] = implode(' – ', $pieces);
                    }
                }
            }

            $name = !empty($planNames)
                ? implode('<br>', $planNames)
                : ($subNumber ?: 'Subscription');

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

    /**
     * Format a Zuora date (YYYY-MM-DD or YYYY-MM-DDThh:mm:ss) as DD-MM-YYYY
     * for display in Espo.
     */
    protected function formatDateForEspo($date)
    {
        if (!$date || !is_string($date)) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1]; // DD-MM-YYYY
        }

        return $date;
    }
}
