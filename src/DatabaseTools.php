<?php
declare(strict_types=1);
namespace MyBB\Tooling;

use mysqli;
use RuntimeException;
use Throwable;

/** Credential-safe database diagnostics and MySQLi SQL backups for MyBB sites. */
final class DatabaseTools
{
    public function __construct(private readonly string $siteRoot) {}

    /** @return array{status:string,driver:string,tables:int} */
    public function check(): array
    {
        [$db, $prefix] = $this->connect();
        try {
            $tables = count($this->mybbTables($db, $prefix));
        } catch (Throwable) {
            throw new RuntimeException('Database connection succeeded, but MyBB tables could not be listed.');
        } finally {
            $db->close();
        }
        if ($tables === 0) throw new RuntimeException('Database connection succeeded, but no tables matching this MyBB site prefix were found.');
        return ['status' => 'connected', 'driver' => 'mysqli', 'tables' => $tables];
    }

    /** Write a restrictive, atomic SQL dump outside the public MyBB document root. */
    public function backup(string $output): array
    {
        $target = $this->resolveOutput($output);
        [$db, $prefix] = $this->connect();
        $temp = dirname($target) . DIRECTORY_SEPARATOR . '.' . basename($target) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $stream = @fopen($temp, 'x');
        if ($stream === false) {
            $db->close();
            throw new RuntimeException('Cannot create a temporary backup in the selected directory.');
        }
        @chmod($temp, 0600);
        try {
            $this->write($stream, "-- MyBB database backup\n-- Created: " . gmdate(DATE_ATOM) . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            foreach ($this->mybbTables($db, $prefix) as $table) {
                $quoted = '`' . str_replace('`', '``', $table) . '`';
                $create = $db->query('SHOW CREATE TABLE ' . $quoted)->fetch_row();
                if (!is_array($create) || !isset($create[1])) throw new RuntimeException('Could not read a table definition.');
                $this->write($stream, 'DROP TABLE IF EXISTS ' . $quoted . ";\n" . $create[1] . ";\n");
                $data = $db->query('SELECT * FROM ' . $quoted, MYSQLI_USE_RESULT);
                $fields = $data->fetch_fields();
                $columns = implode(', ', array_map(static fn($field): string => '`' . str_replace('`', '``', $field->name) . '`', $fields));
                while ($record = $data->fetch_row()) {
                    $values = array_map(static function ($value, $field) use ($db): string {
                        if ($value === null) return 'NULL';
                        $binaryTypes = [MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_BLOB, MYSQLI_TYPE_BIT];
                        if (in_array($field->type, $binaryTypes, true)) return "X'" . bin2hex((string)$value) . "'";
                        return "'" . $db->real_escape_string((string)$value) . "'";
                    }, $record, $fields);
                    $this->write($stream, 'INSERT INTO ' . $quoted . ' (' . $columns . ') VALUES (' . implode(', ', $values) . ");\n");
                }
                $data->free();
                $this->write($stream, "\n");
            }
            $this->write($stream, "SET FOREIGN_KEY_CHECKS=1;\n");
            if (!fflush($stream)) throw new RuntimeException('Could not flush backup data.');
            fclose($stream);
            $stream = null;
            // link() publishes atomically and fails if another process created the target meanwhile.
            if (!@link($temp, $target)) throw new RuntimeException('Could not publish the completed backup file without overwriting an existing path.');
            if (!@unlink($temp)) throw new RuntimeException('Backup was published, but the temporary hard link could not be removed: ' . $temp);
            @chmod($target, 0600);
            return ['status' => 'backed_up', 'path' => $target, 'bytes' => filesize($target), 'sha256' => hash_file('sha256', $target)];
        } catch (Throwable $e) {
            if (is_resource($stream)) fclose($stream);
            @unlink($temp);
            if ($e instanceof RuntimeException) throw $e;
            throw new RuntimeException('Database backup failed; the incomplete temporary file was removed.');
        } finally {
            $db->close();
        }
    }

    /** Validate the destination without loading credentials or opening a database connection. */
    public function previewBackup(string $output): array
    {
        return ['status' => 'dry_run', 'would_create' => $this->resolveOutput($output), 'database_connection_attempted' => false];
    }

    /** @return array{mysqli} */
    private function connect(): array
    {
        $root = realpath($this->siteRoot);
        if (!$root || !is_file($root . '/inc/config.php')) throw new RuntimeException('Validated MyBB configuration is unavailable.');
        try {
            $config = (static function (string $file): array {
                $config = [];
                require $file;
                return is_array($config) ? $config : [];
            })($root . '/inc/config.php');
        } catch (Throwable) {
            throw new RuntimeException('Could not read MyBB database settings from inc/config.php.');
        }
        $database = $config['database'] ?? [];
        if (strtolower((string)($database['type'] ?? '')) !== 'mysqli') {
            throw new RuntimeException('Database tools currently support MyBB mysqli sites only.');
        }
        if (!extension_loaded('mysqli')) throw new RuntimeException('The PHP mysqli extension is required for this site.');
        // MyBB supports separate read/write connection credentials. Diagnostics and dumps use the writer.
        $settings = array_replace($database, is_array($database['write'] ?? null) ? $database['write'] : []);
        foreach (['database', 'table_prefix'] as $required) {
            if (!isset($settings[$required]) || !is_string($settings[$required])) throw new RuntimeException('MyBB database settings are incomplete.');
        }
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $hostname = (string)($settings['hostname'] ?? 'localhost');
            $port = (int)($settings['port'] ?? 3306);
            if (preg_match('/^\[([0-9a-fA-F:]+)\]:(\d+)$/', $hostname, $match)) {
                $hostname = $match[1];
                if (!isset($settings['port'])) $port = (int)$match[2];
            } elseif (substr_count($hostname, ':') === 1 && preg_match('/^([^:]+):(\d+)$/', $hostname, $match)) {
                $hostname = $match[1];
                if (!isset($settings['port'])) $port = (int)$match[2];
            }
            $db = new mysqli(
                $hostname,
                (string)($settings['username'] ?? ''),
                (string)($settings['password'] ?? ''),
                (string)$settings['database'],
                $port,
                (string)($settings['socket'] ?? '') ?: null
            );
            $db->set_charset('utf8mb4');
            return [$db, (string)($database['table_prefix'] ?? '')];
        } catch (Throwable) {
            throw new RuntimeException('Database connection failed. Check MyBB database settings and server availability; credentials were not included.');
        }
    }

    /** @return list<string> only tables owned by this MyBB installation */
    private function mybbTables(mysqli $db, string $prefix): array
    {
        $result = $db->query('SHOW TABLES');
        $tables = [];
        while ($row = $result->fetch_row()) {
            $name = (string)$row[0];
            if ($prefix === '' || str_starts_with($name, $prefix)) $tables[] = $name;
        }
        $result->free();
        return $tables;
    }

    private function resolveOutput(string $output): string
    {
        if ($output === '') throw new RuntimeException('Usage: db backup --output=PATH');
        $path = str_starts_with($output, DIRECTORY_SEPARATOR) ? $output : getcwd() . DIRECTORY_SEPARATOR . $output;
        $parent = realpath(dirname($path));
        $root = realpath($this->siteRoot);
        if ($parent === false || $root === false || !is_dir($parent) || is_link($path)) throw new RuntimeException('Backup directory must already exist and the output cannot be a symlink.');
        $resolved = $parent . DIRECTORY_SEPARATOR . basename($path);
        if (file_exists($resolved)) throw new RuntimeException('Backup output already exists; choose a new path.');
        if ($resolved === $root || str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Database backups must be written outside the public MyBB site directory.');
        }
        if (!is_writable($parent)) throw new RuntimeException('Backup directory is not writable.');
        return $resolved;
    }

    /** @param resource $stream */
    private function write($stream, string $data): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Could not write the database backup.');
            $offset += $written;
        }
    }
}
