<?php

namespace Espo\Custom\Controllers;

class AccountSubscription extends \Espo\Core\Controllers\Base
{
    public function getActionRead($params, $data, $request)
    {
        return [
            'message' => 'hello from AccountSubscription controller',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}