<?php

// The other request of DesignApprovalRaceTest, in its own process and connection: it takes the
// project lock, says so, keeps it while the test runs into it, then commits its rows and lets go.

$job = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$db = $job['database'];

$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']};charset=utf8mb4",
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$pdo->beginTransaction();
$pdo->prepare('SELECT id FROM video_projects WHERE id = ? FOR UPDATE')->execute([$job['project']]);

fwrite(STDOUT, "locked\n");
fflush(STDOUT);
usleep((int) $job['hold_ms'] * 1000);

foreach ($job['rows'] as [$table, $row]) {
    $columns = implode(', ', array_map(static fn (string $column): string => "`{$column}`", array_keys($row)));
    $marks = implode(', ', array_fill(0, count($row), '?'));
    $pdo->prepare("INSERT INTO `{$table}` ({$columns}) VALUES ({$marks})")->execute(array_values($row));
}

foreach ($job['updates'] ?? [] as [$sql, $bindings]) {
    $pdo->prepare($sql)->execute($bindings);
}

$pdo->commit();
