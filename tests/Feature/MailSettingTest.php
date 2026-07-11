<?php

namespace Tests\Feature;

use App\Models\MailSetting;
use App\Models\User;
use App\Support\AdminNotifier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MailSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_is_encrypted_at_rest(): void
    {
        $s = MailSetting::current();
        $s->update(['password' => 'supersecret']);

        $raw = \DB::table('mail_settings')->where('id', $s->id)->value('password');
        $this->assertNotSame('supersecret', $raw);
        $this->assertSame('supersecret', $s->fresh()->password);
    }

    public function test_apply_does_nothing_when_not_configured(): void
    {
        MailSetting::current()->apply();
        $this->assertNotSame('smtp', config('mail.default'));
    }

    public function test_apply_pushes_config_when_configured(): void
    {
        MailSetting::current()->update([
            'enabled' => true, 'host' => 'smtp.example.com', 'port' => 465,
            'encryption' => 'ssl', 'username' => 'u', 'password' => 'p',
            'from_address' => 'noreply@example.com', 'from_name' => 'TxWatch',
        ]);

        MailSetting::current()->apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('noreply@example.com', config('mail.from.address'));
    }

    /** The test env uses the in-memory array transport; inspect its messages. */
    private function sentMessages(): array
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->toArray();
    }

    public function test_admin_notifier_mails_recipients_when_configured(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['is_active' => true, 'email' => 'admin@example.com']);
        $admin->assignRole(Role::findByName('admin'));

        MailSetting::current()->update([
            'enabled' => true, 'host' => 'smtp.example.com', 'from_address' => 'noreply@example.com',
        ]);

        AdminNotifier::warn('Test', 'Body');

        $messages = $this->sentMessages();
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('Test', $messages[0]->getOriginalMessage()->getSubject());
    }

    public function test_admin_notifier_does_not_mail_when_not_configured(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::findByName('admin'));

        AdminNotifier::warn('Test', 'Body');

        $this->assertEmpty($this->sentMessages());
    }

    public function test_alert_recipient_list_parses_explicit_and_falls_back_to_admins(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['is_active' => true, 'email' => 'a@x.de']);
        $admin->assignRole(Role::findByName('admin'));

        $s = MailSetting::current();
        $this->assertSame(['a@x.de'], $s->alertRecipientList());

        $s->update(['alert_recipients' => 'x@y.de, bad, z@y.de']);
        $this->assertSame(['x@y.de', 'z@y.de'], $s->fresh()->alertRecipientList());
    }
}
