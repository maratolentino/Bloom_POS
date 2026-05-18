<?php



/**automatically load classes without requiring manual includes*/

// Define the base directories for our class files
define('BASE_PATH', __DIR__);
define('CLASSES_PATH', BASE_PATH . '/classes');

/**
 * @param string $class The fully qualified class name
 * @return void
 */
function customAutoloader(string $class): void { //$class is the name of the class being instantiated, passed by PHP to the autoloader function
    // Convert class name to file path
    $file = CLASSES_PATH . '/' . str_replace('\\', '/', $class) . '.php';
    
    
    if (file_exists($file)) {
        require_once $file;
    }
}

// Register the autoloader function
spl_autoload_register('customAutoloader');

// Include existing Product classes
require_once 'Product.inc.php';

/**
 * Returns extension-related information for a file
 * @param string $fileName The name or path of the file
 * @return string The file extension (e.g., 'jpg', 'php')
 */
function info(string $fileName): string {
    return pathinfo($fileName, PATHINFO_EXTENSION); //INFO_EXTENSION returns the file extension without the dot
}

// putonsale() to apply a discount to the product in the database and update the object's discount_id property
function putonsale(mysqli $conn, string $sku, string $discount_id): bool {
    $stmt = $conn->prepare("UPDATE inventory SET discount_id = ? WHERE sku = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ss", $discount_id, $sku);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

// removeDiscountFromProduct() to remove the discount from the product in the database and update the object's discount_id property
function removeDiscountFromProduct(mysqli $conn, string $sku): bool {
    $stmt = $conn->prepare("UPDATE inventory SET discount_id = NULL WHERE sku = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("s", $sku);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function cloneProductBySku(mysqli $conn, string $sku): ?string {
    $stmt = $conn->prepare(
        "SELECT inventory.*, categories.category_name
        FROM inventory
        LEFT JOIN categories ON inventory.category_id = categories.category_id
        WHERE inventory.sku = ?"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("s", $sku);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return null;
    }

    $product = Product::fromDbRow($row);
    $copy = $product->cloneProduct();

    // Reset stock to 0 for the cloned item (placeholder entry with no actual stock)
    $copy->stock = 0;

    $category_id = $row['category_id'] !== null ? $row['category_id'] : null;
    $discount_id = $row['discount_id'] ?? null;
    $image_url = $row['image_url'] ?? '';

    $insert = $conn->prepare(
        "INSERT INTO inventory (sku, product_name, price, stock_qty, category_id, discount_id, image_url)
        VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$insert) {
        return null;
    }

    $insert->bind_param(
        "ssdisss",
        $copy->sku,
        $copy->name,
        $copy->price,
        $copy->stock,
        $category_id,
        $discount_id,
        $image_url
    );

    if (!$insert->execute()) {
        $insert->close();
        return null;
    }

    $insert->close();

    // Log with qty_changed = 0 since this is a placeholder entry with no actual stock added
    $log = $conn->prepare("INSERT INTO inventory_logs (sku, action_type, qty_changed) VALUES (?, 'CLONE', 0)");
    if ($log) {
        $log->bind_param("s", $copy->sku);
        $log->execute();
        $log->close();
    }
 
    return $copy->sku;
}

?>