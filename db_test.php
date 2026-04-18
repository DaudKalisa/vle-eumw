<?php
header('Content-Type: text/plain');
set_time_limit(15);
error_reporting(E_ALL);
ini_set('display_errors', 1);

$user = 'u615976264_vle';
$pass = 'kalisa3283';
$db   = 'u615976264_vle';

echo "=== Quick Port Check ===\n";
$sock = @fsockopen('127.0.0.1', 3306, $errno, $errstr, 2);
if ($sock) { echo "Port 3306 OPEN\n"; fclose($sock); }
else { echo "Port 3306 CLOSED ($errno: $errstr)\n"; }

echo "\n=== Socket Connection (no TCP) ===\n";
// Force socket-only connection - this bypasses TCP entirely
$c = mysqli_init();
$c->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
$ok = @$c->real_connect(null, $user, $pass, $db, 0, '/var/lib/mysql/mysql.sock');
if ($c->connect_errno) {
    echo "/var/lib/mysql/mysql.sock ERR({$c->connect_errno}): {$c->connect_error}\n";
} else {
    echo "/var/lib/mysql/mysql.sock OK! Tables: ";
    $r = $c->query("SHOW TABLES");
    echo ($r ? $r->num_rows : 0) . "\n";
    $c->close();
    echo "\n>>> USE THIS IN CONFIG: DB_HOST = null, socket = /var/lib/mysql/mysql.sock\n";
    echo "DONE\n";
    exit;
}

$c2 = mysqli_init();
$c2->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
$ok = @$c2->real_connect(null, $user, $pass, $db, 0, '/tmp/mysql.sock');
if ($c2->connect_errno) {
    echo "/tmp/mysql.sock ERR({$c2->connect_errno}): {$c2->connect_error}\n";
} else {
    echo "/tmp/mysql.sock OK! Tables: ";
    $r = $c2->query("SHOW TABLES");
    echo ($r ? $r->num_rows : 0) . "\n";
    $c2->close();
    echo "\n>>> USE THIS IN CONFIG: DB_HOST = null, socket = /tmp/mysql.sock\n";
    echo "DONE\n";
    exit;
}

echo "\n=== Try ini default socket ===\n";
echo "Default socket: " . ini_get('mysqli.default_socket') . "\n";
echo "Default host: " . ini_get('mysqli.default_host') . "\n";
echo "Default port: " . ini_get('mysqli.default_port') . "\n";

// Try with just localhost via default socket
$c3 = @new mysqli('localhost', $user, $pass, $db);
echo "localhost default: ";
if ($c3->connect_errno) {
    echo "ERR({$c3->connect_errno}): {$c3->connect_error}\n";
} else {
    echo "OK!\n";
    $c3->close();
}

echo "\nDONE\n";
