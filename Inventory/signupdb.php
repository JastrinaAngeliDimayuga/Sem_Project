<?php

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null)
        return $pdo;

    // The driver must be enabled in php.ini (extension=pdo_pgsql)
    if (!extension_loaded('pdo_pgsql')) {
        $msg = 'The pdo_pgsql PHP extension is not enabled. '
            . 'Uncomment extension=pdo_pgsql and extension=pgsql in php.ini, then restart the server.';
        error_log('signupdb: ' . $msg);
        throw new PDOException($msg);
    }

    // 127.0.0.1 is used instead of "localhost" to avoid IPv6 (::1) connection problems
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '5432';        // pgAdmin > server > Properties > Connection
    $name = getenv('DB_NAME') ?: 'postgres';
    $user = getenv('DB_USER') ?: 'postgres';
    $pass = getenv('DB_PASS') ?: 'jenelle';

    $dsn = "pgsql:host=$host;port=$port;dbname=$name;connect_timeout=5";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // the real reason goes to the PHP error log (the page only shows a generic message)
        error_log("signupdb: could not connect to $host:$port/$name as $user - " . $e->getMessage());
        throw $e;
    }

    return $pdo;
}

