<?php

// Include session handler (cart helper functions)
require_once 'session.php';
require_once __DIR__ . '/record.php';

// ── Basic setup
$remembered_id = isset($_COOKIE['bloom_remember_id']) ? htmlspecialchars($_COOKIE['bloom_remember_id'], ENT_QUOTES, 'UTF-8') : '';
if (!is_dir("uploads")) mkdir("uploads", 0777, true);
date_default_timezone_set("Asia/Manila");
require_once __DIR__ . '/Inventory.inc.php';
$auth_error = "";
$reg_error = "";
$store_info = array("name"=>"Bloom POS","address"=>"Calamba, Laguna","contact"=>"0912-345-6789","tax_rate"=>0.12);
$accepted_payments = str_getcsv("Cash,GCash,Maya,Credit/Debit Card", ',', '"', '\\');
$paymentsRef = &$accepted_payments;
$paymentKeys = array_keys($accepted_payments);
$page = isset($_GET["page"]) ? $_GET["page"] : "login";

$loggedIn = isset($_SESSION['user_id']) && $_SESSION['user_id'] !== '';
if (!$loggedIn && !in_array($page, ['login', 'register'], true)) {
  header('Location: ?page=login');
  exit;
}

// Database connection
// Defensive: ensure mysqli extension is present to avoid fatal errors in environments
// where the CLI PHP binary lacks mysqli (e.g., some lightweight installs).
if (!class_exists('mysqli')) {
  if (php_sapi_name() === 'cli-server') {
    header('Content-Type: application/json');
    echo json_encode(['status'=>'error','message'=>'PHP mysqli extension is not available in this PHP binary. Enable extension=mysqli in php.ini or run under XAMPP/Apache.']);
    exit;
  } else {
    die('PHP mysqli extension is not available. Enable extension=mysqli in php.ini or run under XAMPP/Apache.');
  }
}

$conn = new mysqli('127.0.0.1', 'root', '', 'bloom_pos');
if ($conn->connect_error) {
  header('Content-Type: application/json');
  echo json_encode(['status'=>'error','message'=>'DB connection failed: '.$conn->connect_error]);
  exit;
}

// Schema-managed: showcase_sales table should be created via bloom_pos.sql

// Compute admin flag safely from session
$is_admin = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'Admin');

// ── Profile session history
$sessionHistoryRows = [];
$activeSession = null;
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] !== '') {
  ensureSessionHistoryTable($conn);
  $activeSession = getActiveSessionHistory($conn, $_SESSION['user_id']);
  $sessionHistoryRows = getSessionHistory($conn, $_SESSION['user_id'], 20);
}

// ── Cart AJAX endpoints (must run before any page output)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cart_action'])) {
  header('Content-Type: application/json');
  try {
    $act = $_POST['cart_action'];
    if ($act === 'add') {
      $sku = isset($_POST['sku']) ? $_POST['sku'] : '';
      $qty = isset($_POST['qty']) ? (int)$_POST['qty'] : 1;
      $currentQty = isset($_SESSION['cart'][$sku]) ? (int)$_SESSION['cart'][$sku] : 0;
      $stockQty = null;
      $stmt = $conn->prepare("SELECT stock_qty FROM inventory WHERE sku = ?");
      if ($stmt) {
        $stmt->bind_param("s", $sku);
        $stmt->execute();
        $stmt->bind_result($stockQty);
        $stmt->fetch();
        $stmt->close();
      }

      

      
      if ($stockQty === null) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Invalid product SKU']);
        exit;
      }
      if ($currentQty + $qty > (int)$stockQty) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Max stock reached']);
        exit;
      }
      cart_add($sku, $qty);
      header('X-Session-Id: ' . session_id());
      echo json_encode(['status'=>'ok','cart'=>cart_get(),'count'=>cart_count_items()]);
      exit;
    }
    if ($act === 'set') {
      $sku = isset($_POST['sku']) ? $_POST['sku'] : '';
      $qty = isset($_POST['qty']) ? (int)$_POST['qty'] : 0;
      $stockQty = null;
      $stmt = $conn->prepare("SELECT stock_qty FROM inventory WHERE sku = ?");
      if ($stmt) {
        $stmt->bind_param("s", $sku);
        $stmt->execute();
        $stmt->bind_result($stockQty);
        $stmt->fetch();
        $stmt->close();
      }
      if ($stockQty === null) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Invalid product SKU']);
        exit;
      }
      if ($qty > (int)$stockQty) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Max stock reached']);
        exit;
      }
      cart_set($sku, $qty);
      header('X-Session-Id: ' . session_id());
      echo json_encode(['status'=>'ok','cart'=>cart_get(),'count'=>cart_count_items()]);
      exit;
    }
    if ($act === 'remove') {
      $sku = isset($_POST['sku']) ? $_POST['sku'] : '';
      cart_remove($sku);
      header('X-Session-Id: ' . session_id());
      echo json_encode(['status'=>'ok','cart'=>cart_get(),'count'=>cart_count_items()]);
      exit;
    }
    if ($act === 'clear') {
      cart_clear();
      header('X-Session-Id: ' . session_id());
      echo json_encode(['status'=>'ok','cart'=>[],'count'=>0]);
      exit;
    }
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    exit;
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_cart'])) {
  header('Content-Type: application/json');
  header('X-Session-Id: ' . session_id());
  echo json_encode(['cart'=>cart_get(),'count'=>cart_count_items()]);
  exit;
}

function getNextProductSku(mysqli $conn): string {
  $nextSku = 'PR-001';
  $result = $conn->query("SELECT MAX(CAST(SUBSTRING(sku, 4) AS UNSIGNED)) AS max_num FROM inventory WHERE sku REGEXP '^PR-[0-9]{3}$'");
  if ($result) {
    $row = $result->fetch_assoc();
    $maxNum = isset($row['max_num']) ? (int)$row['max_num'] : 0;
    $nextSku = 'PR-' . str_pad($maxNum + 1, 3, '0', STR_PAD_LEFT);
    $result->free();
  }
  return $nextSku;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_showcase'])) {
  header('Content-Type: application/json');
  $items = [];
  $res = $conn->query("SELECT showcase_id, name, description, main, fillers, greenery, meta, image_url FROM showcase_bundles ORDER BY showcase_id ASC");
  if ($res) {
    while ($row = $res->fetch_assoc()) {
      $items[] = $row;
    }
  }
  echo json_encode(['status' => 'ok', 'items' => $items]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_next_sku'])) {
  header('Content-Type: application/json');
  echo json_encode(['status' => 'ok', 'next_sku' => getNextProductSku($conn)]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_showcase') {
  header('Content-Type: application/json');
  if (empty($is_admin)) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
  }
  $name = isset($_POST['name']) ? trim($_POST['name']) : '';
  $description = isset($_POST['description']) ? trim($_POST['description']) : '';
  $main = isset($_POST['main']) ? max(0, (int)$_POST['main']) : 0;
  $fillers = isset($_POST['fillers']) ? max(0, (int)$_POST['fillers']) : 0;
  $greenery = isset($_POST['greenery']) ? max(0, (int)$_POST['greenery']) : 0;
  $meta = isset($_POST['meta']) ? trim($_POST['meta']) : '';
  $image_url = null;

  if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
      $uploadDir = 'uploads/showcase/';
      if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
      $originalName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($_FILES['image_file']['name']));
      $targetPath = $uploadDir . time() . '_' . $originalName;
      if (move_uploaded_file($_FILES['image_file']['tmp_name'], $targetPath)) {
        $image_url = $targetPath;
      } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save showcase image.']);
        exit;
      }
    } else {
      echo json_encode(['status' => 'error', 'message' => 'Showcase image upload failed.']);
      exit;
    }
  } elseif (isset($_POST['image_url']) && $_POST['image_url'] !== '') {
    $image_url = trim($_POST['image_url']);
  }

  $stmt = $conn->prepare("INSERT INTO showcase_bundles (name, description, main, fillers, greenery, meta, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
  $stmt->bind_param('ssiiiss', $name, $description, $main, $fillers, $greenery, $meta, $image_url);
  if ($stmt->execute()) {
    $id = $stmt->insert_id;
    echo json_encode(['status' => 'ok', 'item' => ['showcase_id' => $id, 'name' => $name, 'description' => $description, 'main' => $main, 'fillers' => $fillers, 'greenery' => $greenery, 'meta' => $meta, 'image_url' => $image_url]]);
  } else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to add showcase item']);
  }
  $stmt->close();
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_showcase') {
  header('Content-Type: application/json');
  if (empty($is_admin)) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
  }
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid showcase id']);
    exit;
  }
  $stmt = $conn->prepare("DELETE FROM showcase_bundles WHERE showcase_id = ?");
  $stmt->bind_param('i', $id);
  if ($stmt->execute()) {
    echo json_encode(['status' => 'ok']);
  } else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to delete showcase item']);
  }
  $stmt->close();
  exit;
}

// ── AJAX: Assign/Remove Product Discount ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_product_discount') {
  header('Content-Type: application/json');
  if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Unauthorized']);
    exit;
  }
  $sku = isset($_POST['sku']) ? $conn->real_escape_string($_POST['sku']) : '';
  $discount_id = isset($_POST['discount_id']) && $_POST['discount_id'] !== '' ? (int)$_POST['discount_id'] : null;
  
  if (!$sku) {
    echo json_encode(['status'=>'error','message'=>'Missing SKU']);
    exit;
  }
  
  try {
    if ($discount_id === null) {
      $stmt = $conn->prepare("UPDATE inventory SET discount_id = NULL WHERE sku = ?");
      $stmt->bind_param("s", $sku);
    } else {
      $stmt = $conn->prepare("UPDATE inventory SET discount_id = ? WHERE sku = ?");
      $stmt->bind_param("is", $discount_id, $sku);
    }
    if ($stmt->execute()) {
      echo json_encode(['status'=>'ok','message'=>'Discount assignment updated']);
    } else {
      echo json_encode(['status'=>'error','message'=>'Database update failed']);
    }
    $stmt->close();
  } catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
  }
  exit;
}

// ── AJAX: Delete Promotion with Automatic Unassignment ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_promotion_ajax') {
  header('Content-Type: application/json');
  if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Unauthorized']);
    exit;
  }
  $discount_id = isset($_POST['discount_id']) ? (int)$_POST['discount_id'] : 0;
  
  if (!$discount_id) {
    echo json_encode(['status'=>'error','message'=>'Missing discount ID']);
    exit;
  }
  
  try {
    // First, remove this discount from all products
    $stmt = $conn->prepare("UPDATE inventory SET discount_id = NULL WHERE discount_id = ?");
    if ($stmt) {
      $stmt->bind_param("i", $discount_id);
      $stmt->execute();
      $stmt->close();
    }
    
    // Then delete the promotion
    $stmt = $conn->prepare("DELETE FROM discounts WHERE discount_id = ?");
    if ($stmt) {
      $stmt->bind_param("i", $discount_id);
      if ($stmt->execute()) {
        echo json_encode(['status'=>'ok','message'=>'Promotion deleted successfully']);
      } else {
        echo json_encode(['status'=>'error','message'=>'Failed to delete promotion']);
      }
      $stmt->close();
    } else {
      echo json_encode(['status'=>'error','message'=>'Database error']);
    }
  } catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
  }
  exit;
}

// Ensure `member_since` column exists on `customers` to support Member Since
$colCheck = $conn->query("SHOW COLUMNS FROM customers LIKE 'member_since'");
if ($colCheck && $colCheck->num_rows === 0) {
  $conn->query("ALTER TABLE customers ADD COLUMN member_since DATETIME NULL DEFAULT NULL");
}
// Ensure `contact_email` and `contact_number` columns exist on `customers`
$colCheckEmail = $conn->query("SHOW COLUMNS FROM customers LIKE 'contact_email'");
if ($colCheckEmail && $colCheckEmail->num_rows === 0) {
  $conn->query("ALTER TABLE customers ADD COLUMN contact_email VARCHAR(255) NULL DEFAULT NULL");
}
$colCheckPhone = $conn->query("SHOW COLUMNS FROM customers LIKE 'contact_number'");
if ($colCheckPhone && $colCheckPhone->num_rows === 0) {
  $conn->query("ALTER TABLE customers ADD COLUMN contact_number VARCHAR(32) NULL DEFAULT NULL");
}
// Ensure `approved` column exists on `customers` (1 = approved, 0 = pending)
$colCheckApproved = $conn->query("SHOW COLUMNS FROM customers LIKE 'approved'");
if ($colCheckApproved && $colCheckApproved->num_rows === 0) {
  $conn->query("ALTER TABLE customers ADD COLUMN approved TINYINT(1) NOT NULL DEFAULT 1");
}
// Ensure `created_by`, `approved_by`, `approved_at`, and `rejection_reason` columns exist
$colCheckCreatedBy = $conn->query("SHOW COLUMNS FROM customers LIKE 'created_by'");
if ($colCheckCreatedBy && $colCheckCreatedBy->num_rows === 0) {
  $conn->query("ALTER TABLE customers ADD COLUMN created_by VARCHAR(50) NULL DEFAULT NULL");
}
$colCheckApprovedBy = $conn->query("SHOW COLUMNS FROM customers LIKE 'approved_by'");
if ($colCheckApprovedBy && $colCheckApprovedBy->num_rows === 0) {
  $conn->query("ALTER TABLE customers ADD COLUMN approved_by VARCHAR(50) NULL DEFAULT NULL");
}
$colCheckApprovedAt = $conn->query("SHOW COLUMNS FROM customers LIKE 'approved_at'");
if ($colCheckApprovedAt && $colCheckApprovedAt->num_rows === 0) {
  $conn->query("ALTER TABLE customers ADD COLUMN approved_at DATETIME NULL DEFAULT NULL");
}
$colCheckRej = $conn->query("SHOW COLUMNS FROM customers LIKE 'rejection_reason'");
if ($colCheckRej && $colCheckRej->num_rows === 0) {
  $conn->query("ALTER TABLE customers ADD COLUMN rejection_reason VARCHAR(255) NULL DEFAULT NULL");
}

// Ensure approval history table exists
$historyCheck = $conn->query("SHOW TABLES LIKE 'customer_approval_history'");
if ($historyCheck && $historyCheck->num_rows === 0) {
  $conn->query("CREATE TABLE customer_approval_history (approval_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, action VARCHAR(32) NOT NULL, by_employee_id VARCHAR(50) NULL, note VARCHAR(255) NULL, ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Ensure promotion tracking columns exist in sales table
$promoCols = ['promotion_id', 'promotion_name', 'promotion_type'];
foreach ($promoCols as $col) {
  $colCheck = $conn->query("SHOW COLUMNS FROM sales LIKE '$col'");
  if ($colCheck && $colCheck->num_rows === 0) {
    if ($col === 'promotion_id') {
      $conn->query("ALTER TABLE sales ADD COLUMN promotion_id INT NULL DEFAULT NULL");
    } elseif ($col === 'promotion_type') {
      $conn->query("ALTER TABLE sales ADD COLUMN promotion_type VARCHAR(50) NULL DEFAULT NULL");
    } else {
      $conn->query("ALTER TABLE sales ADD COLUMN promotion_name VARCHAR(100) NULL DEFAULT NULL");
    }
  }
}

// (duplicate cart handlers removed — top-of-file handlers are used)

// ── Login ─────────────────────────────────────────────────────
if ($page === "login" && $_SERVER["REQUEST_METHOD"] === "POST") {
  $emp_id   = isset($_POST["emp_id"])   ? trim($_POST["emp_id"])  : ""; //trim() to remove extra whitespace from employee ID input
  $passcode = isset($_POST["passcode"]) ? $_POST["passcode"]      : "";

  // Validate basic employee ID structure (case-insensitive): EMP-### where ### is 001-999
  if (!preg_match('/^EMP-(\d{3})$/i', $emp_id, $m) || (int)$m[1] < 1 || (int)$m[1] > 999) {
    $auth_error = "Employee ID must follow the format EMP-001 through EMP-999.";
  } else {
    // Lookup using uppercase form (DB stores uppercase IDs)
    $emp_id_up = strtoupper($emp_id);
    $stmt = $conn->prepare("SELECT employee_id, full_name, role, passcode, photo_url FROM employees WHERE employee_id = ?");
    $stmt->bind_param("s", $emp_id_up);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
      $auth_error = "Invalid Employee ID or passcode.";
    } else {
      // Enforce case-sensitive 'EMP' prefix for Admin/Owner accounts only
      $role = isset($row['role']) ? $row['role'] : '';
      $isAdminLike = (strcasecmp($role, 'Admin') === 0) || (strcasecmp($role, 'Owner') === 0);
      if ($isAdminLike && strcmp(substr($emp_id, 0, 3), 'EMP') !== 0) {
        $auth_error = "Admin accounts require the 'EMP' prefix to be uppercase when logging in.";
      } elseif (strcmp($row["passcode"], $passcode) === 0) {  // strcmp() for exact string comparison of passcodes
        // Successful login
        $_SESSION['user_id'] = $row['employee_id'];
        $_SESSION['user_name'] = $row['full_name'];
        $_SESSION['user_role'] = $row['role'];
        $_SESSION['user_photo'] = isset($row['photo_url']) ? $row['photo_url'] : '';
        ensureSessionHistoryTable($conn);
        recordLogin($conn, $row['employee_id']);
        header("Location: ?page=dashboard");
        exit;
      } else {
        $auth_error = "Invalid Employee ID or passcode.";
      }
    }
  }

  if (isset($_POST['remember_me'])) {
    setcookie('bloom_remember_id', $emp_id, time() + (30 * 24 * 60 * 60), '/'); // 30 days
  } else {
    setcookie('bloom_remember_id', '', time() - 3600, '/'); // delete it
  }
}

// ── Register ──────────────────────────────────────────────────

if ($page === "register" && $_SERVER["REQUEST_METHOD"] === "POST" && $is_admin) {
  $emp_id   = isset($_POST["emp_id"])    ? trim($_POST["emp_id"])    : "";
  $name     = isset($_POST["full_name"]) ? trim($_POST["full_name"]) : "";
  $role     = "Cashier"; // New accounts can only be Cashier; Admin creation via form is disabled
  $passcode = isset($_POST["passcode"])  ? $_POST["passcode"]        : "";
  $job_role = (strcasecmp($role, "Admin") === 0) ? "Manager" : "Cashier"; // strcasecmp() for case-insensitive role check

  $nameCheck = validateStaffName($name);
  if (!is_bool($nameCheck) || $nameCheck !== true) {
    $reg_error = is_bool($nameCheck) ? "Invalid name." : $nameCheck;
  } else {
    // Strict employee ID format: EMP-### with range 001-999 (case-sensitive)
    // Require exact uppercase 'EMP' prefix using strcmp
    if (strlen($emp_id) < 7 || strcmp(substr($emp_id, 0, 3), 'EMP') !== 0) {
      $reg_error = "Employee ID must start with uppercase 'EMP' and follow EMP-001 format.";
    } elseif (!preg_match('/^EMP-(\d{3})$/', $emp_id, $m) || (int)$m[1] < 1 || (int)$m[1] > 999) {
      $reg_error = "Employee ID must be in format EMP-001 to EMP-999.";
    } else {
      // Check uniqueness before attempting insert to avoid fatal DB errors
      $chk = $conn->prepare("SELECT 1 FROM employees WHERE employee_id = ? LIMIT 1");
      $chk->bind_param("s", $emp_id);
      $chk->execute();
      $res = $chk->get_result();
      if ($res && $res->num_rows > 0) {
        $reg_error = "Employee ID already exists.";
      }
    }
  }

  if ($reg_error === "") {
    // ── NEW: profile photo upload ──
    $photo_path = "";
    if (isset($_FILES["emp_photo"]) && $_FILES["emp_photo"]["error"] === 0) {
      $dir = "uploads/";
      if (!is_dir($dir)) mkdir($dir, 0777, true);
      $photo_path = $dir . time() . "_" . basename($_FILES["emp_photo"]["name"]);
      move_uploaded_file($_FILES["emp_photo"]["tmp_name"], $photo_path);
    }

    $stmt = $conn->prepare("INSERT INTO employees (employee_id, full_name, role, passcode, job_role, photo_url) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("ssssss", $emp_id, $name, $role, $passcode, $job_role, $photo_path);
    //                 ^^^^^^ 6 s's — one per ? placeholder

    if ($stmt->execute()) {
      header("Location: ?page=employees&registered=1");
      exit;
    } else {
      // Defensive fallback: if insert still fails, present friendly popup message
      $reg_error = "Failed to create account. Employee ID may already exist.";
    }
  }
}

// ── Logout ────────────────────────────────────────────────────
if ($page === "logout") {
  setcookie('bloom_remember_id', '', time() - 3600, '/'); // ← delete cookie
  $logoutEmployee = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '';
  if ($logoutEmployee !== '') {
    ensureSessionHistoryTable($conn);
    recordLogout($conn, $logoutEmployee, getActiveSessionHistoryId());
  }
  destroySession();
  header("Location: ?page=login");
  exit;
}

// ── Checkout – Finalize Sale ──────────────────────────────────
// ── Self-Profile Update (any logged-in user) ─────────────────
if (isset($_POST["update_my_profile"])) {
  $id           = $_SESSION["user_id"];
  $name         = isset($_POST["full_name"])    ? trim($_POST["full_name"])    : "";
  $new_passcode = isset($_POST["new_passcode"]) ? trim($_POST["new_passcode"]) : "";
  $return_page  = isset($_POST["return_page"]) && $_POST["return_page"] !== "" ? $_POST["return_page"] : "dashboard";

  $updates = [];

  $nameCheck = validateStaffName($name);
  if (is_bool($nameCheck) && $nameCheck === true) {
    $name_esc  = $conn->real_escape_string($name);
    $updates[] = "full_name='$name_esc'";
    $_SESSION["user_name"] = $name;
  }

  if ($new_passcode !== "") {
    $pc_esc    = $conn->real_escape_string($new_passcode);
    $updates[] = "passcode='$pc_esc'";
  }

  if (isset($_FILES["profile_photo"]) && $_FILES["profile_photo"]["error"] === 0) {
    $dir = "uploads/";
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $photo_path = $dir . time() . "_" . basename($_FILES["profile_photo"]["name"]);
    if (move_uploaded_file($_FILES["profile_photo"]["tmp_name"], $photo_path)) {
      $photo_esc = $conn->real_escape_string($photo_path);
      $updates[] = "photo_url='$photo_esc'";
      $_SESSION["user_photo"] = $photo_path;
    }
  }

  if (!empty($updates)) {
    $id_esc = $conn->real_escape_string($id);
    $conn->query("UPDATE employees SET " . implode(",", $updates) . " WHERE employee_id='$id_esc'");
  }

  header("Location: ?page=" . urlencode($return_page));
  exit;
}

if ($page === "checkout" && isset($_POST["finalize_sale"])) {
  $cart_data       = json_decode(isset($_POST["cart_json"])       ? $_POST["cart_json"]       : "[]",   true);
  $payment_method  = $conn->real_escape_string(isset($_POST["payment_method"])  ? $_POST["payment_method"]  : "Cash");

  $raw_total       = isset($_POST["final_total"])     ? $_POST["final_total"]     : 0;
  $raw_tax         = isset($_POST["tax_amount"])      ? $_POST["tax_amount"]      : 0;
  $raw_discount    = isset($_POST["discount_amount"]) ? $_POST["discount_amount"] : 0;
  $raw_tendered    = str_replace(",", "", isset($_POST["amount_tendered"]) ? $_POST["amount_tendered"] : 0);

  $total_amount    = is_numeric($raw_total)    ? floatval($raw_total)    : 0.0;
  $tax_amount      = is_numeric($raw_tax)      ? floatval($raw_tax)      : 0.0;
  $discount_amount = is_numeric($raw_discount) ? floatval($raw_discount) : 0.0;
  $amount_tendered = is_numeric($raw_tendered) ? floatval($raw_tendered) : 0.0;

  $customer_id     = (isset($_POST["customer_id"]) && $_POST["customer_id"] !== "") ? (int)$_POST["customer_id"] : null;
  $employee_id     = $_SESSION["user_id"];
  $sale_date       = date("Y-m-d H:i:s");

  $is_walk_in      = is_null($customer_id);

  $transaction_id = generateTransactionRef($employee_id);

  // Handle wallet payment details
  $wallet_contact = "";
  $wallet_account = "";
  $wallet_proof_url = "";
  
  if ($payment_method === "GCash" || $payment_method === "Maya") {
    $wallet_contact = $conn->real_escape_string(isset($_POST["wallet_contact"]) ? $_POST["wallet_contact"] : "");
    $wallet_account = $conn->real_escape_string(isset($_POST["wallet_account_name"]) ? $_POST["wallet_account_name"] : "");
    
    // Handle file upload
    if (isset($_FILES["wallet_proof"]) && $_FILES["wallet_proof"]["error"] === UPLOAD_ERR_OK) {
      $upload_dir = "uploads/wallet_proofs/";
      if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
      }
      
      $file_ext = pathinfo($_FILES["wallet_proof"]["name"], PATHINFO_EXTENSION);
      $filename = $transaction_id . "_" . time() . "." . $file_ext;
      $filepath = $upload_dir . $filename;
      
      if (move_uploaded_file($_FILES["wallet_proof"]["tmp_name"], $filepath)) {
        $wallet_proof_url = $filepath;
      }
    }

    // Server-side validation for wallet reference formats
    if ($payment_method === 'GCash') {
      if (!preg_match('/^[0-9]{13}$/', $wallet_contact)) {
        $msg = 'GCash Reference Number must be exactly 13 digits (numbers only).';
        if (isset($_POST['_ajax'])) { header('Content-Type: application/json'); echo json_encode(['status'=>'error','message'=>$msg]); exit; }
        $_SESSION['payment_error'] = $msg;
        header('Location: ?page=checkout');
        exit;
      }
    } elseif ($payment_method === 'Maya') {
      // Maya: require exactly 12 digits (numbers only)
      if (!preg_match('/^[0-9]{12}$/', $wallet_contact)) {
        $msg = 'Maya Reference ID must be exactly 12 digits (numbers only).';
        if (isset($_POST['_ajax'])) { header('Content-Type: application/json'); echo json_encode(['status'=>'error','message'=>$msg]); exit; }
        $_SESSION['payment_error'] = $msg;
        header('Location: ?page=checkout');
        exit;
      }
    }
    // Server-side validation for wallet account name (letters and spaces only, allow ñ/Ñ)
    if (!preg_match('/^[a-zA-ZñÑ ]+$/', $wallet_account)) {
      $msg = 'Account Name must contain letters and spaces only.';
      if (isset($_POST['_ajax'])) { header('Content-Type: application/json'); echo json_encode(['status'=>'error','message'=>$msg]); exit; }
      $_SESSION['payment_error'] = $msg;
      header('Location: ?page=checkout');
      exit;
    }
  }

  $cart_subtotal = 0;
  for ($i = 0; $i < count($cart_data); $i++) {
    $cart_subtotal += $cart_data[$i]["price"] * $cart_data[$i]["qty"];
  }
  $saleCalc      = calcSaleTotal($cart_subtotal, $discount_amount);

  // Detect whether wallet columns exist in sales table to avoid SQL errors
  $hasWalletCols = false;
  $colCheck = $conn->query("SHOW COLUMNS FROM sales LIKE 'wallet_contact_number'");
  if ($colCheck && $colCheck->num_rows > 0) {
    $hasWalletCols = true;
  }

  // Capture promotion details
  $promotion_id = (isset($_POST["promotion_id"]) && $_POST["promotion_id"] !== "") ? (int)$_POST["promotion_id"] : null;
  $promotion_name = (isset($_POST["promotion_name"]) && $_POST["promotion_name"] !== "") ? $conn->real_escape_string($_POST["promotion_name"]) : null;
  $promotion_type = (isset($_POST["promotion_type"]) && $_POST["promotion_type"] !== "") ? $conn->real_escape_string($_POST["promotion_type"]) : null;

  if ($hasWalletCols) {
    $stmt = $conn->prepare("INSERT INTO sales (transaction_id,sale_date,total_amount,tax_amount,discount_amount,payment_method,amount_tendered,wallet_contact_number,wallet_account_name,wallet_proof_image_url,promotion_id,promotion_name,promotion_type,status,employee_id,customer_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'Completed',?,?)");
    if ($stmt) {
      $stmt->bind_param("ssdddsdsssisssi", $transaction_id, $sale_date, $total_amount, $tax_amount, $discount_amount, $payment_method, $amount_tendered, $wallet_contact, $wallet_account, $wallet_proof_url, $promotion_id, $promotion_name, $promotion_type, $employee_id, $customer_id);
    }
  } else {
    $stmt = $conn->prepare("INSERT INTO sales (transaction_id,sale_date,total_amount,tax_amount,discount_amount,payment_method,amount_tendered,promotion_id,promotion_name,promotion_type,status,employee_id,customer_id) VALUES (?,?,?,?,?,?,?,?,?,?,'Completed',?,?)");
    if ($stmt) {
      $stmt->bind_param("ssdddsdisssi", $transaction_id, $sale_date, $total_amount, $tax_amount, $discount_amount, $payment_method, $amount_tendered, $promotion_id, $promotion_name, $promotion_type, $employee_id, $customer_id);
    }
  }

    if ($stmt && $stmt->execute()) {
    foreach ($cart_data as $item) {
      $sku   = $conn->real_escape_string($item["sku"]);
      $qty   = (int)$item["qty"];
      $price = floatval($item["price"]);
      $sub   = $price * $qty;
      $conn->query("INSERT INTO sale_items (transaction_id,sku,quantity,price_at_time,subtotal) VALUES ('$transaction_id','$sku',$qty,$price,$sub)");
      $conn->query("UPDATE inventory SET stock_qty = stock_qty - $qty WHERE sku = '$sku' AND stock_qty >= $qty");
    }
    if ($customer_id) {
      // Deduct redeemed points if provided (points_redeemed is in PHP float format)
      $raw_points_used = isset($_POST['points_redeemed']) ? $_POST['points_redeemed'] : 0;
      $points_used = is_numeric($raw_points_used) ? (int)floor(floatval($raw_points_used)) : 0;
      if ($points_used > 0) {
        $stmtDeduct = $conn->prepare("UPDATE customers SET loyalty_points = GREATEST(loyalty_points - ?, 0) WHERE customer_id = ?");
        if ($stmtDeduct) {
          $stmtDeduct->bind_param("ii", $points_used, $customer_id);
          $stmtDeduct->execute();
          $stmtDeduct->close();
        }
      }
      // Award loyalty points for this purchase (existing behaviour)
      $ptsEarned = 5;
      $updatePoints = $conn->prepare("UPDATE customers SET loyalty_points = loyalty_points + ? WHERE customer_id = ?");
      if ($updatePoints) {
        $updatePoints->bind_param("ii", $ptsEarned, $customer_id);
        $updatePoints->execute();
        $updatePoints->close();
      }
    }
    // Record selected showcase bundle (if checkout was opened from a showcase selection)
    $bundle_name_post = isset($_POST['bundle_name']) ? trim($_POST['bundle_name']) : '';
    if ($bundle_name_post !== '') {
      $bn_esc = $conn->real_escape_string($bundle_name_post);
      $bundle_qty = 1;
      $showcase_id = null;
      $p = $conn->prepare("SELECT showcase_id FROM showcase_bundles WHERE name = ? LIMIT 1");
      if ($p) {
        $p->bind_param('s', $bn_esc);
        $p->execute();
        $p->bind_result($sid);
        if ($p->fetch()) $showcase_id = (int)$sid;
        $p->close();
      }
      if ($showcase_id !== null) {
        $ins = $conn->prepare("INSERT INTO showcase_sales (transaction_id, showcase_id, bundle_name, quantity, sale_date, employee_id) VALUES (?,?,?,?,?,?)");
        if ($ins) {
          $ins->bind_param('sisiss', $transaction_id, $showcase_id, $bn_esc, $bundle_qty, $sale_date, $employee_id);
          $ins->execute();
          $ins->close();
        }
      } else {
        $ins = $conn->prepare("INSERT INTO showcase_sales (transaction_id, bundle_name, quantity, sale_date, employee_id) VALUES (?,?,?,?,?)");
        if ($ins) {
          $ins->bind_param('ssiss', $transaction_id, $bn_esc, $bundle_qty, $sale_date, $employee_id);
          $ins->execute();
          $ins->close();
        }
      }
    }
    // Compute a human-friendly order number for today's transactions (e.g. #1004)
    $sale_day_esc  = $conn->real_escape_string(date('Y-m-d', strtotime($sale_date)));
    $sale_date_esc = $conn->real_escape_string($sale_date);
    $txn_esc       = $conn->real_escape_string($transaction_id);
    $pos_row = $conn->query("SELECT COUNT(*) as n FROM sales WHERE DATE(sale_date)='$sale_day_esc' AND status='Completed' AND (sale_date < '$sale_date_esc' OR (sale_date = '$sale_date_esc' AND transaction_id <= '$txn_esc'))")->fetch_assoc();
    $pos_n = $pos_row ? (int)$pos_row['n'] : 1;
    $order_num = '#' . (1000 + $pos_n);
    $order_id_display = $order_num;
    $redirect_url = '?page=checkout&success=1&order_id=' . urlencode($order_id_display);
    // Clear the cart so the next transaction starts fresh
    cart_clear();
    // If request came from AJAX (FormData + fetch), return JSON so client can react
    if (isset($_POST['_ajax'])) {
      header('Content-Type: application/json');
      header('X-Session-Id: ' . session_id());
      echo json_encode(['status' => 'ok', 'redirect' => $redirect_url]);
      exit;
    }
    header("Location: $redirect_url");
    exit;
  }
}



// ── Inventory CRUD ────────────────────────────────────────────
if ($page === "inventory" && isset($_SESSION["user_role"]) && $_SESSION["user_role"] === "Admin") {
  function isValidBaseProductSku(string $sku): bool {
    return preg_match('/^[A-Za-z]+-\d{3}$/', $sku) === 1;
  }

  function isValidVariantSku(string $sku): bool {
    return preg_match('/^[A-Za-z]+-\d{3}-V\d+$/', $sku) === 1;
  }

  function isValidProductSku(string $sku): bool {
    return isValidBaseProductSku($sku) || isValidVariantSku($sku);
  }

  function getVariantBaseSku(string $sku): string {
    if (preg_match('/^([A-Za-z]+-\d{3})(?:-V\d+)?$/', $sku, $matches)) {
      return $matches[1];
    }
    return '';
  }

  function normalizeProductSku(string $sku): string {
    return strtoupper(trim($sku));
  }
if (isset($_POST["add_product"]) || isset($_POST["update_product"]) || isset($_POST["add_variant_submit"])) {
    $is_update  = isset($_POST["update_product"]);
    $is_variant = isset($_POST["add_variant_submit"]);
    
    // Choose parameters dynamically based on form layout types
    $sku        = normalizeProductSku(isset($_POST[$is_variant ? "new_sku" : "sku"]) ? $_POST[$is_variant ? "new_sku" : "sku"] : "");
    $old_sku    = normalizeProductSku(isset($_POST["old_sku"]) ? $_POST["old_sku"] : $sku);
    $name       = $conn->real_escape_string(isset($_POST[$is_variant ? "variant_name" : "name"]) ? $_POST[$is_variant ? "variant_name" : "name"] : "");
    $qty        = (int)(isset($_POST[$is_variant ? "variant_qty" : "qty"]) ? $_POST[$is_variant ? "variant_qty" : "qty"] : 0);

    // Only fetch price/cat/discount from the form if it's NOT a variant 
    $price      = !$is_variant ? floatval(str_replace(",", "", isset($_POST["price"]) ? $_POST["price"] : "0")) : 0.0;
    $cat_id     = (!$is_variant && isset($_POST["category_id"]) && $_POST["category_id"] !== "") ? (int)$_POST["category_id"] : null;
    $disc_id    = (!$is_variant && isset($_POST["discount_id"])  && $_POST["discount_id"]  !== "") ? (int)$_POST["discount_id"]  : null;

    if (!$is_variant && $sku === '') {
      $sku = getNextProductSku($conn);
    }

    // Determine whether this operation should validate as a variant
    $editing_variant = $is_variant || isValidVariantSku($old_sku);
    if ($editing_variant) {
      if (!isValidVariantSku($sku)) {
        $_SESSION['inventory_error'] = 'Variant SKU must follow the format PR-001-V1 and be generated from the parent product.';
        header('Location: ?page=inventory&tab=items');
        exit;
      }
    } else {
      if (!isValidBaseProductSku($sku)) {
        $_SESSION['inventory_error'] = 'SKU must follow the format PR-001 and only use letters before the dash.';
        header('Location: ?page=inventory&tab=items');
        exit;
      }
    }

    if (!$is_variant && !is_float($price)) {
      $price = 0.0;
    }

    // Determine base image fallback path
    $image_path = "";
    if ($is_variant) {
      $orig_sku = normalizeProductSku(isset($_POST["original_sku"]) ? $_POST["original_sku"] : "");
      if (!isValidBaseProductSku($orig_sku) || getVariantBaseSku($sku) !== $orig_sku) {
        $_SESSION['inventory_error'] = 'Variant SKU must be derived from the parent SKU, for example PR-001-V1.';
        header('Location: ?page=inventory&tab=items');
        exit;
      }

      $parent_stmt = $conn->prepare("SELECT price, category_id, discount_id, image_url FROM inventory WHERE sku = ?");
      if ($parent_stmt) {
        $parent_stmt->bind_param("s", $orig_sku);
        $parent_stmt->execute();
        $parent_res = $parent_stmt->get_result()->fetch_assoc();
        if ($parent_res) {
          $price      = floatval($parent_res['price']);
          $cat_id     = $parent_res['category_id'];
          $disc_id    = $parent_res['discount_id'];
          $image_path = $parent_res['image_url']; // Fallback variant image to parent's URL
        }
        $parent_stmt->close();
      }
    }

    // PROCESS FILE UPLOADS FOR BOTH REGULAR EDITS AND VARIANTS
    $image_upload_error = "";
    if (isset($_FILES["product_image"]) && $_FILES["product_image"]["error"] !== UPLOAD_ERR_NO_FILE) {
      if ($_FILES["product_image"]["error"] !== UPLOAD_ERR_OK) {
        $upload_errors = [
          UPLOAD_ERR_INI_SIZE => "File is larger than upload_max_filesize",
          UPLOAD_ERR_FORM_SIZE => "File is larger than form MAX_FILE_SIZE",
          UPLOAD_ERR_PARTIAL => "File was only partially uploaded",
          UPLOAD_ERR_NO_TMP_DIR => "Missing temporary folder",
          UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk",
          UPLOAD_ERR_EXTENSION => "File upload stopped by extension"
        ];
        $image_upload_error = $upload_errors[$_FILES["product_image"]["error"]] ?? "Unknown upload error";
      } else {
        $dir = "uploads/";
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $uploaded_file = $dir . time() . "_" . basename($_FILES["product_image"]["name"]);
        if (move_uploaded_file($_FILES["product_image"]["tmp_name"], $uploaded_file)) {
          $image_path = $uploaded_file; // Explicitly assigns to $image_path so updates pick it up!
        } else {
          $image_upload_error = "Failed to move uploaded file. Check directory permissions.";
        }
      }
      if ($image_upload_error !== "") {
        $_SESSION['inventory_warning'] = "Product saved but image upload failed: " . $image_upload_error;
      }
    }

    if ($is_update) {
      if ($old_sku !== $sku) {
        $_SESSION['inventory_error'] = 'SKU cannot be changed while editing a product. Create a new product record instead.';
        header('Location: ?page=inventory&tab=items');
        exit;
      }
      
      // If $image_path has a value, it means a new file was uploaded successfully
      if ($image_path !== "") {
        $stmt = $conn->prepare("UPDATE inventory SET product_name = ?, price = ?, stock_qty = ?, category_id = ?, image_url = ?, discount_id = ? WHERE sku = ?");
        $stmt->bind_param("sdissis", $name, $price, $qty, $cat_id, $image_path, $disc_id, $old_sku);
      } else {
        // Keeps your current image completely safe if no new file is uploaded
        $stmt = $conn->prepare("UPDATE inventory SET product_name = ?, price = ?, stock_qty = ?, category_id = ?, discount_id = ? WHERE sku = ?");
        $stmt->bind_param("sdiiis", $name, $price, $qty, $cat_id, $disc_id, $old_sku);
      }
    } else {
      // This path handles both regular new products AND variants seamlessly
      $stmt = $conn->prepare("INSERT INTO inventory (sku, product_name, price, stock_qty, category_id, discount_id, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
      $stmt->bind_param("ssdiiis", $sku, $name, $price, $qty, $cat_id, $disc_id, $image_path);
    }

    $dbSuccess = false;
    if ($stmt) {
      $dbSuccess = $stmt->execute();
      $stmt->close();
    }

    $category_name = '';
    if ($cat_id !== null) {
      $categoryStmt = $conn->prepare("SELECT category_name FROM categories WHERE category_id = ?");
      if ($categoryStmt) {
        $categoryStmt->bind_param("i", $cat_id);
        $categoryStmt->execute();
        $categoryStmt->bind_result($category_name);
        $categoryStmt->fetch();
        $categoryStmt->close();
      }
    }

    $productRow = [
      'sku' => $sku,
      'product_name' => $name,
      'price' => $price,
      'stock_qty' => $qty,
      'category_name' => $category_name,
      'description' => '',
      'discount_id' => null,
    ];

    if ($dbSuccess) {
      $product = Product::fromDbRow($productRow);
      if ($disc_id !== null) {
        $product->putOnSale($conn, (string)$disc_id);
      } else {
        $product->takeOffSale($conn);
      }
    }

    header("Location: ?page=inventory&tab=items");
    exit;
  }

// Handle Add Variant Form Submission
  if (isset($_POST["add_variant_submit"])) {
    $original_sku = normalizeProductSku($_POST["original_sku"] ?? "");
    $new_sku      = normalizeProductSku($_POST["new_sku"] ?? "");
    $variant_name = $conn->real_escape_string($_POST["variant_name"] ?? "");
    $variant_qty  = (int)($_POST["variant_qty"] ?? 0);

    if (!isValidVariantSku($new_sku) || !isValidBaseProductSku($original_sku)) {
      $_SESSION['inventory_error'] = 'Variant SKU must follow the format PR-001-V1 and derive from the original product.';
      header('Location: ?page=inventory&tab=items');
      exit;
    }

    if (getVariantBaseSku($new_sku) !== $original_sku) {
      $_SESSION['inventory_error'] = 'Variant SKU must be based on the original SKU, for example PR-001-V1.';
      header('Location: ?page=inventory&tab=items');
      exit;
    }

    addVariantWithClone($conn, $original_sku, $new_sku, $variant_name, $variant_qty);

    header("Location: ?page=inventory&tab=items");
    exit;
  }

  if (isset($_POST["delete_sku"])) {
    $sku = $conn->real_escape_string(isset($_POST["delete_sku"]) ? $_POST["delete_sku"] : "");
    $conn->query("DELETE FROM inventory WHERE sku='$sku'");
    header("Location: ?page=inventory&tab=items");
    exit;
  }
  if (isset($_POST["add_category"])) {
    $n = $conn->real_escape_string(isset($_POST["category_name"]) ? $_POST["category_name"] : "");
    $conn->query("INSERT INTO categories (category_name) VALUES ('$n')");
    header("Location: ?page=inventory&tab=categories");
    exit;
  }
  if (isset($_POST["update_category"])) {
    $id = (int)(isset($_POST["category_id"])   ? $_POST["category_id"]   : 0);
    $n  = $conn->real_escape_string(isset($_POST["category_name"]) ? $_POST["category_name"] : "");
    $conn->query("UPDATE categories SET category_name='$n' WHERE category_id=$id");
    header("Location: ?page=inventory&tab=categories");
    exit;
  }
  if (isset($_POST["delete_category"])) {
    $id = (int)(isset($_POST["category_id"]) ? $_POST["category_id"] : 0);
    $conn->query("DELETE FROM categories WHERE category_id=$id");
    header("Location: ?page=inventory&tab=categories");
    exit;
  }
  if (isset($_POST["assign_items_submit"])) {
    $cat_id = (int)(isset($_POST["category_id"]) ? $_POST["category_id"] : 0);
    if (isset($_POST["assign_skus"]) && !empty($_POST["assign_skus"]))
      foreach ($_POST["assign_skus"] as $s) {
        $s = $conn->real_escape_string($s);
        $conn->query("UPDATE inventory SET category_id=$cat_id WHERE sku='$s'");
      }
    header("Location: ?page=inventory&tab=categories");
    exit;
  }
  if (isset($_POST["unlink_sku"])) {
    $s = $conn->real_escape_string(isset($_POST["unlink_sku"]) ? $_POST["unlink_sku"] : "");
    $conn->query("UPDATE inventory SET category_id=NULL WHERE sku='$s'");
    header("Location: ?page=inventory&tab=categories");
    exit;
  }
  if (isset($_POST["add_discount"])) {
    $d_name   = $conn->real_escape_string(isset($_POST["d_name"])   ? $_POST["d_name"]   : "");
    $d_type   = $conn->real_escape_string(isset($_POST["d_type"])   ? $_POST["d_type"]   : "percent");
    $d_value  = floatval(str_replace(",", "", isset($_POST["d_value"])  ? $_POST["d_value"]  : "0"));
    $d_status = (int)(isset($_POST["d_status"]) ? $_POST["d_status"] : 0);
    $d_expiry = (isset($_POST["d_expiry"]) && $_POST["d_expiry"] !== "") ? "'" . $conn->real_escape_string($_POST["d_expiry"]) . "'" : "NULL";
    $conn->query("INSERT INTO discounts (discount_name,discount_type,discount_value,status,expiry_date) VALUES ('$d_name','$d_type',$d_value,$d_status,$d_expiry)");
    header("Location: ?page=inventory&tab=discounts");
    exit;
  }
  if (isset($_POST["toggle_discount_status"])) {
    $id         = (int)(isset($_POST["toggle_id"])       ? $_POST["toggle_id"]       : 0);
    $new_status = ((int)(isset($_POST["current_status"]) ? $_POST["current_status"]  : 0) === 1) ? 0 : 1;
    $conn->query("UPDATE discounts SET status=$new_status WHERE discount_id=$id");
    header("Location: ?page=inventory&tab=discounts");
    exit;
  }
  if (isset($_POST["delete_discount"])) {
    $id = (int)(isset($_POST["delete_discount_id"]) ? $_POST["delete_discount_id"] : 0);
    $conn->query("DELETE FROM discounts WHERE discount_id=$id");
    header("Location: ?page=inventory&tab=discounts");
    exit;
  }
}

// ── CRM ───────────────────────────────────────────────────────
if ($page === "crm") {
  if (isset($_POST["add_customer"])) {
    $n_raw = isset($_POST["full_name"]) ? trim($_POST["full_name"]) : '';
    $nameCheck = validateStaffName($n_raw);
    if ($nameCheck !== true) {
      $_SESSION["crm_error"] = $nameCheck;
      header("Location: ?page=crm");
      exit;
    }
    $n = $conn->real_escape_string($n_raw);
    $email_raw = isset($_POST["contact_email"]) ? trim($_POST["contact_email"]) : '';
    $phone_raw = isset($_POST["contact_number"]) ? trim($_POST["contact_number"]) : '';

    // Validation: email required, must be gmail and only letters/numbers/periods before @
    if ($email_raw === '' || !preg_match('/^[A-Za-z0-9.]+@gmail\.com$/i', $email_raw)) {
      $_SESSION["crm_error"] = 'Email is required and must be a valid Gmail address (only letters, numbers and periods allowed before @gmail.com).';
      header("Location: ?page=crm");
      exit;
    }

    // Validation: phone required, must be exactly 11 digits
    if (!preg_match('/^[0-9]{11}$/', $phone_raw)) {
      $_SESSION["crm_error"] = 'Contact number is required and must be exactly 11 digits (numbers only).';
      header("Location: ?page=crm");
      exit;
    }

    $c_email = $conn->real_escape_string($email_raw);
    $c_phone = $conn->real_escape_string($phone_raw);
    $c_combined = $conn->real_escape_string($c_email . ' / ' . $c_phone);
    // ── NEW: customer photo upload ──
    $photo_path = "";
    if (isset($_FILES["cust_photo"]) && $_FILES["cust_photo"]["error"] === 0) {
      $dir = "uploads/";
      if (!is_dir($dir)) mkdir($dir, 0777, true);
      $photo_path = $conn->real_escape_string($dir . time() . "_" . basename($_FILES["cust_photo"]["name"]));
      move_uploaded_file($_FILES["cust_photo"]["tmp_name"], $photo_path);
    }
    // Member Since handling: accept an optional date input and store into member_since
    $member_since = isset($_POST['member_since']) && $_POST['member_since'] !== '' ? $conn->real_escape_string($_POST['member_since']) : null;
    $member_since_sql = $member_since ? "'" . $member_since . "'" : "NULL";
    $creator = $conn->real_escape_string($_SESSION['user_id']);
    if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'Admin') {
      // Admin creates an approved customer
      $now = date('Y-m-d H:i:s');
      $conn->query("INSERT INTO customers (full_name,contact_info,contact_email,contact_number,photo_url,member_since,approved,created_by,approved_by,approved_at) VALUES ('$n','$c_combined','$c_email','$c_phone','$photo_path', $member_since_sql, 1, '$creator', '$creator', '$now')");
      $cid = $conn->insert_id;
      if ($cid) {
        $conn->query("INSERT INTO customer_approval_history (customer_id,action,by_employee_id,note) VALUES ($cid,'created_and_approved','$creator','Created by admin and auto-approved')");
      }
    } else {
      // Cashier creates a pending customer
      $conn->query("INSERT INTO customers (full_name,contact_info,contact_email,contact_number,photo_url,member_since,approved,created_by) VALUES ('$n','$c_combined','$c_email','$c_phone','$photo_path', $member_since_sql, 0, '$creator')");
      $cid = $conn->insert_id;
      if ($cid) {
        $conn->query("INSERT INTO customer_approval_history (customer_id,action,by_employee_id,note) VALUES ($cid,'created_pending','$creator','Created by cashier, pending admin approval')");
      }
    }
    header("Location: ?page=crm");
    exit;
  }
  if (isset($_POST["delete_customer"]) && isset($_SESSION['user_role']) && ($_SESSION['user_role'] === 'Admin' || $_SESSION['user_role'] === 'Cashier')) {
    $id = (int)(isset($_POST["customer_id"]) ? $_POST["customer_id"] : 0);

    // Clean up approval history associated with this customer first to avoid relational conflicts
    $conn->query("DELETE FROM customer_approval_history WHERE customer_id=$id");

    // Wipe customer permanently from database
    $conn->query("DELETE FROM customers WHERE customer_id=$id");

    header("Location: ?page=crm");
    exit;
  }
  if (isset($_POST["update_customer"]) && isset($_SESSION['user_role']) && ($_SESSION['user_role'] === 'Admin' || $_SESSION['user_role'] === 'Cashier')) {
    $id = (int)(isset($_POST["customer_id"])  ? $_POST["customer_id"]  : 0);
    $n_raw = isset($_POST["full_name"]) ? trim($_POST["full_name"]) : '';
    $nameCheck = validateStaffName($n_raw);
    if ($nameCheck !== true) {
      $_SESSION["crm_error"] = $nameCheck;
      header("Location: ?page=crm");
      exit;
    }
    $n  = $conn->real_escape_string($n_raw);
    $email_raw = isset($_POST["contact_email"]) ? trim($_POST["contact_email"]) : '';
    $phone_raw = isset($_POST["contact_number"]) ? trim($_POST["contact_number"]) : '';

    if ($email_raw === '' || !preg_match('/^[A-Za-z0-9.]+@gmail\.com$/i', $email_raw)) {
      $_SESSION["crm_error"] = 'Email is required and must be a valid Gmail address (only letters, numbers and periods allowed before @gmail.com).';
      header("Location: ?page=crm");
      exit;
    }
    if (!preg_match('/^[0-9]{11}$/', $phone_raw)) {
      $_SESSION["crm_error"] = 'Contact number is required and must be exactly 11 digits (numbers only).';
      header("Location: ?page=crm");
      exit;
    }

    $c_email = $conn->real_escape_string($email_raw);
    $c_phone = $conn->real_escape_string($phone_raw);
    $c_combined = $conn->real_escape_string($c_email . ' / ' . $c_phone);
    // ── NEW: customer photo upload ──
    $photo_sql = "";
    if (isset($_FILES["cust_photo"]) && $_FILES["cust_photo"]["error"] === 0) {
      $dir = "uploads/";
      if (!is_dir($dir)) mkdir($dir, 0777, true);
      $photo_path = $conn->real_escape_string($dir . time() . "_" . basename($_FILES["cust_photo"]["name"]));
      move_uploaded_file($_FILES["cust_photo"]["tmp_name"], $photo_path);
      $photo_sql = ", photo_url='$photo_path'";
    }
    // Allow updating Member Since (`member_since`) if provided
    $created_sql = "";
    if (isset($_POST['member_since']) && $_POST['member_since'] !== '') {
      $ms = $conn->real_escape_string($_POST['member_since']);
      $created_sql = ", member_since='$ms'";
    }
    $conn->query("UPDATE customers SET full_name='$n',contact_info='$c_combined',contact_email='$c_email',contact_number='$c_phone'$photo_sql$created_sql WHERE customer_id=$id");
    header("Location: ?page=crm");
    exit;
  }
  // Approve a pending customer (Admin only)
  if (isset($_POST["approve_customer"]) && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'Admin') {
    $id = (int)(isset($_POST["customer_id"]) ? $_POST["customer_id"] : 0);
    $admin = $conn->real_escape_string($_SESSION['user_id']);
    $now = date('Y-m-d H:i:s');
    $conn->query("UPDATE customers SET approved=1, approved_by='$admin', approved_at='$now', rejection_reason=NULL WHERE customer_id=$id");
    $conn->query("INSERT INTO customer_approval_history (customer_id,action,by_employee_id,note) VALUES ($id,'approved','$admin','Approved by admin')");
    header("Location: ?page=crm");
    exit;
  }
  // Reject a pending customer (Admin only)
  if (isset($_POST["reject_customer"]) && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'Admin') {
    $id = (int)(isset($_POST["customer_id"]) ? $_POST["customer_id"] : 0);
    $reason = $conn->real_escape_string(isset($_POST['rejection_reason']) ? $_POST['rejection_reason'] : 'Rejected by admin');
    $admin = $conn->real_escape_string($_SESSION['user_id']);
    $now = date('Y-m-d H:i:s');
    $conn->query("UPDATE customers SET approved=2, approved_by='$admin', approved_at='$now', rejection_reason='$reason' WHERE customer_id=$id");
    $conn->query("INSERT INTO customer_approval_history (customer_id,action,by_employee_id,note) VALUES ($id,'rejected','$admin','" . $reason . "')");
    header("Location: ?page=crm");
    exit;
  }
}


// ── Employees ─────────────────────────────────────────────────
if ($page === "employees" && $_SESSION["user_role"] === "Admin") {
  if (isset($_POST["update_employee"])) {
    $id       = $conn->real_escape_string(isset($_POST["employee_id"]) ? $_POST["employee_id"] : "");
    $name     = $conn->real_escape_string(isset($_POST["full_name"])   ? $_POST["full_name"]   : "");
    $role     = $conn->real_escape_string(isset($_POST["role"])        ? $_POST["role"]        : "Cashier");
    $job_role = ($role === "Admin") ? "Manager" : "Cashier";
    // ── NEW: employee photo upload ──
    $photo_sql  = "";
    $photo_path = null;
    if (isset($_FILES["emp_photo"]) && $_FILES["emp_photo"]["error"] === 0) {
      $dir = "uploads/";
      if (!is_dir($dir)) mkdir($dir, 0777, true);
      $photo_path = $conn->real_escape_string($dir . time() . "_" . basename($_FILES["emp_photo"]["name"]));
      move_uploaded_file($_FILES["emp_photo"]["tmp_name"], $photo_path);
      $photo_sql = ", photo_url='$photo_path'";
    }
    $conn->query("UPDATE employees SET full_name='$name',role='$role',job_role='$job_role'$photo_sql WHERE employee_id='$id'");
    // ── NEW: keep session in sync if editing own profile ──
    if ($id === $_SESSION["user_id"]) {
      $_SESSION["user_name"] = $name;
      if ($photo_path !== null) {
        $_SESSION["user_photo"] = $photo_path;
      }
    }
    header("Location: ?page=employees");
    exit;
  }
  if (isset($_POST["delete_employee"])) {
    $id = $conn->real_escape_string(isset($_POST["employee_id"]) ? $_POST["employee_id"] : "");
    $conn->query("DELETE FROM employees WHERE employee_id='$id'");
    header("Location: ?page=employees");
    exit;
  }
  if (isset($_POST["reset_passcode"])) {
    $id = $conn->real_escape_string(isset($_POST["employee_id"])  ? $_POST["employee_id"]  : "");
    $pc = $conn->real_escape_string(isset($_POST["new_passcode"]) ? $_POST["new_passcode"] : "");
    $conn->query("UPDATE employees SET passcode='$pc' WHERE employee_id='$id'");
    header("Location: ?page=employees");
    exit;
  }
}

// Ensure predefined categories exist (Main / Filler / Greenery)
$__predef = ['Main', 'Filler', 'Greenery'];
foreach ($__predef as $__pn) {
    $__esc = $conn->real_escape_string($__pn);
    $__chk = $conn->query("SELECT category_id FROM categories WHERE LOWER(category_name) = LOWER('$__esc') LIMIT 1");
    if ($__chk && $__chk->num_rows === 0) {
    try {
      $conn->query("INSERT INTO categories (category_name) VALUES ('$__esc')");
    } catch (mysqli_sql_exception $e) {
      // If insert fails due to schema issues (duplicate PK, missing AUTO_INCREMENT),
      // skip here to avoid fatal uncaught exception. Admin should repair DB schema.
    }
    }
}


// ══ DATA FETCH ═══════════════════════════════════════════════
$cats_res  = $conn->query("SELECT * FROM categories ORDER BY category_name");
$cats      = $cats_res ? $cats_res->fetch_all(MYSQLI_ASSOC) : [];
$discs_res = $conn->query("SELECT * FROM discounts ORDER BY discount_id");
$discounts = $discs_res ? $discs_res->fetch_all(MYSQLI_ASSOC) : [];

$inv_res   = $conn->query("SELECT i.*,c.category_name,d.discount_name,d.discount_value,d.discount_type,d.status as disc_status FROM inventory i LEFT JOIN categories c ON i.category_id=c.category_id LEFT JOIN discounts d ON i.discount_id=d.discount_id ORDER BY i.product_name");
$inventory = $inv_res ? $inv_res->fetch_all(MYSQLI_ASSOC) : [];
$inventoryRef = &$inventory;

$active_promos_res = $conn->query("SELECT * FROM discounts WHERE status=1");
$active_promos     = $active_promos_res ? $active_promos_res->fetch_all(MYSQLI_ASSOC) : [];

// Approved customers (for checkout selection)
$customers_res = $conn->query("SELECT * FROM customers WHERE approved=1 ORDER BY full_name");
$customers     = $customers_res ? $customers_res->fetch_all(MYSQLI_ASSOC) : [];
// All customers (for admin CRM view, includes pending)
$customers_all_res = $conn->query("SELECT * FROM customers ORDER BY full_name");
$customers_all     = $customers_all_res ? $customers_all_res->fetch_all(MYSQLI_ASSOC) : [];

$employees_res = $conn->query("SELECT * FROM employees ORDER BY role DESC, full_name");
$employees     = $employees_res ? $employees_res->fetch_all(MYSQLI_ASSOC) : [];

// Build employee id => name map for quick lookup
$empMap = [];
foreach ($employees as $e) {
  $empMap[$e['employee_id']] = $e['full_name'];
}

// Fetch approval history and map by customer_id
$historyMap = [];
$hist_res = $conn->query("SELECT * FROM customer_approval_history ORDER BY ts DESC");
if ($hist_res) {
  while ($h = $hist_res->fetch_assoc()) {
    $cid = $h['customer_id'];
    if (!isset($historyMap[$cid])) $historyMap[$cid] = [];
    $historyMap[$cid][] = $h;
  }
}

// Fetch customer transaction history and map by customer_id
$customerSalesMap = [];
$sales_res = $conn->query("SELECT s.*, e.full_name as cashier FROM sales s LEFT JOIN employees e ON s.employee_id=e.employee_id WHERE s.customer_id IS NOT NULL ORDER BY s.sale_date DESC");
if ($sales_res) {
  while ($sale = $sales_res->fetch_assoc()) {
    $sale['items'] = [];
    $txn_esc = $conn->real_escape_string($sale['transaction_id']);
    $items_res = $conn->query("SELECT si.sku, si.quantity, si.price_at_time, si.subtotal, COALESCE(i.product_name, '') as product_name FROM sale_items si LEFT JOIN inventory i ON si.sku=i.sku WHERE si.transaction_id='$txn_esc'");
    if ($items_res) {
      while ($item = $items_res->fetch_assoc()) {
        $sale['items'][] = $item;
      }
    }
    $cid = (int)$sale['customer_id'];
    if (!isset($customerSalesMap[$cid])) $customerSalesMap[$cid] = [];
    $customerSalesMap[$cid][] = $sale;
  }
}

$date_today      = date("Y-m-d");
$daily_rev       = 0;
$daily_trx       = 0;
$low_stock       = 0;
$total_items     = count($inventory);
$total_customers = count($customers);

$trx_row   = $conn->query("SELECT COUNT(*) as t FROM sales WHERE DATE(sale_date)='$date_today' AND status='Completed'")->fetch_assoc();
$daily_trx = (int)$trx_row["t"];

if ($page === "dashboard") {
  $rev_row   = $conn->query("SELECT COALESCE(SUM(total_amount),0) as t FROM sales WHERE DATE(sale_date)='$date_today' AND status='Completed'")->fetch_assoc();
  $daily_rev = floatval($rev_row["t"]);
  $low_stock = (int)$conn->query("SELECT COUNT(*) as t FROM inventory WHERE stock_qty < 10")->fetch_assoc()["t"];
}

$report_period = isset($_GET["period"]) ? $_GET["period"] : "today";
echo "<!-- [Event] report_period='" . htmlspecialchars($report_period, ENT_QUOTES, 'UTF-8') . "' -->\n";
switch ($report_period) { //switch statement to determine date range for reports based on 'period' query parameter
  case "week":
    $r_where = "DATE(sale_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    break;
  case "month":
    $r_where = "DATE(sale_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    break;
  default:
    $r_where = "DATE(sale_date) = '$date_today'";
}
$r_rev = 0;
$r_trx = 0;
$r_sales = null;
$r_top = null;
if ($page === "reports") {
  $r_rev   = $conn->query("SELECT COALESCE(SUM(total_amount),0) as t FROM sales WHERE $r_where AND status='Completed'")->fetch_assoc()["t"];
  $r_trx   = $conn->query("SELECT COUNT(*) as t FROM sales WHERE $r_where AND status='Completed'")->fetch_assoc()["t"];
  $r_sales = $conn->query("SELECT s.*,e.full_name as cashier FROM sales s LEFT JOIN employees e ON s.employee_id=e.employee_id WHERE $r_where ORDER BY s.sale_date DESC LIMIT 100");
  $r_top   = $conn->query("SELECT i.product_name,SUM(si.quantity) as cnt,SUM(si.subtotal) as rev FROM sale_items si JOIN inventory i ON si.sku=i.sku JOIN sales s ON si.transaction_id=s.transaction_id WHERE $r_where AND s.status='Completed' GROUP BY si.sku ORDER BY cnt DESC LIMIT 5");
    // Determine best selling showcase bundle for the selected period
    $r_best_bundle = '—';
    $bestQ = $conn->query("SELECT COALESCE(sb.name, ss.bundle_name) as name, SUM(ss.quantity) as cnt FROM showcase_sales ss LEFT JOIN showcase_bundles sb ON ss.showcase_id=sb.showcase_id WHERE $r_where GROUP BY ss.showcase_id, ss.bundle_name ORDER BY cnt DESC LIMIT 1");
    if ($bestQ && $bestQ->num_rows) {
      $br = $bestQ->fetch_assoc();
      if (!empty($br['name'])) $r_best_bundle = $br['name'];
    }
}

$activeTab = isset($_GET["tab"]) ? $_GET["tab"] : "items";
echo "<!-- [Event] activeTab='" . htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') . "' -->\n";

// ══ HELPER FUNCTIONS ═════════════════════════════════════════

function effectivePrice($item) //function to calculate effective price of an item after applying discount if applicable
{
  $p = floatval($item["price"]);
  if (!empty($item["disc_status"]) && $item["disc_status"] == 1 && !empty($item["discount_value"])) {
    $dtype = strtolower((string)$item["discount_type"]);
    switch ($dtype) {
      case "percentage":
      case "percent":
        return $p * (1 - $item["discount_value"] / 100);
      case "fixed":
        return max(0, $p - $item["discount_value"]);
    }
  }
  return $p;
}

function makeInitials($fullName)
{
  if (str_word_count($fullName) === 0) return "?"; //str_word_count() to check if name is empty or only whitespace
  $words = array_values(array_filter(explode(" ", trim($fullName))));
  if (empty($words)) return "?";
  $initials = "";
  $i = 0;
  do {
    $initials .= strtoupper($words[$i][0]);
    $i++;
  } while ($i < min(count($words), 3));
  return $initials;
}

function validateStaffName($name)
{
  $name = trim($name);
  if (empty($name))                          return "Name is required.";
  // Allow letters, spaces, and the Spanish ñ/Ñ character
  if (!preg_match("/^[a-zA-ZñÑ ]*$/", $name)) return "Name must contain letters and spaces only (ñ allowed).";
  if (strlen($name) < 3)                     return "Name must be at least 3 characters."; //strlen() to check minimum length of staff name
  return true;
}

function validateEmail($email)
{
  $email = trim($email);
  if (empty($email)) return true;
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return "Invalid email format.";
  return true;
}

function generateTransactionRef($employeeId)
{
  $letters = strtoupper(substr(preg_replace("/[^A-Za-z]/", "", $employeeId), 0, 3));
  $counter  = 1000 + rand(0, 8999); //random 4-digit number starting from 1000
  return "TXN-" . $letters . $counter;
}

function calcSaleTotal($subtotal, $discountAmount = 0)
{
  $taxable = $subtotal - $discountAmount;
  $tax     = round($taxable * 0.12, 2);
  $total   = round($taxable + $tax, 2);
  return ["subtotal" => $subtotal, "discount" => $discountAmount, "tax" => $tax, "total" => $total];
}

function factorial(int $n): int
{
  $n      = max(0, $n);
  $result = 1;
  for ($i = 2; $i <= $n; $i++) {
    $result *= $i;
  }
  return $result;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($store_info['name'], ENT_QUOTES, 'UTF-8'); ?></title>
  <?php echo '<!-- ' . htmlspecialchars($store_info['name'], ENT_QUOTES, 'UTF-8')
    . ' | Payment methods: ' . count($accepted_payments)
    . ' | Possible checkout sequences: ' . factorial(count($accepted_payments))
    . ' -->' . "\n"; ?>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    :root {
      --espresso: #382E28;
      --chestnut: #7C5A44;
      --chestnut-d: #5E4230;
      --chestnut-l: #9B7360;
      --taupe: #D4BCA9;
      --taupe-l: #EDE0D6;
      --oatmeal: #F8F5F2;
      --oatmeal-d: #F0EAE4;
      --white: #FFFFFF;

      --accent: var(--chestnut);
      --accent-d: var(--chestnut-d);
      --bg: var(--oatmeal);
      --surface: var(--white);
      --border: var(--taupe);
      --text: var(--espresso);
      --text-2: #5C4B3F;
      --text-3: #A08872;

      --green: #3D7A5A;
      --green-l: #D6EDDF;
      --amber: #B07D30;
      --amber-l: #F5E9D0;
      --red: #A83232;
      --red-l: #F5D6D6;
      --blue: #3A6B8A;
      --blue-l: #D6E8F5;

      --radius: 12px;
      --radius-lg: 18px;
      --radius-xl: 24px;
      --sidebar: 248px;
      --shadow: 0 1px 4px rgba(56, 46, 40, .07), 0 2px 6px rgba(56, 46, 40, .05);
      --shadow-md: 0 4px 16px rgba(56, 46, 40, .10);
      --shadow-lg: 0 8px 32px rgba(56, 46, 40, .14);
      --shadow-xl: 0 16px 48px rgba(56, 46, 40, .18);
    }

    html,
    body {
      height: 100%;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
      font-size: 15.5px;
      color: var(--text);
      background: var(--bg);
      -webkit-font-smoothing: antialiased;
    }

    .app {
      display: flex;
      height: 100vh;
      overflow: hidden;
    }

    .sidebar {
      width: var(--sidebar);
      background: var(--espresso);
      display: flex;
      flex-direction: column;
      flex-shrink: 0;
      overflow-y: auto;
    }

    .main {
      flex: 1;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      background: var(--oatmeal);
    }

    .page {
      padding: 36px 40px;
      flex: 1;
    }

    /* ── Sidebar ── */
    .sb-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 26px 22px 20px;
      border-bottom: 1px solid rgba(212, 188, 169, .18);
      background: linear-gradient(180deg, rgba(124, 90, 68, .25) 0%, transparent 100%);
    }

    .sb-brand-text {
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .sb-brand-logo {
      width: 56px;
      height: auto;
      display: block;
      margin: 0;
    }

    .sb-brand-name {
      font-size: 17px;
      font-weight: 800;
      color: var(--taupe-l);
      letter-spacing: -.3px;
      margin-bottom: 1px;
    }

    .sb-brand-sub {
      font-size: 12px;
      color: var(--taupe);
      margin-top: 0;
      opacity: .75;
    }

    .sb-section {
      padding: 18px 18px 6px;
      font-size: 11px;
      font-weight: 700;
      color: var(--taupe);
      text-transform: uppercase;
      letter-spacing: .12em;
      opacity: .55;
    }

    .sb-link {
      display: flex;
      align-items: center;
      gap: 11px;
      padding: 11px 18px;
      margin: 2px 10px;
      border-radius: var(--radius);
      color: rgba(237, 224, 214, .75);
      text-decoration: none;
      font-size: 14px;
      font-weight: 500;
      transition: all .18s cubic-bezier(.4, 0, .2, 1);
    }

    .sb-link:hover {
      background: rgba(212, 188, 169, .14);
      color: var(--taupe-l);
      transform: translateX(2px);
    }

    .sb-link.active {
      background: linear-gradient(135deg, var(--chestnut), var(--chestnut-d));
      color: #fff;
      box-shadow: 0 3px 10px rgba(94, 66, 48, .4);
    }

    .sb-link svg,
    .sb-link img {
      width: 17px;
      height: 17px;
      opacity: .85;
      flex-shrink: 0;
      object-fit: contain;
    }

    .sb-link.active svg,
    .sb-link.active img {
      opacity: 1;
    }

    .sb-footer {
      margin-top: auto;
      padding: 18px;
      border-top: 1px solid rgba(212, 188, 169, .15);
    }

    .sb-user {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    /* ── UPDATED: sb-avatar now supports photo ── */
    .sb-avatar {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--chestnut-l), var(--chestnut-d));
      color: #fff;
      font-weight: 700;
      font-size: 13px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      box-shadow: 0 2px 8px rgba(56, 46, 40, .3);
      overflow: hidden;
      position: relative;
      cursor: default;
    }

    .sb-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 50%;
      display: block;
    }

    .sb-user-info {
      min-width: 0;
      flex: 1;
    }

    .sb-user-name {
      font-size: 14px;
      font-weight: 600;
      color: var(--taupe-l);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .sb-user-role {
      font-size: 12px;
      color: var(--taupe);
      opacity: .7;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .sb-logout {
      display: block;
      margin-top: 12px;
      padding: 9px;
      border-radius: var(--radius);
      text-align: center;
      color: var(--taupe);
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
      background: rgba(212, 188, 169, .1);
      border: 1px solid rgba(212, 188, 169, .2);
      transition: all .18s;
    }

    .sb-logout:hover {
      background: rgba(168, 50, 50, .25);
      color: #f5d6d6;
      border-color: rgba(168, 50, 50, .35);
    }

    /* ── Page header ── */
    .page-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 32px;
    }

    .page-title {
      font-size: 26px;
      font-weight: 800;
      letter-spacing: -.5px;
      color: var(--espresso);
    }

    .page-sub {
      font-size: 14px;
      color: var(--text-3);
      margin-top: 3px;
    }

    /* ── Cards ── */
    .card {
      background: var(--surface);
      border: 1px solid var(--taupe);
      border-radius: var(--radius-lg);
      padding: 24px 28px;
      box-shadow: var(--shadow);
    }

    .stat-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
      margin-bottom: 28px;
    }

    .stat-card {
      background: var(--surface);
      border: 1px solid var(--taupe-l);
      border-radius: var(--radius-lg);
      padding: 22px 24px;
      box-shadow: var(--shadow);
      transition: box-shadow .2s, transform .2s;
    }

    .stat-card:hover {
      box-shadow: var(--shadow-md);
      transform: translateY(-2px);
    }

    .stat-label {
      font-size: 11.5px;
      color: var(--text-3);
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .07em;
      margin-bottom: 10px;
    }

    .stat-value {
      font-size: 30px;
      font-weight: 800;
      letter-spacing: -.6px;
      color: var(--espresso);
    }

    .stat-hint {
      font-size: 12px;
      color: var(--text-3);
      margin-top: 5px;
    }

    /* ── Tables ── */
    .tbl-wrap {
      overflow-x: auto;
    }

    .table-header-with-search {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 14px;
      flex-wrap: wrap;
    }

    .table-header-with-search .section-title {
      font-size: 14px;
      font-weight: 700;
      color: var(--espresso);
    }

    .table-header-with-search .search-field {
      width: 320px;
      max-width: 100%;
    }

    .table-header-with-search .search-field input {
      width: 100%;
      padding: 10px 14px;
      border: 1px solid var(--taupe);
      border-radius: 999px;
      font-size: 14px;
      color: var(--text);
      background: var(--surface);
      transition: border-color .2s, box-shadow .2s;
    }

    .table-header-with-search .search-field input:focus {
      outline: none;
      border-color: var(--chestnut);
      box-shadow: 0 0 0 4px rgba(226, 175, 154, .15);
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th {
      padding: 12px 16px;
      text-align: left;
      font-size: 11.5px;
      font-weight: 700;
      color: var(--text-3);
      text-transform: uppercase;
      letter-spacing: .07em;
      border-bottom: 2px solid var(--taupe-l);
      background: var(--oatmeal);
    }

    td {
      padding: 14px 16px;
      border-bottom: 1px solid var(--taupe-l);
      font-size: 14.5px;
      vertical-align: middle;
    }

    th:last-child,
    td:last-child {
      padding-left: 24px;
      padding-right: 24px;
    }

    tr:last-child td {
      border-bottom: none;
    }

    tr:hover td {
      background: #faf7f5;
    }

    /* ── Badges ── */
    .badge {
      display: inline-flex;
      align-items: center;
      padding: 4px 11px;
      border-radius: 99px;
      font-size: 12px;
      font-weight: 600;
    }

    .badge-green {
      background: var(--green-l);
      color: var(--green);
    }

    .badge-red {
      background: var(--red-l);
      color: var(--red);
    }

    .badge-amber {
      background: var(--amber-l);
      color: var(--amber);
    }

    .badge-blue {
      background: var(--blue-l);
      color: var(--blue);
    }

    .badge-gray {
      background: var(--oatmeal);
      color: var(--text-2);
      border: 1px solid var(--taupe);
    }

    .badge-brown {
      background: var(--taupe-l);
      color: var(--chestnut-d);
    }

    /* ── Forms ── */
    .form-group {
      margin-bottom: 16px;
    }

    .form-group label {
      display: block;
      font-size: 12px;
      font-weight: 700;
      color: var(--text-3);
      text-transform: uppercase;
      letter-spacing: .07em;
      margin-bottom: 6px;
    }

    input[type=text],
    input[type=password],
    input[type=number],
    input[type=date],
    input[type=email],
    select,
    textarea {
      width: 100%;
      padding: 11px 14px;
      border: 1.5px solid var(--taupe);
      border-radius: var(--radius);
      font-size: 14.5px;
      color: var(--text);
      background: var(--white);
      outline: none;
      transition: border-color .18s, box-shadow .18s;
      font-family: inherit;
    }

    input:focus,
    select:focus,
    textarea:focus {
      border-color: var(--chestnut);
      box-shadow: 0 0 0 3px rgba(124, 90, 68, .13);
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }

    .form-row-3 {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 14px;
    }

    /* ── Checkout customer combobox ── */
    .customer-combobox {
      position: relative;
      font-family: inherit;
      font-size: 13px;
    }

    .customer-combobox .combo-control {
      display: flex;
      justify-content: space-between;
      align-items: center;
      min-height: 46px;
      width: 100%;
      padding: 12px 14px;
      border: 1.5px solid var(--taupe);
      border-radius: var(--radius);
      background: var(--white);
      color: var(--text);
      cursor: pointer;
      transition: border-color .18s, box-shadow .18s;
    }

    .customer-combobox .combo-control:focus,
    .customer-combobox.open .combo-control {
      outline: none;
      border-color: var(--chestnut);
      box-shadow: 0 0 0 3px rgba(124, 90, 68, .13);
    }

    .customer-combobox .combo-value {
      display: inline-block;
      max-width: calc(100% - 24px);
      overflow: hidden;
      white-space: nowrap;
      text-overflow: ellipsis;
    }

    .customer-combobox .combo-arrow {
      margin-left: 12px;
      color: var(--text-3);
      font-size: 14px;
    }

    .customer-combobox .combo-panel {
      position: absolute;
      left: 0;
      right: 0;
      top: calc(100% + 8px);
      background: var(--white);
      border: 1.5px solid var(--taupe);
      border-radius: var(--radius);
      box-shadow: 0 16px 40px rgba(56,46,40,.12);
      z-index: 20;
      display: none;
    }

    .customer-combobox.open .combo-panel {
      display: block;
    }

    .customer-combobox .combo-search {
      width: 100%;
      padding: 11px 14px;
      border: none;
      border-bottom: 1px solid var(--taupe);
      border-radius: var(--radius) var(--radius) 0 0;
      font-size: 13px;
      color: var(--text);
      outline: none;
      background: var(--white);
    }

    .customer-combobox .combo-list {
      max-height: 220px;
      overflow-y: auto;
      background: var(--white);
    }

    .customer-combobox .combo-option {
      padding: 11px 14px;
      cursor: pointer;
      transition: background .18s;
      color: var(--text);
      font-size: 13px;
      line-height: 1.4;
    }

    .customer-combobox .combo-option:hover,
    .customer-combobox .combo-option.focused {
      background: var(--taupe-l);
    }

    .customer-combobox .combo-option.selected {
      background: var(--chestnut);
      color: #fff;
    }

    .customer-combobox .combo-option.combo-pinned {
      font-weight: 700;
    }

    .customer-combobox .combo-no-results {
      padding: 12px 14px;
      color: var(--text-3);
      font-size: 13px;
    }

    /* ── Buttons ── */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 10px 18px;
      border-radius: var(--radius);
      border: none;
      cursor: pointer;
      font-size: 14px;
      font-weight: 600;
      transition: all .18s cubic-bezier(.4, 0, .2, 1);
      text-decoration: none;
      font-family: inherit;
    }

    .btn:active {
      transform: scale(.97);
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--chestnut-l), var(--chestnut-d));
      color: #fff;
      box-shadow: 0 2px 8px rgba(94, 66, 48, .3);
    }

    .btn-primary:hover {
      background: linear-gradient(135deg, var(--chestnut), var(--chestnut-d));
      box-shadow: 0 4px 14px rgba(94, 66, 48, .4);
      transform: translateY(-1px);
    }

    .btn-secondary {
      background: var(--white);
      color: var(--text-2);
      border: 1.5px solid var(--taupe);
    }

    .btn-secondary:hover {
      background: var(--oatmeal-d);
      border-color: var(--chestnut-l);
    }

    .btn-danger {
      background: var(--red-l);
      color: var(--red);
      border: 1px solid #E0B0B0;
    }

    .btn-danger:hover {
      background: #eec4c4;
    }

    .btn-ghost {
      background: transparent;
      color: var(--text-2);
      border: none;
    }

    .btn-ghost:hover {
      background: var(--oatmeal);
    }

    .btn-sm {
      padding: 7px 15px;
      font-size: 13px;
      min-height: 36px;
      white-space: nowrap;
    }

    .btn-lg {
      padding: 14px 28px;
      font-size: 16px;
      border-radius: var(--radius-lg);
    }

    .btn-full {
      width: 100%;
      justify-content: center;
    }

    /* ── Tabs ── */
    .tabs {
      display: flex;
      gap: 2px;
      border-bottom: 2px solid var(--taupe-l);
      margin-bottom: 28px;
    }

    .tab-link {
      padding: 11px 20px;
      font-size: 14.5px;
      font-weight: 600;
      color: var(--text-3);
      text-decoration: none;
      border-bottom: 2.5px solid transparent;
      margin-bottom: -2px;
      transition: all .18s;
    }

    .tab-link:hover {
      color: var(--text);
    }

    .tab-link.active {
      color: var(--chestnut);
      border-color: var(--chestnut);
    }

    /* ── Modals ── */
    .overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(28, 22, 18, .6);
      backdrop-filter: blur(4px);
      z-index: 200;
      align-items: center;
      justify-content: center;
    }

    .overlay.open {
      display: flex;
    }

    .modal-box {
      background: var(--white);
      border-radius: var(--radius-xl);
      padding: 32px 34px;
      width: 100%;
      max-width: 500px;
      box-shadow: var(--shadow-xl);
      position: relative;
      max-height: 92vh;
      overflow-y: auto;
      border: 1px solid var(--taupe-l);
    }

    .modal-box.profile {
      max-width: 900px;
      padding: 36px 40px;
    }

    .modal-box.profile .profile-table th,
    .modal-box.profile .profile-table td {
      white-space: nowrap;
      padding: 13px 20px;
      font-size: 14px;
    }

    .modal-box.profile .profile-table tr.active-session-row {
      background: rgba(220, 242, 222, 0.45);
    }

    .modal-box.profile .profile-table .text-success {
      color: var(--green);
    }

    .modal-box.profile .profile-table .live-session-timer {
      font-weight: 700;
      color: var(--espresso);
      display: inline-block;
      min-width: 70px;
    }

    .modal-box.wide {
      max-width: 2000px;
    }

    .modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 24px;
    }

    .modal-title {
      font-size: 19px;
      font-weight: 800;
      color: var(--espresso);
    }

    .modal-close {
      background: var(--oatmeal);
      border: 1px solid var(--taupe);
      font-size: 18px;
      cursor: pointer;
      color: var(--text-3);
      line-height: 1;
      padding: 6px 10px;
      border-radius: var(--radius);
      transition: all .15s;
    }

    .modal-close:hover {
      background: var(--red-l);
      color: var(--red);
      border-color: #E0B0B0;
    }

    .dialog-message {
      color: var(--text-2);
      font-size: 14px;
      margin-top: 8px;
      line-height: 1.65;
    }

    .dialog-input {
      width: 100%;
      padding: 12px 14px;
      border: 1px solid rgba(161, 148, 141, .35);
      border-radius: 14px;
      font-size: 14px;
      margin-top: 16px;
      box-sizing: border-box;
      outline: none;
    }

    .dialog-input:focus {
      border-color: var(--chestnut);
      box-shadow: 0 0 0 3px rgba(226, 175, 154, .18);
    }

    .dialog-actions {
      display: flex;
      justify-content: flex-end;
      gap: 12px;
      margin-top: 24px;
      flex-wrap: wrap;
    }

    #myProfileModal {
      width: 100vw !important;
      height: 100vh !important;
      left: 0 !important;
      top: 0 !important;
    }

    #myProfileModal .modal-box {
      max-width: 900px !important;
      width: min(900px, 92vw) !important;
    }


    /* ── Auth ── */
    .auth-wrap {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: radial-gradient(ellipse at 30% 40%, #f0e6db 0%, var(--oatmeal) 60%);
    }

    .auth-box {
      background: var(--white);
      border: 1px solid var(--taupe-l);
      border-radius: var(--radius-xl);
      padding: 48px 42px;
      width: 420px;
      box-shadow: var(--shadow-xl);
    }

    .auth-logo {
      font-size: 26px;
      font-weight: 800;
      color: var(--chestnut);
      text-align: center;
      margin-bottom: 6px;
      letter-spacing: -.4px;
    }

    .auth-sub {
      font-size: 14px;
      color: var(--text-3);
      text-align: center;
      margin-bottom: 32px;
    }

    .auth-err {
      background: var(--red-l);
      color: var(--red);
      border-radius: var(--radius);
      padding: 12px 16px;
      font-size: 14px;
      margin-bottom: 18px;
      border: 1px solid #E0B0B0;
    }

    .auth-ok {
      background: var(--green-l);
      color: var(--green);
      border-radius: var(--radius);
      padding: 12px 16px;
      font-size: 14px;
      margin-bottom: 18px;
      border: 1px solid #B0D9C2;
    }

    /* ── Checkout layout ── */
    .checkout-wrap {
      display: flex;
      height: 100vh;
      overflow: hidden;
    }

    .co-left {
      flex: 1;
      display: flex;
      flex-direction: column;
      padding: 26px;
      overflow: hidden;
      background: var(--oatmeal);
    }

    .co-right {
      width: 400px;
      display: flex;
      flex-direction: column;
      background: var(--white);
      border-left: 1.5px solid var(--taupe);
      box-shadow: -4px 0 20px rgba(56, 46, 40, .06);
    }

    .co-products {
      flex: 1;
      overflow-y: auto;
    }

    /* ── Product grid (checkout) ── */
    .prod-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(158px, 1fr));
      gap: 13px;
    }

    .prod-tile {
      background: var(--white);
      border: 1.5px solid var(--taupe-l);
      border-radius: var(--radius-lg);
      cursor: pointer;
      transition: all .18s cubic-bezier(.4, 0, .2, 1);
      overflow: hidden;
      box-shadow: var(--shadow);
    }

    .prod-tile:hover {
      border-color: var(--chestnut);
      box-shadow: 0 4px 18px rgba(124, 90, 68, .18);
      transform: translateY(-3px);
    }

    .prod-tile:active {
      transform: scale(.97);
    }

    .prod-img {
      height: 140px;
      background: linear-gradient(145deg, var(--taupe-l), var(--oatmeal-d));
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }

    .prod-img img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      padding: 6px;
      transition: transform .3s ease;
    }

    .prod-tile:hover .prod-img img {
      transform: scale(1.06);
    }

    .prod-info {
      padding: 12px 12px 14px;
    }

    .prod-name {
      font-size: 13.5px;
      font-weight: 600;
      color: var(--espresso);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .prod-price {
      font-size: 14.5px;
      font-weight: 800;
      color: var(--chestnut);
      margin-top: 3px;
    }

    .prod-stock {
      font-size: 11.5px;
      color: var(--text-3);
      margin-top: 2px;
    }

    /* ── Cart ── */
    .co-cart-header {
      padding: 22px 22px 16px;
      border-bottom: 1.5px solid var(--taupe-l);
    }

    .co-cart-title {
      font-size: 17px;
      font-weight: 800;
      color: var(--espresso);
    }

    .cart-list {
      flex: 1;
      overflow-y: auto;
      padding: 6px 0;
    }

    .cart-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 13px 22px;
      border-bottom: 1px solid var(--taupe-l);
      transition: background .12s;
    }

    .cart-item:hover {
      background: #faf7f5;
    }

    .ci-name {
      flex: 1;
      min-width: 0;
    }

    .ci-pname {
      font-size: 14px;
      font-weight: 600;
      color: var(--espresso);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .ci-sku {
      font-size: 11px;
      color: var(--text-3);
      font-family: 'Courier New', monospace;
      margin-top: 1px;
    }

    .ci-qty {
      display: flex;
      align-items: center;
      gap: 7px;
    }

    .qty-btn {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      border: 1.5px solid var(--taupe);
      background: var(--oatmeal);
      cursor: pointer;
      font-size: 15px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--text-2);
      transition: all .15s;
      font-weight: 700;
    }

    .qty-btn:hover {
      border-color: var(--chestnut);
      background: var(--taupe-l);
      color: var(--chestnut);
    }

    .ci-total {
      font-size: 14.5px;
      font-weight: 700;
      width: 78px;
      text-align: right;
      color: var(--espresso);
    }

    .ci-del {
      background: none;
      border: none;
      cursor: pointer;
      color: var(--text-3);
      font-size: 17px;
      padding: 2px 3px;
      transition: color .12s;
      border-radius: 6px;
    }

    .ci-del:hover {
      color: var(--red);
      background: var(--red-l);
    }

    .cart-empty {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: var(--text-3);
      gap: 10px;
      padding: 48px 24px;
    }

    /* ── Cart totals ── */
    .co-totals {
      padding: 18px 22px;
      border-top: 1.5px solid var(--taupe-l);
      background: var(--oatmeal-d);
    }

    .tot-row {
      display: flex;
      justify-content: space-between;
      font-size: 14px;
      color: var(--text-2);
      padding: 5px 0;
    }

    .tot-row.grand {
      font-size: 19px;
      font-weight: 800;
      color: var(--espresso);
      padding-top: 12px;
      margin-top: 8px;
      border-top: 1.5px solid var(--taupe);
    }

    .co-actions {
      padding: 25px 24px 24px;
      /* Increases spacing around the borders */
      display: flex;
      flex-direction: column;
      gap: 20px;
      /* Adds more breathing space between each button */
    }

    /* Ensure the right cart panel behaves as a column with a bounded height
       so the item list can scroll while totals and actions remain visible. */
    .co-right {
      max-height: 100vh;
      /* already display:flex above but ensure column layout persists */
      display: flex;
      flex-direction: column;
    }

    .cart-list {
      flex: 1 1 auto; /* allow growth and shrinking, and enable scrolling */
      min-height: 60px;
      overflow-y: auto;
    }

    .co-totals,
    .co-actions {
      flex-shrink: 0; /* keep totals and action buttons visible */
    }

    /* ── Receipt ── */
    .receipt {
      font-family: 'Courier New', monospace;
      font-size: 13px;
      line-height: 1.65;
      padding: 18px;
      background: var(--oatmeal);
      border-radius: var(--radius);
      border: 1px solid var(--taupe);
    }

    .receipt-title {
      text-align: center;
      font-weight: 700;
      font-size: 15px;
      margin-bottom: 10px;
      color: var(--espresso);
    }

    .receipt-sep {
      border: none;
      border-top: 1px dashed var(--taupe);
      margin: 10px 0;
    }

    .receipt-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
    }

    .receipt-row span:first-child {
      flex: 1 1 auto;
      text-align: left;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .receipt-row span:last-child {
      flex: 0 0 auto;
      text-align: right;
      min-width: 6ch;
    }

    /* ── Inventory grid ── */
    .inv-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
      gap: 16px;
    }

    .inv-card {
      background: var(--white);
      border: 1.5px solid var(--taupe-l);
      border-radius: var(--radius-lg);
      overflow: hidden;
      cursor: pointer;
      transition: all .2s cubic-bezier(.4, 0, .2, 1);
      position: relative;
      box-shadow: var(--shadow);
    }

    .inv-card:hover {
      border-color: var(--chestnut);
      box-shadow: var(--shadow-lg);
      transform: translateY(-3px);
    }

    .inv-card-img {
      height: 150px;
      background: linear-gradient(145deg, var(--taupe-l), var(--oatmeal-d));
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      position: relative;
    }

.inv-card-img img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      object-position: center;
      padding: 6px;
      transition: transform .3s ease;
      display: block;
    }

    .inv-card:hover .inv-card-img img {
      transform: scale(1.05);
    }

    .inv-card-body {
      padding: 14px 16px 16px;
    }

    .inv-card-name {
      font-size: 14px;
      font-weight: 700;
      color: var(--espresso);
      margin-bottom: 2px;
    }

    .inv-card-sku {
      font-size: 11px;
      color: var(--text-3);
      font-family: 'Courier New', monospace;
    }

    .inv-card-price {
      font-size: 17px;
      font-weight: 800;
      color: var(--chestnut);
      margin-top: 8px;
    }

    .inv-card-stock {
      font-size: 12px;
      color: var(--text-3);
      margin-top: 3px;
    }

    .inv-del-btn {
      position: absolute;
      top: 10px;
      right: 10px;
      background: rgba(255, 255, 255, .95);
      border: 1.5px solid #E0B0B0;
      border-radius: 50%;
      width: 30px;
      height: 30px;
      display: none;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      color: var(--red);
      font-size: 15px;
      box-shadow: 0 2px 6px rgba(56, 46, 40, .12);
      transition: all .15s;
    }

    .inv-del-btn:hover {
      background: var(--red-l);
    }

    .inv-card:hover .inv-del-btn {
      display: flex;
    }

    .low-stock {
      color: var(--red);
      font-weight: 700;
    }

    /* ── New Variant ── */
    .btn-variant {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    background-color: #ffffff;
    color: #4a3f35; /* Earthy/chestnut tint */
    border: 1px solid #dcd6d0;
    border-radius: 6px;
    padding: 6px 14px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    }

    .btn-variant:hover {
        background-color: #fcfbfa;
        border-color: #c5bbb2;
        color: #2e251e;
        transform: translateY(-1px);
    }

    .btn-variant:active {
        transform: translateY(0);
        background-color: #f5f2ef;
    }

    /* ── Alerts ── */
    .alert {
      padding: 14px 18px;
      border-radius: var(--radius);
      font-size: 14px;
      margin-bottom: 20px;
      font-weight: 500;
    }

    .alert-success {
      background: var(--green-l);
      color: var(--green);
      border: 1px solid #B0D9C2;
    }

    .alert-danger {
      background: var(--red-l);
      color: var(--red);
      border: 1px solid #E0B0B0;
    }

    .inv-actions {
      display: flex;
      gap: 8px;
      margin-top: 14px;
    }

    .inv-btn {
      flex: 1;
      border: none;
      padding: 8px 10px;
      border-radius: 10px;
      cursor: pointer;
      font-size: 12px;
      font-weight: 700;
      transition: .2s;
    }

    /* MODAL */

    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .45);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
    }

    .modal-box {
      width: 340px;
      background: white;
      border-radius: 18px;
      padding: 24px;
      box-shadow: 0 15px 40px rgba(0, 0, 0, .2);
    }

    .modal-input {
      width: 100%;
      padding: 12px;
      border-radius: 10px;
      border: 1px solid #DDD;
      font-size: 14px;
    }

    /* ── Scrollbar ── */
    ::-webkit-scrollbar {
      width: 6px;
      height: 6px;
    }

    ::-webkit-scrollbar-thumb {
      background: var(--taupe);
      border-radius: 99px;
    }

    ::-webkit-scrollbar-track {
      background: transparent;
    }

    /* ── Category cards ── */
    .cat-card {
      background: var(--white);
      border: 1.5px solid var(--taupe-l);
      border-radius: var(--radius-lg);
      padding: 22px 24px;
      margin-bottom: 16px;
      box-shadow: var(--shadow);
    }

    .cat-card-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 14px;
      padding-bottom: 14px;
      border-bottom: 1px solid var(--taupe-l);
    }

    .cat-items-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
      gap: 10px;
    }

    .cat-item-pill {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: var(--oatmeal);
      border: 1px solid var(--taupe);
      border-radius: var(--radius);
      padding: 10px 14px;
      font-size: 13.5px;
    }

    /* ── CRM ── */
    .customer-row {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .cust-avatar {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--taupe-l), var(--oatmeal-d));
      color: var(--chestnut-d);
      font-weight: 700;
      font-size: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      border: 1.5px solid var(--taupe);
      overflow: hidden;
    }

    .crm-row-clickable {
      cursor: pointer;
      transition: background-color 0.15s ease;
    }

    .crm-row-clickable:hover td {
      background: #f5f1ef;
    }

    /* ── Reports ── */
    .period-tabs {
      display: flex;
      gap: 7px;
      margin-bottom: 24px;
    }

    .period-btn {
      padding: 8px 20px;
      border-radius: 99px;
      border: 1.5px solid var(--taupe);
      background: var(--white);
      font-size: 14px;
      cursor: pointer;
      color: var(--text-2);
      font-weight: 500;
      text-decoration: none;
      transition: all .15s;
      font-family: inherit;
    }

    .period-btn:hover {
      border-color: var(--chestnut);
      color: var(--chestnut);
      background: var(--oatmeal);
    }

    .period-btn.active {
      background: linear-gradient(135deg, var(--chestnut-l), var(--chestnut-d));
      border-color: transparent;
      color: #fff;
      box-shadow: 0 2px 8px rgba(94, 66, 48, .3);
    }

    /* ── Promo card ── */
    .promo-card {
      background: var(--white);
      border: 1.5px solid var(--taupe-l);
      border-radius: var(--radius-lg);
      padding: 18px 22px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 12px;
      border-left: 5px solid var(--chestnut);
      box-shadow: var(--shadow);
      transition: box-shadow .2s;
    }

    .promo-card:hover {
      box-shadow: var(--shadow-md);
    }

    .promo-card.inactive {
      border-left-color: var(--taupe);
      opacity: .6;
    }

    /* ── Employee card ── */
    .emp-card {
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 16px 20px;
      background: var(--white);
      border: 1.5px solid var(--taupe-l);
      border-radius: var(--radius-lg);
      margin-bottom: 12px;
      box-shadow: var(--shadow);
      transition: box-shadow .2s;
    }

    .emp-card:hover {
      box-shadow: var(--shadow-md);
    }

    .emp-avatar {
      width: 46px;
      height: 46px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--taupe-l), var(--oatmeal-d));
      color: var(--chestnut-d);
      font-weight: 700;
      font-size: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      border: 2px solid var(--taupe);
      overflow: hidden;
    }

    /* ── Checkout search & category filter ── */
    .co-search {
      position: relative;
    }

    .co-search input {
      padding-left: 40px;
    }

    .co-search-icon {
      position: absolute;
      left: 13px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-3);
      pointer-events: none;
    }

    .cat-filter {
      display: flex;
      gap: 7px;
      margin: 14px 0;
      flex-wrap: wrap;
    }

    .cat-pill {
      padding: 6px 16px;
      border-radius: 99px;
      border: 1.5px solid var(--taupe-l);
      background: var(--white);
      font-size: 13px;
      cursor: pointer;
      color: var(--text-2);
      font-weight: 500;
      transition: all .15s;
    }

    .cat-pill:hover {
      border-color: var(--chestnut);
      color: var(--chestnut);
      background: var(--oatmeal);
    }

    .cat-pill.active {
      background: linear-gradient(135deg, var(--chestnut-l), var(--chestnut-d));
      border-color: transparent;
      color: #fff;
      box-shadow: 0 2px 7px rgba(94, 66, 48, .25);
    }

    .tab-pane {
      display: none;
    }

    .tab-pane.active {
      display: block;
    }

    /* ── Responsive ── */
    @media (max-width: 1024px) {
      /* medium screens: slightly narrower cart to preserve product area */
      .co-right { width: 360px; }
    }

    /* Tweak layout for intermediate widths (tablet / small laptop) where
       the cart can otherwise dominate the viewport. This tightens the
       cart and makes product tiles smaller so both areas remain visible. */
    @media (min-width: 769px) and (max-width: 980px) {
      .co-right { width: 320px; }
      .prod-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; }
      .co-left { padding: 18px; }
      .prod-img { height: 110px; }
      .co-cart-header { padding: 16px 16px 12px; }
      .co-totals { padding: 10px 12px; }
      .co-actions { padding: 12px 14px 14px; }
    }

    @media (max-width: 768px) {
      .app {
        flex-direction: column;
      }

      .sidebar {
        width: 100%;
        height: auto;
        min-height: unset;
        flex-direction: row;
        flex-wrap: wrap;
        overflow-x: auto;
      }

      .main {
        overflow-y: auto;
      }

      .page {
        padding: 22px 18px;
      }

      .form-row,
      .form-row-3 {
        grid-template-columns: 1fr;
      }

      .stat-grid {
        grid-template-columns: 1fr 1fr;
      }

      .co-left,
      .co-right {
        min-width: 0;
      }

      /* Keep side-by-side until quite narrow; only stack on very small screens */
      /* stack at <=480px to avoid cart taking full width on moderate viewports */
      @media (max-width: 480px) {
        .checkout-wrap {
          flex-direction: column;
          height: auto;
          overflow: auto;
        }
        .co-right { width: 100%; border-left: none; border-top: 1.5px solid var(--taupe); }
      }

      /* For tablet/smaller viewports keep cart narrower */
      .co-right { width: 320px; }

      /* Keep the cart panel bounded on small screens so the item list can scroll
         while totals/actions remain visible. This prevents the summary from
         completely covering the selected items area. */
      .co-right {
        max-height: calc(100vh - 120px);
        overflow: hidden;
        display: flex;
        flex-direction: column;
      }

      .cart-list {
        /* ensure list grows and scrolls inside the right panel */
        flex: 1 1 auto;
        min-height: 80px;
        overflow-y: auto;
      }

      .co-totals,
      .co-actions {
        /* keep totals and action buttons visible (not collapsed) */
        flex-shrink: 0;
      }

      .prod-grid {
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
      }

      /* Reduce totals area size on small screens to save vertical space */


      /* Make the totals block more compact: smaller fonts, tighter spacing */
      .co-totals {
        padding: 6px 8px;
        max-height: 220px;
      }

      .tot-row {
        font-size: 11px;
        padding: 1px 0;
        line-height: 1.1;
        display: flex;
        justify-content: space-between;
        align-items: center;
      }

      .tot-row span:first-child {
        font-size: 11px;
      }

      .tot-row span:last-child {
        font-size: 12px;
        font-weight: 600;
      }

      .tot-row.grand {
        font-size: 13px;
        padding-top: 4px;
        margin-top: 6px;
        font-weight: 800;
      }

      .co-actions {
        padding: 8px 10px 10px;
        gap: 8px;
      }

      /* tighten the Finalize button on small screens */
      .btn.btn-primary.btn-full.btn-lg {
        padding: 12px 18px;
        font-size: 15px;
      }

      @media (max-width: 420px) {
        .co-totals { padding: 6px 8px; }
        .tot-row { font-size: 10px; }
        .tot-row span:first-child { font-size: 10px; }
        .tot-row span:last-child { font-size: 11px; }
        .tot-row.grand { font-size: 12px; }
        .btn.btn-primary.btn-full.btn-lg { font-size: 12px; padding: 8px 10px; }
      }
    }
  </style>
</head>

<body>

  <?php if ($page === 'login'): ?>
    <div class="auth-wrap">
      <div class="auth-box">
        <div class="auth-logo">Bloom POS</div>
        <div class="auth-sub">Flower Shop Point of Sale</div>
        <?php if ($auth_error !== ''): ?><div class="auth-err"><?= htmlspecialchars($auth_error, ENT_QUOTES, 'UTF-8') ?></div>
        <script>
          document.addEventListener('DOMContentLoaded', function(){
            var err = <?= json_encode($auth_error) ?>;
            if (err && err !== '') {
              var f = document.querySelector('form[action="?page=login"]');
              if (f) { try { f.reset(); var el = f.querySelector('[name=emp_id]'); if(el) el.focus(); } catch(e){} }
            }
          });
        </script>
        <?php endif; ?>
        <?php if (isset($_GET['registered'])): ?><div class="auth-ok">Account created. You may now log in.</div><?php endif; ?>
        <form method="POST" action="?page=login">
          <div class="form-group"><label>Employee ID</label><input type="text" name="emp_id" value="<?= htmlspecialchars($remembered_id, ENT_QUOTES, 'UTF-8') ?>" placeholder="EMP-001" required autofocus></div>
          <div class="form-group"><label>Passcode</label><input type="password" name="passcode" placeholder="Enter passcode" required></div>
          <label style="display:flex; align-items:center; gap:8px; font-size:13px;
              color:var(--text-2); margin-bottom:16px; cursor:pointer;">
            <input type="checkbox" name="remember_me" <?= $remembered_id ? 'checked' : '' ?>>
            Remember my Employee ID for 30 days
          </label>
          <button type="submit" class="btn btn-primary btn-full btn-lg" style="margin-top:8px;">Sign In</button>
        </form>
      </div>
    </div>



  <?php elseif ($page === 'register'): ?>
    <div class="auth-wrap">
      <div class="auth-box" style="width:420px;">
        <div class="auth-logo">Bloom POS</div>
        <div class="auth-sub">Register Staff Account</div>
        <?php if ($reg_error !== ''): ?><div class="auth-err"><?= htmlspecialchars($reg_error, ENT_QUOTES, 'UTF-8') ?></div>
        <script>
          document.addEventListener('DOMContentLoaded', function(){
            var err = <?= json_encode($reg_error) ?>;
            if (err && err !== '') {
              var f = document.querySelector('form[action="?page=register"]');
              if (f) { try { f.reset(); var el = f.querySelector('[name=emp_id]'); if(el) el.focus(); } catch(e){} }
            }
          });
        </script>
        <?php endif; ?>
        <form method="POST" action="?page=register" enctype="multipart/form-data">
          <div class="form-row">
            <div class="form-group"><label>Employee ID</label><input type="text" name="emp_id" placeholder="EMP-003" required></div>
            <div class="form-group"><label>Role</label>
              <select name="role" style="appearance:none; -webkit-appearance:none; -moz-appearance:none; background-image:none; padding-right:12px;">
                <option value="Cashier">Cashier</option>
              </select>
            </div>
          </div>
          <div class="form-group"><label>Full Name</label><input type="text" name="full_name" placeholder="Full Name" required pattern="[A-Za-zÑñ ]+" title="Letters and spaces only" oninput="this.value = this.value.replace(/[^A-Za-zÑñ\s]/g,'')"></div>
          <div class="form-group"><label>Passcode</label><input type="password" name="passcode" placeholder="Choose a passcode" required></div>
          <div class="form-group">
            <label>Profile Photo <span style="color:var(--text-3); font-weight:400; text-transform:none;">(optional)</span></label>
            <input type="file" name="emp_photo" accept="image/*" style="padding:6px;">
          </div>
          <button type="submit" class="btn btn-primary btn-full btn-lg" style="margin-top:8px;">Create Account</button>
        </form>
        <p style="text-align:center; margin-top:16px; font-size:12.5px; color:var(--text-3);">
          <a href="?page=employees" style="color:var(--chestnut); font-weight:600;">Back to Employees</a>
        </p>
      </div>
    </div>

  <?php elseif ($page === 'showcase'): ?>
    <?php
      $showcaseBundles = [];
      $showcaseRes = $conn->query("SELECT showcase_id, name, description, main, fillers, greenery, meta, image_url FROM showcase_bundles ORDER BY showcase_id ASC");
      if ($showcaseRes) {
        while ($row = $showcaseRes->fetch_assoc()) {
          $showcaseBundles[] = $row;
        }
      }
    ?>
    <div class="page">
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; gap:16px;">
        <div style="display:flex; align-items:center; gap:14px;">
          <a href="?page=dashboard" style="color:var(--text-3); text-decoration:none; font-size:20px; line-height:1;">&larr;</a>
          <div>
            <div style="font-size:17px; font-weight:700; color:var(--espresso);">Flower Arrangement Showcase</div>
            <div style="font-size:12px; color:var(--text-3);">Explore premium bundle packages and bloom your own bouquet.</div>
          </div>
        </div>
          <?php if ($is_admin): ?>
            <button type="button" class="btn btn-primary add-showcase-btn" onclick="openAddShowcaseModal()">Add Showcase</button>
          <?php endif; ?>
        </div>

      <div class="showcase-panel">
        <div class="showcase-grid" id="showcaseGrid"></div>
      </div>
    </div>

    <div class="overlay" id="showcaseModal">
      <div class="modal-box wide" style="max-width:920px; width:100%;">
        <div class="modal-header">
          <button class="modal-close" onclick="closeShowcaseModal()">&times;</button>
        </div>
        <div class="showcase-modal-grid">
          <div class="modal-image-block">
            <div class="modal-image-placeholder" id="showcaseModalImage">Preview</div>
          </div>
          <div class="modal-copy-block">
            <div style="margin-bottom:18px;">
              <div class="modal-bundle-name" id="showcaseModalName"></div>
              <div class="showcase-stock-warning" id="showcaseModalStockStatus" aria-live="polite"></div>
            </div>
            <div class="modal-bundle-sub" id="showcaseModalMeta"></div>
            <div class="modal-bundle-description" id="showcaseModalDescription"></div>
            <button type="button" class="btn btn-primary btn-full btn-lg" id="showcaseModalAction">Bloom your own bouquet</button>
          </div>
        </div>
      </div>
    </div>

        <?php if (isset($_SESSION['payment_error']) && $_SESSION['payment_error'] !== ''): ?>
          <script>
            document.addEventListener('DOMContentLoaded', function(){
              try {
                var overlay = document.getElementById('pay_overlay');
                var ed = document.getElementById('payment_error');
                if (ed) { ed.innerHTML = <?= json_encode($_SESSION['payment_error']) ?>; ed.style.display = 'block'; }
                if (overlay) overlay.classList.add('open');
              } catch(e) { console.error(e); }
            });
          </script>
        <?php unset($_SESSION['payment_error']); endif; ?>

    <div class="overlay" id="addShowcaseModal">
      <div class="modal-box" style="max-width:520px; width:100%;">
        <div class="modal-header">
          <span class="modal-title">Add Showcase Item</span>
          <button class="modal-close" onclick="closeAddShowcaseModal()">&times;</button>
        </div>
        <form id="addShowcaseForm" enctype="multipart/form-data">
          <div class="form-group"><label>Name</label><input type="text" id="newShowcaseName" name="name" placeholder="Enter showcase name" required></div>
          <div class="form-group"><label>Description</label><textarea id="newShowcaseDescription" name="description" rows="4" placeholder="Enter showcase description" required></textarea></div>
          <div class="form-group"><label>Main flowers</label><input type="number" min="0" id="newShowcaseFlowers" name="main" placeholder="Main flower count" required></div>
          <div class="form-group"><label>Fillers</label><input type="number" min="0" id="newShowcaseFillers" name="fillers" placeholder="Filler count" required></div>
          <div class="form-group"><label>Free greenery</label><input type="number" min="0" id="newShowcaseGreenery" name="greenery" placeholder="Greenery count" required></div>
          <div class="form-group"><label>Image</label><input type="file" id="newShowcaseImage" name="image_file" accept="image/*" style="padding:6px;"></div>
          <div class="showcase-image-preview" id="newShowcasePreview">Image preview</div>
          <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:18px;">
            <button type="button" class="btn btn-secondary" onclick="closeAddShowcaseModal()">Cancel</button>
            <button type="submit" class="btn btn-primary">Add Showcase</button>
          </div>
        </form>
      </div>
    </div>

    <div class="overlay" id="deleteConfirmModal">
      <div class="modal-box" style="max-width:420px; width:100%;">
        <div class="modal-header">
          <span class="modal-title">Confirm delete</span>
          <button class="modal-close" onclick="closeDeleteConfirm()">&times;</button>
        </div>
        <div class="modal-body" style="padding:0 24px 18px;">
          <p id="deleteConfirmMessage" style="margin:0; color:var(--text-2);">Do you wish to delete this showcase?</p>
        </div>
        <div class="dialog-actions" style="padding:0 24px 24px; display:flex; justify-content:flex-end; gap:12px;">
          <button type="button" class="btn btn-secondary" onclick="closeDeleteConfirm()">Cancel</button>
          <button type="button" class="btn btn-primary" onclick="confirmDeleteShowcase()">Confirm</button>
        </div>
      </div>
    </div>

    <style>
      .showcase-panel { padding: 18px 0; }
      .showcase-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:26px; }
      .showcase-card { position:relative; cursor:pointer; border:1px solid var(--taupe); border-radius:18px; background:var(--white); box-shadow:0 12px 22px rgba(0,0,0,.06); overflow:hidden; }
      .showcase-delete-btn { position:absolute; top:12px; right:12px; width:32px; height:32px; border:none; border-radius:50%; background:rgba(255,255,255,.92); color:var(--espresso); font-size:18px; line-height:1; cursor:pointer; opacity:0; transition:opacity .2s ease, transform .2s ease; }
      .showcase-card:hover .showcase-delete-btn { opacity:1; transform:translateY(-1px); }
      .showcase-image { height:180px; background:linear-gradient(145deg, #ffd9e8 0%, #f1e4ff 100%); display:flex; align-items:center; justify-content:center; }
      .showcase-image-inner { text-align:center; font-size:13px; color:var(--text-3); padding:16px; }
      .showcase-card-body { padding:14px; }
      .showcase-card-title { font-size:16px; font-weight:700; margin-bottom:6px; color:var(--espresso); }
      .showcase-card-meta { font-size:12px; color:var(--text-3); line-height:1.5; }
      .sub-nav { border:none; background:var(--taupe); color:var(--espresso); width:48px; height:48px; border-radius:50%; font-size:24px; cursor:pointer; transition:transform .2s ease; }
      .sub-nav:hover { transform:scale(1.04); }
      .showcase-modal-grid { display:grid; grid-template-columns:1.1fr .9fr; gap:24px; align-items:start; margin-top:18px; }
      .modal-image-block { background:var(--oatmeal); border-radius:22px; padding:24px; display:flex; align-items:center; justify-content:center; }
      .modal-image-placeholder { width:100%; min-height:360px; border-radius:20px; background:linear-gradient(135deg, #f8d1d1, #e5daf5); display:flex; align-items:center; justify-content:center; font-size:14px; color:var(--text-3); text-align:center; padding:24px; }
      .modal-copy-block { display:flex; flex-direction:column; justify-content:space-between; }
      .modal-bundle-name { font-size:28px; font-weight:800; color:var(--espresso); margin:0; }
      .showcase-stock-warning { display:none; font-size:13px; font-weight:700; color:#d14343; margin:8px 0 0; padding:.35em .75em; border-radius:999px; background:rgba(209,67,67,.1); letter-spacing:.02em; text-transform:uppercase; }
      .showcase-stock-warning.is-insufficient { display:inline-flex; color:#d14343; }
      .modal-bundle-sub { font-size:13px; color:var(--text-3); margin:12px 0 18px; text-transform:uppercase; letter-spacing:.08em; }
      .modal-bundle-description { font-size:15px; line-height:1.7; color:var(--text-2); margin-bottom:24px; }
      .btn.btn-disabled { opacity:.56 !important; cursor:not-allowed !important; pointer-events:none !important; }
      .showcase-footer { margin-top:32px; }
      .sub-carousel { display:flex; align-items:center; gap:12px; }
      .sub-track-window { overflow:hidden; flex:1; }
      .sub-track { display:flex; gap:10px; transition:transform .35s ease; }
      .sub-thumb { min-width:120px; height:96px; background:linear-gradient(135deg, #ffe3e3, #e9e8ff); border-radius:18px; padding:10px; display:flex; flex-direction:column; justify-content:space-between; cursor:pointer; border:2px solid transparent; }
      .sub-thumb.active { border-color:var(--chestnut); }
      .sub-thumb-title { font-size:12px; font-weight:700; color:var(--espresso); }
      .sub-thumb-meta { font-size:11px; color:var(--text-3); }
      .add-showcase-btn { padding:10px 18px; border-radius:12px; font-weight:700; box-shadow:0 10px 24px rgba(0,0,0,.08); }
      .showcase-image-preview { min-height:180px; border-radius:18px; background:linear-gradient(135deg, #f2f0ff, #fff4f6); display:flex; align-items:center; justify-content:center; color:var(--text-3); margin-top:10px; padding:16px; text-align:center; }
      .showcase-image-preview.has-image { background-size:cover; background-position:center; color:transparent; }
      @media(max-width:1080px) { .showcase-modal-grid { grid-template-columns:1fr; } .showcase-card { min-width:calc((100% - 14px) / 2); flex:0 0 calc((100% - 14px) / 2); max-width:calc((100% - 14px) / 2); } }
      @media(max-width:760px) { .showcase-controls { flex-direction:column; } .showcase-nav-btn { width:42px; height:42px; } .showcase-card { min-width:100%; flex:0 0 100%; max-width:100%; } }
    </style>

    <script>
      const SHOWCASE_BUNDLES = <?= json_encode($showcaseBundles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const allProducts = <?= json_encode($inventory, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      // Normalize server-side keys to friendly JS names (image_url -> imageUrl)
      (function(){
        for (let i = 0; i < SHOWCASE_BUNDLES.length; i++) {
          const b = SHOWCASE_BUNDLES[i];
          if (b && b.image_url && !b.imageUrl) b.imageUrl = b.image_url;
          if (b && !b.meta) b.meta = `${(b.main||0)} main • ${(b.fillers||0)} filler • ${(b.greenery||0)} greenery`;
        }

        // Merge any locally persisted showcase bundles (fallback for images saved as data-URLs)
        try {
          const raw = localStorage.getItem('showcase_bundles_local');
          if (raw) {
            const local = JSON.parse(raw) || [];
            local.forEach(l => {
              // avoid duplicate if server already returned item with same id or name
              const exists = SHOWCASE_BUNDLES.some(s => (s.showcase_id && l.showcase_id && String(s.showcase_id) === String(l.showcase_id)) || (s.name && l.name && s.name === l.name));
              if (!exists) {
                // ensure keys
                if (l.image_url && !l.imageUrl) l.imageUrl = l.image_url;
                if (!l.meta) l.meta = `${(l.main||0)} main • ${(l.fillers||0)} filler • ${(l.greenery||0)} greenery`;
                SHOWCASE_BUNDLES.push(l);
              } else {
                // if exists but local has an imageUrl and server doesn't, merge image
                const match = SHOWCASE_BUNDLES.find(s => (s.showcase_id && l.showcase_id && String(s.showcase_id) === String(l.showcase_id)) || (s.name && l.name && s.name === l.name));
                if (match && !match.imageUrl && l.imageUrl) match.imageUrl = l.imageUrl;
              }
            });
          }
        } catch (e) { console.error('showcase local merge error', e); }

        // ensure storage sync helper
        window.saveShowcaseLocal = function() {
          try {
            const toSave = SHOWCASE_BUNDLES.map(b => ({ showcase_id: b.showcase_id, name: b.name, description: b.description, main: b.main, fillers: b.fillers, greenery: b.greenery, meta: b.meta, imageUrl: b.imageUrl }));
            localStorage.setItem('showcase_bundles_local', JSON.stringify(toSave));
          } catch(e) { console.error('persist showcase local error', e); }
        };
      })();
      const SHOWCASE_IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
      let subSlide = 0;

      function getShowcaseCategoryStock(categoryKey) {
        const keys = {
          main: ['main'],
          filler: ['filler'],
          greenery: ['greenery']
        };
        return allProducts.reduce((total, item) => {
          if (!item) return total;
          let category = '';
          if (item.category) category = String(item.category).trim().toLowerCase();
          else if (item.category_name) category = String(item.category_name).trim().toLowerCase();
          return keys[categoryKey].includes(category) ? total + (parseInt(item.stock_qty, 10) || 0) : total;
        }, 0);
      }

      function updateShowcaseStockStatus(bundle, stockStatusEl, actionBtn) {
        const requiredMain = Number(bundle.main || 0);
        const requiredFillers = Number(bundle.fillers || 0);
        const requiredGreenery = Number(bundle.greenery || 0);
        const availableMain = getShowcaseCategoryStock('main');
        const availableFillers = getShowcaseCategoryStock('filler');
        const availableGreenery = getShowcaseCategoryStock('greenery');
        const insufficient = requiredMain > availableMain || requiredFillers > availableFillers || requiredGreenery > availableGreenery;

        if (stockStatusEl) {
          stockStatusEl.textContent = insufficient ? 'Not Enough Stocks' : '';
          stockStatusEl.classList.toggle('is-insufficient', insufficient);
          stockStatusEl.style.display = insufficient ? 'inline-flex' : 'none';
        }

        if (actionBtn) {
          actionBtn.disabled = insufficient;
          actionBtn.setAttribute('aria-disabled', insufficient ? 'true' : 'false');
          actionBtn.classList.toggle('btn-disabled', insufficient);
          if (!insufficient) {
            actionBtn.style.opacity = '';
            actionBtn.style.pointerEvents = '';
          }
        }
      }

      function openShowcaseModal(index) {
        const bundle = SHOWCASE_BUNDLES[index];
        const label = document.getElementById('showcaseModalLabel');
        const name = document.getElementById('showcaseModalName');
        const meta = document.getElementById('showcaseModalMeta');
        const desc = document.getElementById('showcaseModalDescription');
        const image = document.getElementById('showcaseModalImage');
        const action = document.getElementById('showcaseModalAction');
        const stockStatus = document.getElementById('showcaseModalStockStatus');

        if (label) label.textContent = bundle.name;
        if (name) name.textContent = bundle.name;
        if (meta) meta.textContent = bundle.meta;
        if (desc) desc.textContent = bundle.description;
        if (image) {
          if (bundle.imageUrl) {
            image.style.backgroundImage = 'url(' + bundle.imageUrl + ')';
            image.style.backgroundSize = 'cover';
            image.style.backgroundPosition = 'center';
            image.textContent = '';
          } else {
            image.style.backgroundImage = 'none';
            image.textContent = '';
          }
        }
        if (action) {
          action.onclick = function() {
            window.location = '?page=checkout&bundle_name=' + encodeURIComponent(bundle.name)
              + '&main=' + bundle.main
              + '&fillers=' + bundle.fillers
              + '&greenery=' + bundle.greenery;
          };
          updateShowcaseStockStatus(bundle, stockStatus, action);
        }

        subSlide = index;
        renderSubTrack(index);
        document.getElementById('showcaseModal').classList.add('open');
      }

      function closeShowcaseModal() {
        document.getElementById('showcaseModal').classList.remove('open');
      }

      function renderSubTrack(active) {
        const subTrack = document.getElementById('subTrack');
        if (!subTrack) return;
        subTrack.innerHTML = '';
        SHOWCASE_BUNDLES.forEach((bundle, index) => {
          const thumb = document.createElement('button');
          thumb.type = 'button';
          thumb.className = 'sub-thumb' + (index === active ? ' active' : '');
          thumb.onclick = () => openShowcaseModal(index);
          thumb.innerHTML = '<div class="sub-thumb-title">' + bundle.name + '</div><div class="sub-thumb-meta">' + bundle.meta + '</div>';
          subTrack.appendChild(thumb);
        });
        const width = subTrack.children[0] ? subTrack.children[0].offsetWidth + 10 : 0;
        subTrack.style.transform = 'translateX(' + (-active * width) + 'px)';
      }

      function advanceSubCarousel(direction) {
        subSlide = (subSlide + direction + SHOWCASE_BUNDLES.length) % SHOWCASE_BUNDLES.length;
        openShowcaseModal(subSlide);
      }

      function createShowcaseCard(bundle, index) {
        const card = document.createElement('div');
        card.className = 'showcase-card';
        card.onclick = function() { openShowcaseModal(index); };

        const image = document.createElement('div');
        image.className = 'showcase-image';
        if (bundle.imageUrl) {
          image.style.backgroundImage = 'url("' + bundle.imageUrl + '")';
          image.style.backgroundSize = 'cover';
          image.style.backgroundPosition = 'center';
          const inner = document.createElement('div');
          inner.className = 'showcase-image-inner';
          inner.style.background = 'rgba(0,0,0,0.28)';
          inner.style.color = '#ffffff';
          inner.style.width = '100%';
          inner.style.height = '100%';
          inner.style.display = 'flex';
          inner.style.alignItems = 'center';
          inner.style.justifyContent = 'center';
          inner.style.textAlign = 'center';
          inner.style.padding = '16px';
          image.appendChild(inner);
        } else {
          const inner = document.createElement('div');
          inner.className = 'showcase-image-inner';
          image.appendChild(inner);
        }

        const body = document.createElement('div');
        body.className = 'showcase-card-body';
        const title = document.createElement('div');
        title.className = 'showcase-card-title';
        title.textContent = bundle.name;
        const meta = document.createElement('div');
        meta.className = 'showcase-card-meta';
        meta.textContent = bundle.meta;
        body.appendChild(title);
        body.appendChild(meta);

        if (SHOWCASE_IS_ADMIN) {
          const deleteBtn = document.createElement('button');
          deleteBtn.type = 'button';
          deleteBtn.className = 'showcase-delete-btn';
          deleteBtn.innerHTML = '&times;';
          deleteBtn.setAttribute('aria-label', 'Delete showcase item');
          deleteBtn.onclick = function(event) { event.stopPropagation(); removeShowcaseItem(index); };
          card.appendChild(deleteBtn);
        }

        card.appendChild(image);
        card.appendChild(body);
        return card;
      }

      function renderShowcaseGrid() {
        const grid = document.getElementById('showcaseGrid');
        if (!grid) return;
        grid.innerHTML = '';
        SHOWCASE_BUNDLES.forEach((bundle, index) => {
          grid.appendChild(createShowcaseCard(bundle, index));
        });
      }

      let showcaseDeleteIndex = null;
      let showcaseDeleteId = null;

      function removeShowcaseItem(index) {
        showcaseDeleteIndex = index;
        showcaseDeleteId = SHOWCASE_BUNDLES[index] ? SHOWCASE_BUNDLES[index].showcase_id : null;
        const message = document.getElementById('deleteConfirmMessage');
        if (message) {
          message.textContent = 'Do you wish to delete this showcase?';
        }
        const modal = document.getElementById('deleteConfirmModal');
        if (modal) modal.classList.add('open');
      }

      function closeDeleteConfirm() {
        showcaseDeleteIndex = null;
        showcaseDeleteId = null;
        const modal = document.getElementById('deleteConfirmModal');
        if (modal) modal.classList.remove('open');
      }

      async function confirmDeleteShowcase() {
        if (showcaseDeleteIndex === null || showcaseDeleteId === null) return;
        const index = showcaseDeleteIndex;
        const id = showcaseDeleteId;
        showcaseDeleteIndex = null;
        showcaseDeleteId = null;

        try {
          const endpoint = window.location.href.split('?')[0];
          console.log('Deleting showcase', id, 'via', endpoint);
          const res = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'delete_showcase', id: id })
          });
          const data = await res.json();
          if (!res.ok) {
            console.error('Delete showcase HTTP error', res.status, data);
            return;
          }
          if (data.status === 'ok') {
            SHOWCASE_BUNDLES.splice(index, 1);
            renderShowcaseGrid();
            try { if (window.saveShowcaseLocal) window.saveShowcaseLocal(); } catch(e) {}
            closeShowcaseModal();
            closeDeleteConfirm();
          } else {
            console.error('Delete showcase failed', data.message, data);
          }
        } catch (err) {
          console.error('Delete showcase error', err);
        }
      }

      document.addEventListener('DOMContentLoaded', function() {
        const addForm = document.getElementById('addShowcaseForm');
        const imageInput = document.getElementById('newShowcaseImage');

        if (imageInput) {
          imageInput.addEventListener('change', previewNewShowcaseImage);
        }

        if (addForm) {
          addForm.addEventListener('submit', function(event) {
            event.preventDefault();
            const nameEl = document.getElementById('newShowcaseName');
            const descEl = document.getElementById('newShowcaseDescription');
            const flowersEl = document.getElementById('newShowcaseFlowers');
            const fillersEl = document.getElementById('newShowcaseFillers');
            const greeneryEl = document.getElementById('newShowcaseGreenery');
            const imageEl = document.getElementById('newShowcaseImage');
            if (!nameEl || !descEl || !flowersEl || !fillersEl || !greeneryEl) return;

            const mainCount = parseInt(flowersEl.value.trim(), 10) || 0;
            const fillersCount = parseInt(fillersEl.value.trim(), 10) || 0;
            const greeneryCount = parseInt(greeneryEl.value.trim(), 10) || 0;
            const metaText = `${mainCount} main flower${mainCount === 1 ? '' : 's'} • ${fillersCount} filler${fillersCount === 1 ? '' : 's'} • ${greeneryCount} free greenery${greeneryCount === 1 ? '' : 's'}`;
            const newBundle = {
              name: nameEl.value.trim(),
              meta: metaText,
              description: descEl.value.trim(),
              imageUrl: null,
              main: mainCount,
              fillers: fillersCount,
              greenery: greeneryCount,
            };

            const file = imageEl && imageEl.files && imageEl.files[0];
            const submitBundle = function() {
              submitShowcaseToServer(newBundle, file).then(() => {
                closeAddShowcaseModal();
                if (addForm) addForm.reset();
                previewNewShowcaseImage();
              });
            };

            submitBundle();
          });
        }

        renderShowcaseGrid();
        // ensure persisted showcase local storage is up-to-date
        try { if (window.saveShowcaseLocal) window.saveShowcaseLocal(); } catch(e) {}
        // apply category restrictions UI for selected bundle
        try { if (typeof updateCategoryRestrictions === 'function') updateCategoryRestrictions(); } catch(e) {}

        // show selected bundle info in cart header (right panel)
        try {
          if (typeof SELECTED_BUNDLE !== 'undefined' && SELECTED_BUNDLE && SELECTED_BUNDLE.name) {
            const cartTitle = document.querySelector('.co-cart-title');
            if (cartTitle) {
              let info = document.getElementById('cart_bundle_info');
              if (!info) {
                info = document.createElement('div');
                info.id = 'cart_bundle_info';
                info.style.fontSize = '12px';
                info.style.color = 'var(--text-3)';
                info.style.marginTop = '6px';
                cartTitle.parentNode.insertBefore(info, cartTitle.nextSibling);
              }
              info.textContent = `Bundle: ${SELECTED_BUNDLE.name} — ${SELECTED_BUNDLE.main} main, ${SELECTED_BUNDLE.fillers} filler${SELECTED_BUNDLE.fillers===1? '':'s'}, ${SELECTED_BUNDLE.greenery} greenery`;
            }
          }
        } catch(e) { console.error(e); }
      });

      // Ensure local persisted showcases are saved when we modify the client list
      function persistShowcasesClient() {
        try { if (window.saveShowcaseLocal) window.saveShowcaseLocal(); } catch(e) {}
      }

      function openAddShowcaseModal() {
        if (!SHOWCASE_IS_ADMIN) return;
        const modal = document.getElementById('addShowcaseModal');
        if (modal) modal.classList.add('open');
      }

      function closeAddShowcaseModal() {
        const modal = document.getElementById('addShowcaseModal');
        if (modal) modal.classList.remove('open');
      }

      function previewNewShowcaseImage() {
        const input = document.getElementById('newShowcaseImage');
        const preview = document.getElementById('newShowcasePreview');
        if (!preview) return;
        if (input && input.files && input.files[0]) {
          const file = input.files[0];
          const reader = new FileReader();
          reader.onload = function(evt) {
            preview.classList.add('has-image');
            preview.style.backgroundImage = 'url(' + evt.target.result + ')';
            preview.textContent = '';
          };
          reader.readAsDataURL(file);
        } else {
          preview.classList.remove('has-image');
          preview.style.backgroundImage = 'none';
          preview.textContent = 'Image preview';
        }
      }

      async function submitShowcaseToServer(bundle, file) {
        try {
          const endpoint = window.location.href.split('?')[0];
          const formData = new FormData();
          formData.append('action', 'add_showcase');
          formData.append('name', bundle.name);
          formData.append('description', bundle.description);
          formData.append('main', bundle.main);
          formData.append('fillers', bundle.fillers);
          formData.append('greenery', bundle.greenery);
          formData.append('meta', bundle.meta);
          if (file) {
            formData.append('image_file', file);
          } else if (bundle.imageUrl) {
            formData.append('image_url', bundle.imageUrl);
          }
          console.log('Adding showcase', bundle, 'via', endpoint);
          const res = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
          });
          const data = await res.json();
          if (!res.ok) {
            console.error('Add showcase HTTP error', res.status, data);
            return;
          }
          if (data.status === 'ok' && data.item) {
            bundle.showcase_id = data.item.showcase_id;
            bundle.imageUrl = data.item.image_url || bundle.imageUrl;
            SHOWCASE_BUNDLES.push(bundle);
            renderShowcaseGrid();
            // persist client-side as fallback (for data-URL images)
            try { if (window.saveShowcaseLocal) window.saveShowcaseLocal(); } catch(e) {}
          } else {
            console.error('Failed to save showcase', data.message, data);
          }
        } catch (err) {
          console.error('Error saving showcase', err);
        }
      }

      function addShowcaseItem(bundle) {
        SHOWCASE_BUNDLES.push(bundle);
        renderShowcaseGrid();
      }
    </script>

  <?php elseif ($page === 'checkout'): ?>
    <div class="checkout-wrap">
      <div class="co-left">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; flex-shrink:0;">
          <div style="display:flex; align-items:center; gap:14px;">
            <a href="?page=dashboard" style="color:var(--text-3); text-decoration:none; font-size:20px; line-height:1;">&larr;</a>
            <div>
              <div style="font-size:17px; font-weight:700; color:var(--espresso);">Register</div>
              <div style="font-size:11px; color:var(--text-3);"><?= date('D, d M Y &middot; h:i A') ?></div>
            </div>
          </div>
          <div class="co-search">
            <span class="co-search-icon">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8" />
                <line x1="21" y1="21" x2="16.65" y2="16.65" />
              </svg>
            </span>
            <input type="text" id="sku_scanner" placeholder="Search product or scan SKU..." style="width:280px;">
          </div>
        </div>

        <?php
          $bundleName = isset($_GET['bundle_name']) ? trim($_GET['bundle_name']) : '';
          $bundleMain = isset($_GET['main']) ? intval($_GET['main']) : 0;
          $bundleFillers = isset($_GET['fillers']) ? intval($_GET['fillers']) : 0;
          $bundleGreenery = isset($_GET['greenery']) ? intval($_GET['greenery']) : 0;
        ?>
        <?php if ($bundleName !== ''): ?>
          <div class="alert alert-info" style="margin-bottom:18px; display:flex; flex-direction:column; gap:8px;">
            <div style="font-weight:700; color:var(--espresso);">Selected: <?= htmlspecialchars($bundleName, ENT_QUOTES, 'UTF-8') ?></div>
            <div style="font-size:14px; color:var(--text-3);">Please select exactly <?= $bundleMain ?> main flower<?= $bundleMain === 1 ? '' : 's' ?>, <?= $bundleFillers ?> filler<?= $bundleFillers === 1 ? '' : 's' ?>, and <?= $bundleGreenery ?> free greenery<?= $bundleGreenery === 1 ? '' : 's' ?> to complete your bouquet.</div>
          </div>
        <?php endif; ?>

        <div class="cat-filter" id="cat-filter">
          <span class="cat-pill active" data-cat="">All</span>
          <?php foreach ($cats as $c): ?>
            <span class="cat-pill" data-cat="<?= htmlspecialchars($c['category_name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c['category_name'], ENT_QUOTES, 'UTF-8') ?></span>
          <?php endforeach; ?>
        </div>

        <?php if (isset($_GET['success'])): ?>
          <div class="alert alert-success" style="flex-shrink:0;">
            Sale <strong><?php echo htmlspecialchars(isset($_GET['order_id']) ? $_GET['order_id'] : '', ENT_QUOTES, 'UTF-8'); ?></strong> completed successfully.
          </div>
        <?php endif; ?>

        <div class="co-products">
          <div class="prod-grid" id="prod-grid">
            <?php foreach ($inventory as $item):
              $ep = effectivePrice($item);
              $hasDisc = $ep < $item['price'];
              $taxRate = isset($store_info['tax_rate']) ? floatval($store_info['tax_rate']) : 0.12;
              $ep_with_vat = $ep * (1 + $taxRate);
              $base_with_vat = floatval($item['price']) * (1 + $taxRate);
            ?>
              <div class="prod-tile"
                data-sku="<?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?>"
                data-cat="<?= htmlspecialchars($item['category_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                data-name="<?= strtolower(htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8')) ?>"
                onclick="addToCart(<?= htmlspecialchars(json_encode([
                                      'sku'          => $item['sku'],
                                      'product_name' => $item['product_name'],
                                      'price'        => $ep,
                                      'stock_qty'    => $item['stock_qty'],
                                      'category'     => $item['category_name'] ?? ''
                                    ]), ENT_QUOTES, 'UTF-8') ?>)">
                <div class="prod-img">
                  <?php if (!empty($item['image_url'])): ?>
                    <img src="/Bloom_POS/<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                  <?php else: ?>
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#D4BCA9" stroke-width="1.5">
                      <rect x="3" y="3" width="18" height="18" rx="2" />
                      <circle cx="8.5" cy="8.5" r="1.5" />
                      <polyline points="21 15 16 10 5 21" />
                    </svg>
                  <?php endif; ?>
                </div>
                <div class="prod-info">
                  <span class="badge badge-gray" style="font-size:10px; margin-bottom:6px; display:inline-block;"><?= htmlspecialchars($item['category_name'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8') ?></span>
                  <?php if (!empty($item['disc_status']) && $item['disc_status'] == 1 && !empty($item['discount_value'])): 
                    $dlabel = in_array(strtolower($item['discount_type'] ?? ''), ['percentage', 'percent']) ? (floatval($item['discount_value']) . '% OFF') : ('₱' . number_format($item['discount_value'], 2) . ' OFF');
                  ?>
                    <span class="badge badge-blue" style="font-size:10px; margin-bottom:6px; display:inline-block;"><?= htmlspecialchars($dlabel, ENT_QUOTES, 'UTF-8') ?></span>
                  <?php endif; ?>
                  <div class="prod-name"><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="prod-price">
                    &#8369;<?= number_format($ep_with_vat, 2) ?>
                    <?php if ($hasDisc): ?><span style="font-size:10px; text-decoration:line-through; color:var(--text-3); margin-left:3px;">&#8369;<?= number_format($base_with_vat, 2) ?></span><?php endif; ?>
                  </div>
                  <div class="prod-stock <?= $item['stock_qty'] < 10 ? 'low-stock' : '' ?>">Stock: <?= $item['stock_qty'] ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Cart panel -->
      <div class="co-right">
        <div class="co-cart-header">
          <div style="display:flex; align-items:center; justify-content:space-between;">
            <div class="co-cart-title">Order <span id="order_num" style="color:var(--text-3); font-weight:400; font-size:13px;">#<?= 1000 + ($daily_trx + 1) ?></span></div>
            <span id="cart_count" class="badge badge-brown">0 items</span>
          </div>
          <div style="margin-top:10px;">
            <div class="customer-combobox" id="customer_combobox">
              <div class="combo-control" id="customer_combo_control" role="combobox" aria-haspopup="listbox" aria-expanded="false" tabindex="0">
                <span class="combo-value" id="customer_combo_value">Walk-in Customer</span>
                <span class="combo-arrow">▾</span>
              </div>
              <div id="points_section" style="margin-top:8px; display:flex; gap:8px; align-items:center; font-size:13px;">
                <div style="color:var(--text-3);">Points:</div>
                <div id="points_balance" style="font-weight:700; color:var(--chestnut);">0 pts</div>
                <div style="margin-left:8px; color:var(--text-3);">Redeem</div>
                <input type="number" id="points_input" min="0" value="0" style="width:90px; padding:8px; border-radius:6px; border:1px solid var(--taupe);" oninput="onPointsInput()">
                <button type="button" onclick="clearPoints()" class="btn btn-secondary" style="padding:6px 10px;">Clear</button>
              </div>
              <div class="combo-panel" id="customer_combo_panel">
                <input type="text" id="customer_search" class="combo-search" placeholder="Search customers..." autocomplete="off">
                <div class="combo-list" id="customer_list" role="listbox" aria-label="Choose a customer">
                  <div class="combo-option combo-pinned selected" data-value="" data-label="Walk-in Customer">Walk-in Customer</div>
                  <?php foreach ($customers as $c): ?>
                    <?php $cust_label = 'CUST-' . str_pad($c['customer_id'], 3, '0', STR_PAD_LEFT) . ' - ' . $c['full_name'] . ' (' . intval($c['loyalty_points']) . ' pts)'; ?>
                    <div class="combo-option" data-value="<?= $c['customer_id'] ?>" data-label="<?= htmlspecialchars($cust_label, ENT_QUOTES, 'UTF-8') ?>">
                      <?= htmlspecialchars($cust_label, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  <?php endforeach; ?>
                  <div class="combo-no-results" style="display:none;">No customers found</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="cart-list" id="cart_list">
          <div class="cart-empty" id="cart_empty">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.25;">
              <circle cx="9" cy="21" r="1" />
              <circle cx="20" cy="21" r="1" />
              <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
            </svg>
            <div style="font-size:13px; font-weight:500;">Basket is empty</div>
            <div style="font-size:12px;">Select products to add</div>
          </div>
        </div>

        <div class="co-totals">
          <div class="tot-row"><span>Subtotal</span><span id="d_subtotal">&#8369;0.00</span></div>
          <div class="tot-row">
            <span>Promotion</span>
            <select id="promo_select" onchange="calcTotals()" style="border:none; background:transparent; color:var(--chestnut); font-weight:600; font-size:12px; outline:none; padding:0; width:auto;">
              <option value="0" data-value="0" data-type="" data-name="">None</option>
              <?php foreach ($active_promos as $p): ?>
                <option value="<?= (int)$p['discount_id'] ?>" data-value="<?= $p['discount_value'] ?>" data-type="<?= htmlspecialchars($p['discount_type'], ENT_QUOTES, 'UTF-8') ?>" data-name="<?= htmlspecialchars($p['discount_name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($p['discount_name'], ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="tot-row"><span>Discount</span><span id="d_discount" style="color:var(--green);">&#8212;</span></div>
          <div class="tot-row"><span>Points Redeemed</span><span id="d_points">&#8212;</span></div>
          <div class="tot-row"><span>VAT (12%)</span><span id="d_tax">&#8369;0.00</span></div>
          <div class="tot-row grand"><span>Total</span><span id="d_total" style="color:var(--chestnut);">&#8369;0.00</span></div>
        </div>

        <div class="co-actions">
          <div style="display:flex; gap:8px;">
            <button onclick="voidCart()" class="btn btn-secondary btn-full">Void</button>
            <button onclick="holdSale()" class="btn btn-secondary btn-full" style="color:var(--amber);">Hold</button>
            <button onclick="recallHeld()" class="btn btn-secondary btn-full" style="color:var(--blue);">Recall</button>
          </div>
          <button onclick="openPayModal()" class="btn btn-primary btn-full btn-lg">Finalize Sale</button>
        </div>
      </div>
    </div>

    <!-- Payment Modal -->
    <div class="overlay" id="pay_overlay">
      <div class="modal-box wide" style="max-width:900px !important; width:900px;">
        <div class="modal-header">
          <span class="modal-title">Complete Payment</span>
          <button class="modal-close" onclick="closePayModal()">&times;</button>
        </div>
        <form method="POST" action="?page=checkout" id="sale_form" enctype="multipart/form-data">
          <input type="hidden" name="finalize_sale" value="1">
          <input type="hidden" name="cart_json" id="f_cart">
          <input type="hidden" name="final_total" id="f_total">
          <input type="hidden" name="tax_amount" id="f_tax">
          <input type="hidden" name="discount_amount" id="f_disc">
          <input type="hidden" name="points_redeemed" id="f_points">
          <input type="hidden" name="customer_id" id="f_customer">
          <input type="hidden" name="promotion_id" id="f_promotion_id">
          <input type="hidden" name="promotion_name" id="f_promotion_name">
          <input type="hidden" name="promotion_type" id="f_promotion_type">
          <input type="hidden" name="bundle_name" id="f_bundle_name">
          <input type="hidden" name="bundle_main" id="f_bundle_main">
          <input type="hidden" name="bundle_fillers" id="f_bundle_fillers">
          <input type="hidden" name="bundle_greenery" id="f_bundle_greenery">
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
            <div>
              <div style="font-size:11px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:.06em; margin-bottom:10px;">Receipt Preview</div>
              <div class="receipt">
                <div class="receipt-title">Bloom POS</div>
                <div style="text-align:center; font-size:10px; color:var(--text-3);"><?= date('d M Y &middot; h:i A') ?></div>
                <hr class="receipt-sep">
                <div id="r_items"></div>
                <hr class="receipt-sep">
                <div class="receipt-row"><span>Subtotal</span><span id="r_sub">&#8369;0.00</span></div>
                <div class="receipt-row" id="r_promo_row" style="display:none;"><span>Promotion</span><span id="r_promo_name"></span></div>
                <div class="receipt-row"><span>Discount</span><span id="r_disc">&#8369;0.00</span></div>
                <div class="receipt-row"><span>Points Redeemed</span><span id="r_points">&#8369;0.00</span></div>
                <div class="receipt-row"><span>VAT 12%</span><span id="r_tax">&#8369;0.00</span></div>
                <hr class="receipt-sep">
                <div class="receipt-row" style="font-weight:700; font-size:14px;"><span>TOTAL</span><span id="r_total">&#8369;0.00</span></div>
                <hr class="receipt-sep">
                <div class="receipt-row"><span>Change</span><span id="r_change">&#8212;</span></div>
                <div class="receipt-row"><span>Mode of Payment</span><span id="r_payment_method">&#8212;</span></div>
                <div class="receipt-row" id="r_wallet_row" style="display:none;"><span id="r_wallet_contact_label">Contact Number</span><span id="r_wallet_contact">&#8212;</span></div>
              </div>
            </div>
            <div>
              <div class="form-group">
                <label>Payment Method</label>
                <div style="display:flex; gap:8px; margin-top:6px;">
                  <?php foreach (['Cash', 'GCash', 'Maya'] as $pm): ?>
                    <label style="display:flex; align-items:center; gap:8px; padding:10px 14px; border:1.5px solid var(--taupe); border-radius:var(--radius); cursor:pointer; flex:1; font-size:13px; color:var(--text);">
                      <input type="radio" name="payment_method" value="<?= $pm ?>" <?= $pm === 'Cash' ? 'checked' : '' ?> onchange="handlePaymentMethodChange(this.value)"> <?= $pm ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <div id="cash_area" class="form-group">
                <label>Amount Received</label>
                <input type="text" name="amount_tendered" id="amount_tendered" placeholder="0.00" oninput="fmtCash(this); calcChange();">
              </div>
              <div id="digital_wallet_area" style="display:none;">
                <div class="form-group">
                  <label id="wallet_contact_label">Contact Number <span style="color:var(--red);">*</span></label>
                  <input type="text" id="wallet_contact" name="wallet_contact" placeholder="e.g., 09123456789" inputmode="numeric" pattern="[0-9]*" maxlength="13" oninput="this.value = this.value.replace(/\D/g,'').slice(0, this.maxLength);">
                </div>
                <div class="form-group">
                  <label>Account Name <span style="color:var(--red);">*</span></label>
                  <input type="text" id="wallet_account_name" name="wallet_account_name" placeholder="Account holder name" pattern="[A-Za-zÑñ ]+" title="Letters and spaces only" oninput="this.value = this.value.replace(/[^A-Za-zÑñ\s]/g,'')">
                </div>
                <div class="form-group">
                  <label>Reference/Transaction Proof <span style="color:var(--red);">*</span></label>
                  <input type="file" id="wallet_proof" name="wallet_proof" accept="image/*">
                </div>
              </div>
              <div id="payment_error" style="display:none; background:var(--red-l); border:1px solid var(--red); border-radius:var(--radius); padding:12px; margin-bottom:16px; color:var(--red); font-size:13px; font-weight:600;" role="alert"></div>
              <div style="background:var(--taupe-l); border-radius:var(--radius); padding:14px 16px; margin-bottom:16px; border:1px solid var(--taupe);">
                <div style="font-size:11px; color:var(--chestnut-d); text-transform:uppercase; letter-spacing:.06em; font-weight:700; margin-bottom:4px;">Total Due</div>
                <div id="modal_total" style="font-size:28px; font-weight:800; color:var(--chestnut);">&#8369;0.00</div>
              </div>
              <button type="submit" id="submit_sale_btn" class="btn btn-primary btn-full btn-lg" style="position:relative; z-index:10000; pointer-events:auto; outline: 2px solid rgba(255,0,0,0);">Confirm &amp; Complete Sale</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <script>
      const TAX_RATE = 0.12;
      function formatItemCount(n) {
        const count = Number(n) || 0;
        return count === 1 ? (count + ' item') : (count + ' items');
      }
      let cart = [];
      let selectedCustomerId = '';
      const STORE_INFO = <?= json_encode($store_info, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const allProducts = <?= json_encode($inventory) ?>;
      // Normalize inventory product objects to include a consistent `category` property
      (function(){
        try {
          for (let i = 0; i < allProducts.length; i++) {
            const p = allProducts[i];
            if (!p) continue;
            if (!p.category && p.category_name) p.category = p.category_name;
            // make sure category is a simple string
            if (p.category === undefined || p.category === null) p.category = '';
          }
        } catch(e) { console.error('normalize allProducts error', e); }
      })();
      // Selected showcase bundle (if any) passed via query params
      let SELECTED_BUNDLE = {
        name: <?= json_encode($bundleName) ?> || '',
        main: <?= intval($bundleMain) ?> || 0,
        fillers: <?= intval($bundleFillers) ?> || 0,
        greenery: <?= intval($bundleGreenery) ?> || 0
      };

      function setSelectedBundle(obj) {
        try {
          SELECTED_BUNDLE = Object.assign({ name: '', main:0, fillers:0, greenery:0 }, obj || {});
          // persist selection
          try { localStorage.setItem('selected_bundle', JSON.stringify(SELECTED_BUNDLE)); } catch(e) {}
          // update cart header info
          try {
            const cartTitle = document.querySelector('.co-cart-title');
            if (cartTitle) {
              let info = document.getElementById('cart_bundle_info');
              if (!info) {
                info = document.createElement('div');
                info.id = 'cart_bundle_info';
                info.style.fontSize = '12px';
                info.style.color = 'var(--text-3)';
                info.style.marginTop = '6px';
                cartTitle.parentNode.insertBefore(info, cartTitle.nextSibling);
              }
              if (SELECTED_BUNDLE && SELECTED_BUNDLE.name) {
                info.textContent = `Bundle: ${SELECTED_BUNDLE.name} — ${SELECTED_BUNDLE.main} main, ${SELECTED_BUNDLE.fillers} filler${SELECTED_BUNDLE.fillers===1? '':'s'}, ${SELECTED_BUNDLE.greenery} greenery`;
              } else {
                info.textContent = '';
              }
            }
          } catch(e) {}
          // update UI restrictions
          try { if (typeof updateCategoryRestrictions === 'function') updateCategoryRestrictions(); } catch(e) {}
        } catch(e) { console.error('setSelectedBundle error', e); }
      }

      // restore persisted selection only when a bundle was actually selected for checkout
      try {
        const hasBundleQuery = <?= json_encode($bundleName !== '') ?>;
        if ((!SELECTED_BUNDLE || !SELECTED_BUNDLE.name) && hasBundleQuery && localStorage.getItem('selected_bundle')) {
          const sb = JSON.parse(localStorage.getItem('selected_bundle')) || null;
          if (sb && sb.name) setSelectedBundle(sb);
        } else if (SELECTED_BUNDLE && SELECTED_BUNDLE.name) {
          try { localStorage.setItem('selected_bundle', JSON.stringify(SELECTED_BUNDLE)); } catch(e) {}
        }
      } catch(e) { /* ignore */ }
      const CUSTOMER_POINTS = <?= json_encode(array_reduce($customers, function($carry,$c){ $carry[$c['customer_id']] = intval($c['loyalty_points'] ?? 0); return $carry; }, []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

      function getSelectedCustomerValue() {
        return selectedCustomerId;
      }

      function setCustomerSelection(value, label) {
        selectedCustomerId = value === null ? '' : String(value);
        const selectedLabel = label || (selectedCustomerId === '' ? 'Walk-in Customer' : 'Walk-in Customer');
        document.getElementById('customer_combo_value').textContent = selectedLabel;
        document.getElementById('f_customer').value = selectedCustomerId;
        document.querySelectorAll('#customer_list .combo-option').forEach(el => {
          el.classList.toggle('selected', el.dataset.value === selectedCustomerId);
        });
        updatePointsUI();
        calcTotals();
      }

      function updatePointsUI() {
        const cid = getSelectedCustomerValue();
        const bal = parseInt(CUSTOMER_POINTS[cid] || 0, 10) || 0;
        const balEl = document.getElementById('points_balance');
        if (balEl) balEl.textContent = bal + ' pts';
        const pin = document.getElementById('points_input');
        if (pin) {
          pin.max = bal;
          const cur = parseInt(pin.value || '0', 10) || 0;
          if (cur > bal) pin.value = bal;
        }
      }

      function onPointsInput() {
        const pin = document.getElementById('points_input');
        if (!pin) return;
        const cid = getSelectedCustomerValue();
        const bal = parseInt(CUSTOMER_POINTS[cid] || 0, 10) || 0;
        let v = parseInt(pin.value || '0', 10) || 0;
        if (v < 0) v = 0;
        if (v > bal) v = bal;
        pin.value = v;
        calcTotals();
      }

      function clearPoints() {
        const pin = document.getElementById('points_input');
        if (pin) pin.value = 0;
        calcTotals();
      }

      function filterCustomerOptions(query) {
        const q = String(query || '').trim().toLowerCase();
        const list = document.getElementById('customer_list');
        const options = Array.from(list.querySelectorAll('.combo-option:not(.combo-pinned)'));
        let matches = 0;
        options.forEach(opt => {
          const label = opt.dataset.label.toLowerCase();
          const visible = q === '' || label.includes(q);
          opt.style.display = visible ? '' : 'none';
          if (visible) matches++;
        });
        const noResults = list.querySelector('.combo-no-results');
        if (noResults) {
          noResults.style.display = matches === 0 ? 'block' : 'none';
        }
      }

      function initCustomerCombobox() {
        const wrapper = document.getElementById('customer_combobox');
        const control = document.getElementById('customer_combo_control');
        const panel = document.getElementById('customer_combo_panel');
        const search = document.getElementById('customer_search');
        const list = document.getElementById('customer_list');
        const optionElements = Array.from(list.querySelectorAll('.combo-option'));

        function openDropdown() {
          wrapper.classList.add('open');
          control.setAttribute('aria-expanded', 'true');
          search.focus();
          filterCustomerOptions(search.value);
        }

        function closeDropdown() {
          wrapper.classList.remove('open');
          control.setAttribute('aria-expanded', 'false');
          clearFocusedOption();
        }

        function clearFocusedOption() {
          optionElements.forEach(el => el.classList.remove('focused'));
        }

        function getVisibleOptions() {
          return optionElements.filter(el => el.style.display !== 'none');
        }

        function selectOption(option) {
          if (!option || !option.dataset) return;
          setCustomerSelection(option.dataset.value, option.dataset.label);
          closeDropdown();
          calcTotals();
        }

        control.addEventListener('click', function(e) {
          e.stopPropagation();
          if (wrapper.classList.contains('open')) {
            closeDropdown();
          } else {
            openDropdown();
          }
        });

        list.addEventListener('click', function(e) {
          const option = e.target.closest('.combo-option');
          if (option) {
            selectOption(option);
          }
        });

        search.addEventListener('input', function(e) {
          filterCustomerOptions(e.target.value);
        });

        search.addEventListener('keydown', function(e) {
          const visible = getVisibleOptions();
          const currentIndex = visible.findIndex(opt => opt.classList.contains('focused'));
          if (e.key === 'ArrowDown') {
            e.preventDefault();
            const nextIndex = currentIndex < visible.length - 1 ? currentIndex + 1 : 0;
            clearFocusedOption();
            if (visible[nextIndex]) visible[nextIndex].classList.add('focused');
          } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const prevIndex = currentIndex > 0 ? currentIndex - 1 : visible.length - 1;
            clearFocusedOption();
            if (visible[prevIndex]) visible[prevIndex].classList.add('focused');
          } else if (e.key === 'Enter') {
            e.preventDefault();
            if (visible[currentIndex]) {
              selectOption(visible[currentIndex]);
            } else {
              const first = visible[0];
              if (first) selectOption(first);
            }
          } else if (e.key === 'Escape') {
            closeDropdown();
          }
        });

        document.addEventListener('click', function(event) {
          if (!wrapper.contains(event.target)) {
            closeDropdown();
          }
        });

        setCustomerSelection('', 'Walk-in Customer');
      }

      window.addEventListener('DOMContentLoaded', initCustomerCombobox);

      // Initialize cart from server-side session
      (async function loadServerCart(){
        try{
          const res = await fetch(window.location.pathname + '?get_cart=1');
          if (!res.ok) return;
          const j = await res.json();
          const srv = j.cart || {};
          cart = Object.keys(srv).map(sku => {
            const qty = parseInt(srv[sku],10) || 0;
            const prod = allProducts.find(p => p.sku === sku) || null;
            if (!prod) return null;
            let price = parseFloat(prod.price);
            const dtype = (prod.discount_type || prod.discountType || '').toLowerCase();
            const dvalue = parseFloat(prod.discount_value || prod.discountValue || 0) || 0;
            const active = prod.disc_status == 1 && dvalue > 0;
            if (active) {
              if (dtype === 'percentage' || dtype === 'percent') {
                price = price * (1 - dvalue / 100);
              } else if (dtype === 'fixed') {
                price = Math.max(0, price - dvalue);
              }
            }
            return { sku: sku, name: prod.product_name, price: price, qty: qty, stock: prod.stock_qty, category: prod.category_name || '' };
          }).filter(Boolean);
          // if server returned empty cart, clear any stale localStorage cart to ensure cart starts empty
          if (!cart || cart.length === 0) {
            try {
              localStorage.removeItem('cart_local');
            } catch(e) { console.error('clear local cart error', e); }
          }
          renderCart();
        }catch(e){}
      })();

      async function serverCartAction(action, sku, qty){
        console.debug('serverCartAction', action, sku, qty);
        const body = new URLSearchParams();
        body.append('cart_action', action);
        if (sku !== undefined) body.append('sku', sku);
        if (qty !== undefined) body.append('qty', String(qty));
        try{
          const res = await fetch(window.location.pathname, { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString() });
          console.debug('serverCartAction response status', res.status);
          const json = await res.json().catch(() => null);
          if (!res.ok) {
            // prefer server-provided message, fallback to statusText
            const msg = (json && (json.message || json.error)) ? (json.message || json.error) : (res.statusText || 'Server error');
            return { status: 'error', message: msg, code: res.status };
          }
          return json;
        }catch(e){
          console.error('serverCartAction network error', e);
          return { status: 'error', message: e.message || 'Network error' };
        }
      }

      async function addToCart(p) {
        console.debug('addToCart called', p);
        if (p.stock_qty <= 0) { toast('Out of stock!', 'red'); return; }
        // Enforce selected bundle category limits (if any)
        try {
          const cat = (p.category || '').toString();
          const getKey = (c) => {
            const s = (c||'').toLowerCase();
            if (s.indexOf('main') !== -1) return 'main';
            if (s.indexOf('fill') !== -1) return 'fillers';
            if (s.indexOf('green') !== -1) return 'greenery';
            return null;
          };
          const key = getKey(cat);
          if (SELECTED_BUNDLE && SELECTED_BUNDLE.name && key) {
            const allowed = Number(SELECTED_BUNDLE[key] || 0);
            if (allowed === 0) {
              toast(`No selections allowed for this category for the chosen bundle`, 'amber');
              return;
            }
            if (allowed > 0) {
              const currentCount = cart.reduce((s,i)=> s + ((i.category && getKey(i.category)===key) ? (i.qty||0) : 0), 0);
              if (currentCount >= allowed) { toast(`You can only select ${allowed} ${key} for this bundle`, 'amber'); return; }
              if (currentCount + 1 > allowed) { toast(`Selecting this would exceed the ${key} limit (${allowed})`, 'amber'); return; }
            }
          }
        } catch(e) { console.error(e); }
        const existing = cart.find(i => i.sku === p.sku);
        const currentQty = existing ? existing.qty : 0;
        if (currentQty >= p.stock_qty) { toast('Max stock reached', 'amber'); return; }
        const srv = await serverCartAction('add', p.sku, 1);
        if (!srv || srv.status !== 'ok') { toast((srv && srv.message) ? srv.message : 'Cannot add item', 'red'); return; }
        const qty = parseInt((srv.cart || {})[p.sku] || 0, 10);
        if (existing) existing.qty = qty; else cart.push({ sku: p.sku, name: p.product_name, price: parseFloat(p.price), qty: qty, stock: p.stock_qty, category: p.category || '' });
        renderCart();
        toast(p.product_name + ' added');
      }

      function renderCart() {
        const list = document.getElementById('cart_list');
        if (cart.length === 0) {
          list.innerHTML = '<div class="cart-empty"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.25;"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg><div style="font-size:13px;font-weight:500;">Basket is empty</div><div style="font-size:12px;">Select products to add</div></div>';
          document.getElementById('cart_count').textContent = formatItemCount(0);
          calcTotals();
          return;
        }
        let html = '';
        // compute category counts to decide plus-button availability
        const getKey = (c) => {
          const s = (c||'').toLowerCase();
          if (s.indexOf('main') !== -1) return 'main';
          if (s.indexOf('fill') !== -1) return 'fillers';
          if (s.indexOf('green') !== -1) return 'greenery';
          return null;
        };
        const counts = { main:0, fillers:0, greenery:0 };
        cart.forEach(i => { const k = getKey(i.category); if (k) counts[k] = (counts[k]||0) + (i.qty||0); });
        cart.forEach((item, i) => {
          // determine if plus should be disabled for this item
          const key = getKey(item.category);
          const allowed = (SELECTED_BUNDLE && SELECTED_BUNDLE.name && key) ? Number(SELECTED_BUNDLE[key] || 0) : null;
          const plusDisabled = (allowed !== null && (allowed === 0 || (allowed > 0 && counts[key] >= allowed))) || (item.qty >= item.stock);

          html += `<div class="cart-item">
      <div class="ci-name">
        <div class="ci-pname">${item.name}</div>
        <div class="ci-sku">${item.sku}</div>
      </div>
      <div class="ci-qty">
        <button class="qty-btn" onclick="chgQty(${i},-1)">&#8722;</button>
        <span style="font-size:13px;font-weight:700;min-width:22px;text-align:center;">${item.qty}</span>
        ${plusDisabled ? `<button class="qty-btn" disabled aria-disabled="true">&#43;</button>` : `<button class="qty-btn" onclick="chgQty(${i},1)">&#43;</button>`}
      </div>
      <div class="ci-total">&#8369;${(item.price*item.qty).toLocaleString(undefined,{minimumFractionDigits:2})}</div>
      <button class="ci-del" onclick="rmItem(${i})">&#215;</button>
    </div>`;
        });
        list.innerHTML = html;
        document.getElementById('cart_count').textContent = formatItemCount(cart.reduce((s, i) => s + i.qty, 0));
        calcTotals();
        // Update product tile availability based on current bundle selection and counts
        try { updateCategoryRestrictions(); } catch(e) { /* ignore */ }
        // persist cart locally for restore on refresh
        try { localStorage.setItem('cart_local', JSON.stringify(cart)); } catch(e) {}
      }

      function updateCategoryRestrictions() {
        const getKey = (c) => {
          const s = (c||'').toLowerCase();
          if (s.indexOf('main') !== -1) return 'main';
          if (s.indexOf('fill') !== -1) return 'fillers';
          if (s.indexOf('green') !== -1) return 'greenery';
          return null;
        };
        // compute counts per key
        const counts = { main:0, fillers:0, greenery:0 };
        cart.forEach(i => { const k = getKey(i.category); if (k) counts[k] = (counts[k]||0) + (i.qty||0); });

        document.querySelectorAll('.prod-tile').forEach(el => {
          const cat = el.dataset.cat || '';
          const sku = el.dataset.sku || '';
          const key = getKey(cat);
          if (!key || !SELECTED_BUNDLE || !SELECTED_BUNDLE.name) {
            el.style.pointerEvents = '';
            el.style.opacity = '';
            return;
          }
          const allowed = SELECTED_BUNDLE[key] || 0;
          const inCart = !!cart.find(i => i.sku === sku && i.qty > 0);
          if (allowed === 0) {
            // category fully disabled for this bundle
            el.style.pointerEvents = 'none';
            el.style.opacity = '0.45';
          } else if (allowed > 0 && counts[key] >= allowed && !inCart) {
            el.style.pointerEvents = 'none';
            el.style.opacity = '0.45';
          } else {
            el.style.pointerEvents = '';
            el.style.opacity = '';
          }
        });
      }

      async function chgQty(i, d) {
        const item = cart[i];
        const newQty = item.qty + d;
        if (d > 0 && newQty > item.stock) { toast('Max stock reached', 'amber'); return; }
        // enforce bundle category caps when increasing
        try {
          if (d > 0 && SELECTED_BUNDLE && SELECTED_BUNDLE.name) {
            const getKey = (c) => {
              const s = (c||'').toLowerCase();
              if (s.indexOf('main') !== -1) return 'main';
              if (s.indexOf('fill') !== -1) return 'fillers';
              if (s.indexOf('green') !== -1) return 'greenery';
              return null;
            };
            const key = getKey(item.category || '');
            if (key) {
              const allowed = Number(SELECTED_BUNDLE[key] || 0);
              const currentCount = cart.reduce((s,it) => s + ((it.category && getKey(it.category)===key) ? (it.qty||0) : 0), 0);
              if (allowed === 0) { toast('No selections allowed for this category for the chosen bundle', 'amber'); return; }
              if (currentCount >= allowed) { toast(`You can only select ${allowed} ${key} for this bundle`, 'amber'); return; }
              if (currentCount + 1 > allowed) { toast(`Selecting this would exceed the ${key} limit (${allowed})`, 'amber'); return; }
            }
          }
        } catch(e) { console.error(e); }
        const srv = await serverCartAction('set', item.sku, Math.max(0,newQty));
        if (!srv || srv.status !== 'ok') { toast((srv && srv.message) ? srv.message : 'Cannot update cart', 'red'); return; }
        const updatedQty = parseInt((srv.cart || {})[item.sku] || 0,10);
        if (updatedQty <= 0) cart.splice(i,1); else cart[i].qty = updatedQty;
        renderCart();
      }

      async function rmItem(i) {
        const sku = cart[i].sku;
        const srv = await serverCartAction('remove', sku, 0);
        if (!srv || srv.status !== 'ok') { toast((srv && srv.message) ? srv.message : 'Cannot remove item', 'red'); return; }
        cart.splice(i,1);
        renderCart();
      }

      function voidCart() {
        if (!cart.length) return;
        showConfirm('Clear current basket?').then(async function(ok) {
          if (ok) {
            const srv = await serverCartAction('clear');
            if (!srv || srv.status !== 'ok') { toast((srv && srv.message) ? srv.message : 'Cannot clear cart', 'red'); return; }
            cart = [];
            renderCart();
          }
        });
      }

      function holdSale() {
        if (!cart.length) { toast('Nothing to hold', 'amber'); return; }
        // store cart plus currently selected customer so recall restores both
        const payload = { cart: cart, customer: getSelectedCustomerValue() };
        const held = JSON.stringify(payload);
        // save locally first
        sessionStorage.setItem('held_cart', held);
        // clear server cart so cashier can start new transaction
        (async function(){
          const srv = await serverCartAction('clear');
          if (!srv || srv.status !== 'ok') {
            // restore local held if server clear failed
            sessionStorage.removeItem('held_cart');
            toast((srv && srv.message) ? srv.message : 'Failed to hold sale', 'red');
            return;
          }
          cart = [];
          // reset selected customer to Walk-in for new transaction
          setCustomerSelection('', 'Walk-in Customer');
          renderCart();
          toast('Sale held');
        })();
      }

      function recallHeld() {
        const h = sessionStorage.getItem('held_cart');
        if (!h) { toast('No held sale', 'amber'); return; }
        let heldObj;
        try { heldObj = JSON.parse(h); } catch (e) { sessionStorage.removeItem('held_cart'); toast('No held sale', 'amber'); return; }
        const heldArr = Array.isArray(heldObj.cart) ? heldObj.cart : [];
        const heldCustomer = heldObj.customer || '';
        if (!Array.isArray(heldArr) || heldArr.length === 0) { sessionStorage.removeItem('held_cart'); toast('No held sale', 'amber'); return; }
        (async function(){
          // clear current server cart first
          const cleared = await serverCartAction('clear');
          if (!cleared || cleared.status !== 'ok') { toast((cleared && cleared.message) ? cleared.message : 'Cannot recall sale', 'red'); return; }
          // set each SKU on server
          for (const it of heldArr) {
            const sku = it.sku;
            const qty = parseInt(it.qty || 0, 10);
            if (!sku || qty <= 0) continue;
            const res = await serverCartAction('set', sku, qty);
            if (!res || res.status !== 'ok') { toast((res && res.message) ? res.message : 'Failed to restore held sale', 'red'); return; }
          }
          // restore selected customer
          if (heldCustomer !== '') {
            // find option label for this customer
            const opt = document.querySelector('#customer_list .combo-option[data-value="' + String(heldCustomer) + '"]');
            const label = opt ? opt.dataset.label : '';
            setCustomerSelection(heldCustomer, label || undefined);
          }
          // update local cart to heldArr (map to expected shape)
          cart = heldArr.map(it => ({ sku: it.sku, name: it.name || '', price: parseFloat(it.price || 0), qty: parseInt(it.qty || 0,10), stock: it.stock || 999 }));
          sessionStorage.removeItem('held_cart');
          renderCart();
          toast('Sale recalled');
        })();
      }

      function calcTotals() {
        const sub = cart.reduce((s, i) => s + i.price * i.qty, 0);
        const sel = document.getElementById('promo_select');
        const opt = sel.options[sel.selectedIndex];
        const promoId = sel.value || '0';
        const pval = parseFloat(opt.dataset.value || '0') || 0;
        const ptype = (opt.dataset.type || '').toLowerCase();
        const pname = opt.dataset.name || '';
        let disc;
        switch (ptype) {
          case 'percentage':
          case 'percent':
            disc = sub * (pval / 100);
            break;
          case 'fixed':
            disc = Math.min(pval, sub);
            break;
          default:
            disc = 0;
        }
        const taxable = sub - disc;
        const tax = taxable * TAX_RATE;
        const total = taxable + tax;

        // Loyalty points handling: cashier-chosen redemption (1 point = ₱1.00)
        const selectedCust = getSelectedCustomerValue();
        const pointsAvailable = parseInt(CUSTOMER_POINTS[selectedCust] || 0, 10) || 0;
        const requested = parseInt(document.getElementById('points_input') ? document.getElementById('points_input').value || '0' : '0', 10) || 0;
        const pointsToApply = Math.max(0, Math.min(requested, pointsAvailable, Math.floor(total)));
        const pointsApplied = pointsToApply;
        const finalTotal = Math.max(0, total - pointsApplied);

        const fmt = v => '&#8369;' + v.toLocaleString(undefined, {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        });
        document.getElementById('d_subtotal').innerHTML = fmt(sub);
        document.getElementById('d_discount').innerHTML = disc > 0 ? '-' + fmt(disc) : '&#8212;';
        document.getElementById('d_points').innerHTML = pointsApplied > 0 ? '-' + fmt(pointsApplied) : '&#8212;';
        document.getElementById('d_tax').innerHTML = fmt(tax);
        document.getElementById('d_total').innerHTML = fmt(finalTotal);
        document.getElementById('modal_total').innerHTML = fmt(finalTotal);

        document.getElementById('r_sub').innerHTML = fmt(sub);
        document.getElementById('r_disc').innerHTML = fmt(disc);
        // Promotion display on receipt: show promotion name as its own row; Discount row shows the deduction amount
        const rPromoRow = document.getElementById('r_promo_row');
        const rPromoName = document.getElementById('r_promo_name');
        const rDisc = document.getElementById('r_disc');
        if (promoId && promoId !== '0' && disc > 0) {
          if (rPromoRow) rPromoRow.style.display = '';
          if (rPromoName) rPromoName.textContent = pname || '';
          if (rDisc) rDisc.innerHTML = '-' + fmt(disc);
        } else {
          if (rPromoRow) rPromoRow.style.display = 'none';
          if (rPromoName) rPromoName.textContent = '';
          if (rDisc) rDisc.innerHTML = fmt(0);
        }
        document.getElementById('r_points').innerHTML = pointsApplied > 0 ? '-' + fmt(pointsApplied) : '&#8369;0.00';
        document.getElementById('r_tax').innerHTML = fmt(tax);
        document.getElementById('r_total').innerHTML = fmt(finalTotal);

        document.getElementById('f_total').value = finalTotal.toFixed(2);
        document.getElementById('f_tax').value = tax.toFixed(2);
        document.getElementById('f_disc').value = disc.toFixed(2);
        document.getElementById('f_points').value = pointsApplied.toFixed(2);
        document.getElementById('f_cart').value = JSON.stringify(cart);
        document.getElementById('f_customer').value = getSelectedCustomerValue();
        // set promotion hidden fields
        const fpid = document.getElementById('f_promotion_id');
        const fpname = document.getElementById('f_promotion_name');
        const fptype = document.getElementById('f_promotion_type');
        if (fpid) fpid.value = promoId;
        if (fpname) fpname.value = pname;
        if (fptype) fptype.value = ptype;
      }

      function openPayModal() {
        if (!cart.length) {
          toast('Basket is empty', 'amber');
          return;
        }
        // Validate selected bundle counts before opening payment modal
        try {
          if (SELECTED_BUNDLE && SELECTED_BUNDLE.name) {
            const getKey = (c) => {
              const s = (c||'').toLowerCase();
              if (s.indexOf('main') !== -1) return 'main';
              if (s.indexOf('fill') !== -1) return 'fillers';
              if (s.indexOf('green') !== -1) return 'greenery';
              return null;
            };
            const counts = { main:0, fillers:0, greenery:0 };
            cart.forEach(i => { const k = getKey(i.category); if (k) counts[k] = (counts[k]||0) + (i.qty||0); });
            const okMain = (counts.main === Number(SELECTED_BUNDLE.main || 0));
            const okFill = (counts.fillers === Number(SELECTED_BUNDLE.fillers || 0));
            const okGreen = (counts.greenery === Number(SELECTED_BUNDLE.greenery || 0));
            if (!okMain || !okFill || !okGreen) {
              toast(`Please select exactly ${SELECTED_BUNDLE.main} main, ${SELECTED_BUNDLE.fillers} filler${SELECTED_BUNDLE.fillers===1?'':'s'}, and ${SELECTED_BUNDLE.greenery} greenery before finalizing.`, 'amber');
              return;
            }
          }
        } catch(e) { console.error(e); }

        updateReceipt();
        document.getElementById('pay_overlay').classList.add('open');
      }

      function closePayModal() {
        document.getElementById('pay_overlay').classList.remove('open');
      }

      function printReceiptAuto() {
        const receiptEl = document.querySelector('#pay_overlay .receipt');
        if (!receiptEl) return;

        const footerHtml = `
          <hr style="border:none;border-top:1px solid #ddd;margin:10px 0;" />
          <div style="text-align:center; font-size:11px; color:#555; line-height:1.5;">Thank you for shopping at ${STORE_INFO.name}</div>
          <div style="text-align:center; font-size:11px; color:#555;">Please keep this receipt for returns and warranty claims.</div>
        `;

        const css = `
          body{font-family:'Courier New', monospace; padding:16px; color:#231f20; background:#fff; display:flex; justify-content:center; align-items:flex-start; min-height:100vh;}
          .receipt{max-width:360px;width:100%; margin:0 auto;}
          .receipt-title{font-weight:800;text-align:center;font-size:16px;margin-bottom:10px;}
          .receipt-row{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:6px 0;font-size:13px;}
          .receipt-row span:first-child{flex:1 1 auto;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
          .receipt-row span:last-child{flex:0 0 auto;text-align:right;min-width:6ch;}
          .receipt-sep{border:none;border-top:1px solid #ddd;margin:10px 0;}
          @media print { body{margin:0; padding:0; display:block;} .receipt{margin:0 auto;} }
        `;

        const w = window.open('', '_blank', 'width=600,height=800');
        if (!w) return;

        w.document.write(`<!doctype html><html><head><title>Receipt</title><style>${css}</style></head><body>` + receiptEl.outerHTML + footerHtml + `</body></html>`);
        w.document.close();
        w.focus();
        w.onafterprint = function() {
          w.close();
        };
        try {
          w.print();
        } catch (err) {
          console.error('Auto print error', err);
        }
      }

      const saleForm = document.getElementById('sale_form');
      if (saleForm) {
        const tenderedFieldRef = document.getElementById('amount_tendered');
        if (tenderedFieldRef) {
          tenderedFieldRef.addEventListener('input', function() {
            this.setCustomValidity('');
          });
        }
        saleForm.addEventListener('submit', function(e) {
          e.preventDefault();
          (async function() {
            const pm = document.querySelector('input[name="payment_method"]:checked');
            const tenderedField = document.getElementById('amount_tendered');
            const totalDue = parseFloat(document.getElementById('f_total').value) || 0;
            const tenderedVal = parseFloat((tenderedField.value || '0').replace(/,/g, '')) || 0;

            // Client-side validation
            if (pm && pm.value === 'Cash') {
              if (tenderedVal <= 0) {
                tenderedField.setCustomValidity('Please enter Amount Received');
                tenderedField.reportValidity();
                tenderedField.focus();
                return;
              }
              if (tenderedVal < totalDue) {
                const shortage = (totalDue - tenderedVal).toLocaleString(undefined, { minimumFractionDigits: 2 });
                tenderedField.setCustomValidity('Amount received must be at least ₱' + totalDue.toLocaleString(undefined, { minimumFractionDigits: 2 }) + ' (short by ₱' + shortage + ')');
                tenderedField.reportValidity();
                tenderedField.focus();
                return;
              }
              tenderedField.setCustomValidity('');
            } else if (pm && (pm.value === 'GCash' || pm.value === 'Maya')) {
              const contactEl = document.getElementById('wallet_contact');
              const accountEl = document.getElementById('wallet_account_name');
              const proofEl = document.getElementById('wallet_proof');
              const contact = (contactEl.value || '').trim();
              const account = (accountEl.value || '').trim();
              const proof = proofEl.files.length > 0;
              // Account/proof presence
              if (!account) { accountEl.setCustomValidity('Please enter Account Name'); accountEl.reportValidity(); accountEl.focus(); return; }
              if (!proof) { proofEl.setCustomValidity('Please upload Transaction Proof'); proofEl.reportValidity(); proofEl.focus(); return; }
              // Method-specific contact/reference validation
              if (pm.value === 'GCash') {
                if (!/^[0-9]{13}$/.test(contact)) { contactEl.setCustomValidity('Reference Number must be exactly 13 digits (numbers only)'); contactEl.reportValidity(); contactEl.focus(); return; }
              } else if (pm.value === 'Maya') {
                if (!/^[0-9]{12}$/.test(contact)) { contactEl.setCustomValidity('Reference ID must be exactly 12 digits (numbers only)'); contactEl.reportValidity(); contactEl.focus(); return; }
              }
            }

            // Prepare FormData and send via fetch so we can reliably print first
            try {
              const fd = new FormData(saleForm);
              // mark this request as AJAX so server could respond differently if needed
              fd.append('_ajax', '1');

              const resp = await fetch('?page=checkout', { method: 'POST', body: fd, credentials: 'same-origin' });
              if (!resp.ok) {
                toast('Failed to complete sale', 'red');
                return;
              }

              const contentType = resp.headers.get('Content-Type') || '';
              if (contentType.indexOf('application/json') !== -1) {
                const j = await resp.json().catch(() => null);
                if (j && j.status === 'ok' && j.redirect) {
                  updateReceipt();
                  printReceiptAuto();
                  window.location = j.redirect;
                  return;
                } else if (j && j.status === 'error') {
                  const err = j.message || 'Checkout failed';
                  const ed = document.getElementById('payment_error');
                  if (ed) { ed.innerHTML = err; ed.style.display = 'block'; }
                  toast(err, 'red');
                  return;
                }
              }

              // Fallback: Update receipt and follow redirect if available
              updateReceipt();
              printReceiptAuto();
              if (resp.redirected && resp.url) {
                window.location = resp.url;
              } else {
                window.location = '?page=checkout&success=1';
              }
            } catch (err) {
              console.error('Checkout request failed', err);
              toast('Error completing sale', 'red');
            }
          })();
        });
      }

      function updateReceipt() {
        // Build receipt items HTML, include selected showcase bundle details if present
        try {
          const rItems = document.getElementById('r_items');
          let html = '';
          if (SELECTED_BUNDLE && SELECTED_BUNDLE.name) {
            html += `<div class="receipt-row"><span style="font-weight:700">${SELECTED_BUNDLE.name}</span><span></span></div>`;
            html += `<div class="receipt-row"><span style="font-size:12px;color:#666;">Breakdown: ${SELECTED_BUNDLE.main} main, ${SELECTED_BUNDLE.fillers} filler${SELECTED_BUNDLE.fillers===1? '':'s'}, ${SELECTED_BUNDLE.greenery} greenery</span><span></span></div>`;
          }
          html += cart.map(i => `<div class="receipt-row"><span>${i.qty}&times; ${i.name}</span><span>&#8369;${(i.price*i.qty).toFixed(2)}</span></div>`).join('');
          if (rItems) rItems.innerHTML = html;
          // populate hidden bundle fields for server submission
          try {
            const fbn = document.getElementById('f_bundle_name'); if (fbn) fbn.value = SELECTED_BUNDLE.name || '';
            const fb1 = document.getElementById('f_bundle_main'); if (fb1) fb1.value = SELECTED_BUNDLE.main || 0;
            const fb2 = document.getElementById('f_bundle_fillers'); if (fb2) fb2.value = SELECTED_BUNDLE.fillers || 0;
            const fb3 = document.getElementById('f_bundle_greenery'); if (fb3) fb3.value = SELECTED_BUNDLE.greenery || 0;
          } catch(e) {}
        } catch(e) { console.error(e); }
        calcTotals();
        // Populate payment method and wallet preview fields
        try {
          const pm = document.querySelector('input[name="payment_method"]:checked');
          const paymentEl = document.getElementById('r_payment_method');
          const walletRow = document.getElementById('r_wallet_row');
          const walletLabel = document.getElementById('r_wallet_contact_label');
          const walletContactEl = document.getElementById('r_wallet_contact');
          if (paymentEl) paymentEl.textContent = pm ? pm.value : 'Cash';
          if (pm && (pm.value === 'GCash' || pm.value === 'Maya')) {
            if (walletRow) walletRow.style.display = 'flex';
            if (walletLabel) walletLabel.textContent = pm.value === 'GCash' ? 'Reference Number' : 'Reference ID';
            const src = document.getElementById('wallet_contact');
            if (walletContactEl) walletContactEl.textContent = src && src.value ? src.value.trim() : '—';
          } else {
            if (walletRow) walletRow.style.display = 'none';
          }
        } catch (e) { /* ignore */ }
      }

      function handlePaymentMethodChange(method) {
        const cashArea = document.getElementById('cash_area');
        const walletArea = document.getElementById('digital_wallet_area');
        const errorDiv = document.getElementById('payment_error');
        
        if (method === 'Cash') {
          cashArea.style.display = 'block';
          walletArea.style.display = 'none';
          // Clear wallet fields
          document.getElementById('wallet_contact').value = '';
          document.getElementById('wallet_account_name').value = '';
          document.getElementById('wallet_proof').value = '';
          // reset wallet label and placeholder
          const wlbl = document.getElementById('wallet_contact_label'); if (wlbl) wlbl.textContent = 'Contact Number';
          const winput = document.getElementById('wallet_contact'); if (winput) winput.placeholder = 'e.g., 09123456789';
          // clear any visible payment error and custom validity on wallet inputs
          errorDiv.style.display = 'none';
          try { document.getElementById('wallet_contact').setCustomValidity(''); } catch(e){}
          try { document.getElementById('wallet_account_name').setCustomValidity(''); } catch(e){}
          try { document.getElementById('wallet_proof').setCustomValidity(''); } catch(e){}
        } else if (method === 'GCash' || method === 'Maya') {
          cashArea.style.display = 'none';
          walletArea.style.display = 'block';
          // Clear cash field
          document.getElementById('amount_tendered').value = '';
          // set wallet label/placeholder for selected method
          const wlbl = document.getElementById('wallet_contact_label');
          const winput = document.getElementById('wallet_contact');
            if (wlbl) wlbl.textContent = method === 'GCash' ? 'Reference Number' : 'Reference ID';
            if (winput) {
              winput.placeholder = method === 'GCash' ? '13-digit reference number (numbers only)' : '12-digit reference ID (numbers only)';
              winput.maxLength = method === 'GCash' ? 13 : 12;
              // ensure any non-digit characters are removed and length capped
              winput.value = (winput.value || '').replace(/\D/g, '').slice(0, winput.maxLength);
            }
          errorDiv.style.display = 'none';
        }
        
        validatePaymentForm();
      }
      
      function validatePaymentForm() {
        const pm = document.querySelector('input[name="payment_method"]:checked');
        let submitBtn = document.getElementById('submit_sale_btn');
        const errorDiv = document.getElementById('payment_error');
        if (!submitBtn) submitBtn = document.querySelector('button[type="submit"]#submit_sale_btn');
        // default to enabled; we'll disable if validation fails
        if (submitBtn) submitBtn.disabled = false;
        
        console.debug('validatePaymentForm:', { pm: pm ? pm.value : null });
        if (pm && (pm.value === 'GCash' || pm.value === 'Maya')) {
          // Validate digital wallet fields
          const contactEl = document.getElementById('wallet_contact');
          const accountEl = document.getElementById('wallet_account_name');
          const proofEl = document.getElementById('wallet_proof');
          const contact = (contactEl && (contactEl.value || '').trim()) || '';
          const account = (accountEl && (accountEl.value || '').trim()) || '';
          const proof = proofEl && proofEl.files && proofEl.files.length > 0;

          // set required attributes appropriately
          if (contactEl) contactEl.required = true;
          if (accountEl) accountEl.required = true;
          if (proofEl) proofEl.required = true;
          const amtEl = document.getElementById('amount_tendered');
          if (amtEl) amtEl.required = false;
          
          if (!contact || !account || !proof) {
            console.debug('wallet validation failed', { contact, account, proof });
            const label = pm.value === 'GCash' ? 'Reference Number' : 'Reference ID';
            if (!contact) {
              errorDiv.innerHTML = '⚠ Please enter ' + label;
              errorDiv.style.display = 'block';
              return;
            }
            if (!account) {
              errorDiv.innerHTML = '⚠ Please enter Account Name';
              errorDiv.style.display = 'block';
              return;
            }
            if (!proof) {
              errorDiv.innerHTML = '⚠ Please upload Transaction Proof';
              errorDiv.style.display = 'block';
              return;
            }
          } else {
            // Additional format checks
            if (pm.value === 'GCash' && !/^[0-9]{13}$/.test(contact)) {
              errorDiv.innerHTML = '⚠ Reference Number must be exactly 13 digits (numbers only)';
              errorDiv.style.display = 'block';
              return;
            }
            if (pm.value === 'Maya' && !/^[0-9]{12}$/.test(contact)) {
              errorDiv.innerHTML = '⚠ Reference ID must be exactly 12 digits (numbers only)';
              errorDiv.style.display = 'block';
              return;
            }
            console.debug('wallet validation passed');
            errorDiv.style.display = 'none';
            // clear custom validity
            if (contactEl) { contactEl.setCustomValidity(''); }
            if (accountEl) { accountEl.setCustomValidity(''); }
            if (proofEl) { proofEl.setCustomValidity(''); }
          }
        } else if (pm && pm.value === 'Cash') {
          // Validate cash field
          const tenderedEl = document.getElementById('amount_tendered');
          if (tenderedEl) tenderedEl.required = true;
          const tendered = parseFloat((document.getElementById('amount_tendered').value || '0').replace(/,/g, '')) || 0;
          const total = parseFloat(document.getElementById('f_total').value) || 0;

          if (tendered > 0 && tendered >= total) {
            errorDiv.style.display = 'none';
            console.debug('cash validation passed', { tendered, total });
          } else if (tendered > 0 && tendered < total) {
            console.debug('cash insufficient', { tendered, total });
          } else {
            console.debug('cash no tendered yet');
          }
        }
        if (submitBtn) {
          submitBtn.disabled = false;
          console.debug('submitBtn.disabled forced=false');
        }
      }

      function fmtCash(el) {
        let raw = el.value.replace(/[^0-9]/g, '');
        if (!raw) {
          el.value = '';
          return;
        }
        el.value = (parseInt(raw, 10) / 100).toLocaleString('en-US', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        });
      }

      function calcChange() {
        const total = parseFloat(document.getElementById('f_total').value) || 0;
        const tendered = parseFloat((document.getElementById('amount_tendered').value || '0').replace(/,/g, '')) || 0;
        const change = tendered - total;
        const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
        const isCash = paymentMethod && paymentMethod.value === 'Cash';
        
        // Update change display
        document.getElementById('r_change').innerHTML = change >= 0 ? '&#8369;' + change.toLocaleString(undefined, {
          minimumFractionDigits: 2
        }) : '&#8212;';
        
        // Validate and show/hide error for cash payments
        const errorDiv = document.getElementById('payment_error');
        const submitBtn = document.getElementById('submit_sale_btn');
        
        if (isCash) {
          if (tendered > 0 && tendered < total) {
            const shortage = (total - tendered).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            errorDiv.innerHTML = '⚠ Insufficient amount. Short by &#8369;' + shortage + '.';
            errorDiv.style.display = 'block';
            if (submitBtn) submitBtn.disabled = true;
          } else if (tendered > 0 && tendered >= total) {
            errorDiv.style.display = 'none';
            if (submitBtn) submitBtn.disabled = false;
          } else {
            errorDiv.style.display = 'none';
            if (submitBtn) submitBtn.disabled = false;
          }
        } else {
          errorDiv.style.display = 'none';
          if (submitBtn) submitBtn.disabled = false;
        }
      }
      
      // Add event listeners for digital wallet fields
      document.addEventListener('DOMContentLoaded', function() {
        const walletContact = document.getElementById('wallet_contact');
        const walletAccount = document.getElementById('wallet_account_name');
        const walletProof = document.getElementById('wallet_proof');
        const amountTendered = document.getElementById('amount_tendered');
        const pmChecked = document.querySelector('input[name="payment_method"]:checked');
        
        if (walletContact) walletContact.addEventListener('input', validatePaymentForm);
        if (walletAccount) walletAccount.addEventListener('input', validatePaymentForm);
        if (walletProof) walletProof.addEventListener('change', validatePaymentForm);
        if (amountTendered) amountTendered.addEventListener('input', function() { fmtCash(this); calcChange(); validatePaymentForm(); });

        // Initialize payment form state on load
        validatePaymentForm();
        if (pmChecked) handlePaymentMethodChange(pmChecked.value);

        // Debug: log submit button clicks and element at its center
        const submitBtn = document.getElementById('submit_sale_btn');
        if (submitBtn) {
          submitBtn.addEventListener('click', function(evt) {
            console.debug('submit button clicked - disabled=', this.disabled);
            try {
              const r = this.getBoundingClientRect();
              const cx = Math.round(r.left + r.width/2);
              const cy = Math.round(r.top + r.height/2);
              const el = document.elementFromPoint(cx, cy);
              console.debug('elementFromPoint at button center:', el, 'coords:', cx, cy);
            } catch (err) { console.error('elemFromPoint error', err); }
          });
        }

        // Debug: capture clicks on overlays to see if they intercept clicks
        document.querySelectorAll('.overlay').forEach(o => {
          o.addEventListener('click', function(e) {
            console.debug('overlay clicked target:', e.target);
          }, true);
        });
      });

      document.getElementById('sku_scanner').addEventListener('input', function() {
        const q = this.value.toLowerCase();
        document.querySelectorAll('.prod-tile').forEach(el => {
          el.style.display = el.dataset.name.includes(q) ? '' : 'none'; //includes for partial match; can switch to startsWith for stricter search
        });
      });
      document.getElementById('sku_scanner').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          const sku = this.value.trim().toUpperCase();
          const found = allProducts.find(p => p.sku.toUpperCase() === sku);
          if (found) {
            addToCart(found);
            this.value = '';
          } else toast('SKU not found', 'red');
        }
      });
      document.getElementById('cat-filter').addEventListener('click', function(e) {
        const pill = e.target.closest('.cat-pill');
        if (!pill) return;
        document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
        pill.classList.add('active');
        const cat = pill.dataset.cat;
        document.querySelectorAll('.prod-tile').forEach(el => {
          el.style.display = (!cat || (el.dataset.cat || '').toLowerCase() === cat.toLowerCase()) ? '' : 'none';
        });
      });

      function toast(msg, type = 'green') {
        let t = document.getElementById('_toast');
        if (!t) {
          t = document.createElement('div');
          t.id = '_toast';
          t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);padding:10px 22px;border-radius:99px;font-size:13px;font-weight:600;z-index:9999;transition:opacity .3s;box-shadow:0 4px 12px rgba(56,46,40,.2);';
          document.body.appendChild(t);
        }
        t.textContent = msg;
        switch (type) {
          case 'red':
            t.style.background = '#A83232';
            break;
          case 'amber':
            t.style.background = '#B07D30';
            break;
          default:
            t.style.background = '#382E28';
        }
        t.style.color = '#fff';
        t.style.opacity = '1';
        clearTimeout(t._t);
        t._t = setTimeout(() => t.style.opacity = '0', 2200);
      }
      // Print receipt preview when Pay modal is open and user presses Ctrl+P
      document.addEventListener('keydown', function(e) {
        try {
          if (e.ctrlKey && (e.key === 'p' || e.key === 'P')) {
            const overlay = document.getElementById('pay_overlay');
            if (overlay && overlay.classList.contains('open')) {
              e.preventDefault();
              const receiptEl = overlay.querySelector('.receipt');
              if (!receiptEl) return;

              const footerHtml = `
                <hr style="border:none;border-top:1px solid #ddd;margin:10px 0;" />
                <div style="text-align:center; font-size:11px; color:#555; line-height:1.5;">Thank you for shopping at ${STORE_INFO.name}</div>
                <div style="text-align:center; font-size:11px; color:#555;">Please keep this receipt for returns and warranty claims.</div>
              `;

              const css = `
                body{font-family:'Courier New', monospace; padding:16px; color:#231f20; background:#fff; display:flex; justify-content:center; align-items:flex-start; min-height:100vh;}
                .receipt{max-width:360px;width:100%; margin:0 auto;}
                .receipt-title{font-weight:800;text-align:center;font-size:16px;margin-bottom:10px;}
                .receipt-row{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:6px 0;font-size:13px;}
                .receipt-row span:first-child{flex:1 1 auto;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
                .receipt-row span:last-child{flex:0 0 auto;text-align:right;min-width:6ch;}
                .receipt-sep{border:none;border-top:1px solid #ddd;margin:10px 0;}
                @media print { body{margin:0; padding:0; display:block;} .receipt{margin:0 auto;} }
              `;

              const w = window.open('', '_blank', 'width=600,height=800');
              w.document.write(`<!doctype html><html><head><title>Receipt</title><style>${css}</style></head><body>` + receiptEl.outerHTML + footerHtml + `</body></html>`);
              w.document.close();
              w.focus();
              w.onafterprint = function() {
                w.close();
              };
              setTimeout(function() {
                w.print();
              }, 250);
              setTimeout(function() {
                if (!w.closed) w.close();
              }, 8000);
            }
          }
        } catch (err) {
          console.error('Print preview error', err);
        }
      });
    </script>

  <?php else:
    // ── UPDATED: pull photo from session ──
    $initials   = isset($_SESSION['user_name']) ? makeInitials($_SESSION['user_name']) : '';
    $user_photo = isset($_SESSION['user_photo']) ? $_SESSION['user_photo'] : '';
    $is_admin   = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'Admin');
  ?>
    <div class="app">
      <div class="overlay" id="confirmDialogModal">
        <div class="modal-box" style="max-width:420px;">
          <div class="modal-header">
            <span class="modal-title" id="confirmDialogTitle">Confirm action</span>
            <button class="modal-close" type="button" onclick="closeDialogModal()">&times;</button>
          </div>
          <div id="confirmDialogMessage" class="dialog-message">Are you sure?</div>
          <input type="text" id="confirmDialogInput" class="dialog-input" style="display:none;" autocomplete="off" />
          <div class="dialog-actions">
            <button type="button" class="btn btn-secondary" id="confirmDialogCancel">Cancel</button>
            <button type="button" class="btn btn-primary" id="confirmDialogConfirm">OK</button>
          </div>
        </div>
      </div>
      <nav class="sidebar">
        <div class="sb-brand">
          <img src="logo.svg" alt="Bloom POS logo" class="sb-brand-logo">
          <div class="sb-brand-text">
            <div class="sb-brand-name">Bloom POS</div>
            <div class="sb-brand-sub">Flower Shop System</div>
          </div>
        </div>

        <div style="padding:12px 0 4px;">
          <div class="sb-section">Operations</div>

          <a href="?page=dashboard" class="sb-link <?= $page === 'dashboard' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="3" width="7" height="7" />
              <rect x="14" y="3" width="7" height="7" />
              <rect x="14" y="14" width="7" height="7" />
              <rect x="3" y="14" width="7" height="7" />
            </svg>
            Dashboard
          </a>

          <a href="?page=checkout" class="sb-link <?= $page === 'checkout' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="9" cy="21" r="1" />
              <circle cx="20" cy="21" r="1" />
              <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
            </svg>
            Checkout
          </a>

          <a href="?page=showcase" class="sb-link <?= $page === 'showcase' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M3 8h18" />
              <path d="M3 8l2-3h14l2 3" />
              <path d="M3 8v10h18V8" />
              <path d="M3 12h18" />
              <path d="M7 18h3" />
              <path d="M14 18h3" />
              <path d="M11 18v-5h2v5" />
            </svg>
            Flower Showcase
          </a>

          <?php if ($is_admin): ?>
            <a href="?page=inventory" class="sb-link <?= $page === 'inventory' ? 'active' : '' ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
              </svg>
              Inventory
            </a>
          <?php endif; ?>

          <div class="sb-section" style="margin-top:8px;">Management</div>

          <a href="?page=crm" class="sb-link <?= $page === 'crm' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
              <circle cx="9" cy="7" r="4" />
              <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
              <path d="M16 3.13a4 4 0 0 1 0 7.75" />
            </svg>
            Customers
          </a>

          <?php if ($is_admin): ?>
            <a href="?page=employees" class="sb-link <?= $page === 'employees' ? 'active' : '' ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                <circle cx="12" cy="7" r="4" />
              </svg>
              Employees
            </a>

            <a href="?page=reports" class="sb-link <?= $page === 'reports' ? 'active' : '' ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="20" x2="18" y2="10" />
                <line x1="12" y1="20" x2="12" y2="4" />
                <line x1="6" y1="20" x2="6" y2="14" />
              </svg>
              Reports
            </a>
          <?php endif; ?>
        </div>

        <!-- ══ UPDATED SIDEBAR FOOTER: profile photo + name ══ -->
        <div class="sb-footer">
          <div class="sb-user">
            <div class="sb-avatar" onclick="document.getElementById('myProfileModal').classList.add('open')" style="cursor:pointer;" title="Edit my profile">
              <?php if (!empty($user_photo)): ?>
                <img src="<?= htmlspecialchars($user_photo, ENT_QUOTES, 'UTF-8') ?>"
                  alt="<?= htmlspecialchars(isset($_SESSION['user_name']) ? $_SESSION['user_name'] : '', ENT_QUOTES, 'UTF-8') ?>">
              <?php else: ?>
                <?= $initials ?>
              <?php endif; ?>
            </div>
            <div class="sb-user-info">
              <div class="sb-user-name"><?= htmlspecialchars(isset($_SESSION['user_name']) ? $_SESSION['user_name'] : '', ENT_QUOTES, 'UTF-8') ?></div>
              <div class="sb-user-role"><?= htmlspecialchars(isset($_SESSION['user_role']) ? $_SESSION['user_role'] : '', ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars(isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
          </div>
          <a href="?page=logout" class="sb-logout">Sign Out</a>
        </div>
        <!-- ══ MY PROFILE MODAL ══ -->
        <div class="overlay" id="myProfileModal">
          <div class="modal-box profile">
            <div class="modal-header">
              <span class="modal-title">My Profile</span>
              <button class="modal-close" onclick="document.getElementById('myProfileModal').classList.remove('open')">&times;</button>
            </div>

            <div style="display:flex; align-items:center; gap:16px; margin-bottom:22px; padding:16px; background:var(--oatmeal); border:1px solid var(--taupe-l); border-radius:var(--radius-lg);">
              <div style="width:64px; height:64px; border-radius:50%; background:linear-gradient(135deg, var(--chestnut-l), var(--chestnut-d)); color:#fff; font-weight:700; font-size:20px; display:flex; align-items:center; justify-content:center; overflow:hidden; box-shadow:0 2px 8px rgba(56,46,40,.3); flex-shrink:0;">
                <?php if (!empty($user_photo)): ?>
                  <img src="<?= htmlspecialchars($user_photo, ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                <?php else: ?>
                  <?= $initials ?>
                <?php endif; ?>
              </div>
              <div style="min-width:0;">
                <div style="font-size:16px; font-weight:700; color:var(--espresso);"><?= htmlspecialchars(isset($_SESSION['user_name']) ? $_SESSION['user_name'] : '', ENT_QUOTES, 'UTF-8') ?></div>
                <div style="font-size:12px; color:var(--text-3); margin-top:3px;">
                  <?= htmlspecialchars(isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '', ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars(isset($_SESSION['user_role']) ? $_SESSION['user_role'] : '', ENT_QUOTES, 'UTF-8') ?>
                </div>
              </div>
            </div>

            <form method="POST" action="?page=<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data">
              <input type="hidden" name="return_page" value="<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>">

              <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" value="<?= htmlspecialchars(isset($_SESSION['user_name']) ? $_SESSION['user_name'] : '', ENT_QUOTES, 'UTF-8') ?>" required pattern="[A-Za-zÑñ ]+" title="Letters and spaces only" oninput="this.value = this.value.replace(/[^A-Za-zÑñ\s]/g,'')">
              </div>

              <div class="form-group">
                <label>Profile Photo</label>
                <input type="file" name="profile_photo" accept="image/*" style="padding:6px;">
              </div>

              <div class="form-group">
                <label>New Passcode <span style="color:var(--text-3); font-weight:400; text-transform:none;">(leave blank to keep current)</span></label>
                <input type="password" name="new_passcode" placeholder="Enter new passcode" autocomplete="new-password">
              </div>

              <button type="submit" name="update_my_profile" class="btn btn-primary btn-full" style="margin-bottom:24px;">Save Changes</button>
            </form>

            <div style="border-top:1px solid var(--taupe-l); padding-top:20px;">
              <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
                <div style="font-size:14px; font-weight:700; color:var(--espresso);">Login &amp; Logout Record</div>
                <span class="badge badge-gray">Session History</span>
              </div>
              <div class="tbl-wrap" style="border:1px solid var(--taupe-l); border-radius:var(--radius); overflow:hidden;">
                <table class="profile-table" style="width:100%; table-layout:auto;">
                  <thead>
                    <tr>
                      <th style="min-width:140px;">Date</th>
                      <th style="min-width:130px;">Login Time</th>
                      <th style="min-width:130px;">Logout Time</th>
                      <th style="min-width:110px;">Duration</th>
                    </tr>
                  </thead>

                  <tbody>
                    <?php if (!empty($sessionHistoryRows)): ?>
                      <?php foreach ($sessionHistoryRows as $sessionRow): ?>
                        <?php
                          $loginDate = date('M d, Y', strtotime($sessionRow['login_date']));
                          $loginTime = date('g:i A', strtotime($sessionRow['login_time']));
                          $isActive = $sessionRow['logout_time'] === null;
                          $logoutTime = $isActive ? '<span class="text-success" style="font-weight:600;">Active</span>' : date('g:i A', strtotime($sessionRow['logout_time']));
                          $durationText = htmlspecialchars($sessionRow['duration'] ?: '00:00:00', ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr class="<?= $isActive ? 'active-session-row' : '' ?>">
                          <td><?= htmlspecialchars($loginDate, ENT_QUOTES, 'UTF-8') ?></td>
                          <td><?= htmlspecialchars($loginTime, ENT_QUOTES, 'UTF-8') ?></td>
                          <td><?= $logoutTime ?></td>
                          <td class="session-duration-cell" data-active="<?= $isActive ? '1' : '0' ?>" data-login="<?= htmlspecialchars($sessionRow['login_time'], ENT_QUOTES, 'UTF-8') ?>" data-prior-duration="<?= isset($sessionRow['duration_seconds']) ? (int)$sessionRow['duration_seconds'] : 0 ?>">
                            <?php if ($isActive): ?>
                              <span class="live-session-timer"><?= $durationText ?></span>
                            <?php else: ?>
                              <?= $durationText ?>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="4" style="text-align:center; padding:24px; color:var(--text-3); font-style:italic;">No login records to display yet.</td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <script>
          (function() {
            var _mpm = document.getElementById('myProfileModal');
            if (_mpm) {
              _mpm.addEventListener('click', function(e) {
                if (e.target === _mpm) _mpm.classList.remove('open');
              });
            }
          })();
        </script>
        <?php if ($activeSession): ?>
          <script>
            (function() {
              function pad(value) {
                return String(value).padStart(2, '0');
              }

              function updateActiveTimer(cell) {
                if (!cell) return;
                var loginTime = cell.dataset.login;
                var priorSeconds = parseInt(cell.dataset.priorDuration || '0', 10);
                if (!loginTime) return;

                var start = new Date(loginTime.replace(' ', 'T'));
                if (isNaN(start.getTime())) return;

                var elapsed = Math.floor((Date.now() - start.getTime()) / 1000);
                if (elapsed < 0) elapsed = 0;
                var total = priorSeconds + elapsed;
                var hours = pad(Math.floor(total / 3600));
                var mins = pad(Math.floor((total % 3600) / 60));
                var secs = pad(total % 60);
                cell.querySelector('.live-session-timer').textContent = hours + ':' + mins + ':' + secs;
              }

              var durationCell = document.querySelector('.session-duration-cell[data-active="1"]');
              if (!durationCell) return;
              updateActiveTimer(durationCell);
              setInterval(function() {
                updateActiveTimer(durationCell);
              }, 1000);
            })();
          </script>
        <?php endif; ?>
      </nav>

      <main class="main">
        <?php

        // ── DASHBOARD ────────────────────────────────────────────────
        if ($page === 'dashboard'): ?>
          <div class="page">
            <div class="page-header">
              <div>
                <div class="page-title">Dashboard</div>
                <div class="page-sub"><?php echo date('l, d F Y'); ?> &middot; <?php echo isset($_SESSION['user_role']) ? htmlspecialchars($_SESSION['user_role'], ENT_QUOTES, 'UTF-8') : ''; ?> view</div>
              </div>
            </div>

            <div class="stat-grid">
              <div class="stat-card">
                <div class="stat-label">Today's Revenue</div>
                <div class="stat-value" style="color:var(--green);">&#8369;<?php echo number_format($daily_rev, 2); ?></div>
                <div class="stat-hint"><?php echo $daily_trx; ?> transaction<?php echo $daily_trx != 1 ? 's' : ''; ?> today</div>
              </div>
              <!-- Session Started card removed -->

              <div class="stat-card">
                <div class="stat-label">Total Products</div>
                <div class="stat-value"><?php echo $total_items; ?></div>
                <div class="stat-hint">Items in inventory</div>
              </div>
              <?php if ($is_admin): ?>
                <div class="stat-card">
                  <div class="stat-label">Low Stock</div>
                  <div class="stat-value" style="color:<?php echo $low_stock > 0 ? 'var(--red)' : 'var(--green)'; ?>;"><?php echo $low_stock; ?></div>
                  <div class="stat-hint">Items below 10 units</div>
                </div>
              <?php endif; ?>
              <div class="stat-card">
                <div class="stat-label">Customers</div>
                <div class="stat-value"><?php echo $total_customers; ?></div>
                <div class="stat-hint">Registered in CRM</div>
              </div>
            </div>

            <?php if ($is_admin && $low_stock > 0):
              $ls_res  = $conn->query("SELECT * FROM inventory WHERE stock_qty < 10 ORDER BY stock_qty ASC");
              $ls_rows = $ls_res ? $ls_res->fetch_all(MYSQLI_ASSOC) : []; ?>
              <div class="card">
                <div style="font-size:13px; font-weight:700; color:var(--red); margin-bottom:14px;">Low Stock Alerts</div>
                <div class="tbl-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Stock</th>
                        <th>Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($ls_rows as $r): ?>
                        <tr>
                          <td style="font-weight:600;"><?= htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8') ?></td>
                          <td><span style="font-family:monospace; font-size:12px; color:var(--text-3);"><?= $r['sku'] ?></span></td>
                          <td><span class="badge badge-red"><?= $r['stock_qty'] ?> left</span></td>
                          <td><a href="?page=inventory&tab=items&open_sku=<?= htmlspecialchars(urlencode($r['sku']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-secondary">Restock</a></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            <?php endif; ?>
          </div>

        <?php
        // ── INVENTORY ────────────────────────────────────────────────
        elseif ($page === 'inventory'): ?>
          <div class="page">
            <div class="page-header">
              <div>
                <div class="page-title">Inventory</div>
                <div class="page-sub"><?= $total_items ?> products across <?= count($cats) ?> categories</div>
              </div>
              <?php if ($activeTab === 'items'): ?>
                <button onclick="openAddModal()" class="btn btn-primary">+ Add Product</button>
              <?php endif; ?>
            </div>
            <?php if (isset($_SESSION['inventory_error'])): ?>
              <div class="alert alert-danger" style="margin:0 0 16px;">
                <?= htmlspecialchars($_SESSION['inventory_error'], ENT_QUOTES, 'UTF-8') ?>
              </div>
              <?php unset($_SESSION['inventory_error']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['inventory_warning'])): ?>
              <div class="alert alert-warning" style="margin:0 0 16px;">
                <?= htmlspecialchars($_SESSION['inventory_warning'], ENT_QUOTES, 'UTF-8') ?>
              </div>
              <?php unset($_SESSION['inventory_warning']); ?>
            <?php endif; ?>

            <div class="tabs">
              <a href="?page=inventory&tab=items" class="tab-link <?= $activeTab === 'items' ? 'active' : '' ?>">Products</a>
              <a href="?page=inventory&tab=discounts" class="tab-link <?= $activeTab === 'discounts' ? 'active' : '' ?>">Discounts</a>
            </div>

            <!-- Products -->
            <div class="tab-pane <?= $activeTab === 'items' ? 'active' : '' ?>">
              <div class="cat-filter" id="inv-cat-filter">
  <span class="cat-pill active" data-cat="">All</span>
  <span class="cat-pill" data-cat="Main">Main</span>
  <span class="cat-pill" data-cat="Filler">Filler</span>
  <span class="cat-pill" data-cat="Greenery">Greenery</span>
</div>

              <?php if (empty($inventory)): ?>
                <div class="card" style="text-align:center; padding:40px; color:var(--text-3);">No products yet. Click <strong>Add Product</strong> to get started.</div>
              <?php else: ?>
                <div class="inv-grid">
                  <?php foreach ($inventory as $item):
                    $ep = effectivePrice($item);
                    $hasDisc = $ep < $item['price'];
                    $taxRate = isset($store_info['tax_rate']) ? floatval($store_info['tax_rate']) : 0.12;
                    $ep_with_vat = $ep * (1 + $taxRate);
                    $base_with_vat = floatval($item['price']) * (1 + $taxRate);
                  ?>
                  <div class="inv-card" data-cat="<?= htmlspecialchars($item['category_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" onclick="openEditModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8') ?>)">
                      <form data-confirm="Delete this product?" method="POST" action="?page=inventory&tab=items" style="position:absolute; top:8px; right:8px; z-index:2;">
                        <input type="hidden" name="delete_sku" value="<?= $item['sku'] ?>">
                        <button type="submit" class="inv-del-btn" onclick="event.stopPropagation();" title="Delete">&times;</button>
                      </form>
                      <div class="inv-card-img">
                        <?php if (!empty($item['image_url'])): ?><img src="/Bloom_POS/<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?>
                          <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#D4BCA9" stroke-width="1.5">
                            <rect x="3" y="3" width="18" height="18" rx="2" />
                            <circle cx="8.5" cy="8.5" r="1.5" />
                            <polyline points="21 15 16 10 5 21" />
                          </svg>
                        <?php endif; ?>
                        <?php if (!empty($item['disc_status']) && $item['disc_status'] == 1 && !empty($item['discount_value'])):
                          $dlabel = in_array(strtolower($item['discount_type'] ?? ''), ['percentage', 'percent']) ? (floatval($item['discount_value']) . '% OFF') : ('₱' . number_format($item['discount_value'], 2) . ' OFF');
                        ?>
                          <span class="badge badge-blue" style="font-size:10px; position:absolute; top:8px; right:8px;"><?= htmlspecialchars($dlabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                      </div>
                      <div class="inv-card-body">
                        <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:4px;">
                          <span class="badge badge-gray" style="font-size:10px;"><?= htmlspecialchars($item['category_name'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8') ?></span>
                          <?php if ($item['stock_qty'] < 10): ?><span class="badge badge-red" style="font-size:10px;"><?= $item['stock_qty'] ?> left</span><?php endif; ?>
                        </div>
                        
                        <div class="inv-card-name"><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="inv-card-sku"><?= $item['sku'] ?></div>
                        <div class="inv-card-price">
                          &#8369;<?= number_format($ep_with_vat, 2) ?>
                          <?php if ($hasDisc): ?><span style="font-size:10px; text-decoration:line-through; color:var(--text-3); margin-left:4px;">&#8369;<?= number_format($base_with_vat, 2) ?></span><?php endif; ?>
                        </div>
                        <div class="inv-card-stock">Stock: <?= $item['stock_qty'] ?></div>
                        <div class="inv-actions" style="margin-top: 10px; width: 100%;">
                          <?php if (!preg_match('/-V\\d+$/i', $item['sku'])): ?>
                            <button type="button" class="btn-variant" style="width: 100%;" onclick="event.stopPropagation(); openVariantModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8') ?>)">
                              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                              Add Variant
                            </button>
                          <?php else: ?>
                            <button type="button" class="btn-variant" style="width:100%; opacity:0.6; cursor:not-allowed;" disabled title="Cannot add a variant to another variant">Add Variant</button>
                          <?php endif; ?>
                        </div>

                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Categories -->
            <div class="tab-pane <?= $activeTab === 'categories' ? 'active' : '' ?>">
              <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
                <div style="font-size:14px; color:var(--text-2);"><?= count($cats) ?> categories</div>
                <button onclick="document.getElementById('addCatModal').classList.add('open')" class="btn btn-primary">+ New Category</button>
              </div>
              <?php if (empty($cats)): ?>
                <div class="card" style="text-align:center; padding:40px; color:var(--text-3);">No categories yet.</div>
                <?php else:
                foreach ($cats as $cat):
                  $cid        = $cat['category_id'];
                  $citems     = $conn->query("SELECT * FROM inventory WHERE category_id=$cid");
                  $citemsRows = $citems ? $citems->fetch_all(MYSQLI_ASSOC) : [];
                ?>
                  <div class="cat-card">
                    <div class="cat-card-header">
                      <span style="font-size:15px; font-weight:700; color:var(--espresso);"><?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?> <span class="badge badge-gray"><?= count($citemsRows) ?></span></span>
                      <div style="display:flex; gap:8px;">
                        <button onclick='openEditCatModal(<?= $cid ?>, <?= json_encode($cat["category_name"]) ?>)' class="btn btn-sm btn-secondary">Edit</button>
                        <button onclick="document.getElementById('assign_cat_id').value=<?= $cid ?>; document.getElementById('assignModal').classList.add('open');" class="btn btn-sm btn-secondary">Assign Items</button>
                      </div>
                    </div>
                    <?php if (!empty($citemsRows)): ?>
                      <div class="cat-items-grid">
                        <?php foreach ($citemsRows as $ci): ?>
                          <div class="cat-item-pill">
                            <div>
                              <div style="font-size:13px; font-weight:600; color:var(--espresso);"><?= htmlspecialchars($ci['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
                              <div style="font-size:10px; color:var(--text-3); font-family:monospace;"><?= $ci['sku'] ?></div>
                            </div>
                            <form data-confirm="Remove from category?" method="POST" action="?page=inventory&tab=categories" style="margin:0;">
                              <input type="hidden" name="unlink_sku" value="<?= $ci['sku'] ?>">
                              <button type="submit" class="btn btn-sm btn-ghost" style="color:var(--red);">&times;</button>
                            </form>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php else: ?>
                      <p style="font-size:13px; color:var(--text-3); font-style:italic;">No products assigned yet.</p>
                    <?php endif; ?>
                  </div>
              <?php endforeach;
              endif; ?>
            </div>

            <!-- Discounts -->
            <div class="tab-pane <?= $activeTab === 'discounts' ? 'active' : '' ?>">
              <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; align-items:start;">
                <div class="card">
                  <div style="font-size:15px; font-weight:700; color:var(--espresso); margin-bottom:18px;">Create Promotion</div>
                  <form method="POST" action="?page=inventory&tab=discounts">
                    <div class="form-group"><label>Promotion Name</label><input type="text" name="d_name" placeholder="e.g. 10% Off Mother's Day Sale" required pattern="[A-Za-z0-9Ññ %'.,-]+" title="Letters, numbers, spaces and common symbols are allowed" oninput="this.value = this.value.replace(/[^A-Za-z0-9Ññ %'.,-]/g,'')"></div>
                    <div class="form-row">
                      <div class="form-group"><label>Type</label>
                        <select name="d_type" id="disc_type_sel">
                          <option value="percent">Percentage (%)</option>
                          <option value="fixed">Fixed Amount (&#8369;)</option>
                        </select>
                      </div>
                      <div class="form-group"><label>Value</label>
                        <input type="text" name="d_value" id="disc_val" placeholder="0.00" onblur="fmtDisc(this)" required>
                      </div>
                    </div>
                    <div class="form-row">
                      <div class="form-group"><label>Status</label>
                        <select name="d_status">
                          <option value="1">Active</option>
                          <option value="0">Draft</option>
                        </select>
                      </div>
                      <div class="form-group"><label>Expires On</label><input type="date" name="d_expiry"></div>
                    </div>
                    <button type="submit" name="add_discount" class="btn btn-primary btn-full">Save Promotion</button>
                  </form>
                </div>
                <div>
                  <div style="font-size:15px; font-weight:700; color:var(--espresso); margin-bottom:14px;">Existing Promotions</div>
                  <?php if (empty($discounts)): ?>
                    <div class="card" style="text-align:center; padding:30px; color:var(--text-3);">No promotions yet.</div>
                    <?php else: foreach ($discounts as $d):
                      $is_active = $d['status'] == 1;
                      $dt = strtolower($d['discount_type'] ?? '');
                      $vdisp = in_array($dt, ['percentage','percent']) ? $d['discount_value'] . '%' : '&#8369;' . number_format($d['discount_value'], 2);
                    ?>
                      <div class="promo-card <?= !$is_active ? 'inactive' : '' ?>">
                        <div>
                          <div style="font-size:14px; font-weight:700; color:var(--espresso);"><?= htmlspecialchars($d['discount_name'], ENT_QUOTES, 'UTF-8') ?>
                            <span class="badge <?= $is_active ? 'badge-green' : 'badge-gray' ?>" style="margin-left:6px;"><?= $is_active ? 'Active' : 'Draft' ?></span>
                          </div>
                          <div style="font-size:12px; color:var(--chestnut); font-weight:600; margin-top:3px;"><?= $vdisp ?> off</div>
                          <div style="font-size:11px; color:var(--text-3); margin-top:2px;"><?= $d['expiry_date'] ? 'Expires ' . date('d M Y', strtotime($d['expiry_date'])) : 'No expiry' ?></div>
                        </div>
                        <div style="display:flex; gap:6px; flex-direction:column;">
                          <form method="POST" action="?page=inventory&tab=discounts">
                            <input type="hidden" name="toggle_id" value="<?= $d['discount_id'] ?>">
                            <input type="hidden" name="current_status" value="<?= $d['status'] ?>">
                            <button type="submit" name="toggle_discount_status" class="btn btn-sm <?= $is_active ? 'btn-danger' : 'btn-secondary' ?>"><?= $is_active ? 'Disable' : 'Enable' ?></button>
                          </form>
                          <div>
                            <button type="button" class="btn btn-sm btn-danger promo-delete-btn" data-id="<?= (int)$d['discount_id'] ?>" data-name="<?= htmlspecialchars($d['discount_name'], ENT_QUOTES, 'UTF-8') ?>">Delete</button>
                          </div>
                        </div>
                      </div>
                  <?php endforeach;
                  endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Product Add/Edit Modal -->
          <div class="overlay" id="productModal">
            <div class="modal-box wide" style="max-width:900px; width:900px;">
              <div class="modal-header">
                <span class="modal-title" id="prod_modal_title">New Product</span>
                <button class="modal-close" onclick="document.getElementById('productModal').classList.remove('open')">&times;</button>
              </div>
              <form method="POST" action="?page=inventory&tab=items" enctype="multipart/form-data" id="prod_form">
                <input type="hidden" name="old_sku" id="hidden_sku">
                <div class="form-group"><label>SKU / ID</label><input type="text" name="sku" id="form_sku" placeholder="PR-001" pattern="^PR-[0-9]{3}(?:-V[0-9]+)?$" title="SKU format: PR-001 or PR-001-V1 (variants)" readonly tabindex="-1" style="pointer-events:none; background:#f4f1ee; color:#333; cursor:not-allowed;" required></div>
                <div class="form-group"><label>Product Name</label><input type="text" name="name" id="form_name" placeholder="Product name" required title="Enter product name (any characters allowed)"></div>
                <div class="form-row-3">
                  <div class="form-group"><label>Price (&#8369;)</label><input type="text" name="price" id="form_price" oninput="onPriceInput(this)" placeholder="0.00" required></div>
                  <div class="form-group"><label>VAT (12%)</label><input type="text" id="form_vat" readonly placeholder="0.00" style="background:#f4f1ee; color:#333;"></div>
                  <div class="form-group"><label>Stock Qty</label><input type="number" name="qty" id="form_qty" placeholder="0" required></div>
                </div>
                <div class="form-row">
                  <div class="form-group"><label>Category</label>
                    <select name="category_id" id="form_cat">
                      <option value="">Uncategorized</option>
                        <?php
                        $predefinedCats = ['Main', 'Filler', 'Greenery'];
                        $catIdMap = [];
                        foreach ($cats as $c) { $catIdMap[strtolower($c['category_name'])] = $c['category_id']; }
                        foreach ($predefinedCats as $pn):
                          $pid = isset($catIdMap[strtolower($pn)]) ? $catIdMap[strtolower($pn)] : '';
                      ?>
                        <option value="<?= htmlspecialchars($pid, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($pn, ENT_QUOTES, 'UTF-8') ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-group"><label>Discount</label>
                    <select name="discount_id" id="form_disc">
                      <option value="">No Discount</option>
                      <?php foreach ($discounts as $d): ?><option value="<?= $d['discount_id'] ?>"><?= htmlspecialchars($d['discount_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="form-group">
                  <label>Product Image</label>
                  <div id="image_preview_container" style="margin-bottom:8px; display:none;">
                    <img id="image_preview" style="max-width:200px; max-height:200px; border-radius:4px; border:1px solid #ddd; padding:4px;">
                  </div>
                  <input type="file" name="product_image" id="product_image_input" accept="image/*" style="padding:6px;" onchange="previewImage(this)">
                </div>
                <button type="submit" id="prod_submit_btn" name="add_product" class="btn btn-primary btn-full" style="margin-top:6px;">Save Product</button>
              </form>
            </div>
          </div>

          <!-- Add Variant Modal -->
          <div class="overlay" id="addVariantModal">
            <div class="modal-box" style="max-width:380px;">
              <div class="modal-header">
                <span class="modal-title">Add Product Variant</span>
                <button class="modal-close" type="button" onclick="document.getElementById('addVariantModal').classList.remove('open')">&times;</button>
              </div>
              <form method="POST" action="?page=inventory&tab=items" enctype="multipart/form-data">
                <input type="hidden" name="original_sku" id="variant_orig_sku">
                
                <div class="form-group">
                  <label>Base Product</label>
                  <input type="text" id="variant_orig_name" readonly style="background:#f4f1ee; color:#777;">
                </div>
                
                <div class="form-group">
                  <label>New Variant SKU / ID</label>
                  <input type="text" name="new_sku" id="variant_new_sku" placeholder="e.g. PR-001-V1" pattern="^[A-Za-z]+-[0-9]{3}-V[0-9]+$" title="Variant SKU is automatically generated from the parent SKU, e.g. PR-001-V1." readonly style="background:#f4f1ee; color:#333; cursor:not-allowed;" required>
                </div>
                
                <div class="form-group">
                  <label>Variant Name</label>
                  <input type="text" name="variant_name" id="variant_new_name" placeholder="Enter variant name" required>
                </div>
                
                <div class="form-group">
                  <label>Initial Stock Quantity</label>
                  <input type="number" name="variant_qty" placeholder="0" min="0" required>
                </div>

                <div class="form-group">
                  <label>Variant Image (Optional)</label>
                  <input type="file" name="product_image" accept="image/*" style="padding:6px; border:1px solid #ccc; width:100%; border-radius:4px;">
                  <span style="font-size:11px; color:#666;">Leave empty to inherit the parent product's image.</span>
                </div>
                
                <p style="font-size:12px; color:#777; margin-bottom:14px;">
                  * Base price, Category, Promotions, and Images are automatically inherited.
                </p>

                <button type="submit" name="add_variant_submit" class="btn btn-primary btn-full">Save Variant</button>
              </form>
            </div>
          </div>

          <script>
            function getNextVariantSku(baseSku) {
              const normalized = String(baseSku).toUpperCase().trim();
              const match = normalized.match(/^([A-Z]+-\d{3})(?:-V\d+)?$/);
              if (!match) return normalized + '-V1';
              const base = match[1];
              let maxIndex = 0;
              if (typeof inventoryItems !== 'undefined') {
                inventoryItems.forEach(item => {
                  const m = String(item.sku).toUpperCase().match(new RegExp('^' + base + '-V(\\d+)$'));
                  if (m) {
                    maxIndex = Math.max(maxIndex, parseInt(m[1], 10));
                  }
                });
              }
              return base + '-V' + String(maxIndex + 1);
          }

          function openVariantModal(item) {
              const generatedSku = getNextVariantSku(item.sku);
              document.getElementById('variant_orig_sku').value = item.sku;
              document.getElementById('variant_orig_name').value = item.product_name + ' (' + item.sku + ')';

              const skuField = document.getElementById('variant_new_sku');
              const nameField = document.getElementById('variant_new_name');
              if (skuField) {
                skuField.value = generatedSku;
              }
              if (nameField) {
                nameField.value = generatedSku;
              }

              document.getElementById('addVariantModal').classList.add('open');
          }
          </script>          

          <!-- Add Category Modal -->
          <div class="overlay" id="addCatModal">
            <div class="modal-box" style="max-width:360px;">
              <div class="modal-header">
                <span class="modal-title">New Category</span>
                <button class="modal-close" onclick="document.getElementById('addCatModal').classList.remove('open')">&times;</button>
              </div>
              <form method="POST" action="?page=inventory&tab=categories">
                <div class="form-group"><label>Category Name</label><input type="text" name="category_name" required autofocus pattern="[A-Za-zÑñ ]+" title="Letters and spaces only" oninput="this.value = this.value.replace(/[^A-Za-zÑñ\s]/g,'')"></div>
                <button type="submit" name="add_category" class="btn btn-primary btn-full">Create Category</button>
              </form>
            </div>
          </div>

          <!-- Edit Category Modal -->
          <div class="overlay" id="editCatModal">
            <div class="modal-box" style="max-width:360px;">
              <div class="modal-header">
                <span class="modal-title">Edit Category</span>
                <button class="modal-close" onclick="document.getElementById('editCatModal').classList.remove('open')">&times;</button>
              </div>
              <form method="POST" action="?page=inventory&tab=categories">
                <input type="hidden" name="category_id" id="edit_cat_id">
                <div class="form-group"><label>Category Name</label><input type="text" name="category_name" id="edit_cat_name" required pattern="[A-Za-zÑñ ]+" title="Letters and spaces only" oninput="this.value = this.value.replace(/[^A-Za-zÑñ\s]/g,'')"></div>
                <div style="display:flex; gap:8px;">
                  <button type="submit" name="update_category" class="btn btn-primary btn-full">Update</button>
                  <button type="submit" name="delete_category" class="btn btn-danger btn-full" data-confirm="Delete this category?">Delete</button>
                </div>
              </form>
            </div>
          </div>

          <!-- Assign Items Modal -->
          <div class="overlay" id="assignModal">
            <div class="modal-box">
              <div class="modal-header">
                <span class="modal-title">Assign Products to Category</span>
                <button class="modal-close" onclick="document.getElementById('assignModal').classList.remove('open')">&times;</button>
              </div>
              <form method="POST" action="?page=inventory&tab=categories">
                <input type="hidden" name="category_id" id="assign_cat_id">
                <div style="max-height:280px; overflow-y:auto; border:1px solid var(--taupe); border-radius:var(--radius); padding:10px; margin-bottom:16px;">
                  <?php foreach ($inventory as $item): ?>
                    <label style="display:flex; align-items:center; gap:8px; padding:7px 4px; border-bottom:1px solid var(--taupe-l); cursor:pointer; font-size:13px; color:var(--text);">
                      <input type="checkbox" name="assign_skus[]" value="<?= $item['sku'] ?>">
                      <span><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?> <span style="color:var(--text-3); font-size:11px; font-family:monospace;">(<?= $item['sku'] ?>)</span></span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <button type="submit" name="assign_items_submit" class="btn btn-primary btn-full">Save Assignments</button>
              </form>
            </div>
          </div>

          <script>
              function openAddModal() {
              document.getElementById('prod_modal_title').textContent = 'New Product';
              document.getElementById('prod_submit_btn').name = 'add_product';
              document.getElementById('prod_form').reset();
              document.getElementById('hidden_sku').value = '';
              document.getElementById('image_preview_container').style.display = 'none';
              
              // Wipes any cached file selection out of the input element
              const imgInput = document.getElementById('product_image_input');
              if (imgInput) imgInput.value = '';

              const skuEl = document.getElementById('form_sku');
              if (skuEl) {
                skuEl.readOnly = true;
                skuEl.value = 'PR-001';
                skuEl.style.pointerEvents = 'none';
                skuEl.style.background = '#f4f1ee';
                skuEl.style.cursor = 'not-allowed';
                skuEl.style.color = '#333';
              }

              fetch('?page=inventory&tab=items&get_next_sku=1')
                .then(response => response.json())
                .then(data => {
                  if (data && data.status === 'ok' && data.next_sku) {
                    if (skuEl) skuEl.value = data.next_sku;
                  }
                })
                .catch(() => {
                  if (skuEl) skuEl.value = 'PR-001';
                });

              // clear VAT display
              const vatEl = document.getElementById('form_vat'); if (vatEl) vatEl.value = '';
              document.getElementById('productModal').classList.add('open');
            }

            const inventoryItems = (function(){
              try {
                return JSON.parse(atob('<?= base64_encode(json_encode($inventory, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'));
              } catch (err) {
                console.error('Failed to parse inventoryItems', err);
                return [];
              }
            })();

            // Ensure the product form always sends the action name (button name) even when submitted programmatically
            (function(){
              try {
                const prodForm = document.getElementById('prod_form');
                if (!prodForm) return;
                prodForm.addEventListener('submit', function(e){
                  try {
                    const btn = document.getElementById('prod_submit_btn');
                    if (btn && btn.name) {
                      let hidden = this.querySelector('input[name="' + btn.name + '"]');
                      if (!hidden) {
                        hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = btn.name;
                        hidden.value = '1';
                        this.appendChild(hidden);
                      } else {
                        hidden.value = '1';
                      }
                    }
                  } catch (ex) { console.error(ex); }
                });
              } catch (ex) { console.error(ex); }
            })();

            function openEditModal(data, title = 'Edit Product') {
              document.getElementById('prod_modal_title').textContent = title;
              document.getElementById('prod_submit_btn').name = 'update_product';
              const skuEl = document.getElementById('form_sku');
              if (skuEl) {
                skuEl.value = data.sku;
                skuEl.readOnly = true;
              }
              document.getElementById('hidden_sku').value = data.sku;
              document.getElementById('form_name').value = data.product_name;
              const p = parseFloat(data.price);
              document.getElementById('form_price').value = isNaN(p) ? '' : p.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
              });
              // compute VAT based on server tax rate
              const taxRate = parseFloat(<?= json_encode($store_info['tax_rate']) ?>);
              const vatEl = document.getElementById('form_vat');
              if (vatEl) {
                const base = isNaN(p) ? 0 : p;
                vatEl.value = (base * taxRate).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
              }
              document.getElementById('form_qty').value = data.stock_qty;
              document.getElementById('form_cat').value = data.category_id || '';
              document.getElementById('form_disc').value = data.discount_id || '';
              
              // Show current image if editing and image exists
              if (data.image_url && data.image_url !== '') {
                const previewContainer = document.getElementById('image_preview_container');
                const previewImg = document.getElementById('image_preview');
                previewImg.src = data.image_url;
                previewContainer.style.display = 'block';
              } else {
                document.getElementById('image_preview_container').style.display = 'none';
              }
              
              // Reset file input
              document.getElementById('product_image_input').value = '';
              
              document.getElementById('productModal').classList.add('open');
              document.getElementById('form_qty').focus();
            }
            
            function previewImage(input) {
              const previewContainer = document.getElementById('image_preview_container');
              const previewImg = document.getElementById('image_preview');
              
              if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                  previewImg.src = e.target.result;
                  previewContainer.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
              } else {
                previewContainer.style.display = 'none';
              }
            }

            function openEditModalBySku(sku) {
              const item = inventoryItems.find(i => i.sku === sku);
              if (item) {
                openEditModal(item, 'Restock Product');
              }
            }

            window.addEventListener('DOMContentLoaded', () => {
              const params = new URLSearchParams(window.location.search);
              const sku = params.get('open_sku');
              if (sku) {
                openEditModalBySku(sku);
              }

              const invCatFilter = document.getElementById('inv-cat-filter');
              if (invCatFilter) {
                invCatFilter.addEventListener('click', function(e) {
                  const pill = e.target.closest('.cat-pill');
                  if (!pill) return;
                  invCatFilter.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
                  pill.classList.add('active');
                  const cat = pill.dataset.cat;
                  document.querySelectorAll('.inv-grid .inv-card').forEach(el => {
                    el.style.display = (!cat || el.dataset.cat === cat) ? '' : 'none';
                  });
                });
              }
            });


            function openEditCatModal(id, name) {
              document.getElementById('edit_cat_id').value = id;
              document.getElementById('edit_cat_name').value = name;
              document.getElementById('editCatModal').classList.add('open');
            }

            function fmtCurr(input) {
              let raw = input.value.replace(/[^0-9]/g, '');
              if (!raw) {
                input.value = '';
                return;
              }
              let n = parseInt(raw, 10);
              if (n > 99999900) n = 99999900;
              input.value = (n / 100).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
              });
            }

            function onPriceInput(input) {
              // format the price field then update VAT display
              fmtCurr(input);
              const v = input.value.replace(/[^0-9.-]/g, '');
              const num = parseFloat(v) || 0;
              const taxRate = parseFloat(<?= json_encode($store_info['tax_rate']) ?>);
              const vatEl = document.getElementById('form_vat');
              if (vatEl) {
                vatEl.value = (num * taxRate).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
              }
            }

            function fmtDisc(input) {
              const type = document.getElementById('disc_type_sel').value;
              let raw = input.value.replace(/[^0-9.]/g, '');
              if (!raw || raw === '.') {
                input.value = '';
                return;
              }
              let n = parseFloat(raw);
              if (isNaN(n)) {
                input.value = '';
                return;
              }
              const max = type === 'percent' ? 100 : 99999900;
              if (n > max) n = max;
              input.value = n.toFixed(2);
            }
            document.querySelectorAll('.overlay').forEach(o => o.addEventListener('click', e => {
              if (e.target === o) o.classList.remove('open');
            }));

            const confirmDialogModal = document.getElementById('confirmDialogModal');
            const confirmDialogTitle = document.getElementById('confirmDialogTitle');
            const confirmDialogMessage = document.getElementById('confirmDialogMessage');
            const confirmDialogInput = document.getElementById('confirmDialogInput');
            const confirmDialogConfirm = document.getElementById('confirmDialogConfirm');
            const confirmDialogCancel = document.getElementById('confirmDialogCancel');
            let confirmDialogResolver = null;

            function openDialog(options) {
              confirmDialogTitle.textContent = options.title || 'Confirm action';
              confirmDialogMessage.textContent = options.message || '';
              confirmDialogConfirm.textContent = options.confirmText || 'OK';
              confirmDialogCancel.textContent = options.cancelText || 'Cancel';
              if (options.prompt) {
                confirmDialogInput.style.display = 'block';
                confirmDialogInput.value = options.defaultValue || '';
                confirmDialogInput.placeholder = options.placeholder || '';
                setTimeout(() => confirmDialogInput.focus(), 50);
              } else {
                confirmDialogInput.style.display = 'none';
              }
              confirmDialogModal.classList.add('open');
              return new Promise(resolve => {
                confirmDialogResolver = resolve;
              });
            }

            function closeDialogModal() {
              confirmDialogModal.classList.remove('open');
              if (confirmDialogResolver) {
                confirmDialogResolver(null);
                confirmDialogResolver = null;
              }
            }

            function showConfirm(message, title, confirmText, cancelText) {
              return openDialog({
                title: title || 'Confirm action',
                message: message,
                confirmText: confirmText || 'OK',
                cancelText: cancelText || 'Cancel',
                prompt: false
              });
            }

            function showPrompt(message, placeholder, title, confirmText, cancelText) {
              return openDialog({
                title: title || 'Enter details',
                message: message,
                prompt: true,
                placeholder: placeholder || '',
                confirmText: confirmText || 'OK',
                cancelText: cancelText || 'Cancel'
              });
            }

            confirmDialogConfirm.addEventListener('click', () => {
              if (!confirmDialogResolver) return;
              const value = confirmDialogInput.style.display !== 'none' ? confirmDialogInput.value : true;
              confirmDialogModal.classList.remove('open');
              confirmDialogResolver(value);
              confirmDialogResolver = null;
            });

            confirmDialogCancel.addEventListener('click', () => {
              if (!confirmDialogResolver) return;
              confirmDialogModal.classList.remove('open');
              confirmDialogResolver(null);
              confirmDialogResolver = null;
            });

            confirmDialogModal.addEventListener('click', event => {
              if (event.target === confirmDialogModal) {
                if (!confirmDialogResolver) return;
                confirmDialogModal.classList.remove('open');
                confirmDialogResolver(null);
                confirmDialogResolver = null;
              }
            });

            confirmDialogInput.addEventListener('keydown', event => {
              if (event.key === 'Enter') {
                event.preventDefault();
                confirmDialogConfirm.click();
              }
            });

            document.addEventListener('submit', function(event) {
              const form = event.target;
              if (!(form instanceof HTMLFormElement)) return;
              const promptMessage = form.dataset.prompt;
              const confirmMessage = form.dataset.confirm;
              if (promptMessage) {
                event.preventDefault();
                showPrompt(promptMessage).then(value => {
                  if (value === null) return;
                  const target = form.querySelector(form.dataset.promptTarget || '.rej_reason');
                  if (target) target.value = value;
                  form.submit();
                });
                return;
              }
              if (confirmMessage) {
                event.preventDefault();
                showConfirm(confirmMessage).then(ok => {
                  if (ok) form.submit();
                });
              }
            });

            document.addEventListener('click', function(event) {
              const trigger = event.target.closest('[data-confirm]');
              if (!trigger) return;
              if (trigger instanceof HTMLButtonElement || trigger instanceof HTMLAnchorElement) {
                const confirmMessage = trigger.dataset.confirm;
                if (!confirmMessage) return;
                event.preventDefault();
                showConfirm(confirmMessage).then(ok => {
                  if (!ok) return;
                  const form = trigger.closest('form');
                  if (form) form.submit();
                  else if (trigger instanceof HTMLAnchorElement && trigger.href) window.location.href = trigger.href;
                });
              }
            });

            // AJAX handler for promotion deletion buttons
            document.addEventListener('click', function(e) {
              const btn = e.target.closest('.promo-delete-btn');
              if (!btn) return;
              e.preventDefault();
              const id = btn.dataset.id;
              const name = btn.dataset.name || '';
              if (!id) return;
              showConfirm('Delete promotion "' + name + '"?').then(async function(ok) {
                if (!ok) return;
                try {
                  const body = new URLSearchParams();
                  body.append('action', 'delete_promotion_ajax');
                  body.append('discount_id', id);
                  const resp = await fetch(window.location.pathname, { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString() });
                  if (!resp.ok) { toast('Failed to delete promotion', 'red'); return; }
                  const j = await resp.json();
                  if (j && j.status === 'ok') {
                    // remove promo card from UI
                    const card = btn.closest('.promo-card'); if (card) card.remove();
                    // remove option from promo_select
                    const sel = document.getElementById('promo_select');
                    if (sel) {
                      const opt = sel.querySelector('option[value="' + id + '"]');
                      if (opt) opt.remove();
                      // reset to None if currently selected
                      if (sel.value === id) sel.value = '0';
                    }
                    calcTotals();
                    toast('Promotion deleted');
                  } else {
                    toast((j && j.message) ? j.message : 'Delete failed', 'red');
                  }
                } catch (err) {
                  console.error(err);
                  toast('Error deleting promotion', 'red');
                }
              });
            });
          </script>

          <script>
            // Client-side search for CRM table
            (function() {
              var inp = document.getElementById('cust_search');
              if (!inp) return;
              inp.addEventListener('input', function() {
                var q = this.value.trim().toLowerCase();
                document.querySelectorAll('table tbody tr[data-cust-id]').forEach(function(row) {
                  if (!q) {
                    row.style.display = '';
                    return;
                  }
                  var txt = row.innerText.toLowerCase();
                  row.style.display = txt.indexOf(q) !== -1 ? '' : 'none';
                });
              });
            })();
          </script>

          <!-- History Modal -->
        <?php
        // ── CRM ──────────────────────────────────────────────────────
        elseif ($page === 'crm'): ?>
          <div class="page">
            <div class="page-header">
              <div>
                <div class="page-title">Customers</div>
                <div class="page-sub"><?= count($customers_all) ?> registered customers</div>
              </div>
              <button onclick="document.getElementById('addCustModal').classList.add('open')" class="btn btn-primary">+ Add Customer</button>
            </div>
            <?php if (isset($_SESSION['crm_error'])): ?>
              <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['crm_error'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php unset($_SESSION['crm_error']);
            endif; ?>
            <div class="card">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <div style="font-size:13px; color:var(--text-2);">Customer records</div>
                <div><input type="text" id="cust_search" placeholder="Search customers..." style="padding:8px 10px; border:1px solid var(--taupe); border-radius:8px; font-size:13px;"></div>
              </div>
              <div class="tbl-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Customer</th>
                      <th>Email</th>
                      <th>Contact Number</th>
                      <th>Loyalty Points</th>
                      <th>Member Since</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($customers_all)): ?>
                      <tr>
                        <td colspan="6" style="text-align:center; color:var(--text-3); padding:32px;">No customers yet.</td>
                      </tr>
                      <?php else: foreach ($customers_all as $c):
                        $av = strtoupper(substr($c['full_name'], 0, 1));
                      ?>
                        <tr data-cust-id="<?= $c['customer_id'] ?>" data-customer="<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>" class="crm-row-clickable">
                          <td>
                            <div class="customer-row">
                              <div class="cust-avatar">
                                <?php if (!empty($c['photo_url'])): ?>
                                  <img src="<?= htmlspecialchars($c['photo_url'], ENT_QUOTES, 'UTF-8') ?>" alt=""
                                    style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                                <?php else: ?>
                                  <?= $av ?>
                                <?php endif; ?>
                              </div>
                              <div style="font-size:14px; font-weight:600; color:var(--espresso);">
                                <?= htmlspecialchars($c['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if (isset($c['approved']) && $c['approved'] == 0): ?> <span class="badge badge-amber" style="margin-left:8px; font-size:11px;">To be approved</span><?php endif; ?>
                              </div>
                              <div style="font-size:12px; color:var(--text-3); margin-top:6px;">
                                <?php if (!empty($c['created_by']) && isset($empMap[$c['created_by']])): ?>Added by: <?= htmlspecialchars($empMap[$c['created_by']], ENT_QUOTES, 'UTF-8') ?><?php else: ?>Added by: System<?php endif; ?>
                                <?php if (!empty($c['approved_by']) && isset($empMap[$c['approved_by']])): ?>
                                  &middot;
                                  <?php if (isset($c['approved']) && $c['approved'] == 2): ?>
                                    <span style="color: #dc3545; font-weight: 600;">Rejected by: <?= htmlspecialchars($empMap[$c['approved_by']], ENT_QUOTES, 'UTF-8') ?></span>
                                  <?php else: ?>
                                    Approved by: <?= htmlspecialchars($empMap[$c['approved_by']], ENT_QUOTES, 'UTF-8') ?>
                                  <?php endif; ?>
                                <?php endif; ?>
                              </div>
                            </div>
                          </td>

                          <td style="color:var(--text-2);"><?= htmlspecialchars($c['contact_email'] ?? '&#8212;', ENT_QUOTES, 'UTF-8') ?></td>
                          <td style="color:var(--text-2);"><?= htmlspecialchars($c['contact_number'] ?? '&#8212;', ENT_QUOTES, 'UTF-8') ?></td>
                          <td><span class="badge badge-brown"><?= number_format($c['loyalty_points']) ?> pts</span></td>
                          <td style="color:var(--text-3); font-size:12px;"><?= !empty($c['member_since']) ? date('d M Y', strtotime($c['member_since'])) : '&#8212;' ?></td>
                          <td>
                            <div style="display: flex; gap: 8px; align-items: center;">
                              <?php if ($is_admin): ?>
                                <?php if (isset($c['approved']) && $c['approved'] == 0): ?>
                                  <form method="POST" action="?page=crm" style="margin: 0;">
                                    <input type="hidden" name="customer_id" value="<?= $c['customer_id'] ?>">
                                    <button type="submit" name="approve_customer" class="btn btn-sm btn-primary">Approve</button>
                                  </form>
                                  <form data-prompt="Enter rejection reason (optional):" data-prompt-target=".rej_reason" method="POST" action="?page=crm" style="margin: 0;">
                                    <input type="hidden" name="customer_id" value="<?= $c['customer_id'] ?>">
                                    <input type="hidden" name="rejection_reason" value="" class="rej_reason">
                                    <button type="submit" name="reject_customer" class="btn btn-sm btn-ghost" style="color: var(--red); border: 1px solid rgba(168, 50, 50, .12);">Reject</button>
                                  </form>
                                <?php endif; ?>
                              <?php endif; ?>
                            </div>
                            <div style="display: flex; gap: 8px; align-items: center; margin-top: 8px;">
                              <?php if ($is_admin): ?>
                                <form data-confirm="Are you sure you want to 100% delete this customer and all logs permanently from the database?" method="POST" action="?page=crm" style="margin: 0;">
                                  <input type="hidden" name="customer_id" value="<?= $c['customer_id'] ?>">
                                  <button type="submit" name="delete_customer" class="btn btn-sm btn-danger" onclick="event.stopPropagation();">Delete</button>
                                </form>
                              <?php endif; ?>
                              <button type="button" data-history-customer-id="<?= $c['customer_id'] ?>" class="btn btn-sm btn-secondary cust-history-btn">History</button>
                            </div>
                          </td>
                        </tr>
                    <?php endforeach;
                    endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="overlay" id="addCustModal">
            <div class="modal-box" style="max-width:380px;">
              <div class="modal-header">
                <span class="modal-title">New Customer</span>
                <button class="modal-close" onclick="document.getElementById('addCustModal').classList.remove('open')">&times;</button>
              </div>
              <form method="POST" action="?page=crm" enctype="multipart/form-data">
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" pattern="[A-Za-zÑñ ]+" minlength="3" title="Name must contain letters and spaces only" required oninput="this.value = this.value.replace(/[^A-Za-zÑñ\s]/g,'')"></div>
                <div class="form-row">
                  <div class="form-group"><label>Email</label><input type="email" name="contact_email" placeholder="you@gmail.com" pattern="[A-Za-z0-9.]+@gmail\.com" title="Must be a Gmail address (only letters, numbers, and periods allowed before @)" required></div>
                  <div class="form-group"><label>Contact Number</label><input type="text" name="contact_number" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="09XXXXXXXXX" title="11 digits, numbers only" required></div>
                </div>
                <div class="form-group"><label>Member Since</label><input type="date" name="member_since" value="<?= htmlspecialchars($date_today, ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="form-group"><label>Profile Photo</label><input type="file" name="cust_photo" accept="image/*" style="padding:6px;"></div>
                <button type="submit" name="add_customer" class="btn btn-primary btn-full">Save Customer</button>
              </form>
            </div>
          </div>

          <div class="overlay" id="editCustModal">
            <div class="modal-box" style="max-width:380px;">
              <div class="modal-header">
                <span class="modal-title">Edit Customer</span>
                <button class="modal-close" onclick="document.getElementById('editCustModal').classList.remove('open')">&times;</button>
              </div>
              <form method="POST" action="?page=crm" enctype="multipart/form-data">
                <input type="hidden" name="customer_id" id="edit_cust_id">
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" id="edit_cust_name" pattern="[A-Za-zÑñ ]+" minlength="3" title="Name must contain letters and spaces only" required oninput="this.value = this.value.replace(/[^A-Za-zÑñ\s]/g,'')"></div>
                <div class="form-row">
                  <div class="form-group"><label>Email</label><input type="email" name="contact_email" id="edit_cust_email" pattern="[A-Za-z0-9.]+@gmail\.com" title="Must be a Gmail address (only letters, numbers, and periods allowed before @gmail.com)" required></div>
                  <div class="form-group"><label>Contact Number</label><input type="text" name="contact_number" id="edit_cust_number" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="09XXXXXXXXX" title="11 digits, numbers only" required></div>
                </div>
                <div class="form-group"><label>Member Since</label><input type="date" name="member_since" id="edit_cust_member_since"></div>
                <div class="form-group"><label>Profile Photo</label><input type="file" name="cust_photo" accept="image/*" style="padding:6px;"></div>
                <button type="submit" name="update_customer" class="btn btn-primary btn-full">Update Customer</button>
              </form>
            </div>
          </div>

          <script>
            function openEditCust(c) {
              document.getElementById('edit_cust_id').value = c.customer_id;
              document.getElementById('edit_cust_name').value = c.full_name;
              document.getElementById('edit_cust_email').value = c.contact_email || '';
              document.getElementById('edit_cust_number').value = c.contact_number || '';
              try {
                document.getElementById('edit_cust_member_since').value = c.member_since ? c.member_since.split(' ')[0] : '';
              } catch (e) {
                document.getElementById('edit_cust_member_since').value = '';
              }
              document.getElementById('editCustModal').classList.add('open');
            }
            document.querySelectorAll('.overlay').forEach(o => o.addEventListener('click', e => {
              if (e.target === o) o.classList.remove('open');
            }));
            
            // CRM row click handler to open Edit modal
            document.querySelectorAll('.crm-row-clickable').forEach(row => {
              row.addEventListener('click', function(e) {
                if (e.target.closest('.cust-history-btn') || e.target.closest('button') || e.target.closest('form') || e.target.closest('input') || e.target.closest('a')) {
                  return;
                }
                const custData = this.getAttribute('data-customer');
                if (custData) {
                  try {
                    const cust = JSON.parse(custData);
                    openEditCust(cust);
                  } catch (err) {
                    console.error('Error parsing customer data:', err);
                  }
                }
              });
            });
          </script>

          <!-- Customer History Modal -->
          <div class="overlay" id="historyModal">
            <div class="modal-box" style="width:800px;">
              <div class="modal-header"><span class="modal-title">Customer Transaction History</span><button class="modal-close" onclick="document.getElementById('historyModal').classList.remove('open')">&times;</button></div>
              <div id="historyContent" style="padding:12px; max-height:400px; overflow:auto;"></div>
            </div>
          </div>
          <script>
            const CUSTOMER_SALES = <?= json_encode($customerSalesMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || {};

            function openHistory(cid) {
              const modal = document.getElementById('historyModal');
              const container = document.getElementById('historyContent');
              const title = modal.querySelector('.modal-title');
              if (title) {
                title.textContent = 'Customer Transaction History';
              }
              container.innerHTML = '';
              const items = CUSTOMER_SALES[cid] || [];
              if (items.length === 0) {
                container.innerHTML = '<div style="padding:12px; color:var(--text-3);">No transactions found for this customer.</div>';
              } else {
                items.forEach(it => {
                  const tr = document.createElement('div');
                  tr.style.padding = '12px 0';
                  tr.style.borderBottom = '1px solid #eee';
                  const orderId = it.transaction_id ? it.transaction_id : 'Unknown';
                  const dateText = it.sale_date ? new Date(it.sale_date).toLocaleString() : 'Unknown date';

                  const row = document.createElement('div');
                  row.style.display = 'flex';
                  row.style.justifyContent = 'space-between';
                  row.style.gap = '12px';
                  row.style.alignItems = 'center';

                  const info = document.createElement('div');
                  const titleEl = document.createElement('div');
                  titleEl.style.fontWeight = '700';
                  titleEl.textContent = 'Transaction: ' + orderId;
                  const metaEl = document.createElement('div');
                  metaEl.style.fontSize = '12px';
                  metaEl.style.color = 'var(--text-3)';
                  metaEl.style.marginTop = '4px';
                  metaEl.textContent = dateText + ' · ' + (it.payment_method || 'Unknown payment');
                  info.appendChild(titleEl);
                  info.appendChild(metaEl);

                  const summary = document.createElement('div');
                  summary.style.textAlign = 'right';
                  const amountEl = document.createElement('div');
                  amountEl.style.fontWeight = '700';
                  amountEl.style.color = 'var(--espresso)';
                  amountEl.textContent = '₱' + Number(it.total_amount || 0).toFixed(2);
                  const statusEl = document.createElement('div');
                  statusEl.style.fontSize = '12px';
                  statusEl.style.color = 'var(--text-3)';
                  statusEl.style.marginTop = '4px';
                  statusEl.textContent = it.status || 'Unknown';
                  summary.appendChild(amountEl);
                  summary.appendChild(statusEl);

                  row.appendChild(info);
                  row.appendChild(summary);

                  const buttonRow = document.createElement('div');
                  buttonRow.style.marginTop = '10px';
                  buttonRow.style.display = 'flex';
                  buttonRow.style.justifyContent = 'flex-end';

                  const viewBtn = document.createElement('button');
                  viewBtn.type = 'button';
                  viewBtn.className = 'btn btn-sm btn-secondary';
                  viewBtn.textContent = 'View Details';
                  viewBtn.addEventListener('click', function() {
                    viewTransactionDetails(it);
                  });
                  buttonRow.appendChild(viewBtn);

                  tr.appendChild(row);
                  tr.appendChild(buttonRow);
                  container.appendChild(tr);
                });
              }
              modal.classList.add('open');
            }

            document.addEventListener('click', function(event) {
              const btn = event.target.closest('.cust-history-btn');
              if (!btn) return;
              const cid = btn.dataset.historyCustomerId;
              if (!cid) return;
              event.stopPropagation();
              openHistory(cid);
            });
          </script>

        <?php
        // ── EMPLOYEES ─────────────────────────────────────────────────
        elseif ($page === 'employees' && $is_admin): ?>
          <div class="page">
            <div class="page-header">
              <div>
                <div class="page-title">Employees</div>
                <div class="page-sub"><?= count($employees) ?> staff members</div>
              </div>
              <a href="?page=register" class="btn btn-primary">+ Register Staff</a>
            </div>
            <?php if (isset($_GET['registered'])): ?>
              <div class="alert alert-success" style="margin:0 0 16px;">Account created successfully.</div>
            <?php endif; ?>
            <?php foreach ($employees as $emp):
              $av = makeInitials($emp['full_name']);
              $sales_today = $conn->query("SELECT COALESCE(SUM(total_amount),0) as t FROM sales WHERE employee_id='{$emp['employee_id']}' AND DATE(sale_date)='$date_today' AND status='Completed'")->fetch_assoc()['t'];
            ?>
              <div class="emp-card">
                <div class="emp-avatar">
                  <?php if (!empty($emp['photo_url'])): ?>
                    <img src="<?= htmlspecialchars($emp['photo_url'], ENT_QUOTES, 'UTF-8') ?>" alt=""
                      style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                  <?php else: ?>
                    <?= $av ?>
                  <?php endif; ?>
                </div>
                <div style="flex:1;">
                  <div style="font-size:15px; font-weight:600; color:var(--espresso);">
                    <?= htmlspecialchars($emp['full_name'], ENT_QUOTES, 'UTF-8') ?>
                  </div>
                  <div style="font-size:12px; color:var(--text-3); margin-top:2px;">
                    <?= $emp['employee_id'] ?> &middot; <?= $emp['job_role'] ?>
                  </div>
                </div>
                <div style="text-align:right;">
                  <div style="font-size:13px; font-weight:700; color:var(--green);">&#8369;<?= number_format($sales_today, 2) ?></div>
                  <div style="font-size:11px; color:var(--text-3);">Sales today</div>
                </div>
                <div style="display:flex; gap:6px; margin-left:12px;">
                  <button onclick='openEditEmp(<?= json_encode($emp) ?>)' class="btn btn-sm btn-secondary">Edit</button>
                  <button onclick="document.getElementById('reset_emp_id').value='<?= $emp['employee_id'] ?>'; document.getElementById('resetPassModal').classList.add('open');" class="btn btn-sm btn-secondary">Passcode</button>
                  <?php if ($emp['employee_id'] !== $_SESSION['user_id']): ?>
                    <form data-confirm="Remove this employee?" method="POST" action="?page=employees" style="margin:0;">
                      <input type="hidden" name="employee_id" value="<?= $emp['employee_id'] ?>">
                      <button type="submit" name="delete_employee" class="btn btn-sm btn-danger">Remove</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>

          </div>


          <div class="overlay" id="editEmpModal">
            <div class="modal-box" style="max-width:380px;">
              <div class="modal-header">
                <span class="modal-title">Edit Employee</span>
                <button class="modal-close" onclick="document.getElementById('editEmpModal').classList.remove('open')">&times;</button>
              </div>
              <form method="POST" action="?page=employees" enctype="multipart/form-data">
                <input type="hidden" name="employee_id" id="edit_emp_id">
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" id="edit_emp_name" required pattern="[A-Za-zÑñ ]+" title="Letters and spaces only" oninput="this.value = this.value.replace(/[^A-Za-zÑñ\s]/g,'')"></div>
                <div class="form-group"><label>Role</label>
                  <select name="role" id="edit_emp_role">
                    <option value="Cashier">Cashier</option>
                    <option value="Admin">Admin</option>
                  </select>
                </div>
                <div class="form-group"><label>Profile Photo</label><input type="file" name="emp_photo" accept="image/*" style="padding:6px;"></div>
                <button type="submit" name="update_employee" class="btn btn-primary btn-full">Update Employee</button>
              </form>
            </div>
          </div>


          <div class="overlay" id="resetPassModal">
            <div class="modal-box" style="max-width:340px;">
              <div class="modal-header">
                <span class="modal-title">Reset Passcode</span>
                <button class="modal-close" onclick="document.getElementById('resetPassModal').classList.remove('open')">&times;</button>
              </div>
              <form method="POST" action="?page=employees" enctype="multipart/form-data">
                <input type="hidden" name="employee_id" id="reset_emp_id">
                <div class="form-group"><label>New Passcode</label><input type="password" name="new_passcode" required></div>
                <button type="submit" name="reset_passcode" class="btn btn-primary btn-full">Set Passcode</button>
              </form>
            </div>
          </div>
          <script>
            function openEditEmp(e) {
              document.getElementById('edit_emp_id').value = e.employee_id;
              document.getElementById('edit_emp_name').value = e.full_name;
              document.getElementById('edit_emp_role').value = e.role;
              document.getElementById('editEmpModal').classList.add('open');
            }
            document.querySelectorAll('.overlay').forEach(o => o.addEventListener('click', e => {
              if (e.target === o) o.classList.remove('open');
            }));
          </script>

        <?php
        // ── REPORTS ───────────────────────────────────────────────────
        elseif ($page === 'reports' && $is_admin): ?>
          <div class="page">
            <div class="page-header">
              <div>
                <div class="page-title">Reports &amp; Analytics</div>
                <div class="page-sub">Sales performance overview</div>
              </div>
            </div>

            <div class="period-tabs">
              <a href="?page=reports&period=today" class="period-btn <?= $report_period === 'today' ? 'active' : '' ?>">Today</a>
              <a href="?page=reports&period=week" class="period-btn <?= $report_period === 'week' ? 'active' : '' ?>">Last 7 Days</a>
              <a href="?page=reports&period=month" class="period-btn <?= $report_period === 'month' ? 'active' : '' ?>">Last 30 Days</a>
            </div>

            <div class="stat-grid" style="margin-bottom:24px;">
              <div class="stat-card">
                <div class="stat-label">Total Revenue</div>
                <div class="stat-value" style="color:var(--green);">&#8369;<?= number_format($r_rev, 2) ?></div>
              </div>
              <div class="stat-card">
                <div class="stat-label">Transactions</div>
                <div class="stat-value"><?= $r_trx ?></div>
              </div>
              <div class="stat-card">
                <div class="stat-label">Avg. Order Value</div>
                <div class="stat-value">&#8369;<?= $r_trx > 0 ? number_format($r_rev / $r_trx, 2) : '0.00' ?></div>
              </div>
              <div class="stat-card">
                <div class="stat-label">Best Selling Bundle</div>
                <div class="stat-value"><?= htmlspecialchars($r_best_bundle, ENT_QUOTES, 'UTF-8') ?></div>
              </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px;">
              <div class="card">
                <div style="font-size:14px; font-weight:700; color:var(--espresso); margin-bottom:14px;">Top Selling Products</div>
                <?php $r_top_rows = $r_top ? $r_top->fetch_all(MYSQLI_ASSOC) : [];
                if (!empty($r_top_rows)): ?>
                  <table>
                    <thead>
                      <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Qty Sold</th>
                        <th>Revenue</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php $rank = 1;
                      foreach ($r_top_rows as $r): ?>
                        <tr>
                          <td><span class="badge <?= $rank === 1 ? 'badge-amber' : 'badge-gray' ?>"><?= $rank++ ?></span></td>
                          <td style="font-weight:600; color:var(--espresso);"><?= htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8') ?></td>
                          <td><?= $r['cnt'] ?></td>
                          <td style="color:var(--green); font-weight:600;">&#8369;<?= number_format($r['rev'], 2) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php else: ?><p style="color:var(--text-3); font-size:13px;">No sales data for this period.</p><?php endif; ?>
              </div>

              <div class="card">
                <div style="font-size:14px; font-weight:700; color:var(--espresso); margin-bottom:14px;">Sales by Employee</div>
                <?php
                $emp_sales = $conn->query("SELECT e.full_name, COUNT(s.transaction_id) as cnt, COALESCE(SUM(s.total_amount),0) as rev
                                   FROM sales s JOIN employees e ON s.employee_id=e.employee_id
                                   WHERE $r_where AND s.status='Completed'
                                   GROUP BY s.employee_id ORDER BY rev DESC");
                $emp_rows  = $emp_sales ? $emp_sales->fetch_all(MYSQLI_ASSOC) : [];
                if (!empty($emp_rows)): ?>
                  <table>
                    <thead>
                      <tr>
                        <th>Employee</th>
                        <th>Transactions</th>
                        <th>Revenue</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($emp_rows as $r): ?>
                        <tr>
                          <td style="font-weight:600; color:var(--espresso);"><?= htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                          <td><?= $r['cnt'] ?></td>
                          <td style="color:var(--green); font-weight:600;">&#8369;<?= number_format($r['rev'], 2) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php else: ?><p style="color:var(--text-3); font-size:13px;">No sales data for this period.</p><?php endif; ?>
              </div>
            </div>

            <div class="card">
              <div class="table-header-with-search">
                <div class="section-title">Recent Transactions</div>
                <div class="search-field">
                  <input type="text" id="recent_transactions_search" placeholder="Search Order ID, Date & Time, Cashier">
                </div>
              </div>
              <div class="tbl-wrap">
                <table id="recent_transactions_table">
                  <thead>
                    <tr>
                      <th>Order ID</th>
                      <th>Date &amp; Time</th>
                      <th>Cashier</th>
                      <th>Payment</th>
                      <th>Amount</th>
                      <th>Status</th>
                      <th style="text-align:center;">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php $r_sales_rows = $r_sales ? $r_sales->fetch_all(MYSQLI_ASSOC) : [];
if (!empty($r_sales_rows)):
  foreach ($r_sales_rows as $s):
    $sku_esc = $conn->real_escape_string($s['transaction_id']);
    // Fetch sale items with product names for details view
    $items_res = $conn->query("SELECT si.sku, si.quantity, si.price_at_time, si.subtotal, COALESCE(i.product_name, '') as product_name FROM sale_items si LEFT JOIN inventory i ON si.sku=i.sku WHERE si.transaction_id='$sku_esc'");
    $items_array = [];
    if ($items_res) {
      while ($it = $items_res->fetch_assoc()) {
        $items_array[] = $it;
      }
    }
    // ── Order ID = same value the Checkout page shows: #1000 + nth completed sale of that day
    $sale_day_esc  = $conn->real_escape_string(date('Y-m-d', strtotime($s['sale_date'])));
    $sale_date_esc = $conn->real_escape_string($s['sale_date']);
    $txn_esc       = $conn->real_escape_string($s['transaction_id']);
    $pos_row = $conn->query("SELECT COUNT(*) as n FROM sales WHERE DATE(sale_date)='$sale_day_esc' AND status='Completed' AND (sale_date < '$sale_date_esc' OR (sale_date = '$sale_date_esc' AND transaction_id <= '$txn_esc'))")->fetch_assoc();
    $order_id_display = '#' . (1000 + (int)($pos_row ? $pos_row['n'] : 1));
    // Prepare details payload including conditional wallet fields (if present in DB)
    $details = $s;
    $details['items'] = $items_array;
    ?>
    <tr>
      <td><span style="font-family:monospace; font-size:12px; color:var(--chestnut); font-weight:600;"><?= htmlspecialchars($order_id_display, ENT_QUOTES, 'UTF-8') ?></span></td>
 <td style="color:var(--text-3); font-size:12px;"><?= date('d M Y, h:i A', strtotime($s['sale_date'])) ?></td>
                          <td style="font-weight:500;"><?= htmlspecialchars($s['cashier'] ?? '&#8212;', ENT_QUOTES, 'UTF-8') ?></td>
                          <td><span class="badge badge-gray"><?= htmlspecialchars($s['payment_method'], ENT_QUOTES, 'UTF-8') ?></span></td>
                          <td style="font-weight:700; color:var(--espresso);">&#8369;<?= number_format($s['total_amount'], 2) ?></td>
                          <td><span class="badge <?= $s['status'] === 'Completed' ? 'badge-green' : 'badge-amber' ?>"><?= $s['status'] ?></span></td>
                          <td style="text-align:center;"><button type="button" class="btn btn-sm btn-secondary" onclick="viewTransactionDetails(<?= htmlspecialchars(json_encode($details), ENT_QUOTES, 'UTF-8') ?>)" style="padding:6px 12px; font-size:12px;">View Details</button></td>
                        </tr>
                      <?php endforeach;
                    else: ?>
                      <tr>
                        <td colspan="7" style="text-align:center; color:var(--text-3); padding:28px;">No transactions for this period.</td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        <?php endif; ?>
      </main>

    </div>

  <?php endif; ?>

  <!-- Transaction Details Modal -->
  <div class="overlay" id="transactionDetailModal">
    <div class="modal-box" style="width:750px;">
      <div class="modal-header">
        <span class="modal-title">Transaction Details</span>
        <button class="modal-close" type="button" onclick="closeTransactionModal()">&times;</button>
      </div>
      <div class="modal-body" style="padding:16px;">
        <div class="receipt" id="td_receipt" style="max-width:100%;">
          <div class="receipt-title">Bloom POS - Transaction</div>
          <div id="td_meta" style="font-size:12px;color:var(--text-3);margin-bottom:8px;"></div>
          <hr class="receipt-sep">
          <div id="td_items"></div>
          <hr class="receipt-sep">
          <div class="receipt-row"><span>Total Due</span><span id="td_total">&#8369;0.00</span></div>
          <div class="receipt-row"><span>Mode of Payment</span><span id="td_payment_method">&#8212;</span></div>
          <div class="receipt-row" id="td_amount_received_row" style="display:none;"><span>Amount Received</span><span id="td_amount_received">&#8369;0.00</span></div>
          <div class="receipt-row" id="td_change_row" style="display:none;"><span>Change</span><span id="td_change">&#8369;0.00</span></div>
          <div id="td_wallet_area" style="display:none;margin-top:12px;">
            <div class="receipt-row"><span id="td_wallet_contact_label">Contact Number</span><span id="td_wallet_contact">&#8212;</span></div>
            <div class="receipt-row"><span>Account Name</span><span id="td_wallet_account">&#8212;</span></div>
            <div style="margin-top:8px;"><a id="td_proof_link" href="#" target="_blank" style="display: inline-block; margin-top: 10px;">
            <img id="td_proof_img" src="" alt="Proof of Payment" style="display: none; max-width: 220px; max-height: 350px; width: auto; height: auto; object-fit: contain; border-radius: 8px; border: 1px solid #ddd; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: transform 0.2s;">
          </a></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    function viewTransactionDetails(details) {
      try {
        // details is an object passed from PHP
        const d = details || {};
        const metaEl = document.getElementById('td_meta');
        metaEl.textContent = (d.transaction_id ? ('Transaction: ' + d.transaction_id + ' · ') : '') + (d.sale_date ? new Date(d.sale_date).toLocaleString() : '');

        const itemsEl = document.getElementById('td_items');
        itemsEl.innerHTML = '';
        if (Array.isArray(d.items) && d.items.length) {
          d.items.forEach(it => {
            const name = it.product_name && it.product_name !== '' ? it.product_name : it.sku;
            const qty = parseInt(it.quantity || 0, 10) || 0;
            const subtotal = parseFloat(it.subtotal || 0) || 0;
            const row = `<div class="receipt-row"><span>${qty}× ${name}</span><span>&#8369;${subtotal.toFixed(2)}</span></div>`;
            itemsEl.insertAdjacentHTML('beforeend', row);
          });
        }

        document.getElementById('td_total').textContent = '\u20B1' + (parseFloat(d.total_amount || 0).toFixed(2));

        // Show mode of payment
        const tdPmEl = document.getElementById('td_payment_method');
        if (tdPmEl) tdPmEl.textContent = d.payment_method ? d.payment_method : 'Cash';

        if ((d.payment_method || '').toLowerCase() === 'cash') {
          // Show amount received and change using flex display to preserve receipt row layout
          document.getElementById('td_wallet_area').style.display = 'none';
          document.getElementById('td_amount_received_row').style.display = 'flex';
          document.getElementById('td_change_row').style.display = 'flex';
          const amt = parseFloat(d.amount_tendered || d.amountReceived || 0) || 0;
          document.getElementById('td_amount_received').textContent = '\u20B1' + amt.toFixed(2);
          const change = amt - (parseFloat(d.total_amount || 0) || 0);
          document.getElementById('td_change').textContent = '\u20B1' + (change >= 0 ? change.toFixed(2) : '0.00');
        } else {
          // Digital wallet: show wallet fields
          document.getElementById('td_amount_received_row').style.display = 'none';
          document.getElementById('td_change_row').style.display = 'none';
          document.getElementById('td_wallet_area').style.display = 'block';
          // Set label depending on method
          const labelEl = document.getElementById('td_wallet_contact_label');
          const method = (d.payment_method || '').toString().toLowerCase();
          if (labelEl) {
            if (method === 'gcash') labelEl.textContent = 'Reference Number';
            else if (method === 'maya') labelEl.textContent = 'Reference ID';
            else labelEl.textContent = 'Contact Number';
          }
          document.getElementById('td_wallet_contact').textContent = d.wallet_contact_number || d.wallet_contact || d.wallet_contact_number || '—';
          document.getElementById('td_wallet_account').textContent = d.wallet_account_name || d.wallet_account || '—';
          const proof = d.wallet_proof_image_url || d.wallet_proof_url || '';
          const img = document.getElementById('td_proof_img');
          const link = document.getElementById('td_proof_link');
          if (proof) {
            img.src = proof;
            img.style.display = 'inline-block';
            link.href = proof;
          } else {
            img.style.display = 'none';
            link.href = '#';
          }
        }

        const overlay = document.getElementById('transactionDetailModal');
        if (overlay) overlay.classList.add('open');
      } catch (err) {
        console.error('viewTransactionDetails error', err);
      }
    }

    function closeTransactionModal() {
      const el = document.getElementById('transactionDetailModal');
      if (el) el.classList.remove('open');
    }

    function filterRecentTransactions() {
      var searchInput = document.getElementById('recent_transactions_search');
      var query = '';
      if (searchInput) {
        query = searchInput.value.trim().toLowerCase();
      }
      var rows = document.querySelectorAll('#recent_transactions_table tbody tr');
      rows.forEach(function(row) {
        var orderId = row.cells[0] && row.cells[0].textContent ? row.cells[0].textContent.trim().toLowerCase() : '';
        var dateTime = row.cells[1] && row.cells[1].textContent ? row.cells[1].textContent.trim().toLowerCase() : '';
        var cashier = row.cells[2] && row.cells[2].textContent ? row.cells[2].textContent.trim().toLowerCase() : '';
        var isVisible = query === '' || orderId.indexOf(query) !== -1 || dateTime.indexOf(query) !== -1 || cashier.indexOf(query) !== -1;
        row.style.display = isVisible ? '' : 'none';
      });
    }

    document.addEventListener('DOMContentLoaded', function() {
      const searchInput = document.getElementById('recent_transactions_search');
      if (searchInput) {
        searchInput.addEventListener('input', filterRecentTransactions);
      }
    });
  </script>

</body>

</html>
