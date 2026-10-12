-- Additive migration for MariaDB. Existing receipts keep a NULL snapshot.
CREATE TABLE IF NOT EXISTS warehouse_print_settings (
    warehouse_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    unit_name VARCHAR(200) NOT NULL DEFAULT '',
    department_name VARCHAR(200) NOT NULL DEFAULT '',
    department_address VARCHAR(500) NOT NULL DEFAULT '',
    location VARCHAR(500) NOT NULL DEFAULT '',
    keeper_employee_id BIGINT UNSIGNED NULL,
    accountant_employee_id BIGINT UNSIGNED NULL,
    director_employee_id BIGINT UNSIGNED NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_print_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE,
    CONSTRAINT fk_print_keeper FOREIGN KEY (keeper_employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    CONSTRAINT fk_print_accountant FOREIGN KEY (accountant_employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    CONSTRAINT fk_print_director FOREIGN KEY (director_employee_id) REFERENCES employees(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE import_bill ADD COLUMN IF NOT EXISTS print_snapshot LONGTEXT NULL
    CHECK (print_snapshot IS NULL OR JSON_VALID(print_snapshot));
ALTER TABLE export_bill ADD COLUMN IF NOT EXISTS print_snapshot LONGTEXT NULL
    CHECK (print_snapshot IS NULL OR JSON_VALID(print_snapshot));
