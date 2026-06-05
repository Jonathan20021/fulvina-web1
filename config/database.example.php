<?php

declare(strict_types=1);

/**
 * Plantilla de configuracion de base de datos.
 * Copia este archivo a config/database.php en CADA entorno y ajusta las
 * credenciales. config/database.php esta en .gitignore (no se versiona) para
 * que cada servidor conserve las suyas y los deploy (git pull) no fallen.
 *
 *   XAMPP local:  host 127.0.0.1, user root, sin password, base sch_crm
 *   Produccion:   host del servidor MySQL, usuario y base del hosting
 */

const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'sch_crm';
const DB_USER = 'root';
const DB_PASS = '';
const DB_CHARSET = 'utf8mb4';

function db(bool $require = true): ?PDO
{
    static $pdo = null;
    static $failed = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if ($failed) {
        if ($require) {
            throw new RuntimeException('No se pudo conectar a MySQL. Verifica config/database.php.');
        }
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
        if ($require) {
            throw $e;
        }
        return null;
    }
}
