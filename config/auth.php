<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    | Nag (not block) admins who haven't enabled two-factor auth: a per-session
    | reminder shown by EnsureTwoFactorChallengeIsPassed. Admins can keep working
    | before enrolling. Falls back to the old TWO_FACTOR_REQUIRED_FOR_ADMINS env
    | so existing deployments keep their on/off choice for the reminder.
    */
    'two_factor_nag_admins' => (bool) env('TWO_FACTOR_NAG_ADMINS', env('TWO_FACTOR_REQUIRED_FOR_ADMINS', true)),

    /*
    | Unlock PIN and confirmed devices for accounts with two-factor auth.
    | App\Support\TwoFactorSettings reads these, checks the limits and names the
    | variable in its error message.
    |
    | A user may set a PIN on the 2FA settings page. On a device where they
    | confirmed an authenticator code within trusted_device_days, the PIN then
    | replaces that code. Without a PIN, a return through "Angemeldet bleiben"
    | after the session ended signs the user out completely.
    |
    |   pin_min_length         shortest PIN in digits, 4 to 12
    |   pin_max_attempts       wrong PINs in a row, 1 to 20; then the PIN only
    |                          works again after a sign-in with the app code
    |   trusted_device_days    days a device stays confirmed after the last app
    |                          code entered on it, 1 to 400
    |   trusted_device_cookie  name of the cookie that marks a confirmed device
    */
    'two_factor' => [
        'pin_min_length' => env('TWO_FACTOR_PIN_MIN_LENGTH', 6),
        'pin_max_attempts' => env('TWO_FACTOR_PIN_MAX_ATTEMPTS', 5),
        'trusted_device_days' => env('TWO_FACTOR_TRUSTED_DEVICE_DAYS', 30),
        'trusted_device_cookie' => env('TWO_FACTOR_TRUSTED_DEVICE_COOKIE', 'txwatch_2fa_device'),
    ],

];
