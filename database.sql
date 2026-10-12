CREATE DATABASE IF NOT EXISTS khmer_shop
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE khmer_shop;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_number VARCHAR(80) NOT NULL,
  invoice_date VARCHAR(100) NULL,
  customer_name VARCHAR(150) NOT NULL,
  customer_phone VARCHAR(40) NOT NULL,
  customer_address VARCHAR(1000) NOT NULL,
  total_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_khr BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('pending','confirmed','processing','shipped','completed','cancelled') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_invoice_number (invoice_number),
  KEY idx_orders_created_at (created_at),
  KEY idx_orders_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  product_code VARCHAR(80) NOT NULL,
  product_name VARCHAR(250) NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_order_items_order_id (order_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id)
    REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;
