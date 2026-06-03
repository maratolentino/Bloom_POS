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

/**
 * Creates a variant by cloning an existing Product object instance, 
 * applying custom variant properties, and writing a new row via INSERT.
 */
function addVariantWithClone(mysqli $conn, string $original_sku, string $new_sku, string $variant_name, int $stock_qty): bool {
    // 1. Fetch original row from database
    $stmt = $conn->prepare("SELECT * FROM inventory WHERE sku = ?");
    if (!$stmt) return false;
    $stmt->bind_param("s", $original_sku);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return false;

    // Save the genuine database foreign keys before cloning objects
    $real_category_id = $row['category_id']; 
    $real_discount_id = $row['discount_id'];
    $image_url        = $row['image_url'];

    // 2. Instantiate the base Product object model
    $originalProduct = new Product(
        $row['sku'], 
        $row['product_name'], 
        (float)$row['price'], 
        (int)$row['stock_qty'], 
        $row['category_id'] ?? 'General', 
        $row['description'] ?? '', 
        $row['discount_id']
    );

    // 3. Trigger PHP's magic __clone engine
    $clonedProduct = clone $originalProduct;

    // 4. Override the temporary clone placeholders with your user's explicit input fields
    $clonedProduct->__set('sku', $new_sku);
    $clonedProduct->__set('name', $variant_name);
    $clonedProduct->__set('stock', $stock_qty);

    // 5. Execute a completely clean, separate database INSERT operation
    $stmt = $conn->prepare("INSERT INTO inventory (sku, product_name, price, stock_qty, category_id, discount_id, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return false;

    // Extract values safely. Notice we pass the true integer $real_category_id instead of text string
    $sku   = $clonedProduct->sku;
    $name  = $clonedProduct->name;
    $price = $clonedProduct->price;
    $stock = $clonedProduct->stock;

    $stmt->bind_param("ssdiiss", $sku, $name, $price, $stock, $real_category_id, $real_discount_id, $image_url);
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

?>
