<?php
declare(strict_types=1);

// One-time setup on the server (via SSH):  php tools/init.php
// Asks for the database details, creates config.php with fresh keys,
// creates the tables and prints the app key ONCE.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/Crypto.php';

$root = dirname(__DIR__);
$configFile = $root . '/config.php';
if (is_file($configFile)) {
    fwrite(STDERR, "config.php gibt es schon. Zum Neu-Einrichten zuerst löschen.\n");
    exit(1);
}
function ask(string $q, string $default = ''): string
{
    echo $q . ($default !== '' ? " [$default]" : '') . ': ';
    $a = trim((string) fgets(STDIN));
    return $a === '' ? $default : $a;
}
$url = ask('Adresse der App', 'https://place.parto.at');
$host = ask('MySQL-Host', 'localhost');
$db = ask('Datenbankname');
$user = ask('Datenbank-Benutzer');
echo 'Datenbank-Passwort: ';
system('stty -echo 2>/dev/null');
$pass = trim((string) fgets(STDIN));
system('stty echo 2>/dev/null');
echo "\n";

$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (explode(';', (string) file_get_contents($root . '/schema.sql')) as $sql) {
    $clean = trim((string) preg_replace('/--.*$/m', '', $sql));
    if ($clean !== '') {
        $pdo->exec($clean);
    }
}

$appKey = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$config = [
    'app_url' => rtrim($url, '/'),
    'db_dsn' => $dsn,
    'db_user' => $user,
    'db_pass' => $pass,
    'encryption_key' => Place\Crypto::newKey(),
    'app_key_hash' => hash('sha256', $appKey),
    'allowed_origins' => [rtrim($url, '/'), 'tauri://localhost', 'http://tauri.localhost'],
];
file_put_contents($configFile, "<?php\nreturn " . var_export($config, true) . ";\n");
chmod($configFile, 0600);

echo "\nFertig. Tabellen angelegt, config.php geschrieben.\n\n";
echo "Dein App-Schlüssel (nur jetzt sichtbar, in Parto Place unter Mehr > Server eingeben):\n\n  $appKey\n\n";
echo "Bitte nirgends sonst speichern oder weitergeben.\n";
