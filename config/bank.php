<?php

/*
|--------------------------------------------------------------------------
| Bank access
|--------------------------------------------------------------------------
|
| TxWatch can reach a bank account two ways, and they are very different in
| what they require and what they expose:
|
|   FinTS/HBCI      - talks to the bank directly with the customer's PIN/TAN.
|                     Needs a product registration with the Deutsche
|                     Kreditwirtschaft. Currently SWITCHED OFF, see below.
|   Enable Banking  - a PSD2 aggregator. The customer authorises on their own
|                     bank's website; TxWatch only ever holds a read token.
|
| Both feed the same import + reconcile pipeline (BankStatementImporter), so
| whichever is active, downstream behaviour is identical.
|
*/

return [

    'fints' => [

        /*
         * FinTS IS OFF UNLESS THIS SAYS OTHERWISE - and that default is
         * deliberate, not an oversight.
         *
         * Without a DK-registered product id the bank server rejects every
         * dialog with "9078 - Banking-Programm ist nicht registriert", and it
         * does so AFTER a successful login. From the operator's side that looks
         * like a TxWatch defect: the credentials are right, the pull still
         * fails. Offering a form that always ends there is worse than offering
         * none.
         *
         * Nothing is deleted. Credentials, TAN mode, the persisted session and
         * the bank list all stay put; flipping FINTS_ENABLED=1 brings the whole
         * path back, which is why this is a switch and not a removal.
         */
        'enabled' => env('FINTS_ENABLED', false),
    ],

    'enablebanking' => [

        /*
         * The API host. `api.tilisy.com` is the old name of the same service and
         * is deprecated - it is mentioned here only so nobody mistakes it for
         * something else.
         */
        'base_url' => env('ENABLEBANKING_BASE_URL', 'https://api.enablebanking.com'),

        /*
         * Application id and private key. BOTH ARE OPTIONAL HERE: leaving them
         * empty is the normal case, because an admin uploads the key from the
         * control panel through the UI (Bank -> Bank verbinden) and the key
         * vault keeps it on disk.
         *
         * If they ARE set, they win over the vault. That direction is on
         * purpose: an entry in the compose file is the operator's explicit
         * statement and must not be silently overridden by a click. The setup
         * page says so when it detects this case, because otherwise someone
         * uploads a key, sees "stored", and then spends an afternoon wondering
         * why the bank still sees the old one.
         */
        'application_id' => env('ENABLEBANKING_APPLICATION_ID'),
        'key_path' => env('ENABLEBANKING_KEY_PATH'),

        /*
         * Where the uploaded key lives when no env var points elsewhere.
         *
         * Under storage/app/private, NOT storage/app/public: the latter is
         * symlinked into the document root, and a private RSA key one HTTP GET
         * away from the world is the whole compromise in a single file. The
         * deploy instructions already bind-mount storage/, so it survives a
         * container rebuild - unlike a path inside the image.
         */
        'key_dir' => storage_path('app/private/enablebanking'),

        /*
         * The WISH for how long a consent should last - not a promise, and not a
         * limit of its own.
         *
         * What decides is `maximum_consent_validity` of the chosen bank, which
         * the page reads before starting the consent. Measured against the live
         * list on 2026-08-17, German banks grant 180 days; the 90 that every
         * older PSD2 guide names is the original cap, raised since for account
         * information. Hard-coding 90 would have halved the validity and made the
         * account holder re-authorise twice as often for nothing.
         *
         * 180 is therefore the wish, and the bank lowers it where it grants less.
         * Sending MORE than the bank allows is rejected outright, which is why the
         * clamp is not optional.
         */
        'consent_days' => 180,

        /*
         * How far back the first pull reaches, and how much every later pull
         * re-covers so late bookings are not missed. Same values the FinTS sync
         * uses, for the same reason.
         */
        'first_pull_days' => 90,
        'overlap_days' => 3,

        /*
         * Hard ceiling on transactions per account and pull, plus a page limit.
         *
         * A bank server that always returns a continuation_key would otherwise
         * spin this loop forever. The limits are generous but finite - and when
         * one bites, the result SAYS so (see TransactionPage::truncated). A cap
         * nobody reports looks exactly like a complete pull and is a hole in the
         * books.
         */
        'max_transactions' => 2000,
        'max_pages' => 50,
    ],
];
