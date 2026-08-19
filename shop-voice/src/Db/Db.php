<?php
declare(strict_types=1);

namespace ShopVoice\Db;

use PDO;
use ShopVoice\Support\Clock;

/**
 * Thin PDO wrapper plus a file-based migration runner.
 *
 * Production is MySQL on cPanel; the test suite runs the same DDL on SQLite.
 * The schema sticks to a portable subset so no dialect translation is needed
 * (see the header of 001_init.sql).
 */
final class Db
{
    private PDO $pdo;

    public function __construct(string $dsn, ?string $user = null, ?string $pass = null)
    {
        $this->pdo = new PDO($dsn, $user ?: null, $pass ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if ($this->driver() === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** @param array<string,mixed> $params */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): void
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);
        $this->run(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $table,
                implode(', ', $columns),
                implode(', ', $placeholders)
            ),
            $data
        );
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = "{$column} = :set_{$column}";
            $params["set_{$column}"] = $value;
        }
        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = "{$column} = :where_{$column}";
            $params["where_{$column}"] = $value;
        }

        return $this->run(
            sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $set), implode(' AND ', $conditions)),
            $params
        )->rowCount();
    }

    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Apply any migration files not yet recorded, in filename order.
     *
     * @return list<string> names of migrations applied by this call
     */
    public function migrate(?string $dir = null, ?Clock $clock = null): array
    {
        $dir ??= __DIR__ . '/migrations';
        $clock ??= new Clock();

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                name       VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at VARCHAR(32) NOT NULL
            )'
        );

        $applied = array_column($this->all('SELECT name FROM migrations'), 'name');
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);

        $ran = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            foreach (self::statements((string) file_get_contents($file)) as $statement) {
                $this->pdo->exec($statement);
            }
            $this->insert('migrations', ['name' => $name, 'applied_at' => $clock->iso()]);
            $ran[] = $name;
        }

        return $ran;
    }

    /**
     * Split a .sql file into executable statements.
     *
     * Walks the text rather than exploding on ";" so that a semicolon or an
     * apostrophe inside a comment or a string literal cannot cut a statement in
     * half — the schema's own comments contain both.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $out = [];
        $current = '';
        $length = strlen($sql);
        $inString = false;
        $inComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inComment) {
                if ($char === "\n") {
                    $inComment = false;
                    $current .= $char;
                }
                continue;
            }

            if ($inString) {
                $current .= $char;
                if ($char === "'") {
                    if ($next === "'") {      // escaped quote inside the literal
                        $current .= $next;
                        $i++;
                    } else {
                        $inString = false;
                    }
                }
                continue;
            }

            if ($char === '-' && $next === '-') {
                $inComment = true;
                $i++;
                continue;
            }

            if ($char === "'") {
                $inString = true;
                $current .= $char;
                continue;
            }

            if ($char === ';') {
                $statement = trim($current);
                if ($statement !== '') {
                    $out[] = $statement;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $tail = trim($current);
        if ($tail !== '') {
            $out[] = $tail;
        }

        return $out;
    }
}
