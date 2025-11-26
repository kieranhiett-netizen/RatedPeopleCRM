<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\ORM\EntityManager;

class AccountSubscription extends \Espo\Core\Controllers\Base
{
    private EntityManager $entityManager;
    
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }
    
    public function getActionRead(Request $request, Response $response): array
    {
        $accountId = $request->getRouteParam('id');
        
        if (!$accountId) {
            return [
                'error' => 'No account ID provided',
                'accountId' => null
            ];
        }
        
        try {
            $pdo = $this->entityManager->getPDO();
            
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