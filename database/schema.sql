CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(50) PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(190) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(190) NOT NULL,
  ip_address VARCHAR(100) NOT NULL,
  succeeded TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_attempt_lookup (username, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  external_code VARCHAR(20) NULL,
  official_name VARCHAR(255) NOT NULL,
  normalized_name VARCHAR(255) NOT NULL,
  cif VARCHAR(50) NULL,
  main_address VARCHAR(500) NOT NULL,
  postal_code VARCHAR(20) NULL,
  city VARCHAR(100) NULL,
  province VARCHAR(100) NULL,
  country VARCHAR(100) NOT NULL DEFAULT 'España',
  notes TEXT NULL,
  imap_folder_name VARCHAR(190) NULL,
  drive_folder_id VARCHAR(190) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_communities_active (active),
  UNIQUE KEY uq_communities_external_code (external_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_aliases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  community_id BIGINT UNSIGNED NOT NULL,
  alias_type VARCHAR(50) NOT NULL,
  value VARCHAR(500) NOT NULL,
  normalized_value VARCHAR(500) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_alias_community FOREIGN KEY (community_id) REFERENCES communities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_identifiers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  community_id BIGINT UNSIGNED NOT NULL,
  identifier_type VARCHAR(50) NOT NULL,
  value VARCHAR(255) NOT NULL,
  normalized_value VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_community_identifier (identifier_type, normalized_value),
  CONSTRAINT fk_identifier_community FOREIGN KEY (community_id) REFERENCES communities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  normalized_name VARCHAR(100) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  official_name VARCHAR(255) NOT NULL,
  normalized_name VARCHAR(255) NOT NULL,
  cif VARCHAR(50) NULL,
  main_service_type_id BIGINT UNSIGNED NULL,
  address VARCHAR(500) NULL,
  email VARCHAR(255) NULL,
  phone VARCHAR(50) NULL,
  website VARCHAR(500) NULL,
  notes TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_supplier_normalized (normalized_name),
  CONSTRAINT fk_supplier_service FOREIGN KEY (main_service_type_id) REFERENCES service_types(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_service_types (
  supplier_id BIGINT UNSIGNED NOT NULL,
  service_type_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (supplier_id, service_type_id),
  CONSTRAINT fk_sst_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
  CONSTRAINT fk_sst_service FOREIGN KEY (service_type_id) REFERENCES service_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_aliases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NOT NULL,
  alias_type VARCHAR(50) NOT NULL,
  value VARCHAR(500) NOT NULL,
  normalized_value VARCHAR(500) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_alias_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_suppliers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  community_id BIGINT UNSIGNED NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(50) NOT NULL,
  contract_reference VARCHAR(255) NULL,
  source_column VARCHAR(100) NOT NULL,
  raw_provider_name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_community_supplier_category (community_id,supplier_id,category),
  INDEX idx_cs_community (community_id),
  CONSTRAINT fk_cs_community FOREIGN KEY (community_id) REFERENCES communities(id) ON DELETE CASCADE,
  CONSTRAINT fk_cs_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS drive_folders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_drive_id VARCHAR(190) NOT NULL,
  folder_name VARCHAR(255) NOT NULL,
  drive_id VARCHAR(190) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_drive_parent_name (parent_drive_id,folder_name),
  UNIQUE KEY uq_drive_id (drive_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS drive_year_state (
  id TINYINT UNSIGNED PRIMARY KEY,
  active_year SMALLINT UNSIGNED NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS drive_year_rollovers (
  year_closed SMALLINT UNSIGNED PRIMARY KEY,
  status VARCHAR(30) NOT NULL,
  moved_files INT UNSIGNED NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  error_message TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mailboxes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  descriptive_name VARCHAR(190) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  imap_host VARCHAR(255) NOT NULL DEFAULT 'imap.ionos.es',
  imap_port SMALLINT UNSIGNED NOT NULL DEFAULT 993,
  use_ssl TINYINT(1) NOT NULL DEFAULT 1,
  username VARCHAR(255) NOT NULL,
  encrypted_password TEXT NOT NULL,
  input_folder VARCHAR(255) NOT NULL DEFAULT 'INBOX',
  active TINYINT(1) NOT NULL DEFAULT 1,
  process_existing_on_activate TINYINT(1) NOT NULL DEFAULT 0,
  baseline_uidvalidity VARCHAR(50) NULL,
  baseline_uid BIGINT UNSIGNED NULL,
  baseline_captured_at DATETIME NULL,
  last_connection_at DATETIME NULL,
  last_connection_ok TINYINT(1) NULL,
  last_error TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS processed_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mailbox_id BIGINT UNSIGNED NOT NULL,
  uidvalidity VARCHAR(100) NOT NULL,
  message_uid VARCHAR(100) NOT NULL,
  message_id_header VARCHAR(500) NULL,
  sender VARCHAR(500) NULL,
  subject VARCHAR(500) NULL,
  received_at VARCHAR(100) NULL,
  status VARCHAR(50) NOT NULL,
  document_count INT UNSIGNED NOT NULL DEFAULT 0,
  imap_destination VARCHAR(500) NULL,
  imap_move_status VARCHAR(50) NOT NULL DEFAULT 'not_required',
  error_message TEXT NULL,
  processed_at DATETIME NOT NULL,
  UNIQUE KEY uq_processed_message (mailbox_id, uidvalidity, message_uid),
  CONSTRAINT fk_message_mailbox FOREIGN KEY (mailbox_id) REFERENCES mailboxes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS processed_attachments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mailbox_id BIGINT UNSIGNED NOT NULL,
  uidvalidity VARCHAR(100) NOT NULL,
  message_uid VARCHAR(100) NOT NULL,
  original_filename VARCHAR(500) NOT NULL,
  attachment_sha256 CHAR(64) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(255) NULL,
  raw_supplier_name VARCHAR(255) NULL,
  provider_cif VARCHAR(50) NULL,
  service_type VARCHAR(100) NULL,
  supply_address VARCHAR(500) NULL,
  amount DECIMAL(15,2) NULL,
  currency VARCHAR(10) NULL,
  invoice_date DATE NULL,
  invoice_number VARCHAR(190) NULL,
  community_id BIGINT UNSIGNED NULL,
  confidence DECIMAL(6,3) NULL,
  final_filename VARCHAR(500) NULL,
  output_path VARCHAR(1000) NULL,
  status VARCHAR(50) NOT NULL,
  extraction_json JSON NULL,
  decision_json JSON NULL,
  debug_trace_json JSON NULL,
  error_message TEXT NULL,
  extractor_version VARCHAR(100) NULL,
  drive_file_id VARCHAR(190) NULL,
  drive_path VARCHAR(1000) NULL,
  drive_status VARCHAR(50) NULL,
  processed_at DATETIME NOT NULL,
  UNIQUE KEY uq_processed_attachment (mailbox_id, uidvalidity, message_uid, attachment_sha256),
  INDEX idx_attachment_hash (attachment_sha256),
  INDEX idx_attachment_status (status),
  CONSTRAINT fk_attachment_mailbox FOREIGN KEY (mailbox_id) REFERENCES mailboxes(id),
  CONSTRAINT fk_attachment_community FOREIGN KEY (community_id) REFERENCES communities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS processing_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_uuid CHAR(36) NOT NULL UNIQUE,
  trigger_type VARCHAR(50) NOT NULL,
  triggered_by_user_id INT UNSIGNED NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status VARCHAR(50) NOT NULL,
  mailboxes_count INT UNSIGNED NOT NULL DEFAULT 0,
  messages_reviewed INT UNSIGNED NOT NULL DEFAULT 0,
  documents_detected INT UNSIGNED NOT NULL DEFAULT 0,
  classified_count INT UNSIGNED NOT NULL DEFAULT 0,
  unclassified_count INT UNSIGNED NOT NULL DEFAULT 0,
  needs_review_count INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_count INT UNSIGNED NOT NULL DEFAULT 0,
  openai_input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  openai_output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_locks (
  name VARCHAR(100) PRIMARY KEY,
  owner VARCHAR(190) NOT NULL,
  acquired_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(100) NOT NULL,
  entity_id VARCHAR(190) NULL,
  old_values_json JSON NULL,
  new_values_json JSON NULL,
  ip_address VARCHAR(100) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO service_types (name, normalized_name) VALUES
('Agua','agua'),('Luz','luz'),('Gas','gas'),('Telecomunicaciones','telecomunicaciones'),
('Seguros','seguros'),('Mantenimiento','mantenimiento'),('Ascensores','ascensores'),
('Limpieza','limpieza'),('Automoción','automocion'),('Otros','otros');

INSERT IGNORE INTO service_types (name, normalized_name) VALUES
('Ascensor','ascensor'),('Electricidad','electricidad'),('Agua','agua'),('Extintores','extintores'),
('Jardinería','jardineria'),('Piscina','piscina'),('Descalcificador','descalcificador');
