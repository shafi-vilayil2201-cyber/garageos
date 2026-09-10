<?php

$pdo = require __DIR__ . '/../config/database.php';

$migrationsPath = __DIR__ . '/migrations';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS migrations (
        id BIGSERIAL PRIMARY KEY,
        migration VARCHAR(255) NOT NULL UNIQUE,
        executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

$migrations = glob($migrationsPath . '/*.sql');

sort($migrations);

foreach ($migrations as $migration) {
    $migrationName = basename($migration);

    $statement = $pdo->prepare(
        'SELECT 1 FROM migrations WHERE migration = :migration'
    );

    $statement->execute([
        'migration' => $migrationName
    ]);

    if ($statement->fetch()) {
        echo "Skipping: {$migrationName}" . PHP_EOL;
        continue;
    }

    echo "Running: {$migrationName}" . PHP_EOL;

    $sql = file_get_contents($migration);

    $pdo->beginTransaction();

    try {
        $pdo->exec($sql);

        $statement = $pdo->prepare(
            'INSERT INTO migrations (migration) VALUES (:migration)'
        );

        $statement->execute([
            'migration' => $migrationName
        ]);

        $pdo->commit();

        echo "Completed: {$migrationName}" . PHP_EOL;

    } catch (Throwable $e) {
        $pdo->rollBack();

        echo "Failed: {$migrationName}" . PHP_EOL;
        echo $e->getMessage() . PHP_EOL;

        exit(1);
    }
}

echo "All migrations completed successfully." . PHP_EOL;
