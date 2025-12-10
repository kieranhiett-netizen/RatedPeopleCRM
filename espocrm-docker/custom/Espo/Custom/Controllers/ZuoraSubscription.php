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
     * Stub for "change subscription" – currently just echoes input back.
     * Called by POST /api/v1/ZuoraSubscription/action/change
     */
    public function postActionChange($params, $data, $request): array
    {
        $this->checkAccess();

        $subscriptionId       = $data->subscriptionId ?? null;
        $zuoraSubscriptionId  = $data->zuoraSubscriptionId ?? null;
        $planId               = $data->planId ?? null;
        $effectivePolicy      = $data->effectivePolicy ?? 'Immediate';

        return [
            'success' => true,
            'message' => sprintf(
                'Change stub: subscription=%s, zuora=%s, plan=%s, policy=%s',
                $subscriptionId,
                $zuoraSubscriptionId,
                $planId,
                $effectivePolicy
            ),
            'input' => $data,
        ];
    }

    /**
     * Stub for "cancel subscription" – currently just echoes input back.
     * Called by POST /api/v1/ZuoraSubscription/action/cancel
     */
    public function postActionCancel($params, $data, $request): array
    {
        $this->checkAccess();

        $subscriptionId       = $data->subscriptionId ?? null;
        $zuoraSubscriptionId  = $data->zuoraSubscriptionId ?? null;
        $cancelPolicy         = $data->cancelPolicy ?? 'EndOfTerm';

        return [
            'success' => true,
            'message' => sprintf(
                'Cancel stub: subscription=%s, zuora=%s, policy=%s',
                $subscriptionId,
                $zuoraSubscriptionId,
                $cancelPolicy
            ),
            'input' => $data,
        ];
    }
}
