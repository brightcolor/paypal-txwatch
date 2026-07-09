<?php

return [
    /*
     * Path to a system Chromium/Chrome binary. When set, Browsershot uses
     * it instead of downloading its own, so the Docker image only needs
     * `puppeteer` installed with PUPPETEER_SKIP_DOWNLOAD=true.
     */
    'chrome_path' => env('CHROMIUM_PATH', '/usr/bin/chromium'),

    /*
     * Where the `puppeteer` node module is installed. Kept outside the
     * bind-mounted project directory (see docker/Dockerfile) so it
     * survives regardless of what's mounted over /var/www/html.
     */
    'node_module_path' => env('NODE_MODULE_PATH', '/opt/node/node_modules'),

    'node_binary' => env('NODE_BINARY', '/usr/bin/node'),
    'npm_binary' => env('NPM_BINARY', '/usr/bin/npm'),
];
