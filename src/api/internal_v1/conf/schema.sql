
-- -----------------------------------------------------
-- Table `files`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `files` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `inode_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL,
  `owner_id` INT(11) NOT NULL,
  `name` VARCHAR(200) COLLATE 'utf8mb4_unicode_ci' NOT NULL,
  `upload_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `upload_ip` VARBINARY(16) NULL DEFAULT NULL,
  PRIMARY KEY (`id`));


-- -----------------------------------------------------
-- Table `inodes`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `inodes` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `hash_blake2b512_160` BINARY(20) NOT NULL,
  `size` BIGINT(20) NOT NULL,
  `compressed_size` BIGINT(20) NULL DEFAULT NULL,
  `compression_type` TINYINT(4) NULL DEFAULT NULL,
  `create_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`));


-- -----------------------------------------------------
-- Table `user`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `user` (
  `id` INT(10) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(20) COLLATE 'utf8mb4_unicode_ci' NOT NULL,
  `email` VARCHAR(200) COLLATE 'utf8mb4_unicode_ci' NULL DEFAULT NULL,
  `password_bcrypt` VARCHAR(70) CHARACTER SET 'ascii' NOT NULL,
  `create_time` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP(),
  `misc` TEXT COLLATE 'utf8mb4_unicode_ci' NULL DEFAULT NULL,
  PRIMARY KEY (`id`));


-- -----------------------------------------------------
-- Table `user_api_keys`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_api_keys` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `api_key` VARCHAR(20) CHARACTER SET 'ascii' NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `create_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`));
