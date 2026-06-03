<?php

// AJAX is used to call functions in this file from the frontend.
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!headers_sent()) {
        // Use the same session name and cookie params as `session.php` so direct requests
        // (AJAX calls to this file) use the same session.
        session_name('BLOOMSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    session_start();
}

// Database connection details. Replace these values if your environment differs.
define('DB_HOST', '127.0.0.1'); //127.0.0.1 is for XAMPP on Windows
define('DB_USER', 'root'); // root is the default user for XAMPP
define('DB_PASS', ''); // empty password
define('DB_NAME', 'bloom_pos'); // Database name

// Connect to the database and return the connection object.
function dbConnect(): mysqli
{
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new RuntimeException('Database connection failed: ' . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4'); // Ensure UTF-8 encoding for proper character support
    return $conn;
}

// Format a duration in seconds to HH:MM:SS format, this is used on the frontend to display session durations
function formatDuration(int $seconds): string
{
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

// Create the session_history table if it doesn't exist. This table stores login/logout records for employees.
function createSessionHistoryTableSql(): string
{
    return <<<SQL
CREATE TABLE IF NOT EXISTS session_history (
    session_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) NOT NULL,
    login_date DATE NOT NULL,
    login_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    logout_time DATETIME NULL DEFAULT NULL,
    duration_seconds INT NULL DEFAULT NULL,
    duration VARCHAR(16) NULL DEFAULT NULL,
    INDEX idx_employee_active (employee_id, logout_time),
    INDEX idx_employee_login (employee_id, login_time),
    CONSTRAINT fk_session_history_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
}

// Ensure the session_history table exists before any operations. This is called at the start of AJAX requests to this file.
function ensureSessionHistoryTable(mysqli $conn): void
{
    $conn->query(createSessionHistoryTableSql());
}

// Get today's session for an employee, if it exists. 
// This is used to check if the employee has already logged in today and to handle same-day re-logins.
function getTodaySession(mysqli $conn, string $employeeId): ?array
{
    $employeeId = strtoupper(trim($employeeId));
    $today = date('Y-m-d');

    $stmt = $conn->prepare(
        'SELECT session_id, login_time, logout_time, duration_seconds FROM session_history WHERE employee_id = ? AND login_date = ? ORDER BY session_id DESC LIMIT 1'
    );
    $stmt->bind_param('ss', $employeeId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

// Close any hanging sessions for an employee that were left open from previous days. 
// This is called at login to ensure that old sessions are properly closed and don't interfere with today's session.
function closeDanglingSessions(mysqli $conn, string $employeeId): void
{
    $employeeId = strtoupper(trim($employeeId));
    if ($employeeId === '') {
        return;
    }

    $today = date('Y-m-d');
    $stmt = $conn->prepare(
        'SELECT session_id, login_time, login_date FROM session_history WHERE employee_id = ? AND logout_time IS NULL AND login_date < ? ORDER BY login_time ASC'
    );
    $stmt->bind_param('ss', $employeeId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $openRows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$openRows) {
        return;
    }

    $updateStmt = $conn->prepare(
        'UPDATE session_history SET logout_time = ?, duration_seconds = ?, duration = ? WHERE session_id = ?'
    );

    foreach ($openRows as $row) {
        $loginTimestamp = strtotime($row['login_time']);
        $dayEndTimestamp = strtotime($row['login_date'] . ' 23:59:59');
        $fallbackLogoutTimestamp = min($loginTimestamp + 8 * 3600, $dayEndTimestamp);
        $fallbackLogoutTimestamp = max($fallbackLogoutTimestamp, $loginTimestamp);

        $logoutTime = date('Y-m-d H:i:s', $fallbackLogoutTimestamp);
        $durationSeconds = $fallbackLogoutTimestamp - $loginTimestamp;
        $duration = formatDuration($durationSeconds);

        $updateStmt->bind_param('sisi', $logoutTime, $durationSeconds, $duration, $row['session_id']);
        $updateStmt->execute();
    }

    $updateStmt->close();
}

// Record a login for an employee. 
// This function handles both new logins and same-day re-logins by updating or creating session_history records accordingly.
function recordLogin(mysqli $conn, string $employeeId): ?int
{
    $employeeId = strtoupper(trim($employeeId));
    if ($employeeId === '') {
        return null;
    }

    closeDanglingSessions($conn, $employeeId);

    $loginTime = date('Y-m-d H:i:s');
    $loginDate = date('Y-m-d', strtotime($loginTime));

    $todaySession = getTodaySession($conn, $employeeId);
    if ($todaySession !== null) {
        if ($todaySession['logout_time'] === null) {
            // Already active today: keep the open row and reuse it.
            $_SESSION['session_history_id'] = (int)$todaySession['session_id'];
            return (int)$todaySession['session_id'];
        }

        // Same-day re-login: reuse today's row and keep accumulated duration_seconds.
        $stmt = $conn->prepare(
            'UPDATE session_history SET login_time = ?, logout_time = NULL WHERE session_id = ?'
        );
        $stmt->bind_param('si', $loginTime, $todaySession['session_id']);
        $stmt->execute();
        $stmt->close();

        $_SESSION['session_history_id'] = (int)$todaySession['session_id'];
        return (int)$todaySession['session_id'];
    }

    // No session today yet: create a new row.
    $stmt = $conn->prepare(
        'INSERT INTO session_history (employee_id, login_date, login_time, duration_seconds, duration) VALUES (?, ?, ?, 0, "00:00:00")'
    );
    $stmt->bind_param('sss', $employeeId, $loginDate, $loginTime);
    $stmt->execute();
    $sessionId = $stmt->insert_id;
    $stmt->close();

    $_SESSION['session_history_id'] = $sessionId;
    return $sessionId;
}

// Record a logout for an employee. 
// This function finds the active session for the employee (optionally using a provided history ID) and updates it with the logout time and calculated duration.
function recordLogout(mysqli $conn, string $employeeId, ?int $historyId = null): bool
{
    $employeeId = strtoupper(trim($employeeId));
    if ($employeeId === '') {
        return false;
    }

    if ($historyId !== null) {
        $stmt = $conn->prepare(
            'SELECT session_id, login_time, duration_seconds FROM session_history WHERE session_id = ? AND employee_id = ? AND logout_time IS NULL LIMIT 1'
        );
        $stmt->bind_param('is', $historyId, $employeeId);
    } else {
        $stmt = $conn->prepare(
            'SELECT session_id, login_time, duration_seconds FROM session_history WHERE employee_id = ? AND logout_time IS NULL ORDER BY login_time DESC LIMIT 1'
        );
        $stmt->bind_param('s', $employeeId);
    }

    // Execute the query to find the active session for the employee. If no active session is found, return false.
    $stmt->execute();
    $result = $stmt->get_result();
    $sessionRow = $result->fetch_assoc();
    $stmt->close();

    if (!$sessionRow) {
        return false;
    }

    $logoutTime = date('Y-m-d H:i:s');
    $loginTimestamp = strtotime($sessionRow['login_time']);
    $segmentSeconds = max(0, strtotime($logoutTime) - $loginTimestamp);
    $durationSeconds = (int)$sessionRow['duration_seconds'] + $segmentSeconds;
    $duration = formatDuration($durationSeconds);

    $stmt = $conn->prepare(
        'UPDATE session_history SET logout_time = ?, duration_seconds = ?, duration = ? WHERE session_id = ?'
    );
    $stmt->bind_param('sisi', $logoutTime, $durationSeconds, $duration, $sessionRow['session_id']);
    $success = $stmt->execute();
    $stmt->close();

    unset($_SESSION['session_history_id']);
    return $success;
}

// Get session history for an employee, limited to a certain number of recent records. 
// This is used on the frontend to display the employee's login/logout history.
function getSessionHistory(mysqli $conn, string $employeeId, int $limit = 20): array
{
    $employeeId = strtoupper(trim($employeeId));
    if ($employeeId === '') {
        return [];
    }

    $stmt = $conn->prepare(
        'SELECT session_id, login_date, login_time, logout_time, duration, duration_seconds
        FROM session_history
        WHERE employee_id = ?
        ORDER BY login_time DESC
        LIMIT ?'
    );
    $stmt->bind_param('si', $employeeId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

// Get the active session for an employee, if it exists. 
// This is used to check if the employee currently has an open session (logged in but not logged out).
function getActiveSessionHistory(mysqli $conn, string $employeeId): ?array
{
    $employeeId = strtoupper(trim($employeeId));
    if ($employeeId === '') {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT session_id, login_time, logout_time, duration, duration_seconds
        FROM session_history
        WHERE employee_id = ? AND logout_time IS NULL
        ORDER BY login_time DESC
        LIMIT 1'
    );
    $stmt->bind_param('s', $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

// Get the active session history ID from the session, if it exists. 
// This is used to track the current session across requests.
function getActiveSessionHistoryId(): ?int
{
    return isset($_SESSION['session_history_id']) && is_numeric($_SESSION['session_history_id'])
        ? (int)$_SESSION['session_history_id']
        : null;
}

// Handle AJAX requests to this file. The 'action' parameter determines which operation to perform (login, logout, get_history).
if (php_sapi_name() !== 'cli' && isset($_REQUEST['action']) && basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    try {
        $conn = dbConnect();
        ensureSessionHistoryTable($conn);
        $action = $_REQUEST['action'];

        if ($action === 'login') {
            if (isset($_POST['employee_id'])) {
                $sessionId = recordLogin($conn, $_POST['employee_id']);
                echo json_encode(['status' => $sessionId ? 'ok' : 'error', 'session_history_id' => $sessionId]);
                exit;
            }
            echo json_encode(['status' => 'error', 'message' => 'Missing employee_id']);
            exit;
        }

        if ($action === 'logout') {
            if (isset($_SESSION['user_id'])) {
                $historyId = getActiveSessionHistoryId();
                $success = recordLogout($conn, $_SESSION['user_id'], $historyId);
                echo json_encode(['status' => $success ? 'ok' : 'error']);
                exit;
            }
            echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
            exit;
        }

        if ($action === 'get_history') {
            // Admin-only: return employee basic info and session_history rows as JSON
            if (!isset($_SESSION['user_role']) || !in_array(strtolower($_SESSION['user_role']), ['admin', 'owner', 'manager'], true)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
                exit;
            }

            $employeeId = isset($_GET['employee_id']) ? strtoupper(trim($_GET['employee_id'])) : '';
            if ($employeeId === '') {
                echo json_encode(['status' => 'error', 'message' => 'Missing employee_id']);
                exit;
            }

            // fetch employee info
            $stmt = $conn->prepare('SELECT employee_id, full_name, role, job_role, photo_url FROM employees WHERE employee_id = ? LIMIT 1');
            $stmt->bind_param('s', $employeeId);
            $stmt->execute();
            $res = $stmt->get_result();
            $employee = $res->fetch_assoc() ?: null;
            $stmt->close();

            // fetch session rows
            $stmt = $conn->prepare('SELECT login_date, login_time, logout_time, duration, duration_seconds FROM session_history WHERE employee_id = ? ORDER BY login_time DESC LIMIT 500');
            $stmt->bind_param('s', $employeeId);
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = [];
            while ($r = $result->fetch_assoc()) {
                $rows[] = $r;
            }
            $stmt->close();

            echo json_encode(['status' => 'ok', 'employee' => $employee, 'sessions' => $rows]);
            exit;
        }

        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}
