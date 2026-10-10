-- Z-Pay Swift Firebase-compatible MySQL production store.
-- Apply only to a fresh production database with MySQL 8+ or MariaDB 10.5+.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS zps_environment_guard (
    id TINYINT UNSIGNED NOT NULL,
    environment VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT chk_environment_guard CHECK (id = 1 AND environment = 'PRODUCTION')
) ENGINE=InnoDB;

INSERT INTO zps_environment_guard (id, environment, created_at)
VALUES (1, 'PRODUCTION', UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE environment = environment;

CREATE TABLE IF NOT EXISTS zps_firebase_nodes (
    path VARBINARY(1024) NOT NULL,
    parent_path VARBINARY(1024) NOT NULL,
    node_key VARBINARY(768) NOT NULL,
    depth SMALLINT UNSIGNED NOT NULL,
    node_type TINYINT UNSIGNED NOT NULL,
    value_json JSON NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (path),
    KEY idx_firebase_nodes_parent (parent_path(768), node_key(191)),
    KEY idx_firebase_nodes_updated (updated_at),
    CONSTRAINT chk_firebase_node_type CHECK (node_type IN (1, 2, 3))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS zps_firebase_versions (
    path VARBINARY(1024) NOT NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (path),
    KEY idx_firebase_versions_updated (updated_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS zps_migration_checkpoints (
    source_path VARBINARY(1024) NOT NULL,
    source_hash BINARY(32) NULL,
    source_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    migrated_at DATETIME(6) NOT NULL,
    verified_at DATETIME(6) NULL,
    state ENUM('MIGRATED', 'VERIFIED', 'MISMATCH', 'FAILED') NOT NULL,
    error_code VARCHAR(64) NULL,
    PRIMARY KEY (source_path),
    KEY idx_migration_state (state, migrated_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS zps_migration_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mode ENUM('IMPORT', 'RECONCILE', 'FINAL_DELTA') NOT NULL,
    state ENUM('RUNNING', 'COMPLETED', 'FAILED') NOT NULL,
    started_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    processed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mismatch_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_path VARBINARY(1024) NULL,
    error_code VARCHAR(64) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_migration_run_token (run_token),
    KEY idx_migration_runs_state (state, started_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
