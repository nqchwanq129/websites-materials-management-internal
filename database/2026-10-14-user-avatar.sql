-- Personal avatars are private files; only their relative path belongs in the DB.
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_path VARCHAR(255) NULL;
