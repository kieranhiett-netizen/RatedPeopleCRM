<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\ORM\EntityManager;

class AccountCaseHistory extends \Espo\Core\Controllers\Base
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
                'accountId' => null,
            ];
        }
        
        try {
            // Load the Account to get c_user_id
            $account = $this->entityManager->getEntity('Account', $accountId);
            
            if (!$account) {
                return [
                    'error' => 'Account not found',
                    'accountId' => $accountId,
                ];
            }
            
            $cUserId = $account->get('cUserId'); // field name in Espo is cUserId for c_user_id
            
            if (!$cUserId) {
                return [
                    'accountId' => $accountId,
                    'caseCount' => 0,
                    'cases' => [],
                    'message' => 'No c_user_id set for this account',
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
            }
            
            $pdo = $this->entityManager->getPDO();
            
            // Join Case_History on account.c_user_id = Case_History.`User ID`
            $sql = "
                SELECT
                    ch.CaseNumber,
                    ch.SuppliedName,
                    ch.SuppliedEmail,
                    ch.SuppliedPhone,
                    ch.SuppliedCompany,
                    ch.Status,
                    ch.Category__c,
                    ch.SubCategory__c,
                    ch.Outcome__c,
                    ch.Sub_Category_2__c,
                    ch.Plan_on_Creation__c
                FROM Case_History ch
                INNER JOIN account a
                    ON ch.`User ID` = a.c_user_id
                WHERE a.id = :accountId
                ORDER BY ch.CaseNumber DESC
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['accountId' => $accountId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Normalise keys for the frontend
            $cases = array_map(function (array $row) {
                return [
                    'caseNumber'      => $row['CaseNumber'] ?? null,
                    'suppliedName'    => $row['SuppliedName'] ?? null,
                    'suppliedEmail'   => $row['SuppliedEmail'] ?? null,
                    'suppliedPhone'   => $row['SuppliedPhone'] ?? null,
                    'suppliedCompany' => $row['SuppliedCompany'] ?? null,
                    'status'          => $row['Status'] ?? null,
                    'category'        => $row['Category__c'] ?? null,
                    'subCategory'     => $row['SubCategory__c'] ?? null,
                    'outcome'         => $row['Outcome__c'] ?? null,
                    'subCategory2'    => $row['Sub_Category_2__c'] ?? null,
                    'plan'            => $row['Plan_on_Creation__c'] ?? null,
                ];
            }, $rows);
            
            return [
                'accountId' => $accountId,
                'cUserId'   => $cUserId,
                'caseCount' => count($cases),
                'cases'     => $cases,
                'timestamp' => date('Y-m-d H:i:s'),
            ];
            
        } catch (\Exception $e) {
            return [
                'error'     => 'Database query failed',
                'message'   => $e->getMessage(),
                'accountId' => $accountId,
            ];
        }
    }
}
