-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 21, 2026 at 05:48 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
SET FOREIGN_KEY_CHECKS = 0;

--
-- Database: `bloom_pos`
--

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `customer_id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `contact_info` varchar(150) DEFAULT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `loyalty_points` int(11) NOT NULL DEFAULT 0,
  `member_since` datetime DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `contact_number` varchar(32) DEFAULT NULL,
  `approved` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` varchar(50) DEFAULT NULL,
  `approved_by` varchar(50) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_approval_history`
--

CREATE TABLE `customer_approval_history` (
  `approval_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `action` varchar(32) NOT NULL,
  `by_employee_id` varchar(50) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `ts` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `discounts`
--

CREATE TABLE `discounts` (
  `discount_id` int(11) NOT NULL,
  `discount_name` varchar(100) NOT NULL,
  `discount_type` enum('percent','fixed') NOT NULL DEFAULT 'percent',
  `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `expiry_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `employee_id` varchar(50) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `role` varchar(16) NOT NULL DEFAULT 'Cashier',
  `passcode` varchar(255) NOT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`employee_id`),
  CONSTRAINT `chk_employee_id_prefix` CHECK (LEFT(`employee_id`,3) = 'EMP'),
  CONSTRAINT `chk_employees_role` CHECK (`role` IN ('Admin','Cashier'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `session_history`
--

CREATE TABLE `session_history` (
  `session_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) NOT NULL,
  `login_date` date NOT NULL,
  `login_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `logout_time` datetime DEFAULT NULL,
  `duration_seconds` int(11) DEFAULT NULL,
  `duration` varchar(16) DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  KEY `idx_employee_active` (`employee_id`, `logout_time`),
  KEY `idx_employee_login` (`employee_id`, `login_time`),
  CONSTRAINT `fk_session_history_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `sku` varchar(50) NOT NULL,
  `product_name` varchar(150) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stock_qty` int(11) NOT NULL DEFAULT 0,
  `category_id` int(11) DEFAULT NULL,
  `discount_id` int(11) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `showcase_bundles`
--

CREATE TABLE `showcase_bundles` (
  `showcase_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `main` int(11) NOT NULL DEFAULT 0,
  `fillers` int(11) NOT NULL DEFAULT 0,
  `greenery` int(11) NOT NULL DEFAULT 0,
  `meta` varchar(255) NOT NULL,
  `image_url` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`showcase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `showcase_sales`
--

CREATE TABLE IF NOT EXISTS `showcase_sales` (
  `bestseller_id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` varchar(50) NOT NULL,
  `showcase_id` int(11) DEFAULT NULL,
  `bundle_name` varchar(200) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `sale_date` datetime NOT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`bestseller_id`),
  KEY `idx_showcase_sale_date` (`sale_date`),
  KEY `idx_showcase_id` (`showcase_id`),
  KEY `fk_showcase_sales_sale` (`order_id`),
  KEY `fk_showcase_sales_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `order_id` varchar(50) NOT NULL,
  `sale_date` datetime NOT NULL DEFAULT current_timestamp(),
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_id` int(11) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `amount_tendered` decimal(10,2) DEFAULT NULL,
  `wallet_contact_number` varchar(50) DEFAULT NULL,
  `wallet_account_name` varchar(150) DEFAULT NULL,
  `wallet_proof_image_url` varchar(255) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Completed',
  `employee_id` varchar(50) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` ( 
  `saleitems_id` int(11) NOT NULL,
  `order_id` varchar(50) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `price_at_time` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`category_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`customer_id`);

--
-- Indexes for table `customer_approval_history`
--
ALTER TABLE `customer_approval_history`
  ADD PRIMARY KEY (`approval_id`),
  ADD KEY `fk_cah_cust` (`customer_id`),
  ADD KEY `fk_cah_emp` (`by_employee_id`);

--
-- Indexes for table `discounts`
--
ALTER TABLE `discounts`
  ADD PRIMARY KEY (`discount_id`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`sku`),
  ADD KEY `fk_inv_cat` (`category_id`),
  ADD KEY `fk_inv_disc` (`discount_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`order_id`),
  ADD KEY `fk_sale_emp` (`employee_id`),
  ADD KEY `fk_sale_cust` (`customer_id`),
  ADD KEY `fk_sale_disc` (`discount_id`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_si_sale` (`order_id`),
  ADD KEY `fk_si_sku` (`sku`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customer_approval_history`
--
ALTER TABLE `customer_approval_history`
  MODIFY `approval_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `discounts`
--
ALTER TABLE `discounts`
  MODIFY `discount_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `customer_approval_history`
--
ALTER TABLE `customer_approval_history`
  ADD CONSTRAINT `fk_cah_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON UPDATE CASCADE ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cah_emp` FOREIGN KEY (`by_employee_id`) REFERENCES `employees` (`employee_id`) ON UPDATE CASCADE ON DELETE SET NULL;

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `fk_inv_cat` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_inv_disc` FOREIGN KEY (`discount_id`) REFERENCES `discounts` (`discount_id`) ON DELETE SET NULL;

--
-- Constraints for table `showcase_sales`
--
ALTER TABLE `showcase_sales`
  ADD CONSTRAINT `fk_showcase_sales_showcase` FOREIGN KEY (`showcase_id`) REFERENCES `showcase_bundles` (`showcase_id`) ON UPDATE CASCADE ON DELETE SET NULL,
  ADD CONSTRAINT `fk_showcase_sales_sale` FOREIGN KEY (`order_id`) REFERENCES `sales` (`order_id`) ON UPDATE CASCADE ON DELETE CASCADE,
  ADD CONSTRAINT `fk_showcase_sales_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON UPDATE CASCADE ON DELETE SET NULL;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `fk_sale_cust` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sale_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sale_disc` FOREIGN KEY (`discount_id`) REFERENCES `discounts` (`discount_id`) ON DELETE SET NULL;

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `fk_si_sale` FOREIGN KEY (`order_id`) REFERENCES `sales` (`order_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_si_sku` FOREIGN KEY (`sku`) REFERENCES `inventory` (`sku`) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;