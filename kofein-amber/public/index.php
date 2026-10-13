<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax', 'path' => '/']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];
$errors = []; $data = []; $user = null; $suppliers = []; $count = 0;
$page = 'error'; $title = 'Страница не найдена';
try {
    if (isset($_SESSION['user_id'])) {
        $user = query('SELECT id, name, email FROM users WHERE id = ?', [$_SESSION['user_id']])->fetch() ?: null;
        if (!$user) unset($_SESSION['user_id']);
    }
    if ($path === '/') redirect($user ? '/dashboard' : '/login');
    $protected = $path === '/dashboard' || $path === '/logout' || str_starts_with($path, '/suppliers') || $path === '/api/suppliers';
    if ($protected && !$user) {
        if ($path === '/api/suppliers') { http_response_code(401); header('Content-Type: application/json'); echo '{"error":"Требуется вход"}'; exit; }
        redirect('/login');
    }
    if (!in_array($method, ['GET', 'POST'], true)) {
        http_response_code(405); header('Allow: GET, POST'); $title = 'Метод не поддерживается';
    } elseif ($method === 'POST' && (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf']))) {
        http_response_code(403); $title = 'Форма устарела'; $errors[] = 'Обновите страницу и повторите действие.';
    } elseif (in_array($path, ['/register', '/login'], true)) {
        if ($user) redirect('/dashboard');
        $page = $path === '/register' ? 'register' : 'login';
        $title = $page === 'register' ? 'Создать аккаунт' : 'С возвращением';
        $data = ['name' => input('name'), 'email' => strtolower(input('email'))];
        if ($method === 'POST') {
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || length($data['email']) > 254) $errors[] = 'Введите корректный email.';
            if ($page === 'register') {
                if ($data['name'] === '' || length($data['name']) > 100) $errors[] = 'Имя: от 1 до 100 символов.';
                if (length($password) < 6 || strlen($password) > 72) $errors[] = 'Пароль: минимум 6 символов и максимум 72 байта.';
                if (!$errors) {
                    try {
                        query('INSERT INTO users (name, email, password) VALUES (?, ?, ?)', [$data['name'], $data['email'], password_hash($password, PASSWORD_DEFAULT)]);
                        flash('Аккаунт создан. Войдите с вашим email и паролем.'); redirect('/login');
                    } catch (PDOException $error) {
                        if (($error->errorInfo[1] ?? null) === 1062) $errors[] = 'Этот email уже зарегистрирован.';
                        else throw $error;
                    }
                }
            } else {
                if ($password === '' || strlen($password) > 72) $errors[] = 'Введите пароль длиной до 72 байт.';
                if (!$errors) {
                    $account = query('SELECT * FROM users WHERE email = ?', [$data['email']])->fetch();
                    if ($account && password_verify($password, $account['password'])) {
                        session_regenerate_id(true); $_SESSION['user_id'] = $account['id'];
                        $_SESSION['csrf'] = bin2hex(random_bytes(32)); redirect('/dashboard');
                    }
                    $errors[] = 'Неверный email или пароль.';
                }
            }
            if ($errors) http_response_code(422);
        }
    } elseif ($path === '/logout' && $method === 'POST') {
        $_SESSION = []; session_destroy();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        redirect('/login');
    } elseif ($path === '/dashboard' && $method === 'GET') {
        $page = 'dashboard'; $title = 'Личный кабинет';
        $count = query('SELECT COUNT(*) FROM suppliers WHERE user_id = ?', [$user['id']])->fetchColumn();
    } elseif (in_array($path, ['/suppliers', '/api/suppliers'], true) && $method === 'GET') {
        $page = 'suppliers'; $title = 'Поставщики';
        $search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 150) : '';
        $suppliers = query('SELECT id, name, contact_person, phone FROM suppliers WHERE user_id = ? AND name LIKE ? ORDER BY id DESC', [$user['id'], '%' . $search . '%'])->fetchAll();
        if ($path === '/api/suppliers') {
            header('Content-Type: application/json; charset=utf-8'); echo json_encode($suppliers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
        }
    } elseif ($path === '/suppliers/create' || preg_match('#^/suppliers/(edit|delete)/([1-9][0-9]{0,9})$#D', $path, $matches)) {
        $action = $path === '/suppliers/create' ? 'create' : $matches[1];
        $id = $action === 'create' ? null : $matches[2];
        $data = $action === 'create' ? ['name' => '', 'contact_person' => '', 'phone' => ''] : query('SELECT * FROM suppliers WHERE id = ? AND user_id = ?', [$id, $user['id']])->fetch();
        if (!$data) { http_response_code(404); $title = 'Поставщик не найден'; }
        elseif ($action === 'delete') {
            if ($method !== 'POST') { http_response_code(405); header('Allow: POST'); $title = 'Удаление доступно только через форму'; }
            else { query('DELETE FROM suppliers WHERE id = ? AND user_id = ?', [$id, $user['id']]); flash('Поставщик удалён.'); redirect('/suppliers'); }
        } else {
            $page = 'supplier-form'; $title = $action === 'create' ? 'Новый поставщик' : 'Редактировать поставщика';
            if ($method === 'POST') {
                $data = ['name' => input('name'), 'contact_person' => input('contact_person'), 'phone' => input('phone')];
                $errors = supplierErrors($data);
                if (!$errors) {
                    $values = [$data['name'], $data['contact_person'] ?: null, $data['phone'] ?: null];
                    if ($action === 'create') query('INSERT INTO suppliers (name, contact_person, phone, user_id) VALUES (?, ?, ?, ?)', [...$values, $user['id']]);
                    else query('UPDATE suppliers SET name = ?, contact_person = ?, phone = ? WHERE id = ? AND user_id = ?', [...$values, $id, $user['id']]);
                    flash($action === 'create' ? 'Поставщик добавлен.' : 'Изменения сохранены.'); redirect('/suppliers');
                }
                http_response_code(422);
            }
        }
    } else { http_response_code(404); }
} catch (Throwable $error) {
    error_log((string)$error); http_response_code(500); $page = 'error'; $title = 'Не удалось выполнить запрос';
    $errors = ['Проверьте подключение к базе данных и выполните миграцию по инструкции README.'];
}
require dirname(__DIR__) . '/app/view.php';
