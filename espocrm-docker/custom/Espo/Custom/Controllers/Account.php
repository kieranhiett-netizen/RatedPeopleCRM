<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;

class Account extends \Espo\Core\Controllers\Record
{
    /**
     * GET /Account/action/getSubscriptions?id={accountId}
     */
    public function actionGetSubscriptions(Request $request, Response $response)
    {
        $accountId = $request->get('id');

        if (!$accountId) {
            return $response->setBody([
                'success' => false,
                'message' => 'Missing account ID',
            ]);
        }

        // Get PDO (Espo's DB connection)
        $pdo = $this->getInjection('entityManager')->getPdo();

        // Adjust column names to match your actual table
        $sql = "
            SELECT
                plan_code,
                start_date,
                end_date,
                status
            FROM c_subscription
            WHERE account_id = :accountId
            ORDER BY start_date DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['accountId' => $accountId]);

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $response->setBody([
            'success' => true,
            'data' => $rows,
        ]);
    }
}
