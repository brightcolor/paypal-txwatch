<?php

namespace App\Services\Bank;

use App\Models\BankTransaction;

/**
 * Imports a parsed bank statement into bank_transactions (idempotent via an
 * import hash so overlapping statements don't create duplicates) and runs the
 * auto-reconciliation afterwards.
 */
class BankStatementImporter
{
    public function __construct(
        private readonly BankStatementParser $parser,
        private readonly BankReconciler $reconciler,
    ) {
    }

    /**
     * @return array{parsed: int, imported: int, skipped: int, matched: int}
     */
    public function import(string $content): array
    {
        $entries = $this->parser->parse($content);

        $imported = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            $hash = $this->hash($entry);

            $created = BankTransaction::firstOrCreate(
                ['import_hash' => $hash],
                array_merge($entry, [
                    'reconciliation_status' => BankTransaction::STATUS_UNMATCHED,
                    'raw' => $entry,
                ]),
            );

            $created->wasRecentlyCreated ? $imported++ : $skipped++;
        }

        $matched = $this->reconciler->reconcile();

        return [
            'parsed' => count($entries),
            'imported' => $imported,
            'skipped' => $skipped,
            'matched' => $matched,
        ];
    }

    /** @param array<string, mixed> $entry */
    private function hash(array $entry): string
    {
        // end_to_end_id + bank_ref are usually unique per SEPA line; fall back to
        // the value date + amount + purpose + counterparty composite.
        return hash('sha256', implode('|', [
            $entry['end_to_end_id'] ?? '',
            $entry['bank_ref'] ?? '',
            $entry['valued_on'] ?? '',
            $entry['amount'] ?? '',
            $entry['purpose'] ?? '',
            $entry['counterparty_iban'] ?? '',
        ]));
    }
}
