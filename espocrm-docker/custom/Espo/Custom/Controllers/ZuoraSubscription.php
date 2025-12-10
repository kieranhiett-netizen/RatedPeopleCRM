<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Exceptions\Forbidden;

class ZuoraSubscription extends \Espo\Core\Controllers\Base
{
    protected function checkAccess(): void
    {
        if (!$this->getAcl()->checkScope('Account', 'edit')) {
            throw new Forbidden();
        }
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
                'message' => 'No subscriptionIds provided from client.'
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
                'message' => 'No subscriptionIds provided from client.'
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
}
