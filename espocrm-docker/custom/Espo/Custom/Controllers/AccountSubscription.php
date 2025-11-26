<?php

namespace Espo\Custom\Controllers;

class AccountSubscription extends \Espo\Core\Controllers\Base
{
    public function getActionRead($params, $data, $request)
    {
        $accountId = $params['id'] ?? null;
        
        if (!$accountId) {
            return [
                'error' => 'No account ID provided',
                'accountId' => null
            ];
        }
        
        try {
            // Get PDO connection from Espo's entity manager
            $pdo = $this->getEntityManager()->getPDO();
            
            // Query the c_subscription table
            $sql = "SELECT * FROM c_subscription WHERE account_id = :accountId";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['accountId' => $accountId]);
            $subscriptions = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            return [
                'accountId' => $accountId,
                'subscriptionCount' => count($subscriptions),
                'subscriptions' => $subscriptions,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
        } catch (\Exception $e) {
            return [
                'error' => 'Database query failed',
                'message' => $e->getMessage(),
                'accountId' => $accountId
            ];
        }
    }
}