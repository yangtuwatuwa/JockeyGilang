    CREATE DATABASE IF NOT EXISTS pos_kelontong
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

    USE pos_kelontong;

    CREATE TABLE users (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        full_name VARCHAR(100) NOT NULL,
        username VARCHAR(50) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin', 'cashier', 'supplier') NOT NULL,
        status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_users_username (username),
        KEY idx_users_role_status (role, status)
    ) ENGINE=InnoDB;

    CREATE TABLE supplier_profiles (
        user_id BIGINT UNSIGNED NOT NULL,
        phone VARCHAR(30) NULL,
        address TEXT NULL,
        PRIMARY KEY (user_id),
        CONSTRAINT fk_supplier_profiles_user
            FOREIGN KEY (user_id) REFERENCES users (id)
            ON UPDATE CASCADE ON DELETE RESTRICT
    ) ENGINE=InnoDB;

    CREATE TABLE categories (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(80) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_categories_name (name)
    ) ENGINE=InnoDB;

    CREATE TABLE products (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        category_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        selling_price DECIMAL(12,2) NOT NULL,
        discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        stock DECIMAL(12,3) NOT NULL DEFAULT 0.000,
        minimum_stock DECIMAL(12,3) NOT NULL DEFAULT 0.000,
        unit VARCHAR(20) NOT NULL,
        status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_products_category_status (category_id, status),
        KEY idx_products_name (name),
        KEY idx_products_stock_status (status, stock, minimum_stock),
        CONSTRAINT chk_products_purchase_price CHECK (purchase_price >= 0),
        CONSTRAINT chk_products_selling_price CHECK (selling_price >= 0),
        CONSTRAINT chk_products_discount CHECK (discount_percent >= 0 AND discount_percent <= 100),
        CONSTRAINT chk_products_stock CHECK (stock >= 0),
        CONSTRAINT chk_products_minimum_stock CHECK (minimum_stock >= 0),
        CONSTRAINT fk_products_category
            FOREIGN KEY (category_id) REFERENCES categories (id)
            ON UPDATE CASCADE ON DELETE RESTRICT
    ) ENGINE=InnoDB;

    CREATE TABLE supplier_products (
        supplier_user_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (supplier_user_id, product_id),
        KEY idx_supplier_products_product (product_id),
        CONSTRAINT fk_supplier_products_supplier
            FOREIGN KEY (supplier_user_id) REFERENCES users (id)
            ON UPDATE CASCADE ON DELETE RESTRICT,
        CONSTRAINT fk_supplier_products_product
            FOREIGN KEY (product_id) REFERENCES products (id)
            ON UPDATE CASCADE ON DELETE RESTRICT
    ) ENGINE=InnoDB;

    CREATE TABLE transactions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        transaction_number VARCHAR(40) NOT NULL,
        cashier_user_id BIGINT UNSIGNED NOT NULL,
        transaction_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        total_amount DECIMAL(14,2) NOT NULL,
        total_discount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        amount_paid DECIMAL(14,2) NOT NULL,
        change_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        payment_method ENUM('cash') NOT NULL DEFAULT 'cash',
        PRIMARY KEY (id),
        UNIQUE KEY uq_transactions_number (transaction_number),
        KEY idx_transactions_date (transaction_date),
        KEY idx_transactions_cashier_date (cashier_user_id, transaction_date),
        CONSTRAINT chk_transactions_total CHECK (total_amount >= 0),
        CONSTRAINT chk_transactions_discount CHECK (total_discount >= 0),
        CONSTRAINT chk_transactions_paid CHECK (amount_paid >= total_amount),
        CONSTRAINT chk_transactions_change CHECK (change_amount >= 0 AND change_amount = amount_paid - total_amount),
        CONSTRAINT fk_transactions_cashier
            FOREIGN KEY (cashier_user_id) REFERENCES users (id)
            ON UPDATE CASCADE ON DELETE RESTRICT
    ) ENGINE=InnoDB;

    CREATE TABLE transaction_details (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        transaction_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        product_name_snapshot VARCHAR(120) NOT NULL,
        selling_price_snapshot DECIMAL(12,2) NOT NULL,
        discount_percent_snapshot DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        unit_price_after_discount DECIMAL(12,2) NOT NULL,
        unit_snapshot VARCHAR(20) NOT NULL,
        quantity DECIMAL(12,3) NOT NULL,
        subtotal DECIMAL(14,2) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_transaction_details_transaction (transaction_id),
        KEY idx_transaction_details_product (product_id),
        CONSTRAINT chk_details_selling_price CHECK (selling_price_snapshot >= 0),
        CONSTRAINT chk_details_discount CHECK (discount_percent_snapshot >= 0 AND discount_percent_snapshot <= 100),
        CONSTRAINT chk_details_discounted_price CHECK (unit_price_after_discount >= 0),
        CONSTRAINT chk_details_quantity CHECK (quantity > 0),
        CONSTRAINT chk_details_subtotal CHECK (subtotal >= 0),
        CONSTRAINT fk_transaction_details_transaction
            FOREIGN KEY (transaction_id) REFERENCES transactions (id)
            ON UPDATE CASCADE ON DELETE RESTRICT,
        CONSTRAINT fk_transaction_details_product
            FOREIGN KEY (product_id) REFERENCES products (id)
            ON UPDATE CASCADE ON DELETE RESTRICT
    ) ENGINE=InnoDB;

    CREATE TABLE stock_movements (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        actor_user_id BIGINT UNSIGNED NOT NULL,
        movement_type ENUM('supplier_in', 'sale_out', 'admin_adjustment') NOT NULL,
        quantity_change DECIMAL(12,3) NOT NULL,
        reason VARCHAR(255) NULL,
        transaction_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_stock_movements_product_date (product_id, created_at),
        KEY idx_stock_movements_actor_date (actor_user_id, created_at),
        KEY idx_stock_movements_transaction (transaction_id),
        CONSTRAINT chk_stock_movement_quantity CHECK (quantity_change <> 0),
        CONSTRAINT chk_stock_movement_direction CHECK (
            (movement_type = 'supplier_in' AND quantity_change > 0) OR
            (movement_type = 'sale_out' AND quantity_change < 0) OR
            (movement_type = 'admin_adjustment')
        ),
        CONSTRAINT chk_admin_adjustment_reason CHECK (
            movement_type <> 'admin_adjustment' OR (reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) > 0)
        ),
        CONSTRAINT fk_stock_movements_product
            FOREIGN KEY (product_id) REFERENCES products (id)
            ON UPDATE CASCADE ON DELETE RESTRICT,
        CONSTRAINT fk_stock_movements_actor
            FOREIGN KEY (actor_user_id) REFERENCES users (id)
            ON UPDATE CASCADE ON DELETE RESTRICT,
        CONSTRAINT fk_stock_movements_transaction
            FOREIGN KEY (transaction_id) REFERENCES transactions (id)
            ON UPDATE CASCADE ON DELETE RESTRICT
    ) ENGINE=InnoDB;

    DELIMITER //
    CREATE TRIGGER trg_stock_movements_transaction_insert
    BEFORE INSERT ON stock_movements
    FOR EACH ROW
    BEGIN
        IF (NEW.movement_type IN ('supplier_in', 'admin_adjustment') AND NEW.transaction_id IS NOT NULL)
        OR (NEW.movement_type = 'sale_out' AND NEW.transaction_id IS NULL) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'transaction_id does not match stock movement type';
        END IF;
    END//

    CREATE TRIGGER trg_stock_movements_transaction_update
    BEFORE UPDATE ON stock_movements
    FOR EACH ROW
    BEGIN
        IF (NEW.movement_type IN ('supplier_in', 'admin_adjustment') AND NEW.transaction_id IS NOT NULL)
        OR (NEW.movement_type = 'sale_out' AND NEW.transaction_id IS NULL) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'transaction_id does not match stock movement type';
        END IF;
    END//
    DELIMITER ;

    INSERT INTO users (id, full_name, username, password_hash, role, status) VALUES
    (1, 'Administrator', 'admin', '$2y$12$tfYyxCJmHze11kQQmA8D0ultWNRSbIepWLGojsV47nxeEZONyhLB6', 'admin', 'active'),
    (2, 'Kasir Satu', 'kasir1', '$2y$12$tfYyxCJmHze11kQQmA8D0ultWNRSbIepWLGojsV47nxeEZONyhLB6', 'cashier', 'active'),
    (3, 'Kasir Dua', 'kasir2', '$2y$12$tfYyxCJmHze11kQQmA8D0ultWNRSbIepWLGojsV47nxeEZONyhLB6', 'cashier', 'active'),
    (4, 'Supplier Sembako', 'supplier1', '$2y$12$tfYyxCJmHze11kQQmA8D0ultWNRSbIepWLGojsV47nxeEZONyhLB6', 'supplier', 'active'),
    (5, 'Supplier Minuman', 'supplier2', '$2y$12$tfYyxCJmHze11kQQmA8D0ultWNRSbIepWLGojsV47nxeEZONyhLB6', 'supplier', 'active');

    INSERT INTO supplier_profiles (user_id, phone, address) VALUES
    (4, '081200000004', 'Jl. Pasar Sembako No. 4'),
    (5, '081200000005', 'Jl. Gudang Minuman No. 5');

    INSERT INTO categories (id, name) VALUES
    (1, 'Makanan'),
    (2, 'Minuman'),
    (3, 'Sembako'),
    (4, 'Snack'),
    (5, 'Perlengkapan Rumah');

    INSERT INTO products
    (id, category_id, name, purchase_price, selling_price, discount_percent, stock, minimum_stock, unit, status) VALUES
    (1, 3, 'Beras Setra Ramos', 12000.00, 15000.00, 0.00, 25.000, 5.000, 'kg', 'active'),
    (2, 3, 'Gula Pasir', 14500.00, 17000.00, 0.00, 18.000, 5.000, 'kg', 'active'),
    (3, 3, 'Minyak Goreng', 16000.00, 19000.00, 5.00, 12.000, 4.000, 'liter', 'active'),
    (4, 1, 'Mi Instan Goreng', 2500.00, 3000.00, 0.00, 48.000, 12.000, 'pcs', 'active'),
    (5, 1, 'Tepung Terigu', 9000.00, 11000.00, 0.00, 7.000, 5.000, 'kg', 'active'),
    (6, 2, 'Air Mineral 600 ml', 2500.00, 3500.00, 0.00, 36.000, 10.000, 'botol', 'active'),
    (7, 2, 'Teh Botol', 3500.00, 4500.00, 0.00, 0.000, 6.000, 'botol', 'active'),
    (8, 4, 'Biskuit Cokelat', 7000.00, 9000.00, 10.00, 9.000, 5.000, 'pack', 'active'),
    (9, 5, 'Sabun Cuci', 5000.00, 6500.00, 0.00, 3.000, 5.000, 'pack', 'active'),
    (10, 1, 'Telur Ayam', 26000.00, 30000.00, 0.00, 4.500, 2.000, 'kg', 'active');

    INSERT INTO supplier_products (supplier_user_id, product_id) VALUES
    (4, 1), (4, 2), (4, 3), (4, 5), (4, 10),
    (5, 4), (5, 6), (5, 7), (5, 8), (5, 9);
