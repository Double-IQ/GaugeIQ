<?php
declare(strict_types=1);

final class Database
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $database = $config['database'] ?? [];
        $driver = strtolower((string)($database['driver'] ?? 'sqlite'));

        if ($driver === 'mysql') {
            $host = (string)($database['host'] ?? '127.0.0.1');
            $port = (string)($database['port'] ?? '3306');
            $name = (string)($database['name'] ?? '');
            $user = (string)($database['username'] ?? '');
            $password = (string)($database['password'] ?? '');
            $charset = (string)($database['charset'] ?? 'utf8mb4');

            if ($name === '' || $user === '') {
                throw new RuntimeException('MySQL database name and username are required.');
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

            $this->pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            return;
        }

        if ($driver !== 'sqlite') {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        $path = (string)($database['path'] ?? '');
        if ($path === '') {
            throw new RuntimeException('SQLite database path is required.');
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the SQLite storage directory.');
        }

        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Wait briefly for concurrent web requests or cron activity instead of
        // failing immediately on SQLite's single-writer lock.
        $this->pdo->exec('PRAGMA busy_timeout = 10000');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
