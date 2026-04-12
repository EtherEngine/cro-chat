<?php
// Test: does mysql CLI work with proc_open approach?
$mysqlBin = 'C:\\xampp\\mysql\\bin\\mysql.exe';
$host = '127.0.0.1';
$port = '3306';
$user = 'root';
$pass = '';

$sql = "SELECT 1;\n";
$tmpFile = tempnam(sys_get_temp_dir(), 'cro_test_') . '.sql';
file_put_contents($tmpFile, $sql);

echo "Trying exec approach...\n";
$passArg = '';
$cmd = sprintf('"%s" -h %s -P %s -u %s %s --force < "%s"', $mysqlBin, $host, $port, $user, $passArg, $tmpFile);
echo "CMD: $cmd\n";

$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
echo "Exit code: $exitCode\n";
echo "Output: " . implode("\n", $output) . "\n";

@unlink($tmpFile);
echo "Done.\n";
