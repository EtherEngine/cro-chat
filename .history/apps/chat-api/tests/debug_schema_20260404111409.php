<?php
$workspaceDir = dirname(__DIR__, 2);
$schemaPath = $workspaceDir . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';
echo "Path: $schemaPath\n";
echo "Exists: " . (file_exists($schemaPath) ? 'yes' : 'no') . "\n\n";

$schema = file_get_contents($schemaPath);
$schema = ltrim($schema, "\xEF\xBB\xBF");
$schema = preg_replace('/^CREATE DATABASE .+$/m', '', $schema);
$schema = preg_replace('/^USE .+$/m', '', $schema);

$stmts = preg_split('/;\s*$/m', $schema);
foreach ($stmts as $i => $s) {
    $s = trim($s);
    if ($s === '' || str_starts_with($s, '--'))
        continue;
    echo "[$i] " . substr($s, 0, 80) . "\n\n";
}
