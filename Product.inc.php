<?php

// Base Class demonstrating Encapsulation
class Product {
    // Private properties: cannot be directly accessed outside the class
    private string $sku;
    private string $name;
    private float $price;
    private int $stock;
    private string $category;
    private string $description;
    private ?string $discount_id;

    public function __construct(string $sku, string $name, float $price, int $stock, string $category, string $description, ?string $discount_id = null) {
        $this->sku         = $sku; //$this->sku is the unique identifier for the product, set at construction and not meant to be changed
        $this->name        = $name;
        $this->price       = $price;
        $this->stock       = $stock;
        $this->category    = $category;
        $this->description = $description;
        $this->discount_id = $discount_id;
    }

    // Magic Getter: Safely read private properties
    public function __get(string $name): mixed { //__get() to allow read-only access to private properties
        return $this->$name;
    }

    // Magic Setter: Safely update properties with validation
    public function __set(string $name, mixed $value): void { //__set() to validate price and stock values before setting
        if ($name === 'price' && $value < 0) {
            $this->price = 0; // Prevent negative prices
        } elseif ($name === 'stock' && $value < 0) {
            $this->stock = 0; // Prevent negative stock
        } else {
            $this->$name = $value;
        }
    }

    // Magic String Method: Replaces a getSummary() function
    public function __toString(): string { //toString() to provide a human-readable summary of the product when treated as a string, replacing the need for a separate getSummary() method
        return $this->name . " (" . $this->sku . ") - ₱" . number_format($this->price, 2) . " | Stock: " . $this->stock . " [" . $this->category . "]";
    }

    public static function fromDbRow(array $row): self {
        // Factory Method: instantiate the correct subclass based on category
        $category = $row['category_name'] ?? '';
        $sku = $row['sku'];
        $name = $row['product_name'];
        $price = (float)$row['price'];
        $stock = (int)$row['stock_qty'];
        $description = (string)($row['description'] ?? '');
        $discount_id = $row['discount_id'] ?? null;

        // Instantiate the appropriate subclass
        $product = match($category) {
            'Flowers' => new FlowerProduct($sku, $name, $price, $stock),
            'Arrangements' => new ArrangementProduct($sku, $name, $price, $stock),
            'Plants' => new PlantProduct($sku, $name, $price, $stock),
            'Accessories' => new AccessoryProduct($sku, $name, $price, $stock),
            default => new self($sku, $name, $price, $stock, $category, $description, $discount_id),
        };

        // Set discount_id if present
        if ($discount_id !== null) {
            $product->discount_id = $discount_id;
        }

        return $product;
    }

    // Check if item is in stock
    public function isAvailable(): bool {
        return $this->stock > 0;
    }

    // Reduce stock after a sale
    public function deductStock(int $qty): bool {
        if ($qty > $this->stock) return false;
        $this->stock -= $qty;
        return true;
    }

    // Restock the product by adding the supplied quantity and updating the database
    public function restock(mysqli $conn, int $qty): bool {
        if ($qty <= 0) {
            return false;
        }

        // Update the database first to ensure data integrity before modifying the object's stock
        $stmt = $conn->prepare("UPDATE inventory SET stock_qty = stock_qty + ? WHERE sku = ?"); // sku for product id in inventory table
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("is", $qty, $this->sku);
        $result = $stmt->execute();
        if ($result) {
            $this->stock += $qty;
        }
        $stmt->close();
        return $result;
    }

    // Restock a product by SKU using a static method that retrieves the product from the database
    public static function restockBySku(mysqli $conn, string $sku, int $qty): bool {
        if ($qty <= 0) {
            return false;
        }

        // Retrieve the product from the database to ensure we have the latest stock information before restocking
        $stmt = $conn->prepare(
            "SELECT inventory.*, categories.category_name
            FROM inventory
            LEFT JOIN categories ON inventory.category_id = categories.category_id
            WHERE inventory.sku = ?"
        );
        if (!$stmt) {
            return false;
        }

        // Bind the SKU parameter and execute the query to fetch the product details
        $stmt->bind_param("s", $sku);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return false;
        }

        $product = self::fromDbRow($row);
        return $product->restock($conn, $qty);
    }

    // Put product on sale
    public function putOnSale(mysqli $conn, string $discount_id): bool { //putonsale() to apply a discount to the product in the database and update the object's discount_id property
        if (putonsale($conn, $this->sku, $discount_id)) {
            $this->discount_id = $discount_id;
            return true;
        }
        return false;
    }

    // Take product off sale
    public function takeOffSale(mysqli $conn): bool { //removeDiscountFromProduct() to remove the discount from the product in the database and update the object's discount_id property
        if (removeDiscountFromProduct($conn, $this->sku)) {
            $this->discount_id = null;
            return true;
        }
        return false;
    }
    public function __clone() {
            // Appends a temporary variant suffix to avoid primary key constraints
            $this->sku  = $this->sku . "-VAR"; 
            $this->name = $this->name . " (Variant)";
            
            // Reset stock for the new variant branch so it starts clean
            $this->stock = 0; 
        }
    } //

// Inheritance: Child classes extending the base Product class

class FlowerProduct extends Product { //extends Product to create a specific type of product with preset category and description values for flowers, demonstrating inheritance
    public function __construct(string $sku, string $name, float $price, int $stock) { //constructor for FlowerProduct that calls the parent constructor with specific category and description values for flowers
        parent::__construct($sku, $name, $price, $stock, "Flowers", "Fresh cut flowers");
    }
}

// __construct() is used for setting default category and description
class ArrangementProduct extends Product {
    public function __construct(string $sku, string $name, float $price, int $stock) {
        parent::__construct($sku, $name, $price, $stock, "Arrangements", "Custom flower arrangement");
    }
}

class PlantProduct extends Product {
    public function __construct(string $sku, string $name, float $price, int $stock) {
        parent::__construct($sku, $name, $price, $stock, "Plants", "Live potted plant");
    }
}

class AccessoryProduct extends Product {
    public function __construct(string $sku, string $name, float $price, int $stock) {
        parent::__construct($sku, $name, $price, $stock, "Accessories", "Vases, ribbons, and add-ons");
    }
}
?>