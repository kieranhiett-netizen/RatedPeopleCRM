<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Exceptions\Forbidden;

class ZuoraSubscription extends \Espo\Core\Controllers\Base
{
    protected function checkAccess(): bool
    {
        if (!$this->getAcl()->checkScope('Account', 'edit')) {
            throw new Forbidden();
        }

        return true;
    }

    /**
     * Change subscription(s) – STUB ONLY
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
     * Cancel subscription(s) – STUB ONLY
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
     * Fetch subscription(s) from Zuora – STUB ONLY
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

        // If we have accountId but no Zuora ID — pull it from the Account entity
        if (!$zuoraAccountId && $accountId) {

            $entityManager = $this->getEntityManager();
            $account = $entityManager->getEntity('Account', $accountId);

            if (!$account) {
                return [
                    'success' => false,
                    'message' => 'Account not found for id ' . $accountId,
                ];
            }

            // Espo exposes DB column "c_zuoraaccount_id" as "cZuoraAccountId"
            $zuoraAccountId = $account->get('cZuoraAccountId');

            if (!$zuoraAccountId) {
                return [
                    'success'       => true,
                    'subscriptions' => [],
                    'message'       => 'Account has no cZuoraAccountId; nothing to fetch.',
                ];
            }
        }

        // At this point we always have a Zuora Account ID in $zuoraAccountId
        // Stub fake subscriptions for now
        $fakeSubscriptions = [
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

        return [
            'success'       => true,
            'subscriptions' => $fakeSubscriptions,
            'message'       => 'Zuora fetch STUB for account ' . $zuoraAccountId,
        ];
    }
}
