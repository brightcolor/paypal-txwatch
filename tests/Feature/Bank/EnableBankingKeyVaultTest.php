<?php

namespace Tests\Feature\Bank;

use App\Services\EnableBanking\KeyVault;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The vault only accepts what can actually sign - and otherwise says why not.
 *
 * THE KEYS HERE ARE GENERATED AND BELONG TO NOBODY. A real key in a test would be
 * a bank credential in the repository.
 */
class EnableBankingKeyVaultTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/eb-vault-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    private function vault(): KeyVault
    {
        return new KeyVault($this->directory);
    }

    public function test_the_application_id_comes_out_of_the_file_name(): void
    {
        $id = '11111111-2222-3333-4444-555555555555';

        $status = $this->vault()->store(self::key(), "prod_{$id}.pem");

        $this->assertTrue($status['present']);
        $this->assertSame($id, $status['application_id']);
        $this->assertSame('prod', $status['environment']);
        $this->assertSame(2048, $status['bits']);
        $this->assertNotNull($status['fingerprint']);
    }

    public function test_sandbox_is_recognised_as_such(): void
    {
        $status = $this->vault()->store(self::key(), 'sandbox_11111111-2222-3333-4444-555555555555.pem');

        $this->assertSame('sandbox', $status['environment']);
    }

    /**
     * THE SHAPES THE CONTROL PANEL ACTUALLY PRODUCES.
     *
     * Measured against real downloads, not guessed - and the reason this test
     * exists: the first draft anchored the pattern at the start of the name, so
     * `txwatch_prod_<uuid>.pem` was refused with "Anwendungskennung fehlt". That
     * is the shape the control panel emits once the application has a name, i.e.
     * exactly the file someone has in front of them.
     */
    #[DataProvider('filenames')]
    public function test_filename_shapes_are_understood(string $name, ?string $environment, ?string $id): void
    {
        $parsed = KeyVault::parseFilename($name);

        $this->assertSame($environment, $parsed['environment']);
        $this->assertSame($id, $parsed['application_id']);
    }

    /** @return iterable<string, array{string, ?string, ?string}> */
    public static function filenames(): iterable
    {
        $id = '11111111-2222-3333-4444-555555555555';

        yield 'Name + Umgebung + Kennung' => ["txwatch_prod_{$id}.pem", 'prod', $id];
        yield 'Umgebung + Kennung' => ["prod_{$id}.pem", 'prod', $id];
        yield 'nur Kennung' => ["{$id}.pem", null, $id];
        yield 'Sandbox mit Namen' => ["txwatch_sandbox_{$id}.pem", 'sandbox', $id];
        yield 'PRODUCTION ausgeschrieben' => ["TxWatch_PRODUCTION_{$id}.pem", 'prod', $id];
        // Pfadanteile aus dem Browser dürfen nichts verschieben.
        yield 'mit Pfadanteil' => ["../../etc/prod_{$id}.pem", 'prod', $id];
        yield 'ohne alles' => ['key.pem', null, null];

        /*
         * „reprod" enthält „prod" und ist keine Produktionsumgebung. Ein reiner
         * Teilstring-Test hätte einen Sandbox-Schlüssel als Produktion
         * ausgewiesen - und die Umgebung ist die Angabe, mit der jemand
         * beantwortet, ob gerade echtes Geld im Spiel ist.
         */
        yield 'reprod ist nicht prod' => ["reprod-test-{$id}.pem", null, $id];
    }

    /**
     * Without an id in the name AND without an explicit one it is refused.
     *
     * A key without an id is unusable: the `kid` in the JWT header is the only
     * thing telling the service whose public key should verify the signature.
     * Storing it blank would produce an installation that looks configured and
     * gets a 401 on every call.
     */
    public function test_renamed_file_without_an_id_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Anwendungskennung/');

        $this->vault()->store(self::key(), 'key.pem');
    }

    public function test_renamed_file_with_an_explicit_id_is_accepted(): void
    {
        $status = $this->vault()->store(self::key(), 'mein-key.pem', 'abcdef01-2222-3333-4444-555555555555');

        $this->assertSame('abcdef01-2222-3333-4444-555555555555', $status['application_id']);
        // Without the pattern in the name the environment stays unknown - not guessed.
        $this->assertNull($status['environment']);
    }

    /** The explicit id beats the file name, not the other way round. */
    public function test_explicit_id_beats_the_file_name(): void
    {
        $status = $this->vault()->store(
            self::key(),
            'prod_11111111-2222-3333-4444-555555555555.pem',
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        );

        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $status['application_id']);
    }

    /** Nothing of the key ever appears in what the UI gets. */
    public function test_the_key_never_appears_in_the_status(): void
    {
        $pem = self::key();
        $status = $this->vault()->store($pem, 'prod_11111111-2222-3333-4444-555555555555.pem');

        $encoded = json_encode($status);

        $this->assertStringNotContainsString('PRIVATE KEY', $encoded);
        // A body line of the PEM - proof no fragment leaked through.
        $this->assertStringNotContainsString(trim(explode("\n", $pem)[1]), $encoded);
    }

    public function test_forget_removes_key_and_note(): void
    {
        $vault = $this->vault();
        $vault->store(self::key(), 'prod_11111111-2222-3333-4444-555555555555.pem');

        $this->assertTrue($vault->hasKey());

        $vault->forget();

        $this->assertFalse($vault->hasKey());
        $this->assertNull($vault->applicationId());
        $this->assertFileDoesNotExist($this->directory . '/' . KeyVault::NOTE_FILE);
    }

    /** Swapping replaces and leaves nothing of the predecessor behind. */
    public function test_swapping_replaces_the_predecessor(): void
    {
        $vault = $this->vault();

        $first = $vault->store(self::key(), 'prod_11111111-2222-3333-4444-555555555555.pem');
        $second = $vault->store(self::key(), 'prod_99999999-8888-7777-6666-555555555555.pem');

        $this->assertNotSame($first['fingerprint'], $second['fingerprint']);
        $this->assertSame('99999999-8888-7777-6666-555555555555', $vault->applicationId());

        // Exactly two files - no leftovers from the atomic write.
        $this->assertSame(
            ['key.json', 'key.pem'],
            array_values(array_diff(scandir($this->directory) ?: [], ['.', '..'])),
        );
    }

    /**
     * An empty vault says "nothing here" and does not throw.
     *
     * The state of a fresh installation. The setup page asks for the status
     * BEFORE anything is stored.
     */
    public function test_an_empty_vault_is_not_an_error(): void
    {
        $vault = $this->vault();

        $this->assertFalse($vault->hasKey());
        $this->assertFalse($vault->isReady());
        $this->assertNull($vault->applicationId());
        $this->assertNotNull($vault->missingReason());
    }

    #[DataProvider('unusable')]
    public function test_unusable_input_is_refused_with_a_reason(string $content, string $expected): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches($expected);

        KeyVault::validate($content);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unusable(): iterable
    {
        yield 'leer' => ['   ', '/leer/'];
        yield 'kein PEM' => ['Guten Tag, ich bin kein Schlüssel.', '/unbrauchbar/'];
        // The two most common slips in the control panel get their own sentence
        // instead of vanishing into "unusable".
        yield 'Zertifikat' => ["-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----", '/Zertifikat/'];
        yield 'öffentlicher Teil' => ["-----BEGIN PUBLIC KEY-----\nMIIB\n-----END PUBLIC KEY-----", '/öffentliche/'];
    }

    public function test_a_too_short_key_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/1024 Bit/');

        KeyVault::validate(self::key(1024));
    }

    /**
     * The environment beats the vault - and says so.
     *
     * The case that nearly slipped through while building: whoever sets the path
     * via env and then uploads through the UI keeps using the old key. That is
     * intended, but it has to be visible, and configuredByEnv() is what the page
     * warns with.
     */
    public function test_the_environment_beats_the_vault(): void
    {
        $vault = $this->vault();
        $vault->store(self::key(), 'prod_11111111-2222-3333-4444-555555555555.pem');

        $this->assertSame('11111111-2222-3333-4444-555555555555', $vault->applicationId());
        $this->assertFalse($vault->configuredByEnv());

        config([
            'bank.enablebanking.application_id' => 'aus-der-umgebung',
            'bank.enablebanking.key_path' => '/pfad/aus/der/umgebung.pem',
        ]);

        $fromEnv = $this->vault();

        $this->assertSame('aus-der-umgebung', $fromEnv->applicationId());
        $this->assertTrue($fromEnv->configuredByEnv());
        $this->assertSame('/pfad/aus/der/umgebung.pem', $fromEnv->keyPath());
        // And the reason then names the variable, not the UI.
        $this->assertStringContainsString('ENABLEBANKING_KEY_PATH', (string) $fromEnv->missingReason());
    }

    /**
     * A fresh RSA key in PKCS#8 PEM, the way the control panel emits it.
     *
     * GENERATED, NOT SHIPPED. A key file in the repository would be worthless but
     * every secret scanner reports it, and by the third false alarm nobody looks
     * any more.
     *
     * The openssl.cnf fallback is not decoration: the Windows PHP on the
     * development machine ships none, and openssl_pkey_new() then fails with
     * "configuration file routines::no such file". Without this branch these
     * tests would only ever run in CI - and tests that do not run while building
     * verify nothing.
     */
    private static function key(int $bits = 2048): string
    {
        $options = ['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $pair = @openssl_pkey_new($options);

        if ($pair === false) {
            foreach (self::OPENSSL_CONFIGS as $config) {
                if (is_readable($config)) {
                    $options['config'] = $config;
                    $pair = @openssl_pkey_new($options);
                    break;
                }
            }
        }

        if ($pair === false) {
            self::fail('openssl konnte keinen Schlüssel erzeugen und es liess sich keine openssl.cnf finden.');
        }

        $pem = '';
        openssl_pkey_export($pair, $pem, null, isset($options['config']) ? ['config' => $options['config']] : null);

        return $pem;
    }

    /** Git for Windows ships one; on Linux the default path already works. */
    private const OPENSSL_CONFIGS = [
        'C:/Program Files/Git/usr/ssl/openssl.cnf',
        'C:/Program Files/Git/mingw64/etc/ssl/openssl.cnf',
    ];
}
