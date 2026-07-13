<?php

namespace App\Services\Bank;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

/**
 * Parses a Sparkasse account statement into normalized entries. Supports the
 * two formats the Sparkasse online banking exports: CAMT.053 (ISO 20022 XML)
 * and MT940 (SWIFT). Namespace-agnostic for CAMT (uses local-name()), and
 * tolerant of the German MT940 :86: subfield encoding.
 *
 * Each entry: [booked_on, valued_on, amount(signed float), currency, purpose,
 * counterparty_name, counterparty_iban, end_to_end_id, bank_ref, source_format].
 */
class BankStatementParser
{
    /**
     * Auto-detects the format from the content and parses it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $content): array
    {
        $trimmed = ltrim($content);

        if (str_starts_with($trimmed, '<?xml') || str_contains($trimmed, 'Document')) {
            return $this->parseCamt053($content);
        }

        return $this->parseMt940($content);
    }

    /** @return array<int, array<string, mixed>> */
    public function parseCamt053(string $xml): array
    {
        $entries = [];

        $dom = new DOMDocument();
        // Suppress libxml warnings for slightly-off exports.
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $ok) {
            throw new \RuntimeException('CAMT-Datei konnte nicht als XML gelesen werden.');
        }

        $xp = new DOMXPath($dom);

        foreach ($xp->query('//*[local-name()="Ntry"]') as $ntry) {
            /** @var DOMElement $ntry */
            $amtNode = $this->first($xp, './*[local-name()="Amt"]', $ntry);
            if (! $amtNode) {
                continue;
            }

            $amount = (float) str_replace(',', '.', trim($amtNode->nodeValue));
            $currency = $amtNode->getAttribute('Ccy') ?: 'EUR';
            $isCredit = strtoupper($this->text($xp, './*[local-name()="CdtDbtInd"]', $ntry)) === 'CRDT';
            $signed = $isCredit ? abs($amount) : -abs($amount);

            $booked = $this->text($xp, './*[local-name()="BookgDt"]/*[local-name()="Dt"]', $ntry)
                ?: $this->text($xp, './*[local-name()="BookgDt"]/*[local-name()="DtTm"]', $ntry);
            $valued = $this->text($xp, './*[local-name()="ValDt"]/*[local-name()="Dt"]', $ntry)
                ?: $this->text($xp, './*[local-name()="ValDt"]/*[local-name()="DtTm"]', $ntry);

            // Remittance info (Verwendungszweck) - concatenate all Ustrd lines.
            $purposeParts = [];
            foreach ($xp->query('.//*[local-name()="RmtInf"]/*[local-name()="Ustrd"]', $ntry) as $u) {
                $purposeParts[] = trim($u->nodeValue);
            }

            // Counterparty: payer (Dbtr) for a credit, payee (Cdtr) for a debit.
            $party = $isCredit ? 'Dbtr' : 'Cdtr';
            $name = $this->text($xp, ".//*[local-name()=\"RltdPties\"]/*[local-name()=\"{$party}\"]/*[local-name()=\"Nm\"]", $ntry);
            $iban = $this->text($xp, ".//*[local-name()=\"{$party}Acct\"]/*[local-name()=\"Id\"]/*[local-name()=\"IBAN\"]", $ntry);

            $entries[] = [
                'booked_on' => $this->date($booked),
                'valued_on' => $this->date($valued),
                'amount' => round($signed, 2),
                'currency' => $currency,
                'purpose' => $purposeParts ? implode(' ', $purposeParts) : null,
                'counterparty_name' => $name ?: null,
                'counterparty_iban' => $iban ?: null,
                'end_to_end_id' => $this->text($xp, './/*[local-name()="EndToEndId"]', $ntry) ?: null,
                'bank_ref' => $this->text($xp, './*[local-name()="AcctSvcrRef"]', $ntry) ?: null,
                'source_format' => 'camt',
            ];
        }

        return $entries;
    }

    /** @return array<int, array<string, mixed>> */
    public function parseMt940(string $text): array
    {
        $entries = [];

        // Group physical lines into logical fields (:NN:...). A line that does
        // not start a new tag continues the previous field (esp. :86:).
        $fields = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (preg_match('/^:(\d{2}[A-Z]?):(.*)$/', $line, $m)) {
                $fields[] = ['tag' => $m[1], 'value' => $m[2]];
            } elseif ($fields && $line !== '-') {
                $fields[count($fields) - 1]['value'] .= "\n" . $line;
            }
        }

        $current = null;
        foreach ($fields as $field) {
            if ($field['tag'] === '61') {
                if ($current) {
                    $entries[] = $current;
                }
                $current = $this->parseMt940Line($field['value']);
            } elseif ($field['tag'] === '86' && $current) {
                $this->applyMt940Info($current, $field['value']);
            }
        }

        if ($current) {
            $entries[] = $current;
        }

        return $entries;
    }

    /** @return array<string, mixed>|null */
    private function parseMt940Line(string $value): ?array
    {
        // :61: valueDate(YYMMDD) [entryDate(MMDD)] mark(C|D|RC|RD|EC|ED) amount N...
        if (! preg_match('/^(\d{6})(\d{4})?(RC|RD|EC|ED|C|D)([\d,]+)/', $value, $m)) {
            return null;
        }

        [$full, $valueDate, $entryDate, $mark, $amountRaw] = $m;

        $amount = (float) str_replace(',', '.', rtrim($amountRaw, ','));
        // R = reversal (invert), base C = credit(+), D = debit(-).
        $credit = str_ends_with($mark, 'C');
        if (str_starts_with($mark, 'R')) {
            $credit = ! $credit;
        }
        $signed = $credit ? abs($amount) : -abs($amount);

        $valued = $this->date('20' . substr($valueDate, 0, 2) . '-' . substr($valueDate, 2, 2) . '-' . substr($valueDate, 4, 2));
        $booked = $entryDate
            ? $this->date('20' . substr($valueDate, 0, 2) . '-' . substr($entryDate, 0, 2) . '-' . substr($entryDate, 2, 2))
            : $valued;

        // Bank reference after the amount: ...N<3 code><customer ref>//<bank ref>
        $bankRef = null;
        if (preg_match('#//(\S+)#', $value, $r)) {
            $bankRef = $r[1];
        }

        return [
            'booked_on' => $booked,
            'valued_on' => $valued,
            'amount' => round($signed, 2),
            'currency' => 'EUR',
            'purpose' => null,
            'counterparty_name' => null,
            'counterparty_iban' => null,
            'end_to_end_id' => null,
            'bank_ref' => $bankRef,
            'source_format' => 'mt940',
        ];
    }

    /** Parses the German :86: subfield encoding into purpose/name/iban. */
    private function applyMt940Info(array &$entry, string $value): void
    {
        $value = str_replace("\n", '', $value);

        if (! str_contains($value, '?')) {
            $entry['purpose'] = trim($value) ?: null;

            return;
        }

        $purpose = [];
        $name = [];
        if (preg_match_all('/\?(\d\d)([^?]*)/', $value, $matches, PREG_SET_ORDER)) {
            foreach ($matches as [$whole, $code, $content]) {
                $code = (int) $code;
                $content = trim($content);
                if ($code >= 20 && $code <= 29) {
                    $purpose[] = $content;
                } elseif ($code === 32 || $code === 33) {
                    $name[] = $content;
                } elseif ($code === 31 && preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $content)) {
                    $entry['counterparty_iban'] = $content;
                }
            }
        }

        $entry['purpose'] = $purpose ? trim(implode('', $purpose)) : $entry['purpose'];
        $entry['counterparty_name'] = $name ? trim(implode('', $name)) : $entry['counterparty_name'];

        // End-to-end id is often embedded as "EREF+..." in the purpose text.
        if ($entry['purpose'] && preg_match('/EREF\+(\S+)/', $entry['purpose'], $e)) {
            $entry['end_to_end_id'] = $e[1];
        }
    }

    private function first(DOMXPath $xp, string $q, DOMElement $ctx): ?DOMElement
    {
        $n = $xp->query($q, $ctx)->item(0);

        return $n instanceof DOMElement ? $n : null;
    }

    private function text(DOMXPath $xp, string $q, DOMElement $ctx): string
    {
        $n = $xp->query($q, $ctx)->item(0);

        return $n ? trim($n->nodeValue) : '';
    }

    private function date(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
