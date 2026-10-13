<?php
declare(strict_types=1);

function db(): PDO
{
    static $connection;
    if (!$connection) {
        $file = dirname(__DIR__) . '/config.php';
        if (!is_file($file)) throw new RuntimeException('Copy config.example.php to config.php first.');
        $config = require $file;
        $connection = new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4",
            $config['username'], $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $connection;
}

function query(string $sql, array $parameters = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($parameters);
    return $statement;
}

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function input(string $key): string { return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : ''; }
function length(string $value): int { return mb_strlen($value, 'UTF-8'); }
function redirect(string $path): never { header('Location: ' . $path, true, 303); exit; }
function csrf(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function flash(string $message): void { $_SESSION['flash'] = $message; }
function supplierErrors(array $data): array
{
    $errors = [];
    if ($data['name'] === '' || length($data['name']) > 150) $errors[] = 'Название компании: от 1 до 150 символов.';
    if (length($data['contact_person']) > 100) $errors[] = 'Контактное лицо: не более 100 символов.';
    if ($data['phone'] !== '' && !preg_match('/^[+0-9() .-]{5,30}$/D', $data['phone'])) $errors[] = 'Телефон: 5–30 символов, цифры, пробелы, +, скобки, точка или дефис.';
    return $errors;
}
