<?php

namespace Tests\Feature\Bank;

use App\Models\FintsBank;
use App\Services\Bank\FintsBanks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FintsBanksImportTest extends TestCase
{
    use RefreshDatabase;

    private function writeCsv(): string
    {
        $utf8 = implode("\n", [
            'Nr.;BLZ;BIC;Institut;Ort;PIN/TAN-Zugang URL;Version',
            '1;14051000;NOLADE21WIS;Sparkasse Mecklenburg-Nordwest;Wismar;https://banking-mv6.s-fints-pt-mv.de/fints30;FinTS V3.0',
            '2;10010010;PBNKDEFFXXX;Postbank;Berlin;https://hbci.postbank.de/banking/hbci.do;FinTS V3.0',
            '3;99999999;XXXXDEFFXXX;Bank ohne PIN/TAN;Nirgendwo;;',
            '4;12345678;TESTDEFFXXX;Müller-Bank;Köln;https://test.example/fints;FinTS V3.0',
            '',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'fintscsv');
        // The official list is Windows-1252 encoded; write it that way so the
        // command's decode step is actually exercised.
        file_put_contents($path, mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8'));

        return $path;
    }

    public function test_import_keeps_only_pintan_banks_and_decodes_umlauts(): void
    {
        $path = $this->writeCsv();

        $this->artisan('fints:import-banks', ['file' => $path])->assertSuccessful();

        // Row 3 (no PIN/TAN URL) is skipped.
        $this->assertSame(3, FintsBank::query()->count());

        $spk = FintsBanks::find('14051000');
        $this->assertNotNull($spk);
        $this->assertSame('Sparkasse Mecklenburg-Nordwest', $spk->name);
        $this->assertSame('https://banking-mv6.s-fints-pt-mv.de/fints30', $spk->url);

        // Windows-1252 -> UTF-8 round trip.
        $this->assertSame('Müller-Bank', FintsBanks::find('12345678')->name);

        @unlink($path);
    }

    public function test_search_matches_name_and_blz_and_builds_label(): void
    {
        $this->artisan('fints:import-banks', ['file' => $this->writeCsv()])->assertSuccessful();

        $byName = FintsBanks::search('Mecklenburg');
        $this->assertArrayHasKey('14051000', $byName);
        $this->assertSame('Sparkasse Mecklenburg-Nordwest, Wismar (14051000)', $byName['14051000']);

        $byBlz = FintsBanks::search('10010');
        $this->assertArrayHasKey('10010010', $byBlz);

        // Too short -> no results.
        $this->assertSame([], FintsBanks::search('1'));

        // Label resolver for a stored BLZ.
        $this->assertSame('Postbank, Berlin (10010010)', FintsBanks::labelFor('10010010'));
    }
}
