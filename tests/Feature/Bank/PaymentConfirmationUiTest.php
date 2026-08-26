<?php

namespace Tests\Feature\Bank;

use App\Filament\Resources\EventResource\Pages\EditEvent;
use App\Filament\Resources\PretixPaymentConfirmationResource\Pages\ListPretixPaymentConfirmations;
use App\Models\Event;
use App\Models\PretixPaymentConfirmation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The screens that answer "why is this order paid" - actually rendered.
 *
 * The record only helps if it can be read, and both the list and the evidence modal
 * exist nowhere else: a broken column or Blade error in them would surface when
 * someone goes looking for an explanation, which is the worst possible moment.
 */
class PaymentConfirmationUiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('admin'));

        return $user;
    }

    /** One record per outcome, so every branch of the view runs. */
    private function records(): Event
    {
        $event = Event::create([
            'name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest',
            'is_active' => true, 'auto_mark_paid' => true,
        ]);

        $gemeinsam = [
            'event_slug' => 'sommerfest', 'event_id' => $event->id, 'amount' => 25.00,
            'currency' => 'EUR', 'purpose' => 'Bestellung ABCDE', 'counterparty_name' => 'Max Mustermann',
            'booked_on' => '2026-07-05', 'source' => PretixPaymentConfirmation::SOURCE_JOURNAL,
            'automatic' => true, 'at' => now(),
        ];

        PretixPaymentConfirmation::create($gemeinsam + [
            'order_code' => 'ABCDE',
            'outcome' => PretixPaymentConfirmation::OUTCOME_CONFIRMED,
            'reason' => PretixPaymentConfirmation::REASON_OK,
            'message' => 'In pretix als bezahlt bestätigt.',
            'pretix_payment_local_id' => 1,
        ]);

        PretixPaymentConfirmation::create($gemeinsam + [
            'order_code' => 'OFFXX',
            'outcome' => PretixPaymentConfirmation::OUTCOME_SKIPPED,
            'reason' => PretixPaymentConfirmation::REASON_SWITCH_OFF,
            'message' => 'Event „Sommerfest": automatische Meldung ist ausgeschaltet.',
        ]);

        PretixPaymentConfirmation::create($gemeinsam + [
            'order_code' => 'NOPRM',
            'outcome' => PretixPaymentConfirmation::OUTCOME_FAILED,
            'reason' => PretixPaymentConfirmation::REASON_API,
            'message' => 'Keine Berechtigung – der API-Token braucht das Recht „Bestellungen ändern".',
        ]);

        return $event;
    }

    public function test_the_list_renders_every_outcome(): void
    {
        $this->actingAs($this->admin());
        $this->records();

        Livewire::test(ListPretixPaymentConfirmations::class)
            ->assertOk()
            ->assertSee('als bezahlt gemeldet')
            ->assertSee('nicht gemeldet')
            ->assertSee('fehlgeschlagen')
            // The reason in full, not a key - that is the entire point of the list.
            ->assertSee('Die Bestellnummer stand im Verwendungszweck')
            ->assertSee('ausgeschaltet');
    }

    /** The evidence modal renders for each outcome. */
    public function test_the_evidence_view_renders(): void
    {
        $this->records();

        foreach (PretixPaymentConfirmation::with('event')->get() as $record) {
            $html = view('filament.pretix.payment-confirmation', ['record' => $record])->render();

            $this->assertStringContainsString($record->outcomeLabel(), $html);
            $this->assertStringContainsString('Bestellung ABCDE', $html, 'Der Verwendungszweck ist der Nachweis.');
            // Where to turn it off is the next question anyone has.
            $this->assertStringContainsString('Zahlungen automatisch melden', $html);
        }
    }

    public function test_the_filters_run(): void
    {
        $this->actingAs($this->admin());
        $this->records();

        $codes = Livewire::test(ListPretixPaymentConfirmations::class)
            ->filterTable('nur_gemeldet')
            ->instance()
            ->getFilteredTableQuery()
            ->pluck('order_code')
            ->all();

        $this->assertSame(['ABCDE'], $codes);

        Livewire::test(ListPretixPaymentConfirmations::class)
            ->filterTable('nur_fehlgeschlagen')
            ->assertOk();
    }

    /** Nothing here may be changed or removed. */
    public function test_the_record_cannot_be_edited_or_deleted(): void
    {
        $resource = \App\Filament\Resources\PretixPaymentConfirmationResource::class;
        $record = $this->records() && PretixPaymentConfirmation::first();

        $this->assertFalse($resource::canCreate());
        $this->assertFalse($resource::canEdit($record));
        $this->assertFalse($resource::canDelete($record));
    }

    /** The switch is reachable and saving it keeps it. */
    public function test_the_event_switch_can_be_turned_on(): void
    {
        $this->actingAs($this->admin());

        $event = Event::create([
            'name' => 'Sommerfest', 'pretix_event_slug' => 'sommerfest',
            'is_active' => true, 'auto_mark_paid' => false,
        ]);

        Livewire::test(EditEvent::class, ['record' => $event->id])
            ->assertFormFieldExists('auto_mark_paid')
            ->fillForm(['auto_mark_paid' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($event->fresh()->auto_mark_paid);
    }
}
