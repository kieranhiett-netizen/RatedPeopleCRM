<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Exceptions\Forbidden;

class ZuoraSubscription extends \Espo\Core\Controllers\Base
{
    protected function checkAccess()
    {
        if (!$this->getAcl()->checkScope('Account', 'edit')) {
            throw new Forbidden();
        }
    }

    public function postActionChange($params, $data, $request)
    {
        $this->checkAccess();

        /** @var \Espo\Custom\Services\ZuoraSubscription $service */
        $service = $this->getContainer()
            ->get('serviceFactory')
            ->create('ZuoraSubscription');

        return $service->changeSubscription($data);
    }

    public function postActionCancel($params, $data, $request)
    {
        $this->checkAccess();

        /** @var \Espo\Custom\Services\ZuoraSubscription $service */
        $service = $this->getContainer()
            ->get('serviceFactory')
            ->create('ZuoraSubscription');

        return $service->cancelSubscription($data);
    }
}
