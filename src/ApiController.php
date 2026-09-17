<?php

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
});
set_error_handler(function (int $errno, string $errstr) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $errstr]);
    exit;
});

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/Auth.php';

Auth::start();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    match ($action) {

        'login'  => handleLogin(),
        'logout' => handleLogout(),
        'me'     => handleMe(),

        'dashboard_stats' => requireAuth(fn() => dashboardStats()),

        'get_rooms'    => requireAuth(fn() => getRooms()),
        'add_room'     => requireAuth(fn() => addRoom(),    'rooms'),
        'update_room'  => requireAuth(fn() => updateRoom(), 'rooms'),
        'delete_room'  => requireAuth(fn() => deleteRoom(), 'rooms'),

        'post_reading'  => postReading(), 
        'get_readings'  => requireAuth(fn() => getReadings()),
        'get_chart'     => requireAuth(fn() => getChart()),

        'get_anomalies'    => requireAuth(fn() => getAnomalies()),
        'resolve_anomaly'  => requireAuth(fn() => resolveAnomaly(), 'anomalies'),

        'set_threshold' => requireAuth(fn() => setThreshold(), 'thresholds'),

        'get_users'    => requireAuth(fn() => getUsers(),   'users'),
        'add_user'     => requireAuth(fn() => addUser(),    'users'),
        'update_user'  => requireAuth(fn() => updateUser(), 'users'),
        'delete_user'  => requireAuth(fn() => deleteUser(), 'users'),
        'toggle_user'  => requireAuth(fn() => toggleUser(), 'users'),

        'get_logs' => requireAuth(fn() => getLogs(), 'logs'),

        'get_report'    => requireAuth(fn() => getReport()),
        'get_analytics' => requireAuth(fn() => getAnalytics()),
        'auto_resolve_anomalies' => requireAuth(fn() => autoResolveAnomalies()),

        'get_settings'  => requireAuth(fn() => getSettings(),  'settings'),
        'save_settings' => requireAuth(fn() => saveSettings(), 'settings'),

        'update_profile'  => requireAuth(fn() => updateProfile()),
        'change_password' => requireAuth(fn() => changePassword()),

        default => json('error', 'Unknown action')
    };
} catch (Throwable $e) {
    json('error', $e->getMessage());
}


function json(string $status, mixed $data = null, mixed $payload = null): void {
    $out = ['status' => $status];
    if (is_string($data)) $out['message'] = $data;
    else                  $out['data']    = $data;
    if ($payload !== null) $out['extra']  = $payload;
    echo json_encode($out);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw ?: '{}', true) ?? $_POST;
}

function requireAuth(callable $fn, string $perm = ''): void {
    if (!Auth::check()) { json('error', 'Unauthenticated'); }
    if ($perm && !Auth::can($perm)) { json('error', 'Forbidden'); }
    $fn();
}


function handleLogin(): void {
    $b    = body();
    $user = Auth::attempt($b['email'] ?? '', $b['password'] ?? '');
    if (!$user) { json('error', 'Invalid credentials or account inactive.'); }

    echo json_encode(['status' => 'ok', 'data' => $user]);
    exit;
}

function handleLogout(): void {
    Auth::logout();
    json('ok', 'Logged out');
}

function handleMe(): void {
    $user = Auth::user();
    if (!$user) { json('error', 'Not authenticated'); }
    json('ok', $user);
}

function dashboardStats(): void {
    $db = Database::connect();

    $rooms = $db->query(
        'SELECT r.room_id, r.room_name, r.equipment_label, r.threshold_watts, r.status,
                b.building_name,
                et.type_name, et.icon_key,
                rd.voltage, rd.current_amp, rd.power_watts, rd.energy_kwh, rd.read_at
         FROM   rooms r
         JOIN   buildings      b  ON b.building_id = r.building_id
         JOIN   equipment_types et ON et.type_id   = r.type_id
         LEFT JOIN readings rd ON rd.reading_id = (
             SELECT MAX(reading_id) FROM readings WHERE room_id = r.room_id
         )
         WHERE  r.is_active = 1
         ORDER  BY r.room_id'
    )->fetchAll();

    $totalPower = array_sum(array_column($rooms, 'power_watts'));

    // MAX - MIN energy per room para sa tamang kWh ngayon
    $todayEnergy = $db->query(
        'SELECT COALESCE(SUM(today_kwh), 0) FROM (
            SELECT (MAX(energy_kwh) - MIN(energy_kwh)) AS today_kwh
            FROM readings
            WHERE DATE(read_at) = CURDATE()
            GROUP BY room_id
        ) AS t'
    )->fetchColumn();

    // MAX - MIN energy per room para sa buwang ito
    $monthEnergy = $db->query(
        'SELECT COALESCE(SUM(month_kwh), 0) FROM (
            SELECT (MAX(energy_kwh) - MIN(energy_kwh)) AS month_kwh
            FROM readings
            WHERE YEAR(read_at) = YEAR(NOW()) AND MONTH(read_at) = MONTH(NOW())
            GROUP BY room_id
        ) AS m'
    )->fetchColumn();

    $activeAnomalies = $db->query(
        'SELECT COUNT(*) FROM anomalies WHERE status="active"'
    )->fetchColumn();

    // ── INAYOS: Kumpletong 24-Hour Timeline para sa Graph (00:00 hanggang 23:00) ──
    $rawReadings = $db->query(
        "SELECT 
            HOUR(read_at) AS hr,
            ROUND(AVG(power_watts), 2) AS avg_power
        FROM readings 
        WHERE DATE(read_at) = CURDATE()
        GROUP BY HOUR(read_at)"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $chartLabels = [];
    $chartValues = [];

    // Mag-generate ng 24 oras para may kumpletong X-axis timeline ang graph ngayong araw
    for ($h = 0; $h < 24; $h++) {
        $chartLabels[] = date('g:00 A', strtotime("{$h}:00"));
        $chartValues[] = isset($rawReadings[$h]) ? (float)$rawReadings[$h] : 0;
    }

    json('ok', [
        'total_power'      => (float)$totalPower,
        'today_energy'     => (float)$todayEnergy,
        'month_energy'     => (float)$monthEnergy,
        'active_anomalies' => (int)$activeAnomalies,
        'rooms'            => $rooms,
        'chart'            => [
            'labels' => $chartLabels,
            'values' => $chartValues,
        ],
    ]);
}

function getRooms(): void {
    $db   = Database::connect();
    $rows = $db->query(
        'SELECT r.*, b.building_name, et.type_name, et.icon_key
         FROM   rooms r
         JOIN   buildings b       ON b.building_id = r.building_id
         JOIN   equipment_types et ON et.type_id   = r.type_id
         WHERE  r.is_active = 1 ORDER BY r.room_id'
    )->fetchAll();
    json('ok', $rows);
}

function addRoom(): void {
    $b  = body();
    $db = Database::connect();

    $bid = $db->prepare('SELECT building_id FROM buildings WHERE building_name=?');
    $bid->execute([$b['building_name'] ?? 'Building A']);
    $buildingId = $bid->fetchColumn();
    if (!$buildingId) {
        $db->prepare('INSERT INTO buildings (building_name) VALUES (?)')->execute([$b['building_name']]);
        $buildingId = $db->lastInsertId();
    }

    $db->prepare(
        'INSERT INTO rooms (building_id, type_id, room_name, equipment_label, threshold_watts)
         VALUES (?,?,?,?,?)'
    )->execute([$buildingId, $b['type_id'] ?? 8, $b['room_name'], $b['equipment_label'], $b['threshold_watts'] ?? 1000]);

    Auth::log(Auth::user()['user_id'], 'room', 'Added room: ' . $b['room_name']);
    json('ok', 'Room added');
}

function updateRoom(): void {
    $b  = body();
    $db = Database::connect();
    $db->prepare(
        'UPDATE rooms SET room_name=?, equipment_label=?, threshold_watts=? WHERE room_id=?'
    )->execute([$b['room_name'], $b['equipment_label'], $b['threshold_watts'], $b['room_id']]);
    Auth::log(Auth::user()['user_id'], 'room', 'Updated room ID: ' . $b['room_id']);
    json('ok', 'Room updated');
}

function deleteRoom(): void {
    $b  = body();
    $db = Database::connect();
    $db->prepare('UPDATE rooms SET is_active=0 WHERE room_id=?')->execute([$b['room_id']]);
    Auth::log(Auth::user()['user_id'], 'room', 'Deleted room ID: ' . $b['room_id']);
    json('ok', 'Room removed');
}


function postReading(): void {
    $token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if ($token !== 'ESP32_SECRET_TOKEN_CHANGE_ME') {
        http_response_code(401);
        json('error', 'Unauthorized');
    }

    $b  = body();
    $db = Database::connect();

    $roomId  = (int)($b['room_id'] ?? 0);
    $voltage = (float)($b['voltage'] ?? 0);
    $current = (float)($b['current'] ?? $b['current_amp'] ?? $b['current_amps'] ?? 0);
    $power   = (float)($b['power'] ?? $b['power_watts'] ?? 0);
    $energy  = (float)($b['energy'] ?? $b['energy_kwh'] ?? 0);

    $db->prepare(
        'INSERT INTO readings (room_id, voltage, current_amp, power_watts, energy_kwh)
         VALUES (?,?,?,?,?)'
    )->execute([$roomId, $voltage, $current, $power, $energy]);
    $readingId = $db->lastInsertId();

    $threshold = $db->prepare('SELECT threshold_watts FROM rooms WHERE room_id=?');
    $threshold->execute([$roomId]);
    $limit = (float)$threshold->fetchColumn();

    if ($power > $limit && $limit > 0) {
        $typeId = $db->query('SELECT anomaly_type_id FROM anomaly_types WHERE type_label="HIGH POWER"')->fetchColumn() ?: 1;
        $db->prepare(
            'INSERT INTO anomalies (room_id, reading_id, anomaly_type_id, power_at_event, threshold_used)
             VALUES (?,?,?,?,?)'
        )->execute([$roomId, $readingId, $typeId, $power, $limit]);

        $db->prepare("UPDATE rooms SET status='anomaly' WHERE room_id=?")->execute([$roomId]);
        Auth::log(null, 'anomaly', "Anomaly detected in room_id {$roomId} — power: {$power} W");
    } else {
        $db->prepare("UPDATE rooms SET status='normal' WHERE room_id=?")->execute([$roomId]);
    }

    json('ok', ['reading_id' => (int)$readingId]);
}

function getReadings(): void {
    $db     = Database::connect();
    $roomId = (int)($_GET['room_id'] ?? 0);
    $limit  = min((int)($_GET['limit'] ?? 50), 500);

    $stmt = $db->prepare(
        'SELECT * FROM readings WHERE room_id=? ORDER BY read_at DESC LIMIT ?'
    );
    $stmt->execute([$roomId, $limit]);
    json('ok', $stmt->fetchAll());
}

function getChart(): void {
    $db     = Database::connect();
    $roomId = (int)($_GET['room_id'] ?? 0);
    $period = $_GET['period'] ?? 'today';

    $where = match ($period) {
        'week'  => 'read_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)',
        'month' => 'YEAR(read_at) = YEAR(NOW()) AND MONTH(read_at) = MONTH(NOW())',
        default => 'DATE(read_at) = CURDATE()',
    };

    if ($roomId > 0) {
        $stmt = $db->prepare(
            "SELECT DATE_FORMAT(read_at, '%H:%i') AS label, power_watts AS value
             FROM readings 
             WHERE room_id = ? AND $where 
             ORDER BY read_at ASC"
        );
        $stmt->execute([$roomId]);
    } else {
        // Dashboard chart rollup (System-wide average per hour)
        $stmt = $db->prepare(
            "SELECT DATE_FORMAT(read_at, '%H:00') AS label, ROUND(AVG(power_watts), 2) AS value
             FROM readings 
             WHERE $where 
             GROUP BY HOUR(read_at) 
             ORDER BY MIN(read_at) ASC"
        );
        $stmt->execute();
    }

    json('ok', $stmt->fetchAll());
}


function getAnomalies(): void {
    $db     = Database::connect();
    $status = $_GET['status'] ?? 'all';
    $where  = $status !== 'all' ? "WHERE a.status='$status'" : '';

    $rows = $db->query(
        "SELECT a.*, r.room_name, r.equipment_label,
                at2.type_label, u.full_name AS resolved_by_name
         FROM anomalies a
         JOIN rooms r           ON r.room_id         = a.room_id
         JOIN anomaly_types at2 ON at2.anomaly_type_id = a.anomaly_type_id
         LEFT JOIN users u      ON u.user_id          = a.resolved_by
         $where
         ORDER BY a.detected_at DESC LIMIT 100"
    )->fetchAll();
    json('ok', $rows);
}

function resolveAnomaly(): void {
    $b   = body();
    $db  = Database::connect();
    $uid = Auth::user()['user_id'];
    $db->prepare(
        "UPDATE anomalies SET status='resolved', resolved_by=?, resolved_at=NOW() WHERE anomaly_id=?"
    )->execute([$uid, $b['anomaly_id']]);
    Auth::log($uid, 'anomaly', 'Resolved anomaly ID: ' . $b['anomaly_id']);
    json('ok', 'Anomaly resolved');
}


function setThreshold(): void {
    $b  = body();
    $db = Database::connect();
    $db->prepare('UPDATE rooms SET threshold_watts=? WHERE room_id=?')
       ->execute([$b['threshold_watts'], $b['room_id']]);
    Auth::log(Auth::user()['user_id'], 'settings', 'Set threshold for room_id ' . $b['room_id'] . ' to ' . $b['threshold_watts'] . ' W');
    json('ok', 'Threshold updated');
}


function getUsers(): void {
    $db   = Database::connect();
    $rows = $db->query(
        'SELECT u.user_id, u.full_name, u.email, u.avatar, u.department,
                u.status, u.last_login, u.created_at,
                r.role_key, r.role_name
         FROM users u JOIN roles r ON r.role_id = u.role_id
         ORDER BY u.user_id'
    )->fetchAll();
    json('ok', $rows);
}

function addUser(): void {
    $b  = body();
    $db = Database::connect();

    $roleId = $db->prepare('SELECT role_id FROM roles WHERE role_key=?');
    $roleId->execute([$b['role_key'] ?? 'staff']);
    $rid = $roleId->fetchColumn();

    $hash = password_hash($b['password'], PASSWORD_BCRYPT);
    $db->prepare(
        'INSERT INTO users (role_id, full_name, email, password, department, avatar, status)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([$rid, $b['full_name'], $b['email'], $hash, $b['department'] ?? null,
                strtoupper(substr($b['full_name'], 0, 2)), 'active']);

    Auth::log(Auth::user()['user_id'], 'settings', 'Added user: ' . $b['email']);
    json('ok', 'User added');
}

function updateUser(): void {
    $b  = body();
    $db = Database::connect();

    $roleId = $db->prepare('SELECT role_id FROM roles WHERE role_key=?');
    $roleId->execute([$b['role_key'] ?? 'staff']);
    $rid = $roleId->fetchColumn();

    $db->prepare(
        'UPDATE users SET role_id=?, full_name=?, email=?, department=? WHERE user_id=?'
    )->execute([$rid, $b['full_name'], $b['email'], $b['department'] ?? null, $b['user_id']]);

    Auth::log(Auth::user()['user_id'], 'settings', 'Updated user ID: ' . $b['user_id']);
    json('ok', 'User updated');
}

function deleteUser(): void {
    $b  = body();
    if ((int)$b['user_id'] === Auth::user()['user_id']) json('error', 'Cannot delete yourself');
    Database::connect()->prepare('DELETE FROM users WHERE user_id=?')->execute([$b['user_id']]);
    Auth::log(Auth::user()['user_id'], 'settings', 'Deleted user ID: ' . $b['user_id']);
    json('ok', 'User deleted');
}

function toggleUser(): void {
    $b  = body();
    $db = Database::connect();
    $db->prepare(
        "UPDATE users SET status = IF(status='active','inactive','active') WHERE user_id=?"
    )->execute([$b['user_id']]);
    Auth::log(Auth::user()['user_id'], 'settings', 'Toggled status for user ID: ' . $b['user_id']);
    json('ok', 'Status toggled');
}


function getLogs(): void {
    $db   = Database::connect();
    $rows = $db->query(
        'SELECT l.*, u.full_name FROM activity_logs l
         LEFT JOIN users u ON u.user_id = l.user_id
         ORDER BY l.logged_at DESC LIMIT 200'
    )->fetchAll();
    json('ok', $rows);
}


function getReport(): void {
    $db     = Database::connect();
    $period = $_GET['period'] ?? 'daily';

    $dateFilter = match ($period) {
        'weekly'  => 'DATE(read_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)',
        'monthly' => 'YEAR(read_at)=YEAR(NOW()) AND MONTH(read_at)=MONTH(NOW())',
        default   => 'DATE(read_at) = CURDATE()',
    };

    $summary = $db->query(
        "SELECT COALESCE(SUM(room_kwh), 0) AS total_energy,
                COALESCE(MAX(peak_power), 0) AS peak_power,
                COUNT(DISTINCT room_id) AS rooms_monitored
         FROM (
             SELECT room_id, 
                    (MAX(energy_kwh) - MIN(energy_kwh)) AS room_kwh,
                    MAX(power_watts) AS peak_power
             FROM readings
             WHERE $dateFilter
             GROUP BY room_id
         ) AS r_sum"
    )->fetch();

    $anomalyCount = $db->query(
        "SELECT COUNT(*) FROM anomalies WHERE $dateFilter"
    )->fetchColumn();

    $byRoom = $db->query(
        "SELECT rm.room_name, rm.equipment_label,
                COALESCE(MAX(r.energy_kwh) - MIN(r.energy_kwh), 0) AS energy,
                COALESCE(AVG(r.power_watts), 0) AS avg_power,
                COALESCE(MAX(r.power_watts), 0) AS peak_power
         FROM rooms rm
         LEFT JOIN readings r ON r.room_id = rm.room_id AND $dateFilter
         WHERE rm.is_active = 1
         GROUP BY rm.room_id ORDER BY energy DESC"
    )->fetchAll();

    json('ok', [
        'summary'       => $summary,
        'anomaly_count' => (int)$anomalyCount,
        'by_room'       => $byRoom,
    ]);
}


function getSettings(): void {
    $db   = Database::connect();
    $rows = $db->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll();
    $out  = [];
    foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
    json('ok', $out);
}

function saveSettings(): void {
    $b  = body();
    $db = Database::connect();
    $s  = $db->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?');
    foreach ($b as $k => $v) $s->execute([$k, $v, $v]);
    Auth::log(Auth::user()['user_id'], 'settings', 'System settings updated');
    json('ok', 'Settings saved');
}


function updateProfile(): void {
    $b   = body();
    $db  = Database::connect();
    $uid = Auth::user()['user_id'];
    $db->prepare('UPDATE users SET full_name=?, email=?, department=? WHERE user_id=?')
       ->execute([$b['full_name'], $b['email'], $b['department'] ?? null, $uid]);

    $_SESSION['user']['full_name']  = $b['full_name'];
    $_SESSION['user']['email']      = $b['email'];
    Auth::log($uid, 'auth', 'Profile updated');
    json('ok', 'Profile updated');
}

function changePassword(): void {
    $b   = body();
    $db  = Database::connect();
    $uid = Auth::user()['user_id'];

    $hash = $db->prepare('SELECT password FROM users WHERE user_id=?');
    $hash->execute([$uid]);
    if (!password_verify($b['current_password'], $hash->fetchColumn())) {
        json('error', 'Current password is incorrect.');
    }
    if ($b['new_password'] !== $b['confirm_password']) json('error', 'Passwords do not match.');
    if (strlen($b['new_password']) < 6)                json('error', 'Password must be at least 6 characters.');

    $db->prepare('UPDATE users SET password=? WHERE user_id=?')
       ->execute([password_hash($b['new_password'], PASSWORD_BCRYPT), $uid]);
    Auth::log($uid, 'auth', 'Password changed');
    json('ok', 'Password updated');
}


function getAnalytics(): void {
    $db = Database::connect();

    $hourly = $db->query(
        "SELECT HOUR(read_at) AS hr, ROUND(AVG(power_watts),1) AS avg_power
         FROM readings
         WHERE read_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY HOUR(read_at)
         ORDER BY hr"
    )->fetchAll();

    $hourlyMap = array_fill(0, 24, 0);
    foreach ($hourly as $h) $hourlyMap[(int)$h['hr']] = (float)$h['avg_power'];

    $weekly = $db->query(
        "SELECT d.day, d.day_name, COALESCE(SUM(d.kwh), 0) AS total_kwh
         FROM (
             SELECT DATE(read_at) AS day,
                    DAYNAME(read_at) AS day_name,
                    (MAX(energy_kwh) - MIN(energy_kwh)) AS kwh
             FROM readings
             WHERE read_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY DATE(read_at), room_id
         ) d
         GROUP BY d.day, d.day_name
         ORDER BY d.day"
    )->fetchAll();

    $rooms = $db->query(
        "SELECT r.room_id, r.room_name, r.equipment_label,
                ROUND(AVG(rd.power_watts),1)  AS avg_power,
                ROUND(MAX(rd.power_watts),1)  AS peak_power,
                ROUND(COALESCE(MAX(rd.energy_kwh) - MIN(rd.energy_kwh), 0), 2) AS total_kwh,
                COUNT(DISTINCT a.anomaly_id)  AS anomaly_count
         FROM rooms r
         LEFT JOIN readings rd ON rd.room_id = r.room_id
             AND rd.read_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         LEFT JOIN anomalies a ON a.room_id = r.room_id
             AND a.detected_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         WHERE r.is_active = 1
         GROUP BY r.room_id
         ORDER BY total_kwh DESC"
    )->fetchAll();

    $weekTotal = $db->query(
        "SELECT ROUND(COALESCE(SUM(kwh), 0), 2) FROM (
             SELECT (MAX(energy_kwh) - MIN(energy_kwh)) AS kwh
             FROM readings
             WHERE read_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY room_id
         ) t"
    )->fetchColumn() ?: 0;

    $dailyAvg = $db->query(
        "SELECT ROUND(AVG(day_total), 2) FROM (
             SELECT DATE(read_at) AS d, (MAX(energy_kwh) - MIN(energy_kwh)) AS day_total
             FROM readings
             WHERE read_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY DATE(read_at), room_id
         ) t"
    )->fetchColumn() ?: 0;

    $monthForecast = round($dailyAvg * 30, 2);

    $anomalyStats = $db->query(
        "SELECT COUNT(*) AS total,
                SUM(power_at_event - threshold_used) AS total_excess,
                MIN(HOUR(detected_at)) AS first_hour,
                MAX(HOUR(detected_at)) AS last_hour
         FROM anomalies
         WHERE detected_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    )->fetch();

    $topRoom = $db->query(
        "SELECT r.room_name, r.equipment_label, COUNT(*) AS cnt
         FROM anomalies a JOIN rooms r ON r.room_id = a.room_id
         WHERE a.detected_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY a.room_id ORDER BY cnt DESC LIMIT 1"
    )->fetch();

    $rate = $db->query(
        "SELECT setting_value FROM system_settings WHERE setting_key='kwh_rate'"
    )->fetchColumn() ?: 6.00;

    json('ok', [
        'hourly_pattern' => array_values($hourlyMap),
        'weekly'         => $weekly,
        'rooms'          => $rooms,
        'week_total_kwh' => (float)$weekTotal,
        'daily_avg_kwh'  => (float)$dailyAvg,
        'month_forecast' => (float)$monthForecast,
        'kwh_rate'       => (float)$rate,
        'anomaly_stats'  => $anomalyStats,
        'top_room'       => $topRoom ?: null,
    ]);
}


function autoResolveAnomalies(): void {
    $db = Database::connect();
    $db->prepare(
        "DELETE FROM anomalies
         WHERE status = 'resolved'
         AND resolved_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    )->execute();
    $affected = $db->query("SELECT ROW_COUNT()")->fetchColumn();
    Auth::log(null, 'system', "Auto-removed $affected resolved anomalies older than 30 days");
    json('ok', ['removed' => (int)$affected]);
}