-- A successful login replaces the one active session fingerprint for the account.
ALTER TABLE users ADD COLUMN IF NOT EXISTS active_session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL;
