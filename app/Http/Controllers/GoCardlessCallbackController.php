<?php

namespace App\Http\Controllers;

use App\Models\BankConnection;
use App\Services\Bank\GoCardlessClient;
use App\Services\Bank\GoCardlessSync;
use Illuminate\Http\Request;
use Throwable;

/**
 * Where the bank redirects the operator back to after they authorise read
 * access. Finalizes the requisition: stores the linked account ids, marks the
 * connection connected, sets the 90-day consent window, and pulls the first
 * batch of transactions. Then bounces to the bank-connection settings page.
 */
class GoCardlessCallbackController extends Controller
{
    public function __invoke(Request $request)
    {
        $settingsUrl = '/admin/bank-connection';
        $connection = BankConnection::current();

        // The bank can signal a declined/aborted consent.
        if ($request->filled('error')) {
            $connection->update(['status' => BankConnection::STATUS_ERROR, 'last_error' => (string) $request->query('error')]);

            return redirect($settingsUrl)->with('gocardless', 'Freigabe abgebrochen: ' . $request->query('error'));
        }

        if (blank($connection->requisition_id)) {
            return redirect($settingsUrl)->with('gocardless', 'Keine laufende Verbindung gefunden.');
        }

        try {
            $client = new GoCardlessClient($connection);
            $requisition = $client->getRequisition($connection->requisition_id);

            if (empty($requisition['accounts'])) {
                $connection->update(['status' => BankConnection::STATUS_LINKING]);

                return redirect($settingsUrl)->with('gocardless', 'Freigabe noch nicht abgeschlossen – bitte erneut versuchen.');
            }

            $connection->update([
                'account_ids' => $requisition['accounts'],
                'status' => BankConnection::STATUS_CONNECTED,
                'consent_expires_at' => now()->addDays(90),
                'last_error' => null,
            ]);

            $result = app(GoCardlessSync::class)->syncSafely($connection);

            return redirect($settingsUrl)->with(
                'gocardless',
                'Bankkonto verbunden. Erster Abruf: ' . ($result['imported'] ?? 0) . ' neu, ' . ($result['matched'] ?? 0) . ' zugeordnet.',
            );
        } catch (Throwable $e) {
            $connection->update(['status' => BankConnection::STATUS_ERROR, 'last_error' => $e->getMessage()]);

            return redirect($settingsUrl)->with('gocardless', 'Fehler: ' . $e->getMessage());
        }
    }
}
