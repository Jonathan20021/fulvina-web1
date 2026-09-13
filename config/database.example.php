<?php

declare(strict_types=1);

/**
 * PLANTILLA de conexión a MySQL.
 *
 * Copia este archivo a config/database.php en CADA entorno y rellena las
 * credenciales de producción marcadas abajo. config/database.php está en
 * .gitignore: no viaja en el deploy, así cada servidor conserva las suyas y un
 * git pull no las pisa.
 *
 * La conexión se elige sola según dónde corra el código: si el navegador entra
 * por localhost usa la base local de pruebas; en cualquier otro host usa
 * producción. Así abrir el CRM en XAMPP nunca escribe en la base real.
 *
 * Para forzar producción desde local —revisar un dato de verdad, por ejemplo—
 * exporta SCH_DB=produccion antes de arrancar Apache.
 *
 * Antes de usarlo, crea la base local:
 *   CREATE DATABASE sch_medicos_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 * y corre database/migrate.php.
 */

$isLocalRequest = (function (): bool {
    if (PHP_SAPI === 'cli') {
        // Por consola manda la variable de entorno; por defecto, local.
        return getenv('SCH_DB') !== 'produccion';
    }
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
        return false;
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\]|::1)(:\d+)?$/', $host);
})();

if (getenv('SCH_DB') === 'produccion') {
    $isLocalRequest = false;
}

/* define() y no const: const no admite bloques condicionales. */
if ($isLocalRequest) {
    /* ---- XAMPP local: base de pruebas ---- */
    defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
    defined('DB_PORT') || define('DB_PORT', '3306');
    defined('DB_NAME') || define('DB_NAME', 'sch_medicos_local');
    defined('DB_USER') || define('DB_USER', 'root');
    defined('DB_PASS') || define('DB_PASS', '');
} else {
    /* ---- Servidor de producción ---- */
    defined('DB_HOST') || define('DB_HOST', 'HOST_DEL_SERVIDOR_MYSQL');
    defined('DB_PORT') || define('DB_PORT', '3306');
    defined('DB_NAME') || define('DB_NAME', 'NOMBRE_DE_LA_BASE');
    defined('DB_USER') || define('DB_USER', 'USUARIO_MYSQL');
    defined('DB_PASS') || define('DB_PASS', 'CONTRASENA_MYSQL');
}

defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');

/** Nombre legible del entorno activo, para avisos en pantalla. */
function db_environment(): string
{
    return DB_NAME === 'sch_medicos_local' ? 'local' : 'produccion';
}

function db(bool $require = true): ?PDO
{
    static $pdo = null;
    static $failed = false;
    if ($pdo instanceof PDO) { return $pdo; }
    if ($failed) {
        if ($require) { throw new RuntimeException('No se pudo conectar a MySQL. Verifica config/database.php.'); }
        return null;
    }
    $port = defined('DB_PORT') ? DB_PORT : '3306';
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        $failed = true;
        if ($require) { throw $e; }
        return null;
    }
}
