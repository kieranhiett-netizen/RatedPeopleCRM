<?php

namespace Espo\Custom\Controllers;

class AccountSubscription extends \Espo\Core\Controllers\Base
{
    protected $authRequired = false; // TEMPORARY - for testing only
    
    public function getActionRead($params, $data, $request)
    {
        return [
            'message' => 'hello from AccountSubscription controller',
            'timestamp' => date('Y-m-d H:i:s'),
            'params' => $params
        ];
    }
}