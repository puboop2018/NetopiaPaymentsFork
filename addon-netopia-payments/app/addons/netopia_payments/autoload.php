<?php

declare(strict_types=1);

/**
 * Stand-alone PSR-4 autoloader for the NETOPIA Payments CS-Cart addon.
 *
 * Registered once from init.php. Loads:
 *   - Netopia\CsCart\*        from lib/
 *   - Netopia\Payment2\*      from lib/Sdk/
 *   - Psr\Log\*               from lib/Psr/Log/
 *
 * We register our own autoloader (rather than relying on CS-Cart's Composer
 * setup) so the addon is fully self-contained: merchants can install it by
 * copying files into their CS-Cart installation without running Composer.
 */
spl_autoload_register(static function (string $class): void {
    static $libDir = null;
    if ($libDir === null) {
        $libDir = __DIR__ . '/lib/';
    }

    $prefixes = [
        'Netopia\\CsCart\\'    => $libDir,
        'Netopia\\Payment2\\'  => $libDir . 'Sdk/',
        'Psr\\Log\\'           => $libDir . 'Psr/Log/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});
