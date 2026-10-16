<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Oportuniza Bié');
}

$timezone = getenv('APP_TIMEZONE') ?: 'Africa/Luanda';
date_default_timezone_set($timezone);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    session_name('oportuniza_bie_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/database.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(string $path = ''): string
{
    $prefix = $GLOBALS['assetPrefix'] ?? '';
    return $prefix . ltrim($path, '/');
}

function redirect_to(string $path): never
{
    header('Location: ' . base_url($path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($messages) ? $messages : [];
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_is_valid(): bool
{
    $submitted = $_POST['_csrf'] ?? '';
    return is_string($submitted)
        && $submitted !== ''
        && isset($_SESSION['_csrf_token'])
        && hash_equals((string) $_SESSION['_csrf_token'], $submitted);
}

function current_user(): ?array
{
    $user = $_SESSION['user'] ?? null;
    return is_array($user) ? $user : null;
}

function has_role(string|array $roles): bool
{
    $required = (array) $roles;
    $userRoles = $_SESSION['user_roles'] ?? [];
    if (!is_array($userRoles)) {
        return false;
    }
    return count(array_intersect($required, $userRoles)) > 0;
}

function login_user(array $user, array $roles): void
{
    session_regenerate_id(true);
    unset($_SESSION['_csrf_token']);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'full_name' => (string) $user['full_name'],
        'email' => (string) $user['email'],
        'phone' => (string) ($user['phone'] ?? ''),
    ];
    $_SESSION['user_roles'] = array_values(array_unique($roles));
    csrf_token();
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function require_login(): void
{
    if (!current_user()) {
        flash('warning', 'Inicia sessão para continuar.');
        redirect_to('auth/entrar.php');
    }
}

function require_role(string|array $roles): void
{
    require_login();
    if (!has_role($roles)) {
        http_response_code(403);
        exit('Não tens permissão para executar esta acção.');
    }
}

function db_or_null(): ?PDO
{
    static $attempted = false;
    static $connection = null;
    if ($attempted) {
        return $connection;
    }
    $attempted = true;
    try {
        $connection = db();
    } catch (Throwable $exception) {
        error_log('Oportuniza Bié: base de dados indisponível: ' . $exception->getMessage());
        $connection = null;
    }
    return $connection;
}

function format_aoa(mixed $amount): string
{
    return number_format((float) $amount, 2, ',', '.') . ' Kz';
}

function allowed_date(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : null;
}

function compatibility_score(?string $candidateSkills, ?string $candidateEducation, string $jobText): ?int
{
    $profile = trim((string) $candidateSkills . ' ' . (string) $candidateEducation);
    if ($profile === '') {
        return null;
    }
    $words = preg_split('/[^\p{L}\p{N}]+/u', mb_lower_safe($profile)) ?: [];
    $words = array_values(array_unique(array_filter($words, static fn ($word) => strlen((string) $word) >= 4)));
    if ($words === []) {
        return null;
    }
    $job = mb_lower_safe($jobText);
    $matched = 0;
    foreach ($words as $word) {
        if (str_contains($job, $word)) {
            $matched++;
        }
    }
    return (int) round(($matched / count($words)) * 100);
}

function mb_lower_safe(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function role_label(string $role): string
{
    return [
        'candidate' => 'Candidato a emprego',
        'employer' => 'Empresa/empregador',
        'service_provider' => 'Prestador de serviços',
        'client' => 'Cliente',
        'seller' => 'Vendedor',
        'buyer' => 'Comprador',
        'admin' => 'Administrador',
    ][$role] ?? 'Utilizador';
}

function listing_report_form(string $type, int $id): string
{
    if (!current_user()) {
        return '';
    }
    return '<details class="report-details"><summary>Denunciar anúncio</summary><form method="post" class="stack-form compact-form">'
        . csrf_field()
        . '<input type="hidden" name="action" value="report"><input type="hidden" name="reported_type" value="' . e($type) . '"><input type="hidden" name="reported_id" value="' . $id . '">'
        . '<label>Motivo<select name="reason" required><option value="">Selecciona</option><option>Informação falsa</option><option>Possível fraude</option><option>Conteúdo inadequado</option><option>Outro motivo</option></select></label>'
        . '<label>Detalhes (opcional)<textarea name="details" rows="2" maxlength="300"></textarea></label><button class="btn btn-outline btn-small" type="submit">Enviar denúncia</button></form></details>';
}

function write_audit_log(PDO $pdo, ?int $userId, string $action, ?string $entityType = null, ?int $entityId = null): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $action, $entityType, $entityId, substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null]);
    } catch (Throwable $exception) {
        error_log('Não foi possível gravar auditoria: ' . $exception->getMessage());
    }
}

function handle_report_submission(PDO $pdo, string $redirectPath): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'report') {
        return false;
    }
    if (!current_user()) {
        flash('warning', 'Inicia sessão para denunciar um anúncio.');
        redirect_to('auth/entrar.php');
    }
    if (!csrf_is_valid()) {
        flash('danger', 'O formulário expirou. Actualiza a página e tenta novamente.');
        redirect_to($redirectPath);
    }
    $type = (string) ($_POST['reported_type'] ?? '');
    $id = filter_var($_POST['reported_id'] ?? null, FILTER_VALIDATE_INT);
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $details = trim((string) ($_POST['details'] ?? ''));
    if (!in_array($type, ['job', 'task', 'service', 'product'], true) || !$id || $id < 1 || $reason === '' || strlen($reason) > 180 || strlen($details) > 3000) {
        flash('danger', 'Os dados da denúncia são inválidos.');
        redirect_to($redirectPath);
    }
    $reportTables = ['job' => 'job_vacancies', 'task' => 'tasks', 'service' => 'service_listings', 'product' => 'products'];
    $check = $pdo->prepare('SELECT id FROM ' . $reportTables[$type] . ' WHERE id = ? LIMIT 1');
    $check->execute([$id]);
    if (!$check->fetchColumn()) {
        flash('warning', 'O anúncio que tentaste denunciar já não existe.');
        redirect_to($redirectPath);
    }
    $stmt = $pdo->prepare('INSERT INTO reports (reporter_id, reported_type, reported_id, reason, details) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([current_user()['id'], $type, $id, $reason, $details ?: null]);
    write_audit_log($pdo, (int) current_user()['id'], 'report_created', $type, (int) $id);
    flash('success', 'A denúncia foi registada e será analisada pela administração.');
    redirect_to($redirectPath);
}
