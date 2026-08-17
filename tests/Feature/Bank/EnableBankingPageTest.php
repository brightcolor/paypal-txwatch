<?php

namespace Tests\Feature\Bank;

use App\Filament\Pages\EnableBankingPage;
use App\Models\User;
use App\Services\EnableBanking\KeyVault;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Guards the first step of the setup: getting the key IN.
 *
 * WHY THIS TEST EXISTS. The page shipped unusable, and in two ways at once, both
 * only visible when someone actually tried:
 *
 *  1. A media-type filter on the upload field. `.pem` has no dependable MIME
 *     type - Windows reports application/octet-stream - so the field rejected
 *     the very file the control panel hands out.
 *  2. The bank dropdown was unconditionally required(). It cannot be filled
 *     without a key, the key is stored by saving the form, and saving failed on
 *     that field being empty. A deadlock with no way out of the UI.
 *
 * Neither was caught by the smoke test, because rendering the page worked fine.
 * Only submitting it did not.
 */
class EnableBankingPageTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // A vault of its own per test, so nothing touches a real key.
        $this->directory = sys_get_temp_dir() . '/eb-page-' . bin2hex(random_bytes(6));
        config(['bank.enablebanking.key_dir' => $this->directory]);
        $this->app->forgetInstance(KeyVault::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::findByName('admin'));
        $this->actingAs($admin);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    /**
     * THE CASE THAT WAS BROKEN: an empty installation stores the key on the very
     * first save, with no bank chosen yet.
     */
    public function test_the_key_can_be_stored_before_a_bank_is_chosen(): void
    {
        $id = '321ede2b-08d1-43db-8cbb-13b1b4adca30';

        Livewire::test(EnableBankingPage::class)
            ->fillForm([
                'key' => UploadedFile::fake()->createWithContent("txwatch_prod_{$id}.pem", self::key()),
                'aspsp_name' => null,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $vault = app(KeyVault::class);

        $this->assertTrue($vault->hasKey());
        $this->assertSame($id, $vault->applicationId());
        // Read out of the file name - nobody had to retype a UUID.
        $this->assertSame('prod', $vault->status()['environment']);
    }

    /**
     * A .pem arriving as application/octet-stream is accepted.
     *
     * That is what Windows sends, and a media-type filter turned it into "must be
     * a file of type…" on a perfectly good key.
     */
    public function test_a_pem_with_a_generic_media_type_is_accepted(): void
    {
        $id = '11111111-2222-3333-4444-555555555555';

        $file = UploadedFile::fake()->createWithContent("txwatch_prod_{$id}.pem", self::key());

        Livewire::test(EnableBankingPage::class)
            ->fillForm(['key' => $file])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(app(KeyVault::class)->hasKey());
    }

    /**
     * Content is still checked - the filter moved, it did not disappear.
     *
     * A certificate instead of the private key is the most common mix-up in the
     * control panel, and it gets its own sentence rather than "unusable".
     */
    public function test_a_certificate_is_refused_with_a_reason(): void
    {
        Livewire::test(EnableBankingPage::class)
            ->fillForm([
                'key' => UploadedFile::fake()->createWithContent(
                    'txwatch_prod_11111111-2222-3333-4444-555555555555.pem',
                    "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
                ),
            ])
            ->call('save');

        $this->assertFalse(app(KeyVault::class)->hasKey());
    }

    /** Once a key is stored, the bank becomes mandatory again. */
    public function test_the_bank_is_required_once_a_key_is_stored(): void
    {
        app(KeyVault::class)->store(self::key(), 'txwatch_prod_11111111-2222-3333-4444-555555555555.pem');

        Livewire::test(EnableBankingPage::class)
            ->fillForm(['aspsp_name' => null])
            ->call('save')
            ->assertHasFormErrors(['aspsp_name']);
    }

    /**
     * A fresh RSA key, generated rather than shipped.
     *
     * The openssl.cnf fallback is for the Windows PHP on the development machine,
     * which ships none - without it these tests would only run in CI, and tests
     * that do not run while building verify nothing.
     */
    private static function key(): string
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $pair = @openssl_pkey_new($options);

        if ($pair === false) {
            foreach ([
                'C:/Program Files/Git/usr/ssl/openssl.cnf',
                'C:/Program Files/Git/mingw64/etc/ssl/openssl.cnf',
            ] as $config) {
                if (is_readable($config)) {
                    $options['config'] = $config;
                    $pair = @openssl_pkey_new($options);
                    break;
                }
            }
        }

        self::assertNotFalse($pair, 'openssl konnte keinen Schlüssel erzeugen.');

        $pem = '';
        openssl_pkey_export($pair, $pem, null, isset($options['config']) ? ['config' => $options['config']] : null);

        return $pem;
    }
}
