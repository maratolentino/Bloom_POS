<?php
/**
 * session.php - simplified session helpers focused on shopping cart
 * This file no longer manages employee login or server-wide timeouts.
 * It provides a small API for manipulating a cart stored in $_SESSION['cart'].
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Remove any leftover default-named PHPSESSID cookie so only BLOOMSESSID is used
    if (!headers_sent() && isset($_COOKIE['PHPSESSID'])) {
        setcookie('PHPSESSID', '', time() - 3600, '/');
        unset($_COOKIE['PHPSESSID']);
    }

    // Use a fixed session name and explicit cookie params to avoid path/domain issues
    if (!headers_sent()) {
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

/**
 * Return the full cart array (sku => qty)
 */
function cart_get() {
    return isset($_SESSION['cart']) ? $_SESSION['cart'] : array();
}

/**
 * Add quantity to a SKU in the cart (increments). If resulting qty <= 0, removes it.
 */
function cart_add($sku, $qty = 1) {
    $sku = (string)$sku;
    $qty = (int)$qty;
    if ($qty === 0) return true;
    if (!isset($_SESSION['cart'])) $_SESSION['cart'] = array();
    if (!isset($_SESSION['cart'][$sku])) $_SESSION['cart'][$sku] = 0;
    $_SESSION['cart'][$sku] += $qty;
    if ($_SESSION['cart'][$sku] <= 0) unset($_SESSION['cart'][$sku]);
    return true;
}

/**
 * Set exact quantity for a SKU. If qty <= 0 the SKU is removed.
 */
function cart_set($sku, $qty) {
    $sku = (string)$sku;
    $qty = (int)$qty;
    if ($qty > 0) {
        if (!isset($_SESSION['cart'])) $_SESSION['cart'] = array();
        $_SESSION['cart'][$sku] = $qty;
    } else {
        if (isset($_SESSION['cart'][$sku])) unset($_SESSION['cart'][$sku]);
    }
    return true;
}

/**
 * Remove a SKU from the cart
 */
function cart_remove($sku) {
    $sku = (string)$sku;
    if (isset($_SESSION['cart'][$sku])) {
        unset($_SESSION['cart'][$sku]);
        return true;
    }
    return false;
}

/**
 * Clear the entire cart
 */
function cart_clear() {
    unset($_SESSION['cart']);
    return true;
}

/**
 * Return total number of items (sum of quantities)
 */
function cart_count_items() {
    $sum = 0;
    foreach (cart_get() as $q) $sum += (int)$q;
    return $sum;
}

/**
 * Optionally compute cart amount given a DB connection and inventory table prices
 * $conn is optional; if provided this will sum price * qty for SKUs found in inventory.
 */
function cart_total_amount($conn = null) {
    $cart = cart_get();
    if (empty($cart) || $conn === null) return 0.0;
    $skus = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($skus), '?'));
    $types = str_repeat('s', count($skus));
    $stmt = $conn->prepare("SELECT sku, price FROM inventory WHERE sku IN ($placeholders)");
    if (!$stmt) return 0.0;
    $stmt->bind_param($types, ...$skus);
    $stmt->execute();
    $res = $stmt->get_result();
    $total = 0.0;
    while ($r = $res->fetch_assoc()) {
        $sku = $r['sku'];
        $price = floatval($r['price']);
        $qty = isset($cart[$sku]) ? (int)$cart[$sku] : 0;
        $total += $price * $qty;
    }
    return $total;
}

/**
 * Backwards-compatible destroySession (clears session data)
 */
function destroySession() {
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

?>
