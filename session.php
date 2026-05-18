<?php
/**
 * session.php - POS System Session Handler
 * Manages user sessions for the entire server/application
 * Session timeout applies to the whole server session
 */

// Server-wide session timeout (1 hour = 3600 seconds)
define('SESSION_TIMEOUT', 3600);

/**
 * Initialize user session on login
 */
function initializeSession($employee_id, $full_name, $role, $photo_url = '') {
    $_SESSION['user_id']       = $employee_id;
    $_SESSION['user_name']     = $full_name;
    $_SESSION['user_role']     = $role;
    $_SESSION['user_photo']    = $photo_url;
    $_SESSION['login_time']    = date("Y-m-d H:i:s");
    $_SESSION['login_timestamp'] = time(); // For timeout tracking
}

/**
 * Check if user is logged in and session is valid
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !isSessionExpired(); // Check if session has expired
}

/**
 * Check if session has expired
 */
function isSessionExpired() {
    if (!isset($_SESSION['login_timestamp'])) {
        return true;
    }
    
    $current_time = time();
    $session_duration = $current_time - $_SESSION['login_timestamp'];
    
    return $session_duration > SESSION_TIMEOUT;
}

/**
 * Refresh session timeout (keep user logged in)
 */
function refreshSession() {
    if (isLoggedIn()) {
        $_SESSION['login_timestamp'] = time();
        return true;
    }
    return false;
}

/**
 * Get user session data
 */
function getUserData() {
    if (isLoggedIn()) {
        return array(
            'user_id'    => $_SESSION['user_id'],
            'user_name'  => $_SESSION['user_name'],
            'user_role'  => $_SESSION['user_role'],
            'user_photo' => $_SESSION['user_photo'],
            'login_time' => $_SESSION['login_time']
        );
    }
    return null;
}

/**
 * Destroy session completely (logout)
 */
function destroySession() {
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

/**
 * Initialize transaction data for current POS transaction
 */
function initializeTransactionSession() {
    $_SESSION['current_transaction'] = array(
        'transaction_id'   => uniqid('TXN-', true),
        'start_time'       => date("Y-m-d H:i:s"), //date() to store transaction start time
        'items'            => array(),
        'total_amount'     => 0.00,
        'discount_amount'  => 0.00,
        'tax_amount'       => 0.00,
        'payment_method'   => '',
        'status'           => 'active'
    );
}

/**
 * Add item to current transaction
 */
function addItemToTransaction($product_id, $product_name, $quantity, $unit_price, $category) {
    if (!isset($_SESSION['current_transaction'])) {
        initializeTransactionSession();
    }
    
    $item = array(
        'product_id'   => $product_id,
        'product_name' => $product_name,
        'quantity'     => $quantity,
        'unit_price'   => $unit_price,
        'category'     => $category,
        'subtotal'     => $quantity * $unit_price,
        'added_time'   => date("Y-m-d H:i:s")
    );
    
    $_SESSION['current_transaction']['items'][] = $item;
    updateTransactionTotals();
    
    return true;
}

/**
 * Remove item from current transaction by index
 */
function removeItemFromTransaction($item_index) {
    if (isset($_SESSION['current_transaction']['items'][$item_index])) {
        unset($_SESSION['current_transaction']['items'][$item_index]);
        $_SESSION['current_transaction']['items'] = array_values($_SESSION['current_transaction']['items']);
        updateTransactionTotals();
        return true;
    }
    return false;
}

/**
 * Update item quantity in current transaction
 */
function updateItemQuantity($item_index, $new_quantity) {
    if (isset($_SESSION['current_transaction']['items'][$item_index])) {
        $item = &$_SESSION['current_transaction']['items'][$item_index];
        $item['quantity'] = $new_quantity;
        $item['subtotal'] = $new_quantity * $item['unit_price'];
        updateTransactionTotals();
        return true;
    }
    return false;
}

/**
 * Clear all items from current transaction
 */
function clearTransactionItems() {
    $_SESSION['current_transaction']['items'] = array();
    updateTransactionTotals();
}

/**
 * Update transaction totals (subtotal, tax, total)
 */
function updateTransactionTotals() {
    if (!isset($_SESSION['current_transaction'])) {
        return;
    }
     
    $subtotal = 0;
    foreach ($_SESSION['current_transaction']['items'] as $item) {
        $subtotal += $item['subtotal'];
    }
    
    $_SESSION['current_transaction']['total_amount'] = $subtotal;
    
    // Tax calculation (12% tax rate)
    $tax_rate = 0.12;
    $_SESSION['current_transaction']['tax_amount'] = $subtotal * $tax_rate;
}

/**
 * Complete current transaction
 */
function completeTransaction($payment_method = '') {
    if (!isset($_SESSION['current_transaction'])) {
        return false;
    }
    
    $_SESSION['current_transaction']['status'] = 'completed';
    $_SESSION['current_transaction']['payment_method'] = $payment_method;
    $_SESSION['current_transaction']['completion_time'] = date("Y-m-d H:i:s");
    
    // Store completed transaction in history
    if (!isset($_SESSION['transaction_history'])) {
        $_SESSION['transaction_history'] = array();
    }
    $_SESSION['transaction_history'][] = $_SESSION['current_transaction'];
    
    // Initialize new transaction for next sale
    initializeTransactionSession();
    
    return true;
}

/**
 * Get current transaction data
 */
function getCurrentTransaction() {
    return isset($_SESSION['current_transaction']) ? $_SESSION['current_transaction'] : null;
}

/**
 * Get transaction history for session
 */
function getTransactionHistory() {
    return isset($_SESSION['transaction_history']) ? $_SESSION['transaction_history'] : array();
}

?>
