-- Tambah kolom email + reset token untuk password orang tua
ALTER TABLE users
  ADD COLUMN email VARCHAR(150) NULL AFTER username,
  ADD COLUMN reset_token_hash VARCHAR(64) NULL AFTER password_hash,
  ADD COLUMN reset_token_expires DATETIME NULL AFTER reset_token_hash;

CREATE UNIQUE INDEX uniq_users_email ON users (email);
