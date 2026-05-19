<?php
session_start();

// Include session handler
require_once 'session.php';

// ── Cookies: Remember Me ─────────────────────────────────────
$remembered_id = isset($_COOKIE['bloom_remember_id']) //isset() check to prevent undefined index notice if cookie doesn't exist
  ? htmlspecialchars($_COOKIE['bloom_remember_id'], ENT_QUOTES, 'UTF-8')
  : '';
// ── Auto-create uploads folder ───────────────────────────────
if (!is_dir("uploads")) {
  mkdir("uploads", 0777, true);
}

date_default_timezone_set("Asia/Manila"); // Set timezone to Manila

require_once __DIR__ . '/Inventory.inc.php';

$sampleProducts = [
  'flowers'     => new FlowerProduct('FLW-001', 'Red Roses', 99.00, 50),
  'arrangement' => new ArrangementProduct('ARR-001', 'Bridal Bouquet', 599.00, 10),
  'plant'       => new PlantProduct('PLT-001', 'Peace Lily', 199.00, 25),
  'accessory'   => new AccessoryProduct('ACC-001', 'Crystal Vase', 349.00, 15),
];
// echo $sampleProducts['flowers']; → "Red Roses (FLW-001) - ₱99.00 | Stock: 50 [Flowers]"

$auth_error = "";
$reg_error  = "";

// ── Array ────────────────────────────────────────────────────
$store_info = array( // associative array for store details
  "name"     => "Bloom POS",
  "address"  => "Calamba, Laguna",
  "contact"  => "0912-345-6789",
  "tax_rate" => 0.12
);
// str_getcsv() — parse a CSV string of accepted payment methods into an array
$accepted_payments = str_getcsv("Cash,GCash,Maya,Credit/Debit Card");
$paymentsRef       = &$accepted_payments;           // Reference variable (from index.php)
$paymentKeys       = array_keys($accepted_payments); // array_keys() from index.php

// ── Route ────────────────────────────────────────────────────
$page = isset($_GET["page"]) ? $_GET["page"] : "login";
echo "<!-- [Event] page=' " . htmlspecialchars($page, ENT_QUOTES, 'UTF-8') . "' -->\n";

// ── Database ─────────────────────────────────────────────────
$conn = new mysqli('127.0.0.1', 'root', '', 'bloom_pos');
if ($conn->connect_error) {
  echo "DB connection failed: " . $conn->connect_error;
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
  $conn->query("CREATE TABLE customer_approval_history (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, action VARCHAR(32) NOT NULL, by_employee_id VARCHAR(50) NULL, note VARCHAR(255) NULL, ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

if (!isLoggedIn() && $page !== "login" && $page !== "register") {
  header("Location: ?page=login");
  exit;
}
if (isLoggedIn() && $page === "login") {
  header("Location: ?page=dashboard");
  exit;
}

// ── RBAC ─────────────────────────────────────────────────────
// Note: 'crm' is intentionally NOT restricted so Cashiers can access Customer module
$restricted_to_admin = ["inventory", "employees", "reports"];
if (isLoggedIn() && $_SESSION["user_role"] !== "Admin" && in_array($page, $restricted_to_admin)) {
  header("Location: ?page=dashboard");
  exit;
}

// ── Login ─────────────────────────────────────────────────────
if ($page === "login" && $_SERVER["REQUEST_METHOD"] === "POST") {
  $emp_id   = isset($_POST["emp_id"])   ? trim($_POST["emp_id"])  : ""; //trim() to remove extra whitespace from employee ID input
  $passcode = isset($_POST["passcode"]) ? $_POST["passcode"]      : "";
  // ── UPDATED: also fetch photo_url ──
  $stmt = $conn->prepare("SELECT employee_id, full_name, role, passcode, photo_url FROM employees WHERE employee_id = ?");
  $stmt->bind_param("s", $emp_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  if ($row && strcmp($row["passcode"], $passcode) === 0) {  // strcmp() for exact string comparison of passcodes
    // Initialize session on successful login
    initializeSession(
      $row["employee_id"],
      $row["full_name"],
      $row["role"],
      isset($row["photo_url"]) ? $row["photo_url"] : ""
    );
    header("Location: ?page=dashboard");
    exit;
  }
  if (isset($_POST['remember_me'])) {
    setcookie('bloom_remember_id', $emp_id, time() + (30 * 24 * 60 * 60), '/'); // 30 days
  } else {
    setcookie('bloom_remember_id', '', time() - 3600, '/'); // delete it
  }
  $auth_error = "Invalid Employee ID or passcode.";
}

// ── Register ──────────────────────────────────────────────────

if ($page === "register" && $_SERVER["REQUEST_METHOD"] === "POST") {
  $emp_id   = isset($_POST["emp_id"])    ? trim($_POST["emp_id"])    : "";
  $name     = isset($_POST["full_name"]) ? trim($_POST["full_name"]) : "";
  $role     = isset($_POST["role"])      ? $_POST["role"]            : "Cashier";
  $passcode = isset($_POST["passcode"])  ? $_POST["passcode"]        : "";
  $job_role = (strcasecmp($role, "Admin") === 0) ? "Manager" : "Cashier"; // strcasecmp() for case-insensitive role check

  $nameCheck = validateStaffName($name);
  if (!is_bool($nameCheck) || $nameCheck !== true) {
    $reg_error = is_bool($nameCheck) ? "Invalid name." : $nameCheck;
  } elseif (!preg_match("/^[a-zA-Z0-9-]+$/", $emp_id)) { //preg_match() to validate employee ID format (only letters, numbers, hyphens)
    $reg_error = "Employee ID may only contain letters, numbers, and hyphens.";
  } else {
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
      header("Location: ?page=login&registered=1");
      exit;
    } else {
      $reg_error = "Employee ID already exists.";
    }
  }
}

// ── Logout ────────────────────────────────────────────────────
if ($page === "logout") {
  setcookie('bloom_remember_id', '', time() - 3600, '/'); // ← delete cookie
  destroySession();
  header("Location: ?page=login");
  exit;
}

// ── Checkout – Finalize Sale ──────────────────────────────────
// ── Self-Profile Update (any logged-in user) ─────────────────
if (isLoggedIn() && isset($_POST["update_my_profile"])) {
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

  $cart_subtotal = 0;
  for ($i = 0; $i < count($cart_data); $i++) {
    $cart_subtotal += $cart_data[$i]["price"] * $cart_data[$i]["qty"];
  }
  $saleCalc      = calcSaleTotal($cart_subtotal, $discount_amount);

  $stmt = $conn->prepare("INSERT INTO sales (transaction_id,sale_date,total_amount,tax_amount,discount_amount,payment_method,amount_tendered,status,employee_id,customer_id) VALUES (?,?,?,?,?,?,?,'Completed',?,?)");
  $stmt->bind_param("ssdddsdsi", $transaction_id, $sale_date, $total_amount, $tax_amount, $discount_amount, $payment_method, $amount_tendered, $employee_id, $customer_id);
  if ($stmt->execute()) {
    foreach ($cart_data as $item) {
      $sku   = $conn->real_escape_string($item["sku"]);
      $qty   = (int)$item["qty"];
      $price = floatval($item["price"]);
      $sub   = $price * $qty;
      $conn->query("INSERT INTO sale_items (transaction_id,sku,quantity,price_at_time,subtotal) VALUES ('$transaction_id','$sku',$qty,$price,$sub)");
      $conn->query("UPDATE inventory SET stock_qty = stock_qty - $qty WHERE sku = '$sku' AND stock_qty >= $qty");
    }
    if ($customer_id) {
      $pts = (int)floor($total_amount / 100);
      if (is_int($pts) && $pts > 0) {
        $conn->query("UPDATE customers SET loyalty_points = loyalty_points + $pts WHERE customer_id = $customer_id");
      }
    }
    header("Location: ?page=checkout&success=1&trx=$transaction_id");
    exit;
  }
}



// ── Inventory CRUD ────────────────────────────────────────────
if ($page === "inventory" && $_SESSION["user_role"] === "Admin") {
  if (isset($_POST["add_product"]) || isset($_POST["update_product"])) {
    $is_update  = isset($_POST["update_product"]);
    $sku        = $conn->real_escape_string(isset($_POST["sku"])          ? $_POST["sku"]          : "");
    $old_sku    = $conn->real_escape_string(isset($_POST["old_sku"])      ? $_POST["old_sku"]      : $sku);
    $name       = $conn->real_escape_string(isset($_POST["name"])         ? $_POST["name"]         : "");
    $price      = floatval(str_replace(",", "", isset($_POST["price"])    ? $_POST["price"]        : "0"));

    if (!is_float($price)) {
      $price = 0.0;
    }

    $qty        = (int)(isset($_POST["qty"])                              ? $_POST["qty"]          : 0);
    $cat_id     = (isset($_POST["category_id"]) && $_POST["category_id"] !== "") ? (int)$_POST["category_id"] : null;
    $disc_id    = (isset($_POST["discount_id"])  && $_POST["discount_id"]  !== "") ? (int)$_POST["discount_id"]  : null;
    $image_path = "";
    if (isset($_FILES["product_image"]) && $_FILES["product_image"]["error"] === 0) {
      $dir = "uploads/";
      if (!is_dir($dir)) mkdir($dir, 0777, true);
      $image_path = $dir . time() . "_" . basename($_FILES["product_image"]["name"]);
      move_uploaded_file($_FILES["product_image"]["tmp_name"], $image_path);
    }

    if ($is_update) {
      if ($image_path) {
        $stmt = $conn->prepare("UPDATE inventory SET sku = ?, product_name = ?, price = ?, stock_qty = ?, category_id = ?, image_url = ? WHERE sku = ?");
        $stmt->bind_param("ssdiiss", $sku, $name, $price, $qty, $cat_id, $image_path, $old_sku);
      } else {
        $stmt = $conn->prepare("UPDATE inventory SET sku = ?, product_name = ?, price = ?, stock_qty = ?, category_id = ? WHERE sku = ?");
        $stmt->bind_param("ssdiis", $sku, $name, $price, $qty, $cat_id, $old_sku);
      }
    } else {
      $stmt = $conn->prepare("INSERT INTO inventory (sku, product_name, price, stock_qty, category_id, image_url) VALUES (?, ?, ?, ?, ?, ?)");
      $stmt->bind_param("ssdiss", $sku, $name, $price, $qty, $cat_id, $image_path);
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
    $original_sku = $conn->real_escape_string($_POST["original_sku"]);
    $new_sku      = $conn->real_escape_string($_POST["new_sku"]);
    $variant_name = $conn->real_escape_string($_POST["variant_name"]);
    $variant_qty  = (int)$_POST["variant_qty"];

    // FIXED: Now calling the accurate function name using the __clone engine
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
    $d_type   = $conn->real_escape_string(isset($_POST["d_type"])   ? $_POST["d_type"]   : "Percentage");
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
}

$activeTab = isset($_GET["tab"]) ? $_GET["tab"] : "items";
echo "<!-- [Event] activeTab='" . htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') . "' -->\n";

// ══ HELPER FUNCTIONS ═════════════════════════════════════════

function effectivePrice($item) //function to calculate effective price of an item after applying discount if applicable
{
  $p = floatval($item["price"]);
  if (!empty($item["disc_status"]) && $item["disc_status"] == 1 && !empty($item["discount_value"])) {
    switch ($item["discount_type"]) {
      case "Percentage":
        return $p * (1 - $item["discount_value"] / 100);
      case "Fixed":
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
  if (!preg_match("/^[a-zA-Z ]*$/", $name)) return "Name must contain letters and spaces only.";
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
      padding: 26px 22px 20px;
      border-bottom: 1px solid rgba(212, 188, 169, .18);
      background: linear-gradient(180deg, rgba(124, 90, 68, .25) 0%, transparent 100%);
    }

    .sb-brand-name {
      font-size: 17px;
      font-weight: 800;
      color: var(--taupe-l);
      letter-spacing: -.3px;
    }

    .sb-brand-sub {
      font-size: 12px;
      color: var(--taupe);
      margin-top: 3px;
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

    .sb-link svg {
      width: 17px;
      height: 17px;
      opacity: .85;
      flex-shrink: 0;
    }

    .sb-link.active svg {
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
    }

    .inv-card-img img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      padding: 6px;
      transition: transform .3s ease;
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

      .checkout-wrap {
        flex-direction: column;
        height: auto;
        overflow: auto;
      }

      .co-right {
        width: 100%;
        border-left: none;
        border-top: 1.5px solid var(--taupe);
      }

      .prod-grid {
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
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
        <?php if ($auth_error !== ''): ?><div class="auth-err"><?= htmlspecialchars($auth_error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
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
        <p style="text-align:center; margin-top:16px; font-size:12.5px; color:var(--text-3);">
          New employee? <a href="?page=register" style="color:var(--chestnut); font-weight:600;">Create account</a>
        </p>
      </div>
    </div>



  <?php elseif ($page === 'register'): ?>
    <div class="auth-wrap">
      <div class="auth-box" style="width:420px;">
        <div class="auth-logo">Bloom POS</div>
        <div class="auth-sub">Register Staff Account</div>
        <?php if ($reg_error !== ''): ?><div class="auth-err"><?= htmlspecialchars($reg_error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form method="POST" action="?page=register" enctype="multipart/form-data">
          <div class="form-row">
            <div class="form-group"><label>Employee ID</label><input type="text" name="emp_id" placeholder="EMP-003" required></div>
            <div class="form-group"><label>Role</label>
              <select name="role">
                <option value="Cashier">Cashier</option>
                <option value="Admin">Admin</option>
              </select>
            </div>
          </div>
          <div class="form-group"><label>Full Name</label><input type="text" name="full_name" placeholder="Full Name" required></div>
          <div class="form-group"><label>Passcode</label><input type="password" name="passcode" placeholder="Choose a passcode" required></div>
          <div class="form-group">
            <label>Profile Photo <span style="color:var(--text-3); font-weight:400; text-transform:none;">(optional)</span></label>
            <input type="file" name="emp_photo" accept="image/*" style="padding:6px;">
          </div>
          <button type="submit" class="btn btn-primary btn-full btn-lg" style="margin-top:8px;">Create Account</button>
        </form>
        <p style="text-align:center; margin-top:16px; font-size:12.5px; color:var(--text-3);">
          Already have an account? <a href="?page=login" style="color:var(--chestnut); font-weight:600;">Sign in</a>
        </p>
      </div>
    </div>

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

        <div class="cat-filter" id="cat-filter">
          <span class="cat-pill active" data-cat="">All</span>
          <?php foreach ($cats as $c): ?>
            <span class="cat-pill" data-cat="<?= htmlspecialchars($c['category_name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c['category_name'], ENT_QUOTES, 'UTF-8') ?></span>
          <?php endforeach; ?>
        </div>

        <?php if (isset($_GET['success'])): ?>
          <div class="alert alert-success" style="flex-shrink:0;">
            Sale <strong><?php echo htmlspecialchars(isset($_GET['trx']) ? $_GET['trx'] : '', ENT_QUOTES, 'UTF-8'); ?></strong> completed successfully.
          </div>
        <?php endif; ?>

        <div class="co-products">
          <div class="prod-grid" id="prod-grid">
            <?php foreach ($inventory as $item):
              $ep = effectivePrice($item);
              $hasDisc = $ep < $item['price'];
            ?>
              <div class="prod-tile"
                data-cat="<?= htmlspecialchars($item['category_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                data-name="<?= strtolower(htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8')) ?>"
                onclick="addToCart(<?= htmlspecialchars(json_encode([
                                      'sku'          => $item['sku'],
                                      'product_name' => $item['product_name'],
                                      'price'        => $ep,
                                      'stock_qty'    => $item['stock_qty'],
                                    ]), ENT_QUOTES, 'UTF-8') ?>)">
                <div class="prod-img">
                  <?php if (!empty($item['image_url'])): ?>
                    <img src="<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                  <?php else: ?>
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#D4BCA9" stroke-width="1.5">
                      <rect x="3" y="3" width="18" height="18" rx="2" />
                      <circle cx="8.5" cy="8.5" r="1.5" />
                      <polyline points="21 15 16 10 5 21" />
                    </svg>
                  <?php endif; ?>
                </div>
                <div class="prod-info">
                  <div class="prod-name"><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="prod-price">
                    &#8369;<?= number_format($ep, 2) ?>
                    <?php if ($hasDisc): ?><span style="font-size:10px; text-decoration:line-through; color:var(--text-3); margin-left:3px;">&#8369;<?= number_format($item['price'], 2) ?></span><?php endif; ?>
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
            <select id="customer_select" onchange="calcTotals()" style="font-size:12px; padding:6px 10px; width:100%;">
              <option value="">Walk-in Customer</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= $c['customer_id'] ?>"><?= htmlspecialchars($c['full_name'], ENT_QUOTES, 'UTF-8') ?> (<?= $c['loyalty_points'] ?> pts)</option>
              <?php endforeach; ?>
            </select>
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
              <option value="0" data-type="">None</option>
              <?php foreach ($active_promos as $p): ?>
                <option value="<?= $p['discount_value'] ?>" data-type="<?= $p['discount_type'] ?>"><?= htmlspecialchars($p['discount_name'], ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="tot-row"><span>Discount</span><span id="d_discount" style="color:var(--green);">&#8212;</span></div>
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
        <form method="POST" action="?page=checkout" id="sale_form">
          <input type="hidden" name="finalize_sale" value="1">
          <input type="hidden" name="cart_json" id="f_cart">
          <input type="hidden" name="final_total" id="f_total">
          <input type="hidden" name="tax_amount" id="f_tax">
          <input type="hidden" name="discount_amount" id="f_disc">
          <input type="hidden" name="customer_id" id="f_customer">
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
                <div class="receipt-row"><span>Discount</span><span id="r_disc">&#8369;0.00</span></div>
                <div class="receipt-row"><span>VAT 12%</span><span id="r_tax">&#8369;0.00</span></div>
                <hr class="receipt-sep">
                <div class="receipt-row" style="font-weight:700; font-size:14px;"><span>TOTAL</span><span id="r_total">&#8369;0.00</span></div>
                <hr class="receipt-sep">
                <div class="receipt-row"><span>Change</span><span id="r_change">&#8212;</span></div>
              </div>
            </div>
            <div>
              <div class="form-group">
                <label>Payment Method</label>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:6px;">
                  <?php foreach (['Cash', 'Card', 'GCash', 'Maya'] as $pm): ?>
                    <label style="display:flex; align-items:center; gap:8px; padding:10px 14px; border:1.5px solid var(--taupe); border-radius:var(--radius); cursor:pointer; font-size:13px; color:var(--text);">
                      <input type="radio" name="payment_method" value="<?= $pm ?>" <?= $pm === 'Cash' ? 'checked' : '' ?> onchange="toggleCashField(<?= $pm === 'Cash' ? 'true' : 'false' ?>)"> <?= $pm ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <div id="cash_area" class="form-group">
                <label>Amount Tendered</label>
                <input type="text" name="amount_tendered" id="amount_tendered" placeholder="0.00" oninput="fmtCash(this); calcChange();">
              </div>
              <div style="background:var(--taupe-l); border-radius:var(--radius); padding:14px 16px; margin-bottom:16px; border:1px solid var(--taupe);">
                <div style="font-size:11px; color:var(--chestnut-d); text-transform:uppercase; letter-spacing:.06em; font-weight:700; margin-bottom:4px;">Total Due</div>
                <div id="modal_total" style="font-size:28px; font-weight:800; color:var(--chestnut);">&#8369;0.00</div>
              </div>
              <button type="submit" class="btn btn-primary btn-full btn-lg">Confirm &amp; Complete Sale</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <script>
      const TAX_RATE = 0.12;
      let cart = [];
      const STORE_INFO = <?= json_encode($store_info, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const allProducts = <?= json_encode($inventory) ?>;

      function addToCart(p) {
        if (p.stock_qty <= 0) {
          toast('Out of stock!', 'red');
          return;
        }
        const ex = cart.find(i => i.sku === p.sku);
        if (ex) {
          if (ex.qty >= p.stock_qty) {
            toast('Max stock reached', 'amber');
            return;
          }
          ex.qty++;
        } else {
          cart.push({
            sku: p.sku,
            name: p.product_name,
            price: parseFloat(p.price),
            qty: 1,
            stock: p.stock_qty
          });
        }
        renderCart();
        toast(p.product_name + ' added');
      }

      function renderCart() {
        const list = document.getElementById('cart_list');
        if (cart.length === 0) {
          list.innerHTML = '<div class="cart-empty"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.25;"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg><div style="font-size:13px;font-weight:500;">Basket is empty</div><div style="font-size:12px;">Select products to add</div></div>';
          document.getElementById('cart_count').textContent = '0 items';
          calcTotals();
          return;
        }
        let html = '';
        cart.forEach((item, i) => {
          html += `<div class="cart-item">
      <div class="ci-name">
        <div class="ci-pname">${item.name}</div>
        <div class="ci-sku">${item.sku}</div>
      </div>
      <div class="ci-qty">
        <button class="qty-btn" onclick="chgQty(${i},-1)">&#8722;</button>
        <span style="font-size:13px;font-weight:700;min-width:22px;text-align:center;">${item.qty}</span>
        <button class="qty-btn" onclick="chgQty(${i},1)">&#43;</button>
      </div>
      <div class="ci-total">&#8369;${(item.price*item.qty).toLocaleString(undefined,{minimumFractionDigits:2})}</div>
      <button class="ci-del" onclick="rmItem(${i})">&#215;</button>
    </div>`;
        });
        list.innerHTML = html;
        document.getElementById('cart_count').textContent = cart.reduce((s, i) => s + i.qty, 0) + ' items';
        calcTotals();
      }

      function chgQty(i, d) {
        const newQty = cart[i].qty + d;
        if (d > 0 && newQty > cart[i].stock) {
          toast('Max stock reached', 'amber');
          return;
        }
        if (newQty <= 0) {
          cart.splice(i, 1);
        } else {
          cart[i].qty = newQty;
        }
        renderCart();
      }

      function rmItem(i) {
        cart.splice(i, 1);
        renderCart();
      }

      function voidCart() {
        if (!cart.length) return;
        showConfirm('Clear current basket?').then(function(ok) {
          if (ok) {
            cart = [];
            renderCart();
          }
        });
      }

      function holdSale() {
        if (cart.length) {
          sessionStorage.setItem('held_cart', JSON.stringify(cart));
          cart = [];
          renderCart();
          toast('Sale held');
        }
      }

      function recallHeld() {
        const h = sessionStorage.getItem('held_cart');
        if (h) {
          cart = JSON.parse(h);
          sessionStorage.removeItem('held_cart');
          renderCart();
          toast('Sale recalled');
        } else toast('No held sale', 'amber');
      }

      function calcTotals() {
        const sub = cart.reduce((s, i) => s + i.price * i.qty, 0);
        const sel = document.getElementById('promo_select');
        const pval = parseFloat(sel.value) || 0;
        const ptype = sel.options[sel.selectedIndex].dataset.type;
        let disc;
        switch (ptype) {
          case 'Percentage':
            disc = sub * (pval / 100);
            break;
          case 'Fixed':
            disc = Math.min(pval, sub);
            break;
          default:
            disc = 0;
        }
        const taxable = sub - disc,
          tax = taxable * TAX_RATE,
          total = taxable + tax;
        const fmt = v => '&#8369;' + v.toLocaleString(undefined, {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        });
        document.getElementById('d_subtotal').innerHTML = fmt(sub);
        document.getElementById('d_discount').innerHTML = disc > 0 ? '-' + fmt(disc) : '&#8212;';
        document.getElementById('d_tax').innerHTML = fmt(tax);
        document.getElementById('d_total').innerHTML = fmt(total);
        document.getElementById('modal_total').innerHTML = fmt(total);
        document.getElementById('r_sub').innerHTML = fmt(sub);
        document.getElementById('r_disc').innerHTML = fmt(disc);
        document.getElementById('r_tax').innerHTML = fmt(tax);
        document.getElementById('r_total').innerHTML = fmt(total);
        document.getElementById('f_total').value = total.toFixed(2);
        document.getElementById('f_tax').value = tax.toFixed(2);
        document.getElementById('f_disc').value = disc.toFixed(2);
        document.getElementById('f_cart').value = JSON.stringify(cart);
        document.getElementById('f_customer').value = document.getElementById('customer_select').value;
      }

      function openPayModal() {
        if (!cart.length) {
          toast('Basket is empty', 'amber');
          return;
        }
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
          .receipt-row{display:flex;justify-content:space-between;margin:6px 0;font-size:13px;}
          .receipt-row span{display:inline-block;}
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
        saleForm.addEventListener('submit', function() {
          updateReceipt();
          printReceiptAuto();
        });
      }

      function updateReceipt() {
        document.getElementById('r_items').innerHTML = cart.map(i => `<div class="receipt-row"><span>${i.qty}&times; ${i.name}</span><span>&#8369;${(i.price*i.qty).toFixed(2)}</span></div>`).join('');
        calcTotals();
      }

      function toggleCashField(show) {
        document.getElementById('cash_area').style.display = show ? 'block' : 'none';
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
        document.getElementById('r_change').innerHTML = change >= 0 ? '&#8369;' + change.toLocaleString(undefined, {
          minimumFractionDigits: 2
        }) : '&#8212;';
      }

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
          el.style.display = (!cat || el.dataset.cat === cat) ? '' : 'none';
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
                .receipt-row{display:flex;justify-content:space-between;margin:6px 0;font-size:13px;}
                .receipt-row span{display:inline-block;}
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
    $initials   = makeInitials($_SESSION['user_name']);
    $user_photo = isset($_SESSION['user_photo']) ? $_SESSION['user_photo'] : '';
    $is_admin   = $_SESSION['user_role'] === 'Admin';
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
          <div class="sb-brand-name">Bloom POS</div>
          <div class="sb-brand-sub">Flower Shop System</div>
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
                  alt="<?= htmlspecialchars($_SESSION['user_name'], ENT_QUOTES, 'UTF-8') ?>">
              <?php else: ?>
                <?= $initials ?>
              <?php endif; ?>
            </div>
            <div class="sb-user-info">
              <div class="sb-user-name"><?= htmlspecialchars($_SESSION['user_name'], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="sb-user-role"><?= htmlspecialchars($_SESSION['user_role'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars($_SESSION['user_id'], ENT_QUOTES, 'UTF-8') ?></div>
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
                <div style="font-size:16px; font-weight:700; color:var(--espresso);"><?= htmlspecialchars($_SESSION['user_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <div style="font-size:12px; color:var(--text-3); margin-top:3px;">
                  <?= htmlspecialchars($_SESSION['user_id'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars($_SESSION['user_role'], ENT_QUOTES, 'UTF-8') ?>
                </div>
              </div>
            </div>

            <form method="POST" action="?page=<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data">
              <input type="hidden" name="return_page" value="<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>">

              <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" value="<?= htmlspecialchars($_SESSION['user_name'], ENT_QUOTES, 'UTF-8') ?>" required>
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
                    <tr>
                      <td colspan="4" style="text-align:center; padding:24px; color:var(--text-3); font-style:italic;">No login records to display yet.</td>
                    </tr>
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
      </nav>

      <main class="main">
        <?php

        // ── DASHBOARD ────────────────────────────────────────────────
        if ($page === 'dashboard'): ?>
          <div class="page">
            <div class="page-header">
              <div>
                <div class="page-title">Dashboard</div>
                <div class="page-sub"><?php echo date('l, d F Y'); ?> &middot; <?php echo $_SESSION['user_role']; ?> view</div>
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

            <div class="tabs">
              <a href="?page=inventory&tab=items" class="tab-link <?= $activeTab === 'items' ? 'active' : '' ?>">Products</a>
              <a href="?page=inventory&tab=categories" class="tab-link <?= $activeTab === 'categories' ? 'active' : '' ?>">Categories</a>
              <a href="?page=inventory&tab=discounts" class="tab-link <?= $activeTab === 'discounts' ? 'active' : '' ?>">Discounts</a>
            </div>

            <!-- Products -->
            <div class="tab-pane <?= $activeTab === 'items' ? 'active' : '' ?>">
              <?php if (empty($inventory)): ?>
                <div class="card" style="text-align:center; padding:40px; color:var(--text-3);">No products yet. Click <strong>Add Product</strong> to get started.</div>
              <?php else: ?>
                <div class="inv-grid">
                  <?php foreach ($inventory as $item):
                    $ep = effectivePrice($item);
                    $hasDisc = $ep < $item['price'];
                  ?>
                    <div class="inv-card" onclick="openEditModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8') ?>)">
                      <form data-confirm="Delete this product?" method="POST" action="?page=inventory&tab=items" style="position:absolute; top:8px; right:8px; z-index:2;">
                        <input type="hidden" name="delete_sku" value="<?= $item['sku'] ?>">
                        <button type="submit" class="inv-del-btn" onclick="event.stopPropagation();" title="Delete">&times;</button>
                      </form>
                      <div class="inv-card-img">
                        <?php if (!empty($item['image_url'])): ?><img src="<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?>
                          <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#D4BCA9" stroke-width="1.5">
                            <rect x="3" y="3" width="18" height="18" rx="2" />
                            <circle cx="8.5" cy="8.5" r="1.5" />
                            <polyline points="21 15 16 10 5 21" />
                          </svg>
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
                          &#8369;<?= number_format($ep, 2) ?>
                          <?php if ($hasDisc): ?><span style="font-size:10px; text-decoration:line-through; color:var(--text-3); margin-left:4px;">&#8369;<?= number_format($item['price'], 2) ?></span><?php endif; ?>
                        </div>
                        <div class="inv-card-stock">Stock: <?= $item['stock_qty'] ?></div>
                        <div class="inv-actions" style="margin-top: 10px; width: 100%;">
                            <button type="button" class="btn-variant" style="width: 100%;" onclick="event.stopPropagation(); openVariantModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8') ?>)">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                Add Variant
                            </button>
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
                    <div class="form-group"><label>Promotion Name</label><input type="text" name="d_name" placeholder="e.g. Summer Sale" required></div>
                    <div class="form-row">
                      <div class="form-group"><label>Type</label>
                        <select name="d_type" id="disc_type_sel">
                          <option value="Percentage">Percentage (%)</option>
                          <option value="Fixed">Fixed Amount (&#8369;)</option>
                        </select>
                      </div>
                      <div class="form-group"><label>Value</label>
                        <input type="text" name="d_value" id="disc_val" placeholder="0.00" oninput="fmtDisc(this)" required>
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
                      $vdisp = ($d['discount_type'] === 'Percentage') ? $d['discount_value'] . '%' : '&#8369;' . number_format($d['discount_value'], 2);
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
                          <form data-confirm="Delete this promotion?" method="POST" action="?page=inventory&tab=discounts">
                            <input type="hidden" name="delete_discount_id" value="<?= $d['discount_id'] ?>">
                            <button type="submit" name="delete_discount" class="btn btn-sm btn-danger">Delete</button>
                          </form>
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
            <div class="modal-box">
              <div class="modal-header">
                <span class="modal-title" id="prod_modal_title">New Product</span>
                <button class="modal-close" onclick="document.getElementById('productModal').classList.remove('open')">&times;</button>
              </div>
              <form method="POST" action="?page=inventory&tab=items" enctype="multipart/form-data" id="prod_form">
                <input type="hidden" name="old_sku" id="hidden_sku">
                <div class="form-group"><label>SKU / ID</label><input type="text" name="sku" id="form_sku" placeholder="ROSE-001" required></div>
                <div class="form-group"><label>Product Name</label><input type="text" name="name" id="form_name" placeholder="Product name" required></div>
                <div class="form-row">
                  <div class="form-group"><label>Price (&#8369;)</label><input type="text" name="price" id="form_price" oninput="fmtCurr(this)" placeholder="0.00" required></div>
                  <div class="form-group"><label>Stock Qty</label><input type="number" name="qty" id="form_qty" placeholder="0" required></div>
                </div>
                <div class="form-row">
                  <div class="form-group"><label>Category</label>
                    <select name="category_id" id="form_cat">
                      <option value="">Uncategorized</option>
                      <?php foreach ($cats as $c): ?><option value="<?= $c['category_id'] ?>"><?= htmlspecialchars($c['category_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-group"><label>Discount</label>
                    <select name="discount_id" id="form_disc">
                      <option value="">No Discount</option>
                      <?php foreach ($discounts as $d): ?><option value="<?= $d['discount_id'] ?>"><?= htmlspecialchars($d['discount_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="form-group"><label>Product Image</label><input type="file" name="product_image" accept="image/*" style="padding:6px;"></div>
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
              <form method="POST" action="?page=inventory&tab=items">
                <input type="hidden" name="original_sku" id="variant_orig_sku">
                
                <div class="form-group">
                  <label>Base Product</label>
                  <input type="text" id="variant_orig_name" readonly style="background:#f4f1ee; color:#777;">
                </div>
                
                <div class="form-group">
                  <label>New Variant SKU / ID</label>
                  <input type="text" name="new_sku" id="variant_new_sku" placeholder="e.g. ROSE-002" required>
                </div>
                
                <div class="form-group">
                  <label>Variant Color / Specifics</label>
                  <input type="text" name="variant_name" id="variant_new_name" placeholder="e.g. White Rose" required>
                </div>
                
                <div class="form-group">
                  <label>Initial Stock Quantity</label>
                  <input type="number" name="variant_qty" placeholder="0" min="0" required>
                </div>
                
                <p style="font-size:12px; color:#777; margin-bottom:14px;">
                  * Base price, Category, Promotions, and Images are automatically inherited.
                </p>

                <button type="submit" name="add_variant_submit" class="btn btn-primary btn-full">Save Variant</button>
              </form>
            </div>
          </div>

          <script>
          function openVariantModal(item) {
              document.getElementById('variant_orig_sku').value = item.sku;
              document.getElementById('variant_orig_name').value = item.product_name + ' (' + item.sku + ')';
              
              // Suggest placeholder text variations
              document.getElementById('variant_new_sku').value = item.sku + '-VAR';
              document.getElementById('variant_new_name').value = item.product_name + ' (Variant)';
              
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
                <div class="form-group"><label>Category Name</label><input type="text" name="category_name" required autofocus></div>
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
                <div class="form-group"><label>Category Name</label><input type="text" name="category_name" id="edit_cat_name" required></div>
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
              document.getElementById('productModal').classList.add('open');
            }

            const inventoryItems = <?= json_encode($inventory, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            function openEditModal(data, title = 'Edit Product') {
              document.getElementById('prod_modal_title').textContent = title;
              document.getElementById('prod_submit_btn').name = 'update_product';
              document.getElementById('form_sku').value = data.sku;
              document.getElementById('hidden_sku').value = data.sku;
              document.getElementById('form_name').value = data.product_name;
              const p = parseFloat(data.price);
              document.getElementById('form_price').value = isNaN(p) ? '' : p.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
              });
              document.getElementById('form_qty').value = data.stock_qty;
              document.getElementById('form_cat').value = data.category_id || '';
              document.getElementById('form_disc').value = data.discount_id || '';
              document.getElementById('productModal').classList.add('open');
              document.getElementById('form_qty').focus();
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

            function fmtDisc(input) {
              let raw = input.value.replace(/[^0-9]/g, '');
              if (!raw) {
                input.value = '';
                return;
              }
              let n = parseInt(raw, 10);
              const type = document.getElementById('disc_type_sel').value;
              const max = type === 'Percentage' ? 10000 : 99999900;
              if (n > max) n = max;
              input.value = (n / 100).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
              });
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

            // Approval history data injected from server
            const CUST_HISTORY = <?= json_encode($historyMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || {};

            function openHistory(cid) {
              const modal = document.getElementById('historyModal');
              const container = document.getElementById('historyContent');
              container.innerHTML = '';
              const items = CUST_HISTORY[cid] || [];
              if (items.length === 0) {
                container.innerHTML = '<div style="padding:12px; color:var(--text-3);">No history available.</div>';
              } else {
                items.forEach(it => {
                  const t = document.createElement('div');
                  t.style.padding = '10px 0';
                  t.style.borderBottom = '1px solid #eee';
                  t.innerHTML = `<div style="font-weight:700;">${escapeHtml(it.action)}</div><div style="font-size:12px; color:var(--text-3);">${escapeHtml(it.note || '')}</div><div style="font-size:12px; color:var(--text-3); margin-top:6px;">By: ${escapeHtml(it.by_employee_id || '')} &middot; ${escapeHtml(it.ts || '')}</div>`;
                  container.appendChild(t);
                });
              }
              modal.classList.add('open');
            }

            function escapeHtml(s) {
              return String(s).replace(/[&<>\"']/g, function(m) {
                return {
                  '&': '&amp;',
                  '<': '&lt;',
                  '>': '&gt;',
                  '"': '&quot;',
                  "'": '&#39;'
                } [m];
              });
            }
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
          <div class="overlay" id="historyModal">
            <div class="modal-box" style="max-width:600px;">
              <div class="modal-header"><span class="modal-title">Customer Approval History</span><button class="modal-close" onclick="document.getElementById('historyModal').classList.remove('open')">&times;</button></div>
              <div id="historyContent" style="padding:12px; max-height:400px; overflow:auto;"></div>
            </div>
          </div>

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
                        <tr data-cust-id="<?= $c['customer_id'] ?>">
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
                            <div style="display: flex; gap: 12px; row-gap: 10px; align-items: center; flex-wrap: wrap;">
                              <button onclick='openEditCust(<?= json_encode($c) ?>)' class="btn btn-sm btn-secondary">Edit</button>
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
                                <form data-confirm="Are you sure you want to 100% delete this customer and all logs permanently from the database?" method="POST" action="?page=crm" style="margin: 0;">
                                  <input type="hidden" name="customer_id" value="<?= $c['customer_id'] ?>">
                                  <button type="submit" name="delete_customer" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                              <?php endif; ?>
                              <button type="button" onclick="openHistory(<?= $c['customer_id'] ?>)" class="btn btn-sm btn-secondary">History</button>
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
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" pattern="[A-Za-z ]+" minlength="3" title="Name must contain letters and spaces only" required></div>
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
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" id="edit_cust_name" pattern="[A-Za-z ]+" minlength="3" title="Name must contain letters and spaces only" required></div>
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
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" id="edit_emp_name" required></div>
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
              <div style="font-size:14px; font-weight:700; color:var(--espresso); margin-bottom:14px;">Recent Transactions</div>
              <div class="tbl-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Transaction ID</th>
                      <th>Date &amp; Time</th>
                      <th>Cashier</th>
                      <th>Payment</th>
                      <th>Amount</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php $r_sales_rows = $r_sales ? $r_sales->fetch_all(MYSQLI_ASSOC) : [];
                    if (!empty($r_sales_rows)):
                      foreach ($r_sales_rows as $s): ?>
                        <tr>
                          <td><span style="font-family:monospace; font-size:12px; color:var(--chestnut); font-weight:600;"><?= htmlspecialchars($s['transaction_id'], ENT_QUOTES, 'UTF-8') ?></span></td>
                          <td style="color:var(--text-3); font-size:12px;"><?= date('d M Y, h:i A', strtotime($s['sale_date'])) ?></td>
                          <td style="font-weight:500;"><?= htmlspecialchars($s['cashier'] ?? '&#8212;', ENT_QUOTES, 'UTF-8') ?></td>
                          <td><span class="badge badge-gray"><?= htmlspecialchars($s['payment_method'], ENT_QUOTES, 'UTF-8') ?></span></td>
                          <td style="font-weight:700; color:var(--espresso);">&#8369;<?= number_format($s['total_amount'], 2) ?></td>
                          <td><span class="badge <?= $s['status'] === 'Completed' ? 'badge-green' : 'badge-amber' ?>"><?= $s['status'] ?></span></td>
                        </tr>
                      <?php endforeach;
                    else: ?>
                      <tr>
                        <td colspan="6" style="text-align:center; color:var(--text-3); padding:28px;">No transactions for this period.</td>
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
</body>

</html>