<?php

namespace App\Services\EnableBanking;

use RuntimeException;

/**
 * Holds the Enable Banking application credentials: the application id (a UUID,
 * harmless) and the private RSA key that signs every API call. Whoever holds the
 * key IS this application as far as Enable Banking is concerned.
 *
 * ON DISK, NOT IN THE DATABASE. Deliberate, and not the obvious choice:
 * TxWatch encrypts the FinTS PIN into a column and could do the same here. But a
 * database dump travels - to a backup host, to a laptop for debugging, into
 * pg_dump output someone greps. A file with mode 0600 does not, and the nightly
 * pg_dump then simply does not contain a bank credential.
 *
 * THE ID COMES OUT OF THE FILE NAME. The control panel hands out the key as
 * `prod_<application-id>.pem` (or `sandbox_<...>.pem`), so whoever uploads the
 * file has already supplied the id. Asking them to retype a UUID is an
 * invitation to mistype one, and a mistyped id surfaces as a bare 401 with no
 * further explanation.
 */
class KeyVault
{
    /** The key. Always this name; the uploaded file name is kept in the note. */
    public const KEY_FILE = 'key.pem';

    /** Id, environment, provenance and fingerprint - everything except the secret. */
    public const NOTE_FILE = 'key.json';

    /**
     * The application id is the UUID somewhere in the file name.
     *
     * ANCHORED NOWHERE, and that is the fix for a real refusal: the first draft
     * required the name to START with `prod_`, because that is one of the shapes
     * the control panel emits. It also emits the application name in front of it
     * - `txwatch_prod_<uuid>.pem` - and a bare `<uuid>.pem` for sandbox keys.
     * Both were rejected with "Anwendungskennung fehlt", i.e. exactly the files
     * someone actually has in front of them.
     */
    private const ID_PATTERN = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

    /**
     * The environment as a separate word, not as a prefix.
     *
     * As a WORD on purpose: a bare substring search would find "prod" inside a
     * name like "reprod-test" and label a sandbox key as production - and the
     * environment is what the status page shows to answer "am I about to move
     * real money".
     */
    private const ENVIRONMENT_PATTERN = '/(?:^|[_\-.])(prod|production|sandbox)(?:[_\-.]|$)/i';

    /**
     * Smallest key length that still passes.
     *
     * Enable Banking issues 2048 bit. Accepting less would mean accepting a key
     * the service never issued - i.e. something else was uploaded, and that
     * should surface here rather than at a signature check later.
     */
    private const MIN_BITS = 2048;

    /** A private key is not 100 KB; anything larger is a different kind of file. */
    private const MAX_BYTES = 102400;

    public function __construct(private readonly string $directory)
    {
    }

    public function keyPath(): string
    {
        // An env-provided path wins over the vault - same precedence the config
        // documents. Returned here too so callers never have to know which.
        return config('bank.enablebanking.key_path') ?: $this->directory . '/' . self::KEY_FILE;
    }

    public function hasKey(): bool
    {
        return is_readable($this->keyPath());
    }

    /** The application id: env first, then the uploaded note. */
    public function applicationId(): ?string
    {
        $fromEnv = config('bank.enablebanking.application_id');

        if (filled($fromEnv)) {
            return trim((string) $fromEnv);
        }

        $stored = $this->note()['application_id'] ?? null;

        return filled($stored) ? trim((string) $stored) : null;
    }

    /** True when env vars decide - then an upload has no effect and must say so. */
    public function configuredByEnv(): bool
    {
        return filled(config('bank.enablebanking.application_id'))
            || filled(config('bank.enablebanking.key_path'));
    }

    public function isReady(): bool
    {
        return filled($this->applicationId()) && $this->hasKey();
    }

    /** Why it is not ready, in one sentence someone can act on. */
    public function missingReason(): ?string
    {
        if (blank($this->applicationId())) {
            return 'Die Anwendungskennung fehlt. Lade die .pem-Datei aus dem Control Panel hoch – '
                . 'die Kennung steckt in ihrem Dateinamen.';
        }

        if (! $this->hasKey()) {
            // Two different causes, two different sentences: pointing at the UI
            // helps nobody when the path comes from an environment variable.
            return filled(config('bank.enablebanking.key_path'))
                ? 'Der private Schlüssel ist unter dem in ENABLEBANKING_KEY_PATH angegebenen Pfad nicht lesbar.'
                : 'Der private Schlüssel fehlt. Lade die .pem-Datei aus dem Control Panel hoch.';
        }

        return null;
    }

    /**
     * The private key, read fresh from disk on every call.
     *
     * DELIBERATELY NOT CACHED. A swapped key should take effect on the next
     * call without anyone restarting a queue worker - and a private key sitting
     * permanently in the memory of a long-lived process ends up in every core
     * dump.
     */
    public function key(): string
    {
        $content = @file_get_contents($this->keyPath());

        if ($content === false || trim($content) === '') {
            throw new RuntimeException('Der private Schlüssel für Enable Banking liess sich nicht lesen.');
        }

        return $content;
    }

    /**
     * What the UI may display - and not one byte of the key.
     *
     * `fingerprint` is a SHA-256 over the PUBLIC half, not over the file. That
     * is the value two installations (or a control panel entry) can be compared
     * against without the comparison itself revealing anything. A hash of the
     * file would change with a different line ending and be worthless for
     * comparing.
     *
     * @return array{present: bool, application_id: ?string, environment: ?string, filename: ?string, fingerprint: ?string, uploaded_at: ?string, bits: ?int}
     */
    public function status(): array
    {
        $note = $this->note();

        return [
            'present' => $this->hasKey(),
            'application_id' => $this->applicationId(),
            'environment' => $note['environment'] ?? null,
            'filename' => $note['filename'] ?? null,
            'fingerprint' => $note['fingerprint'] ?? null,
            'uploaded_at' => $note['uploaded_at'] ?? null,
            'bits' => isset($note['bits']) ? (int) $note['bits'] : null,
        ];
    }

    /**
     * Validate and store an uploaded key.
     *
     * CHECKED BEFORE WRITING, never after: an unusable key that has already
     * overwritten the previous one turns a working installation into a broken
     * one, and the way back is a file nobody has any more.
     *
     * @return array{present: bool, application_id: ?string, environment: ?string, filename: ?string, fingerprint: ?string, uploaded_at: ?string, bits: ?int}
     */
    public function store(string $content, string $filename, ?string $applicationId = null): array
    {
        $checked = self::validate($content);
        $fromName = self::parseFilename($filename);

        $applicationId = filled($applicationId) ? trim($applicationId) : $fromName['application_id'];

        if (blank($applicationId)) {
            throw new RuntimeException(
                'Zu diesem Schlüssel fehlt die Anwendungskennung. Das Control Panel legt die Datei als '
                . '„prod_<Kennung>.pem" ab – wurde sie umbenannt, trage die Kennung bitte von Hand ein.'
            );
        }

        if (! is_dir($this->directory) && ! mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException(
                'Der Ablageort für den Schlüssel liess sich nicht anlegen: ' . $this->directory
            );
        }

        self::write($this->keyPath(), $content);
        self::write($this->directory . '/' . self::NOTE_FILE, (string) json_encode([
            'application_id' => $applicationId,
            'environment' => $fromName['environment'],
            'filename' => self::baseName($filename),
            'fingerprint' => $checked['fingerprint'],
            'bits' => $checked['bits'],
            'uploaded_at' => now()->toAtomString(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $this->status();
    }

    /**
     * Remove key and note.
     *
     * FILE FIRST, NOTE SECOND, and a failed unlink aborts. The other way round
     * would leave a private key on disk that the UI claims is gone - the worst
     * of all states, because it still works and nobody expects it to.
     */
    public function forget(): void
    {
        $path = $this->directory . '/' . self::KEY_FILE;

        if (is_file($path) && ! @unlink($path)) {
            throw new RuntimeException(
                'Der private Schlüssel liess sich nicht löschen – er liegt weiter auf dem Server und '
                . 'wirkt weiter. Bitte die Schreibrechte auf ' . $this->directory . ' prüfen.'
            );
        }

        $note = $this->directory . '/' . self::NOTE_FILE;

        if (is_file($note)) {
            @unlink($note);
        }
    }

    /**
     * Is this a usable private RSA key?
     *
     * Static and without file access, so every rule is testable on its own.
     *
     * @return array{bits: int, fingerprint: string}
     */
    public static function validate(string $content): array
    {
        if (trim($content) === '') {
            throw new RuntimeException('Die hochgeladene Datei ist leer.');
        }

        if (strlen($content) > self::MAX_BYTES) {
            throw new RuntimeException(sprintf(
                'Die Datei ist %d KB gross. Ein privater Schlüssel ist wenige Kilobyte gross – hier ist '
                . 'etwas anderes hochgeladen worden.',
                (int) round(strlen($content) / 1024)
            ));
        }

        /*
         * THE MOST COMMON MISTAKE FIRST. The control panel offers a certificate
         * next to the private key. Uploading the certificate gets you
         * "unusable" from openssl - a sentence nobody can find their own error
         * in.
         */
        if (str_contains($content, '-----BEGIN CERTIFICATE-----')) {
            throw new RuntimeException(
                'Das ist ein Zertifikat, kein privater Schlüssel. Gebraucht wird die Datei, die mit '
                . '„-----BEGIN PRIVATE KEY-----" beginnt.'
            );
        }

        if (str_contains($content, '-----BEGIN PUBLIC KEY-----')) {
            throw new RuntimeException(
                'Das ist der öffentliche Schlüssel. Gebraucht wird der PRIVATE – die Datei, die das '
                . 'Control Panel beim Registrieren einmalig zum Herunterladen anbietet.'
            );
        }

        $key = @openssl_pkey_get_private($content);

        if ($key === false) {
            throw new RuntimeException(
                'Der Schlüssel ist unbrauchbar – erwartet wird ein RSA-Schlüssel im PEM-Format, so wie ihn '
                . 'das Control Panel ablegt. Ist die Datei mit einem Kennwort geschützt, nimm sie bitte '
                . 'zuerst ohne Kennwort heraus.'
            );
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException(
                'Das ist kein RSA-Schlüssel. Enable Banking signiert ausschliesslich mit RS256, und dafür '
                . 'braucht es RSA.'
            );
        }

        $bits = (int) ($details['bits'] ?? 0);

        if ($bits < self::MIN_BITS) {
            throw new RuntimeException(sprintf(
                'Der Schlüssel hat nur %d Bit; erwartet werden mindestens %d.',
                $bits,
                self::MIN_BITS
            ));
        }

        return [
            'bits' => $bits,
            'fingerprint' => hash('sha256', (string) ($details['key'] ?? '')),
        ];
    }

    /**
     * Environment and application id out of the control panel's file name.
     *
     * The two are read INDEPENDENTLY, because they appear in several
     * combinations - measured against the files the control panel actually
     * produces:
     *
     *     txwatch_prod_321ede2b-….pem   name + environment + id
     *     prod_bb163104-….pem            environment + id
     *     89b65faa-….pem                 id only (no environment named)
     *
     * An id without an environment is fine: the status page then says "unknown"
     * instead of guessing, and a guess here would be a statement about whether
     * real money is in play.
     *
     * @return array{environment: ?string, application_id: ?string}
     */
    public static function parseFilename(string $name): array
    {
        $base = self::baseName($name);

        $id = preg_match(self::ID_PATTERN, $base, $m) === 1 ? strtolower($m[1]) : null;

        $environment = null;

        if (preg_match(self::ENVIRONMENT_PATTERN, $base, $m) === 1) {
            // "production" and "prod" are the same thing; stored as one value so
            // the UI does not need to know both spellings.
            $environment = strtolower($m[1]) === 'sandbox' ? 'sandbox' : 'prod';
        }

        return ['environment' => $environment, 'application_id' => $id];
    }

    /**
     * File name only, no path parts.
     *
     * The name arrives from the browser and is therefore user input. It lands in
     * the note and gets displayed; a "../" in it would have no effect here
     * because nothing is ever written with it - but the note should not claim
     * the file was called that either.
     */
    private static function baseName(string $name): string
    {
        return basename(str_replace('\\', '/', trim($name)));
    }

    /** Write atomically and make it readable by the owner only. */
    private static function write(string $target, string $content): void
    {
        $temp = $target . '.new';

        if (@file_put_contents($temp, $content) === false) {
            throw new RuntimeException('Der Ablageort für den Schlüssel ist nicht beschreibbar: ' . dirname($target));
        }

        // Before the rename, so the file is never even briefly world-readable.
        @chmod($temp, 0600);

        if (! @rename($temp, $target)) {
            @unlink($temp);

            throw new RuntimeException('Der Schlüssel liess sich nicht an seinen Platz schieben.');
        }
    }

    /** @return array<string, mixed> */
    private function note(): array
    {
        $path = $this->directory . '/' . self::NOTE_FILE;

        if (! is_readable($path)) {
            return [];
        }

        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }
}
