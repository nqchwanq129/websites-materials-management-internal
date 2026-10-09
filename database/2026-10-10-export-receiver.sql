-- Apply once to the existing database; old receipts remain unchanged.
ALTER TABLE export_bill ADD COLUMN receiver_name VARCHAR(200) NULL AFTER reason;
