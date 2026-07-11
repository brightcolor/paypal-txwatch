<?php

namespace Tests\Feature;

use App\Mail\SettlementMail;
use App\Models\Settlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_settlement_mail_carries_the_pdf_attachment(): void
    {
        $settlement = Settlement::create([
            'title' => 'FC Anker Juli', 'status' => Settlement::STATUS_OPEN,
            'blocks' => [], 'events' => [], 'gross' => 0, 'fees' => 0, 'payout' => 123.45,
            'vat' => 0, 'net_excl_vat' => 0, 'tx_count' => 0,
        ]);

        $mail = new SettlementMail($settlement, '%PDF-1.4 fake', 'abrechnung.pdf');

        $mail->assertHasSubject('Abrechnung: FC Anker Juli');
        $mail->assertHasAttachment(
            \Illuminate\Mail\Mailables\Attachment::fromData(fn () => '%PDF-1.4 fake', 'abrechnung.pdf')->withMime('application/pdf')
        );
    }

    public function test_sent_fields_are_fillable(): void
    {
        $settlement = Settlement::create([
            'title' => 'X', 'status' => Settlement::STATUS_OPEN,
            'blocks' => [], 'events' => [], 'gross' => 0, 'fees' => 0, 'payout' => 0,
            'vat' => 0, 'net_excl_vat' => 0, 'tx_count' => 0,
            'sent_at' => now(), 'sent_to' => 'verein@example.com',
        ]);

        $this->assertSame('verein@example.com', $settlement->fresh()->sent_to);
        $this->assertNotNull($settlement->fresh()->sent_at);
    }
}
