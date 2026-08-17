<?php

namespace App\Http\Controllers;

use App\Models\EnableBankingConnection;
use App\Services\EnableBanking\Client;
use App\Services\EnableBanking\EnableBankingException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where the bank sends the browser back after the customer gave (or refused)
 * consent.
 *
 * THIS URL IS REGISTERED IN THE CONTROL PANEL. Change the path and the list
 * there has to follow, or Enable Banking rejects the authorisation before the
 * customer even reaches their bank. That is the single most common setup mistake
 * with this API, which is why the setup page prints the exact address.
 *
 * BEHIND `auth`, and that works because the session cookie is SameSite=lax: the
 * bank's redirect is an ordinary top-level GET, so the browser sends it along.
 * Under `strict` the admin would come back logged out and the callback would run
 * into a login form with the one-shot code in the URL - which then expires.
 * Whoever tightens the cookie policy has to look here.
 *
 * ANSWERS WITH A REDIRECT, not JSON: a browser lands here, not a program.
 */
class EnableBankingCallbackController extends Controller
{
    public function __construct(private readonly Client $client)
    {
    }

    public function __invoke(Request $request)
    {
        $connection = EnableBankingConnection::current();

        $error = trim((string) $request->query('error', ''));

        if ($error !== '') {
            /*
             * Cancelling at the bank is the normal case, not an incident -
             * whoever changes their mind clicks "Abbrechen" there. The pending
             * state is consumed anyway: one left lying around could later be
             * redeemed together with someone else's code.
             */
            $connection->consumeState($request->query('state'));

            return $this->back($this->describe(
                $error,
                trim((string) $request->query('error_description', ''))
            ));
        }

        if (! $connection->consumeState($request->query('state'))) {
            return $this->back(
                'Der Rückläufer der Bank passt nicht zu einem laufenden Vorgang. '
                . 'Das passiert, wenn der Vorgang zu lange offen war oder in einem anderen Fenster '
                . 'gestartet wurde – bitte neu beginnen.'
            );
        }

        $code = trim((string) $request->query('code', ''));

        if ($code === '') {
            return $this->back('Die Bank hat keinen Bestätigungscode mitgeschickt.');
        }

        try {
            $session = $this->client->openSession($code);
        } catch (EnableBankingException $e) {
            return $this->back($e->getMessage());
        }

        $sessionId = (string) ($session['session_id'] ?? '');

        if ($sessionId === '') {
            return $this->back('Der Dienst hat keine Verbindungskennung zurückgegeben.');
        }

        $accounts = $this->accounts($session);

        if ($accounts === []) {
            /*
             * A consent without a single account is useless, and storing it would
             * leave a connection that says "active" and pulls nothing. Better to
             * refuse it here than to debug an empty daily import later.
             */
            return $this->back(
                'Die Bank hat kein Konto zu dieser Freigabe gemeldet. Möglicherweise wurde bei der Bank '
                . 'kein Konto ausgewählt – bitte den Vorgang wiederholen.'
            );
        }

        $connection->forceFill([
            'session_id' => $sessionId,
            'accounts' => $accounts,
            // Only preselect when the consent covers exactly one account;
            // guessing among several would silently pull the wrong one.
            'iban' => count($accounts) === 1 ? ($accounts[0]['iban'] ?? null) : $connection->iban,
            'access_valid_until' => $this->validUntil($session),
            'status' => EnableBankingConnection::STATUS_ACTIVE,
            'last_error' => null,
        ])->save();

        Log::info('Enable Banking: Bank verbunden', [
            'aspsp' => $connection->aspsp_name,
            'accounts' => count($accounts),
            'valid_until' => optional($connection->access_valid_until)->toDateString(),
        ]);

        return redirect('/admin/bank-verbinden')->with('status', 'verbunden');
    }

    /**
     * The accounts of the session, reduced to what is actually used.
     *
     * @param  array<string, mixed>  $session
     * @return array<int, array<string, mixed>>
     */
    private function accounts(array $session): array
    {
        $raw = $session['accounts'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $account) {
            if (! is_array($account)) {
                continue;
            }

            $uid = $account['uid'] ?? null;

            // Without a uid the account cannot be read from, so it is not one.
            if (! is_string($uid) || $uid === '') {
                continue;
            }

            $out[] = [
                'uid' => $uid,
                'iban' => $account['account_id']['iban'] ?? ($account['iban'] ?? null),
                'currency' => $account['currency'] ?? null,
                'name' => $account['name'] ?? ($account['product'] ?? null),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $session */
    private function validUntil(array $session): ?string
    {
        $raw = $session['access']['valid_until'] ?? ($session['valid_until'] ?? null);

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private function back(string $message)
    {
        return redirect('/admin/bank-verbinden')->with('bank_error', $message);
    }

    private function describe(string $code, string $text): string
    {
        if (strtoupper($code) === 'ACCESS_DENIED') {
            return 'Die Zustimmung wurde bei der Bank abgebrochen. Es wurde nichts verbunden.';
        }

        return $text !== '' ? $text : sprintf('Die Bank hat den Vorgang abgelehnt (%s).', $code);
    }
}
