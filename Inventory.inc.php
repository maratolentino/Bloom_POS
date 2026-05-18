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

function generateVariantSku(mysqli $conn, string $sku, string $color): string {
    $cleanColor = preg_replace('/[^A-Za-z0-9]+/', '', strtoupper($color));
    $base = preg_replace('/[^A-Za-z0-9]+$/', '', $sku);
    $candidate = $base . '-' . $cleanColor;
    $suffix = 1;

    while (true) {
        $check = $conn->real_escape_string($candidate);
        $row = $conn->query("SELECT 1 FROM inventory WHERE sku='$check' LIMIT 1");
        if (!$row || $row->fetch_assoc() === null) {
            break;
        }
        $candidate = $base . '-' . $cleanColor . '-V' . $suffix;
        $suffix++;
    }

    return $candidate;
}

function generateVariantProductName(string $productName, string $color): string {
    $baseName = trim(preg_replace('/\s*-\s*[^-]+$/', '', $productName));
    if ($baseName === '') {
        $baseName = trim($productName);
    }
    return trim($baseName . ' - ' . ucfirst(strtolower($color)));
}

function createVariantBySku(mysqli $conn, string $sku, string $color, int $qty): ?string {
    $stmt = $conn->prepare("SELECT * FROM inventory WHERE sku = ?");
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

    $newSku = generateVariantSku($conn, $row['sku'], $color);
    $newName = generateVariantProductName($row['product_name'], $color);
    $category_id = $row['category_id'] !== null ? $row['category_id'] : null;
    $discount_id = $row['discount_id'] ?? null;
    $image_url = $row['image_url'] ?? '';

    $insert = $conn->prepare(
        "INSERT INTO inventory (sku, product_name, price, stock_qty, category_id, discount_id, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$insert) {
        return null;
    }

    $insert->bind_param(
        "ssdisss",
        $newSku,
        $newName,
        $row['price'],
        $qty,
        $category_id,
        $discount_id,
        $image_url
    );

    if (!$insert->execute()) {
        $insert->close();
        return null;
    }
    $insert->close();

    return $newSku;
}

?>