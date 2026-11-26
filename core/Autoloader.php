<?php
declare(strict_types=1);

namespace Core;

/**
 * Autoloader PSR-4 propriu, fara dependenta de Composer.
 * Mapeaza namespace-ul `Core\*` catre `core/` si `Modules\<Nume>\*` catre `modules/<nume>/`.
 */
final class Autoloader
{
    private static string $baseDir = '';

    public static function register(string $baseDir): void
    {
        self::$baseDir = rtrim($baseDir, '/\\');
        spl_autoload_register([self::class, 'load'], true, true);
    }

    public static function load(string $class): void
    {
        $class = ltrim($class, '\\');

        if (str_starts_with($class, 'Core\\')) {
            $rel = substr($class, 5);
            $path = self::$baseDir . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $rel) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
            return;
        }

        if (str_starts_with($class, 'Modules\\')) {
            $parts = explode('\\', substr($class, 8));
            if (count($parts) >= 2) {
                $moduleSlug = strtolower(array_shift($parts));
                $rel = implode(DIRECTORY_SEPARATOR, $parts);
                $path = self::$baseDir . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . $moduleSlug . DIRECTORY_SEPARATOR . $rel . '.php';
                if (is_file($path)) {
                    require_once $path;
                }
            }
        }
    }
}
