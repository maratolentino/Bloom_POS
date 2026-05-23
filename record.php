<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Database connection details. Replace these values if your environment differs.
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'bloom_pos');

function dbConnect(): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new RuntimeException('Database connection failed: ' . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function formatDuration(int $seconds): string {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

function createSessionHistoryTableSql(): string {
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

function ensureSessionHistoryTable(mysqli $conn): void {
    $conn->query(createSessionHistoryTableSql());
}

function getTodaySession(mysqli $conn, string $employeeId): ?array {
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

function recordLogin(mysqli $conn, string $employeeId): ?int {
    $employeeId = strtoupper(trim($employeeId));
    if ($employeeId === '') {
        return null;
    }

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

function recordLogout(mysqli $conn, string $employeeId, ?int $historyId = null): bool {
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

function getSessionHistory(mysqli $conn, string $employeeId, int $limit = 20): array {
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

function getActiveSessionHistory(mysqli $conn, string $employeeId): ?array {
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

function getActiveSessionHistoryId(): ?int {
    return isset($_SESSION['session_history_id']) && is_numeric($_SESSION['session_history_id'])
        ? (int)$_SESSION['session_history_id']
        : null;
}

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

        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}
