-- Optional per-piece serials. Array positions match the pieces in the receipt line.
ALTER TABLE import_bill_details ADD COLUMN IF NOT EXISTS serial_numbers LONGTEXT NULL
    CHECK (serial_numbers IS NULL OR (JSON_VALID(serial_numbers) AND JSON_TYPE(serial_numbers) = 'ARRAY'));
