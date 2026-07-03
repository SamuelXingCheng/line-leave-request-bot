<?php
// Db.php
class Database {
    private static $connection = null;

    public static function getConnection() {
        if (self::$connection === null) {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $db   = getenv('DB_NAME') ?: 'line_leave_bot';
            $user = getenv('DB_USER') ?: 'botuser';
            $pass = getenv('DB_PASS') ?: 'botpass123';
            $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

            $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$connection = new PDO($dsn, $user, $pass, $options);
                self::$connection->exec("SET time_zone = '+08:00'");
                date_default_timezone_set('Asia/Taipei');
            } catch (PDOException $e) {
                error_log("❌ Database connection failed: " . $e->getMessage());
                throw $e;
            }
        }

        return self::$connection;
    }
}
