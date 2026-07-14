<?php

namespace App\Services\Bank;

use App\Models\FintsConnection;
use Fhp\Action\GetSEPAAccounts;
use Fhp\Action\GetStatementOfAccount;
use Fhp\FinTs;
use Fhp\Model\SEPAAccount;
use Fhp\Model\TanRequestChallengeImage;
use Fhp\Options\Credentials;
use Fhp\Options\FinTsOptions;

/**
 * Thin wrapper around phpFinTS that encapsulates the multi-step, TAN-gated FinTS
 * flow used by the settings page and the daily sync:
 *
 *   listTanModes()   -> let the operator pick a TAN method
 *   beginLogin()     -> log in; may return a TAN challenge to solve
 *   submitLoginTan() -> finish a TAN-gated login
 *   sync()           -> pull SEPA accounts + statements (unattended)
 *
 * The FinTS session is carried across HTTP requests via persist()/new(); the
 * caller stores those (encrypted) strings on the FintsConnection.
 */
class FintsClient
{
    public function __construct(private readonly FintsConnection $connection)
    {
    }

    private function options(): FinTsOptions
    {
        $options = new FinTsOptions();
        $options->url = (string) $this->connection->fints_url;
        $options->bankCode = (string) $this->connection->bank_code;
        $options->productName = (string) $this->connection->product_id;
        $options->productVersion = (string) ($this->connection->product_version ?: '1.0');

        return $options;
    }

    private function credentials(): Credentials
    {
        return Credentials::create((string) $this->connection->username, (string) $this->connection->pin);
    }

    private function make(?string $persistedState = null): FinTs
    {
        return FinTs::new($this->options(), $this->credentials(), $persistedState ?: null);
    }

    /**
     * @return array<int, array{id: string, name: string, needsMedium: bool, media: array<int, string>}>
     */
    public function listTanModes(): array
    {
        $fints = $this->make();

        try {
            $modes = [];
            foreach ($fints->getTanModes() as $mode) {
                $media = [];
                if ($mode->needsTanMedium()) {
                    foreach ($fints->getTanMedia($mode) as $medium) {
                        $media[] = $medium->getName();
                    }
                }
                $modes[] = [
                    'id' => (string) $mode->getId(),
                    'name' => $mode->getName(),
                    'needsMedium' => $mode->needsTanMedium(),
                    'media' => $media,
                ];
            }

            return $modes;
        } finally {
            $this->quietClose($fints);
        }
    }

    /**
     * Start a login. Returns either an active session or a TAN challenge to
     * relay to the operator.
     *
     * @return array{status: string, state: string, action?: string, challenge?: ?string, image?: ?string, tanMediumName?: ?string}
     */
    public function beginLogin(): array
    {
        $fints = $this->make();
        $this->selectTan($fints);

        $login = $fints->login();

        if ($login->needsTan()) {
            $tan = $login->getTanRequest();

            return [
                'status' => FintsConnection::STATUS_NEEDS_TAN,
                'state' => $fints->persist(),
                'action' => serialize($login),
                'challenge' => $tan?->getChallenge(),
                'image' => $this->challengeImage($tan),
                'tanMediumName' => $tan?->getTanMediumName(),
            ];
        }

        return ['status' => FintsConnection::STATUS_ACTIVE, 'state' => $fints->persist()];
    }

    /**
     * Finish a TAN-gated login.
     *
     * @return array{status: string, state: string}
     */
    public function submitLoginTan(string $persistedState, string $persistedAction, string $tan): array
    {
        $fints = $this->make($persistedState);
        $login = unserialize($persistedAction);

        $fints->submitTan($login, trim($tan));

        return ['status' => FintsConnection::STATUS_ACTIVE, 'state' => $fints->persist()];
    }

    /**
     * Unattended statement pull for the whole date range. Throws
     * FintsNeedsTanException if the bank demands a TAN (the caller then flips the
     * connection to needs_reauth). Returns the fetched transaction objects, the
     * IBAN that was used and a refreshed session to persist.
     *
     * @return array{transactions: array<int, object>, iban: ?string, state: string}
     */
    public function sync(string $persistedState, \DateTime $from, \DateTime $to, ?string $preferredIban = null): array
    {
        $fints = $this->make($persistedState);

        try {
            $accountsAction = GetSEPAAccounts::create();
            $fints->execute($accountsAction);
            $this->assertNoTan($accountsAction);

            $account = $this->pickAccount($accountsAction->getAccounts(), $preferredIban);
            if ($account === null) {
                throw new \RuntimeException('Kein SEPA-Konto bei der Bank gefunden.');
            }

            $statementAction = GetStatementOfAccount::create($account, $from, $to);
            $fints->execute($statementAction);
            $this->assertNoTan($statementAction);

            $transactions = [];
            foreach ($statementAction->getStatement()->getStatements() as $statement) {
                foreach ($statement->getTransactions() as $tx) {
                    $transactions[] = $tx;
                }
            }

            return [
                'transactions' => $transactions,
                'iban' => $account->getIban(),
                'state' => $fints->persist(),
            ];
        } finally {
            $this->quietClose($fints);
        }
    }

    private function selectTan(FinTs $fints): void
    {
        if (filled($this->connection->tan_mode)) {
            $fints->selectTanMode($this->connection->tan_mode, $this->connection->tan_medium ?: null);
        }
    }

    /** @param array<int, SEPAAccount> $accounts */
    private function pickAccount(array $accounts, ?string $preferredIban): ?SEPAAccount
    {
        if ($preferredIban) {
            foreach ($accounts as $account) {
                if ($account->getIban() && strcasecmp($account->getIban(), $preferredIban) === 0) {
                    return $account;
                }
            }
        }

        return $accounts[0] ?? null;
    }

    private function assertNoTan(\Fhp\BaseAction $action): void
    {
        if ($action->needsTan()) {
            throw new FintsNeedsTanException('Die Bank verlangt eine erneute TAN-Freigabe.');
        }
    }

    private function challengeImage(?\Fhp\Model\TanRequest $tan): ?string
    {
        $hhduc = $tan?->getChallengeHhdUc();
        if (! $hhduc) {
            return null;
        }

        try {
            $image = new TanRequestChallengeImage($hhduc);

            return 'data:' . $image->getMimeType() . ';base64,' . base64_encode($image->getData());
        } catch (\Throwable) {
            // Flicker (chipTAN optical) or unsupported challenge - skip the image,
            // the textual challenge instructions still guide the operator.
            return null;
        }
    }

    private function quietClose(FinTs $fints): void
    {
        try {
            $fints->close();
        } catch (\Throwable) {
            // Best effort; a failed close must never mask the real result/error.
        }
    }
}
