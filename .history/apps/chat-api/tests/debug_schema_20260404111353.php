<?php
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$schema = ltrim($schema, "\xEF\xBB\xBF");
$schema = preg_replace('/^CREATE DATABASE .+$/m', '', $schema);
$schema = preg_replace('/^USE .+$/m', '', $schema);

$stmts = preg_split('/;\s*$/m', $schema);
foreach ($stmts as $i => $s) {
    $s = trim($s);
    if ($s === '' || str_starts_with($s, '--')) continue;
    echo "[$i] " . substr($s, 0, 80) . "\n\n";
}
