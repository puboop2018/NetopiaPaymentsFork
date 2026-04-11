<?php

declare(strict_types=1);

/**
 * Stand-alone PSR-4 autoloader for the NETOPIA Payments CS-Cart addon.
 *
 * Registered once from init.php. Loads:
 *   - Netopia\CsCart\*        from lib/
 *   - Netopia\Payment2\*      from lib/Sdk/ (bundled) or ../../../src/ (repo layout)
 *   - Psr\Log\*               from lib/Psr/Log/ (bundled) or ../../../vendor/psr/log/src/
 *
 * The addon is shipped self-contained in addon-netopia-payments/, but the
 * source repo layout keeps the SDK and psr/log in separate locations, so we
 * fall back to those paths when the bundled copies are absent.
 */
spl_autoload_register(static function (string $class): void {
    static $prefixes = null;
    if ($prefixes === null) {
        $libDir = __DIR__ . '/lib/';
        $repoSrc = __DIR__ . '/../../../src/';
        $repoVendor = __DIR__ . '/../../../vendor/psr/log/src/';

        $prefixes = [
            'Netopia\\CsCart\\'   => [$libDir],
            'Netopia\\Payment2\\' => [$libDir . 'Sdk/', $repoSrc],
            'Psr\\Log\\'          => [$libDir . 'Psr/Log/', $repoVendor],
        ];
    }

    foreach ($prefixes as $prefix => $dirs) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        foreach ($dirs as $dir) {
            $file = $dir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    }
});
