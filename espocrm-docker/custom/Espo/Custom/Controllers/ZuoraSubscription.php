<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Exceptions\Forbidden;

class ZuoraSubscription extends \Espo\Core\Controllers\Base
{
    /**
     * Ensure user can edit Accounts (same as before).
     */
    protected function checkAccess(): bool
    {
        if (!$this->getAcl()->checkScope('Account', 'edit')) {
            throw new Forbidden();
        }

        return true;
    }

    /**
     * Change subscription(s) – still STUB
     * Called by POST /api/v1/ZuoraSubscription/action/change
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
     * Cancel subscription(s) – still STUB
     * Called by POST /api/v1/ZuoraSubscription/action/cancel
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
     * Fetch subscription(s) from Zuora – currently STUB
     * Called by POST /api/v1/ZuoraSubscription/action/list
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
        // but don't throw if it fails – just continue with Zuora ID if present.
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
        // update the Account so the link is persisted. Failures here should
        // not prevent us from returning subscriptions.
        if ($account && $account->get('cZuoraAccountId') !== $zuoraAccountId) {
            $account->set('cZuoraAccountId', $zuoraAccountId);
            $entityManager->saveEntity($account);
        }

        // At this point we always have a Zuora Account ID in $zuoraAccountId
        $subscriptions = $this->fetchSubscriptionsFromZuora($zuoraAccountId);

        return [
            'success'       => true,
            'subscriptions' => $subscriptions,
            'message'       => 'Zuora fetch STUB for account ' . $zuoraAccountId,
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
     * Stubbed call to Zuora; aligns with what your JS panel expects.
     */
    protected function fetchSubscriptionsFromZuora($zuoraAccountId)
    {
        // TODO: Replace this stub with a real Zuora REST API call.
        // For now we return the same “Example Plan” row.

        return [
            [
                'id'                    => 'stub-sub-001',
                'zuora_subscription_id' => 'ZSUB-stub-001',
                'name'                  => 'Example Plan',
                'status'                => 'Active',
                'start_date'            => '2025-01-01',
                'end_date'              => '2026-01-01',
                'created_at'            => '2025-01-01',
            ],
        ];
    }
}
