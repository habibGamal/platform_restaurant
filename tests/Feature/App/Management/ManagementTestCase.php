<?php

namespace Tests\Feature\App\Management;

use Tests\TestCase;

abstract class ManagementTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::registerAutoloadBypass();
    }

    protected static function registerAutoloadBypass(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'App\\Filament\\App\\Pages\\Reports\\')) {
                $basename = class_basename($class);
                if (! class_exists($class, false)) {
                    eval("namespace App\\Filament\\App\\Pages\\Reports; class {$basename} extends \\Filament\\Pages\\Page {}");
                }
            } elseif (str_starts_with($class, 'App\\Filament\\App\\Widgets\\Reports\\')) {
                $basename = class_basename($class);
                if (! class_exists($class, false)) {
                    eval("namespace App\\Filament\\App\\Widgets\\Reports; class {$basename} extends \\Filament\\Widgets\\Widget {}");
                }
            }
        }, true, true);
    }
}
