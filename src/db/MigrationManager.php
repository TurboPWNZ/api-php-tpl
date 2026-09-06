<?php

namespace Api\db;

use PDO;

class MigrationManager
{
    private PDO $pdo;
    private string $migrationsTable = 'migrations';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->migrationsTable} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function getExecutedMigrations(): array
    {
        $stmt = $this->pdo->prepare("SELECT name FROM {$this->migrationsTable} ORDER BY id");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function hasMigration(string $name): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->migrationsTable} WHERE name = ?");
        $stmt->execute([$name]);
        return (bool) $stmt->fetch();
    }

    public function recordMigration(string $name): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO {$this->migrationsTable} (name) VALUES (?)");
        $stmt->execute([$name]);
    }

    public function removeMigration(string $name): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->migrationsTable} WHERE name = ?");
        $stmt->execute([$name]);
    }

    public function run(string $migrationClass, string $direction): void
    {
        $reflection = new \ReflectionClass($migrationClass);
        $instance = $reflection->newInstance();

        if ($direction === 'up') {
            $instance->up($this->pdo);
            $this->recordMigration($reflection->getShortName());
        } else {
            $this->removeMigration($reflection->getShortName());
            $instance->down($this->pdo);
        }
    }
}
