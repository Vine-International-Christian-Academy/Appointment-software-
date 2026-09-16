<?php
declare(strict_types=1);

/**
 * Vine Appointments PHP API
 *
 * This intentionally keeps the same JSON routes used by the React client:
 * /api/auth/*, /api/learning-centers, /api/availability-slots, and
 * /api/appointments.
 */

$config = require __DIR__ . '/config.php';

session_name((string)($config['session_name'] ?? 'vine_appointments_session'));
session_set_cookie_params([
    'lifetime' => (int)($config['session_lifetime'] ?? 86400),
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');

function respond(mixed $payload, int $status = 200): never
{
    http_response_code($status);
    if ($status === 204) {
        exit;
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): never
{
    respond(['message' => $message], $status);
}

function db(): PDO
{
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'],
        (int)$db['port'],
        $db['name'],
    );
    try {
        $pdo = new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (Throwable $error) {
        error_log('[vine-appointments] Database connection failed: ' . $error->getMessage());
        fail('Database connection failed.', 500);
    }
}

function body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function route_path(): string
{
    $path = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    $path = preg_replace('#^api/?#', '', $path) ?? $path;
    return trim($path, '/');
}

function current_user(): ?array
{
    if (empty($_SESSION['staff_username'])) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT id, username, email, learning_center_id, created_at
         FROM staff_users WHERE username = ? LIMIT 1',
    );
    $stmt->execute([$_SESSION['staff_username']]);
    $user = $stmt->fetch();
    if (!$user) {
        unset($_SESSION['staff_username'], $_SESSION['staff_learning_center_id']);
        return null;
    }
    $user['learningCenterId'] = $user['learning_center_id'] !== null
        ? (int)$user['learning_center_id']
        : null;
    return $user;
}

function require_staff(): array
{
    $user = current_user();
    if (!$user) {
        fail('Not authenticated', 401);
    }
    return $user;
}

function require_admin(): array
{
    $user = require_staff();
    if ($user['learningCenterId'] !== null) {
        fail('Supervisors do not have permission for this action', 403);
    }
    return $user;
}

function centre_id(array $user): ?int
{
    return $user['learningCenterId'] ?? null;
}

function find_centre(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT id, name, campus, contact_person AS contactPerson
         FROM learning_centers WHERE id = ? LIMIT 1',
    );
    $stmt->execute([$id]);
    $centre = $stmt->fetch();
    if (!$centre) {
        return null;
    }
    $centre['id'] = (int)$centre['id'];
    return $centre;
}

function seed_admin(): void
{
    global $config;
    $count = (int)db()->query('SELECT COUNT(*) FROM staff_users')->fetchColumn();
    if ($count > 0) {
        return;
    }
    $email = strtolower(trim((string)($config['admin_email'] ?? '')));
    $password = (string)($config['admin_password'] ?? '');
    if ($email === '' || $password === '' || str_contains($password, 'CHANGE_THIS')) {
        return;
    }
    $stmt = db()->prepare(
        'INSERT INTO staff_users (username, password_hash, email) VALUES (?, ?, ?)',
    );
    $stmt->execute([$email, password_hash($password, PASSWORD_BCRYPT), $email]);
}

function appointment(int $requestId, ?int $centreId = null): ?array
{
    $sql = 'SELECT
                r.id, r.parent_name AS parentName, r.parent_email AS parentEmail,
                r.parent_phone AS parentPhone, r.notes, r.status, r.created_at AS createdAt,
                i.id AS itemId, i.availability_slot_id AS availabilitySlotId,
                i.student_name AS studentName, i.learning_center_id AS learningCenterId,
                c.name AS learningCenterName, i.preferred_date AS preferredDate,
                i.preferred_time AS preferredTime, i.reason
            FROM appointment_requests r
            INNER JOIN appointment_items i ON i.appointment_request_id = r.id
            INNER JOIN learning_centers c ON c.id = i.learning_center_id
            WHERE r.id = ?';
    $params = [$requestId];
    if ($centreId !== null) {
        $sql .= ' AND i.learning_center_id = ?';
        $params[] = $centreId;
    }
    $sql .= ' ORDER BY i.preferred_date, i.preferred_time, i.id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return null;
    }

    $first = $rows[0];
    $result = [
        'id' => (int)$first['id'],
        'parentName' => $first['parentName'],
        'parentEmail' => $first['parentEmail'],
        'parentPhone' => $first['parentPhone'],
        'notes' => $first['notes'],
        'status' => $first['status'],
        'createdAt' => $first['createdAt'],
        'appointments' => [],
    ];
    foreach ($rows as $row) {
        $result['appointments'][] = [
            'id' => (int)$row['itemId'],
            'studentName' => $row['studentName'],
            'availabilitySlotId' => $row['availabilitySlotId'] !== null
                ? (int)$row['availabilitySlotId']
                : 0,
            'learningCenterId' => (int)$row['learningCenterId'],
            'learningCenterName' => $row['learningCenterName'],
            'preferredDate' => $row['preferredDate'],
            'preferredTime' => $row['preferredTime'],
            'reason' => $row['reason'],
        ];
    }
    return $result;
}

function available_slot(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT s.id, s.learning_center_id AS learningCenterId,
                c.name AS learningCenterName, c.campus,
                s.date, s.time, s.duration_minutes AS durationMinutes,
                s.is_booked AS isBooked
         FROM appointment_availability_slots s
         INNER JOIN learning_centers c ON c.id = s.learning_center_id
         WHERE s.id = ? LIMIT 1',
    );
    $stmt->execute([$id]);
    $slot = $stmt->fetch();
    if (!$slot) {
        return null;
    }
    $slot['id'] = (int)$slot['id'];
    $slot['learningCenterId'] = (int)$slot['learningCenterId'];
    $slot['durationMinutes'] = (int)$slot['durationMinutes'];
    $slot['isBooked'] = (bool)$slot['isBooked'];
    return $slot;
}

function send_notice(string $to, string $subject, string $message): void
{
    global $config;
    $from = (string)($config['mail_from'] ?? 'appointments@example.org');
    $name = (string)($config['mail_from_name'] ?? 'Vine Appointments');
    $headers = [
        'From: ' . $name . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];
    @mail($to, $subject, $message, implode("\r\n", $headers));
}

function notify_supervisors(array $appointment, string $event): void
{
    $centreIds = array_values(array_unique(array_map(
        static fn(array $item): int => (int)$item['learningCenterId'],
        $appointment['appointments'],
    )));
    if (!$centreIds) {
        return;
    }
    $marks = implode(',', array_fill(0, count($centreIds), '?'));
    $stmt = db()->prepare(
        "SELECT email, username, learning_center_id FROM staff_users
         WHERE learning_center_id IN ($marks) AND COALESCE(email, username) <> ''",
    );
    $stmt->execute($centreIds);
    $recipients = $stmt->fetchAll();
    foreach ($recipients as $recipient) {
        $centreId = (int)$recipient['learning_center_id'];
        $items = array_values(array_filter(
            $appointment['appointments'],
            static fn(array $item): bool => (int)$item['learningCenterId'] === $centreId,
        ));
        if (!$items) {
            continue;
        }
        $lines = [
            'A Vine Appointments meeting was ' . $event . '.',
            '',
            'Parent: ' . $appointment['parentName'],
            'Email: ' . $appointment['parentEmail'],
            'Phone: ' . $appointment['parentPhone'],
            '',
        ];
        foreach ($items as $item) {
            $lines[] = sprintf(
                '%s — %s at %s (%s)',
                $item['studentName'],
                $item['preferredDate'],
                $item['preferredTime'],
                $item['learningCenterName'],
            );
        }
        send_notice(
            (string)($recipient['email'] ?: $recipient['username']),
            'Vine Appointments: meeting ' . $event,
            implode("\n", $lines),
        );
    }
}

function appointment_email(array $appointment, string $event): void
{
    $lines = [
        'Your Vine Appointments booking was ' . $event . '.',
        '',
        'Parent: ' . $appointment['parentName'],
        '',
    ];
    foreach ($appointment['appointments'] as $item) {
        $lines[] = sprintf(
            '%s — %s at %s (%s)',
            $item['studentName'],
            $item['preferredDate'],
            $item['preferredTime'],
            $item['learningCenterName'],
        );
    }
    send_notice((string)$appointment['parentEmail'], 'Vine Appointments booking ' . $event, implode("\n", $lines));
}

function centres_for_staff(?array $user = null): array
{
    $user ??= current_user();
    $where = '';
    $params = [];
    if ($user && $user['learningCenterId'] !== null) {
        $where = ' WHERE id = ?';
        $params[] = $user['learningCenterId'];
    }
    $stmt = db()->prepare(
        "SELECT id, name, campus, contact_person AS contactPerson
         FROM learning_centers{$where} ORDER BY id",
    );
    $stmt->execute($params);
    return array_map(static function (array $centre): array {
        $centre['id'] = (int)$centre['id'];
        return $centre;
    }, $stmt->fetchAll());
}

function list_open_slots(?array $user = null): array
{
    $user ??= current_user();
    $sql = 'SELECT s.id, s.learning_center_id AS learningCenterId,
                   c.name AS learningCenterName, c.campus,
                   s.date, s.time, s.duration_minutes AS durationMinutes,
                   s.is_booked AS isBooked
            FROM appointment_availability_slots s
            INNER JOIN learning_centers c ON c.id = s.learning_center_id
            WHERE s.is_booked = 0';
    $params = [];
    if ($user && $user['learningCenterId'] !== null) {
        $sql .= ' AND s.learning_center_id = ?';
        $params[] = $user['learningCenterId'];
    }
    $sql .= ' ORDER BY s.date, s.time, c.name';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return array_map(static function (array $slot): array {
        $slot['id'] = (int)$slot['id'];
        $slot['learningCenterId'] = (int)$slot['learningCenterId'];
        $slot['durationMinutes'] = (int)$slot['durationMinutes'];
        $slot['isBooked'] = (bool)$slot['isBooked'];
        return $slot;
    }, $stmt->fetchAll());
}

function list_staff_appointments(array $user): array
{
    $sql = $user['learningCenterId'] === null
        ? 'SELECT id FROM appointment_requests ORDER BY created_at DESC'
        : 'SELECT DISTINCT appointment_request_id AS id
           FROM appointment_items WHERE learning_center_id = ?';
    $stmt = db()->prepare($sql);
    $stmt->execute($user['learningCenterId'] === null ? [] : [$user['learningCenterId']]);
    $appointments = [];
    foreach ($stmt->fetchAll() as $row) {
        $item = appointment((int)$row['id'], $user['learningCenterId']);
        if ($item) {
            $appointments[] = $item;
        }
    }
    return $appointments;
}

function summary(array $user): array
{
    $centreId = $user['learningCenterId'];
    $whereRequests = '';
    $params = [];
    if ($centreId !== null) {
        $whereRequests = ' WHERE r.id IN (
            SELECT DISTINCT appointment_request_id FROM appointment_items WHERE learning_center_id = ?
        )';
        $params[] = $centreId;
    }
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS totalRequests,
                SUM(status = 'pending') AS pendingRequests,
                SUM(status = 'confirmed') AS confirmedRequests
         FROM appointment_requests r{$whereRequests}",
    );
    $stmt->execute($params);
    $totals = $stmt->fetch() ?: [];

    $studentSql = 'SELECT COUNT(*) FROM appointment_items';
    $studentParams = [];
    if ($centreId !== null) {
        $studentSql .= ' WHERE learning_center_id = ?';
        $studentParams[] = $centreId;
    }
    $totalStudents = db()->prepare($studentSql);
    $totalStudents->execute($studentParams);

    $centreSql = 'SELECT c.name AS learningCenterName, COUNT(i.id) AS count
                  FROM learning_centers c
                  LEFT JOIN appointment_items i ON i.learning_center_id = c.id';
    $centreParams = [];
    if ($centreId !== null) {
        $centreSql .= ' WHERE c.id = ?';
        $centreParams[] = $centreId;
    }
    $centreSql .= ' GROUP BY c.id ORDER BY c.id';
    $centreQuery = db()->prepare($centreSql);
    $centreQuery->execute($centreParams);

    $slotSql = 'SELECT COUNT(*) FROM appointment_availability_slots WHERE is_booked = 0';
    $slotParams = [];
    if ($centreId !== null) {
        $slotSql .= ' AND learning_center_id = ?';
        $slotParams[] = $centreId;
    }
    $slotQuery = db()->prepare($slotSql);
    $slotQuery->execute($slotParams);

    return [
        'totalRequests' => (int)($totals['totalRequests'] ?? 0),
        'pendingRequests' => (int)($totals['pendingRequests'] ?? 0),
        'confirmedRequests' => (int)($totals['confirmedRequests'] ?? 0),
        'totalStudents' => (int)$totalStudents->fetchColumn(),
        'availableSlots' => (int)$slotQuery->fetchColumn(),
        'learningCenterCounts' => array_map(
            static fn(array $row): array => [
                'learningCenterName' => $row['learningCenterName'],
                'count' => (int)$row['count'],
            ],
            $centreQuery->fetchAll(),
        ),
    ];
}

function validate_email(string $email): string
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('A valid email address is required');
    }
    return $email;
}

function create_slot(array $input, array $user): array
{
    $requestedCentre = (int)($input['learningCenterId'] ?? 0);
    $effectiveCentre = $user['learningCenterId'] ?? $requestedCentre;
    if (!$effectiveCentre) {
        fail('Learning center is required');
    }
    if ($user['learningCenterId'] !== null && $requestedCentre !== $user['learningCenterId']) {
        fail('You can only add availability to your assigned learning centre', 403);
    }
    $date = (string)($input['date'] ?? '');
    $time = (string)($input['time'] ?? '');
    $duration = (int)($input['durationMinutes'] ?? 30);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
        fail('A valid date and time are required');
    }
    if ($duration < 5) {
        fail('Duration must be at least 5 minutes');
    }
    if (!find_centre($effectiveCentre)) {
        fail('Learning center not found', 404);
    }
    $stmt = db()->prepare(
        'INSERT INTO appointment_availability_slots
         (learning_center_id, date, time, duration_minutes) VALUES (?, ?, ?, ?)',
    );
    $stmt->execute([$effectiveCentre, $date, $time, $duration]);
    return available_slot((int)db()->lastInsertId()) ?? [];
}

seed_admin();

$route = route_path();
$verb = method();
$input = body();

try {
    if ($route === 'healthz' && $verb === 'GET') {
        respond(['status' => 'ok']);
    }

    if ($route === 'auth/login' && $verb === 'POST') {
        $email = validate_email((string)($input['email'] ?? ''));
        $stmt = db()->prepare('SELECT * FROM staff_users WHERE username = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify((string)($input['password'] ?? ''), $user['password_hash'])) {
            fail('Invalid username or password', 401);
        }
        session_regenerate_id(true);
        $_SESSION['staff_username'] = $user['username'];
        $_SESSION['staff_learning_center_id'] = $user['learning_center_id'];
        $centreName = null;
        if ($user['learning_center_id'] !== null) {
            $centreName = find_centre((int)$user['learning_center_id'])['name'] ?? null;
        }
        respond([
            'username' => $user['username'],
            'email' => $user['email'] ?: $user['username'],
            'learningCenterId' => $user['learning_center_id'] !== null ? (int)$user['learning_center_id'] : null,
            'learningCenterName' => $centreName,
        ]);
    }

    if ($route === 'auth/me' && $verb === 'GET') {
        $user = require_staff();
        $centreName = $user['learningCenterId'] !== null
            ? (find_centre($user['learningCenterId'])['name'] ?? null)
            : null;
        respond([
            'username' => $user['username'],
            'email' => $user['email'] ?: $user['username'],
            'learningCenterId' => $user['learningCenterId'],
            'learningCenterName' => $centreName,
        ]);
    }

    if ($route === 'auth/logout' && $verb === 'POST') {
        $_SESSION = [];
        session_destroy();
        respond(['message' => 'Logged out']);
    }

    if ($route === 'auth/forgot-password' && $verb === 'POST') {
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $message = 'If an account exists for that email, a reset code has been sent.';
        if ($email !== '') {
            $stmt = db()->prepare('SELECT email, username FROM staff_users WHERE username = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user) {
                $code = (string)random_int(100000, 999999);
                $_SESSION['password_reset'] = [
                    'email' => $email,
                    'code' => $code,
                    'expires' => time() + 600,
                ];
                send_notice(
                    (string)($user['email'] ?: $user['username']),
                    'Vine Appointments password reset',
                    "Your password reset code is {$code}. It expires in 10 minutes.",
                );
            }
        }
        respond(['message' => $message]);
    }

    if ($route === 'auth/reset-password' && $verb === 'POST') {
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $reset = $_SESSION['password_reset'] ?? null;
        if (
            !$reset ||
            $reset['email'] !== $email ||
            !hash_equals((string)$reset['code'], trim((string)($input['code'] ?? ''))) ||
            time() > (int)$reset['expires'] ||
            strlen((string)($input['password'] ?? '')) < 8
        ) {
            fail('Invalid or expired reset code.');
        }
        $stmt = db()->prepare('UPDATE staff_users SET password_hash = ? WHERE username = ?');
        $stmt->execute([password_hash((string)$input['password'], PASSWORD_BCRYPT), $email]);
        unset($_SESSION['password_reset']);
        respond(['message' => 'Password reset successfully. You can now sign in.']);
    }

    if ($route === 'auth/users' && $verb === 'GET') {
        require_admin();
        $rows = db()->query(
            'SELECT u.id, u.username, u.email, u.learning_center_id AS learningCenterId,
                    u.created_at AS createdAt, c.name AS learningCenterName
             FROM staff_users u
             LEFT JOIN learning_centers c ON c.id = u.learning_center_id
             ORDER BY u.created_at',
        )->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['learningCenterId'] = $row['learningCenterId'] !== null ? (int)$row['learningCenterId'] : null;
        }
        respond($rows);
    }

    if ($route === 'auth/users' && $verb === 'POST') {
        require_admin();
        $email = validate_email((string)($input['email'] ?? $input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        if (strlen($password) < 8) {
            fail('Password must be at least 8 characters');
        }
        $centreId = isset($input['learningCenterId']) && $input['learningCenterId'] !== null
            ? (int)$input['learningCenterId']
            : null;
        if ($centreId !== null && !find_centre($centreId)) {
            fail('Learning center not found', 404);
        }
        try {
            $stmt = db()->prepare(
                'INSERT INTO staff_users (username, password_hash, email, learning_center_id)
                 VALUES (?, ?, ?, ?)',
            );
            $stmt->execute([$email, password_hash($password, PASSWORD_BCRYPT), $email, $centreId]);
        } catch (PDOException $error) {
            if ((int)$error->errorInfo[1] === 1062) {
                fail('An account with that email already exists', 409);
            }
            throw $error;
        }
        $id = (int)db()->lastInsertId();
        respond([
            'id' => $id,
            'username' => $email,
            'email' => $email,
            'learningCenterId' => $centreId,
            'learningCenterName' => $centreId !== null ? find_centre($centreId)['name'] : null,
            'createdAt' => date('Y-m-d H:i:s'),
        ], 201);
    }

    if (preg_match('#^auth/users/(\d+)/password$#', $route, $matches) && $verb === 'PATCH') {
        require_admin();
        $password = (string)($input['password'] ?? '');
        if (strlen($password) < 8) {
            fail('Password must be at least 8 characters');
        }
        $stmt = db()->prepare('UPDATE staff_users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_BCRYPT), (int)$matches[1]]);
        if ($stmt->rowCount() < 1) {
            fail('User not found', 404);
        }
        respond(['message' => 'Password updated']);
    }

    if (preg_match('#^auth/users/(\d+)$#', $route, $matches) && $verb === 'DELETE') {
        $user = require_admin();
        $stmt = db()->prepare('SELECT username FROM staff_users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$matches[1]]);
        $target = $stmt->fetch();
        if (!$target) {
            fail('User not found', 404);
        }
        if ($target['username'] === $user['username']) {
            fail('You cannot delete your own account');
        }
        db()->prepare('DELETE FROM staff_users WHERE id = ?')->execute([(int)$matches[1]]);
        respond(null, 204);
    }

    if ($route === 'auth/learning-centers' && $verb === 'GET') {
        $user = require_staff();
        respond(centres_for_staff($user));
    }

    if ($route === 'learning-centers' && $verb === 'GET') {
        respond(centres_for_staff());
    }

    if ($route === 'learning-centers' && $verb === 'POST') {
        require_admin();
        $name = trim((string)($input['name'] ?? ''));
        $campus = trim((string)($input['campus'] ?? ''));
        $contact = trim((string)($input['contactPerson'] ?? ''));
        if ($name === '' || $campus === '' || $contact === '') {
            fail('Name, campus, and contact person are required');
        }
        $stmt = db()->prepare(
            'INSERT INTO learning_centers (name, campus, contact_person) VALUES (?, ?, ?)',
        );
        $stmt->execute([$name, $campus, $contact]);
        respond(find_centre((int)db()->lastInsertId()), 201);
    }

    if (preg_match('#^learning-centers/(\d+)$#', $route, $matches) && $verb === 'PATCH') {
        require_admin();
        $fields = [];
        $values = [];
        foreach (['name', 'campus'] as $field) {
            if (array_key_exists($field, $input)) {
                $fields[] = "{$field} = ?";
                $values[] = trim((string)$input[$field]);
            }
        }
        if (array_key_exists('contactPerson', $input)) {
            $fields[] = 'contact_person = ?';
            $values[] = trim((string)$input['contactPerson']);
        }
        if (!$fields) {
            fail('No fields to update');
        }
        $values[] = (int)$matches[1];
        $stmt = db()->prepare('UPDATE learning_centers SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($values);
        $centre = find_centre((int)$matches[1]);
        if (!$centre) {
            fail('Learning center not found', 404);
        }
        respond($centre);
    }

    if ($route === 'availability-slots' && $verb === 'GET') {
        respond(list_open_slots());
    }

    if ($route === 'availability-slots' && $verb === 'POST') {
        $user = require_staff();
        respond(create_slot($input, $user), 201);
    }

    if ($route === 'availability-slots/bulk' && $verb === 'POST') {
        $user = require_staff();
        $start = new DateTimeImmutable((string)($input['startDate'] ?? ''));
        $end = new DateTimeImmutable((string)($input['endDate'] ?? ''));
        if ($end < $start) {
            fail('End date must be on or after the start date');
        }
        $days = array_map('intval', $input['daysOfWeek'] ?? [1, 2, 3, 4, 5]);
        $times = is_array($input['times'] ?? null)
            ? array_values(array_filter(array_map('strval', $input['times'])))
            : [(string)($input['time'] ?? '')];
        if (!$times) {
            fail('At least one meeting time is required');
        }
        $blockedDates = array_fill_keys(
            array_map(
                static fn(mixed $date): string => (new DateTimeImmutable((string)$date))->format('Y-m-d'),
                is_array($input['excludedDates'] ?? null) ? $input['excludedDates'] : [],
            ),
            true,
        );
        $blockedSlots = [];
        foreach (is_array($input['excludedSlots'] ?? null) ? $input['excludedSlots'] : [] as $blockedSlot) {
            if (is_array($blockedSlot) && isset($blockedSlot['date'], $blockedSlot['time'])) {
                $blockedSlots[$blockedSlot['date'] . '|' . $blockedSlot['time']] = true;
            }
        }
        $created = [];
        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            if (
                !in_array((int)$date->format('w'), $days, true) ||
                isset($blockedDates[$date->format('Y-m-d')])
            ) {
                continue;
            }
            foreach ($times as $time) {
                if (isset($blockedSlots[$date->format('Y-m-d') . '|' . $time])) {
                    continue;
                }
                $slotInput = $input;
                $slotInput['date'] = $date->format('Y-m-d');
                $slotInput['time'] = $time;
                $created[] = create_slot($slotInput, $user);
            }
        }
        respond(['created' => count($created), 'slots' => $created], 201);
    }

    if (preg_match('#^availability-slots/(\d+)$#', $route, $matches) && $verb === 'DELETE') {
        $user = require_staff();
        $slot = available_slot((int)$matches[1]);
        if (!$slot) {
            fail('Slot not found', 404);
        }
        if ($user['learningCenterId'] !== null && $slot['learningCenterId'] !== $user['learningCenterId']) {
            fail('You can only manage availability in your assigned learning centre', 403);
        }
        if ($slot['isBooked']) {
            fail('Cannot delete a slot that has already been booked', 409);
        }
        db()->prepare('DELETE FROM appointment_availability_slots WHERE id = ?')->execute([(int)$matches[1]]);
        respond(null, 204);
    }

    if ($route === 'appointments' && $verb === 'GET') {
        respond(list_staff_appointments(require_staff()));
    }

    if ($route === 'appointments' && $verb === 'POST') {
        $parentName = trim((string)($input['parentName'] ?? ''));
        $parentEmail = validate_email((string)($input['parentEmail'] ?? ''));
        $parentPhone = trim((string)($input['parentPhone'] ?? ''));
        $items = $input['appointments'] ?? [];
        if ($parentName === '' || $parentPhone === '' || !is_array($items) || count($items) < 1) {
            fail('Parent details and at least one appointment are required');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $request = $pdo->prepare(
                'INSERT INTO appointment_requests (parent_name, parent_email, parent_phone, notes, status)
                 VALUES (?, ?, ?, ?, "confirmed")',
            );
            $request->execute([$parentName, $parentEmail, $parentPhone, (string)($input['notes'] ?? '')]);
            $requestId = (int)$pdo->lastInsertId();
            $insertItem = $pdo->prepare(
                'INSERT INTO appointment_items
                 (appointment_request_id, availability_slot_id, student_name, grade_level,
                  learning_center_id, preferred_date, preferred_time, reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $lockSlot = $pdo->prepare(
                'SELECT * FROM appointment_availability_slots WHERE id = ? AND is_booked = 0 FOR UPDATE',
            );
            $bookSlot = $pdo->prepare(
                'UPDATE appointment_availability_slots SET is_booked = 1 WHERE id = ?',
            );
            foreach ($items as $item) {
                $slotId = (int)($item['availabilitySlotId'] ?? 0);
                $centreId = (int)($item['learningCenterId'] ?? 0);
                $lockSlot->execute([$slotId]);
                $slot = $lockSlot->fetch();
                if (!$slot || (int)$slot['learning_center_id'] !== $centreId) {
                    throw new RuntimeException('One or more selected appointment times are no longer available.');
                }
                $bookSlot->execute([$slotId]);
                $insertItem->execute([
                    $requestId,
                    $slotId,
                    trim((string)($item['studentName'] ?? '')),
                    (string)$centreId,
                    $centreId,
                    $slot['date'],
                    $slot['time'],
                    trim((string)($item['reason'] ?? '')),
                ]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            fail($error->getMessage(), 409);
        }
        $created = appointment($requestId);
        if (!$created) {
            fail('Appointment could not be created', 500);
        }
        appointment_email($created, 'confirmed');
        notify_supervisors($created, 'created');
        respond($created, 201);
    }

    if (preg_match('#^appointments/(\d+)/status$#', $route, $matches) && $verb === 'PATCH') {
        require_admin();
        $status = (string)($input['status'] ?? '');
        if (!in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
            fail('Invalid appointment status');
        }
        $stmt = db()->prepare('UPDATE appointment_requests SET status = ? WHERE id = ?');
        $stmt->execute([$status, (int)$matches[1]]);
        $updated = appointment((int)$matches[1]);
        if (!$updated) {
            fail('Appointment request not found', 404);
        }
        if ($status === 'cancelled') {
            appointment_email($updated, 'cancelled');
            notify_supervisors($updated, 'cancelled');
        } elseif ($status === 'confirmed') {
            appointment_email($updated, 'confirmed');
        }
        respond($updated);
    }

    if ($route === 'appointment-summary' && $verb === 'GET') {
        respond(summary(require_staff()));
    }

    if ($route === 'auth/staff-emails' && $verb === 'GET') {
        require_admin();
        respond(db()->query(
            'SELECT id, email, added_at AS addedAt FROM staff_emails ORDER BY added_at',
        )->fetchAll());
    }

    if ($route === 'auth/staff-emails' && $verb === 'POST') {
        require_admin();
        $email = validate_email((string)($input['email'] ?? ''));
        try {
            $stmt = db()->prepare('INSERT INTO staff_emails (email) VALUES (?)');
            $stmt->execute([$email]);
        } catch (PDOException $error) {
            if ((int)$error->errorInfo[1] === 1062) {
                fail('Email already exists', 409);
            }
            throw $error;
        }
        respond([
            'id' => (int)db()->lastInsertId(),
            'email' => $email,
            'addedAt' => date('Y-m-d H:i:s'),
        ], 201);
    }

    if (preg_match('#^auth/staff-emails/(\d+)$#', $route, $matches) && $verb === 'DELETE') {
        require_admin();
        db()->prepare('DELETE FROM staff_emails WHERE id = ?')->execute([(int)$matches[1]]);
        respond(null, 204);
    }

    fail('Not found', 404);
} catch (Throwable $error) {
    error_log('[vine-appointments] API error: ' . $error->getMessage());
    fail('Server error', 500);
}