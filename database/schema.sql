CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','ventas','soporte','ingenieria') NOT NULL DEFAULT 'soporte',
  status ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
  created_at DATETIME NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  rnc VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(80) NULL,
  address TEXT NULL,
  city VARCHAR(120) NULL,
  country VARCHAR(120) NULL DEFAULT 'Republica Dominicana',
  sector VARCHAR(80) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'activo',
  support_slug VARCHAR(220) NULL,
  support_token VARCHAR(64) NULL,
  support_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  INDEX idx_clients_name (name),
  INDEX idx_clients_email (email),
  INDEX idx_clients_status (status),
  INDEX idx_clients_support_slug (support_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contacts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(80) NULL,
  position VARCHAR(120) NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NULL,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipment (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  brand VARCHAR(120) NULL,
  model VARCHAR(120) NULL,
  serial VARCHAR(120) NULL,
  area VARCHAR(120) NULL,
  location VARCHAR(190) NULL,
  installation_date DATE NULL,
  warranty_until DATE NULL,
  status VARCHAR(60) NOT NULL DEFAULT 'activo',
  last_service_at DATE NULL,
  next_service_at DATE NULL,
  notes TEXT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  INDEX idx_equipment_serial (serial),
  INDEX idx_equipment_status (status),
  INDEX idx_equipment_next_service (next_service_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quotes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  quote_number VARCHAR(40) NOT NULL UNIQUE,
  title VARCHAR(190) NOT NULL,
  category VARCHAR(80) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'Borrador',
  approved_at DATETIME NULL,
  valid_until DATE NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 18,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency VARCHAR(3) NOT NULL DEFAULT 'DOP',
  exchange_rate DECIMAL(12,4) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  terms TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_quotes_status (status),
  INDEX idx_quotes_valid_until (valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quote_id INT UNSIGNED NOT NULL,
  description TEXT NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  equipment_id INT UNSIGNED NULL,
  subject VARCHAR(190) NOT NULL,
  description TEXT NOT NULL,
  priority VARCHAR(30) NOT NULL DEFAULT 'Media',
  status VARCHAR(40) NOT NULL DEFAULT 'Abierto',
  source VARCHAR(40) NOT NULL DEFAULT 'interno',
  public_reference VARCHAR(80) NULL,
  reported_by VARCHAR(160) NULL,
  reported_email VARCHAR(190) NULL,
  reported_phone VARCHAR(80) NULL,
  assigned_to INT UNSIGNED NULL,
  due_at DATE NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_tickets_status (status),
  INDEX idx_tickets_priority (priority),
  INDEX idx_tickets_due_at (due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  author_name VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(80) NULL,
  company VARCHAR(190) NULL,
  type VARCHAR(80) NULL,
  message TEXT NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'nuevo',
  created_at DATETIME NULL,
  INDEX idx_leads_status (status),
  INDEX idx_leads_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id INT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  details TEXT NULL,
  created_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_activity_entity (entity_type, entity_id),
  INDEX idx_activity_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(120) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Facturación: secuencias NCF autorizadas por la DGII
CREATE TABLE IF NOT EXISTS ncf_sequences (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  prefix VARCHAR(2) NOT NULL DEFAULT 'B',
  ncf_type VARCHAR(2) NOT NULL,
  seq_from BIGINT UNSIGNED NOT NULL DEFAULT 1,
  seq_to BIGINT UNSIGNED NOT NULL DEFAULT 0,
  seq_next BIGINT UNSIGNED NOT NULL DEFAULT 1,
  expiration DATE NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  note VARCHAR(190) NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  INDEX idx_ncf_type (prefix, ncf_type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Facturación: comprobantes fiscales
CREATE TABLE IF NOT EXISTS invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  quote_id INT UNSIGNED NULL,
  invoice_number VARCHAR(40) NOT NULL UNIQUE,
  ncf VARCHAR(19) NULL,
  ncf_type VARCHAR(2) NOT NULL DEFAULT '02',
  ncf_prefix VARCHAR(2) NOT NULL DEFAULT 'B',
  is_ecf TINYINT(1) NOT NULL DEFAULT 0,
  ecf_status VARCHAR(30) NULL,
  ecf_track_id VARCHAR(60) NULL,
  ecf_security_code VARCHAR(20) NULL,
  ecf_sign_date DATETIME NULL,
  ecf_qr_url TEXT NULL,
  ecf_xml MEDIUMTEXT NULL,
  ecf_response TEXT NULL,
  ncf_expiration DATE NULL,
  modifies_ncf VARCHAR(19) NULL,
  modifies_invoice_id INT UNSIGNED NULL,
  title VARCHAR(190) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'Borrador',
  payment_condition VARCHAR(20) NOT NULL DEFAULT 'Contado',
  payment_method VARCHAR(40) NULL,
  issue_date DATE NULL,
  due_date DATE NULL,
  taxed_base DECIMAL(12,2) NOT NULL DEFAULT 0,
  exempt_base DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 18,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  isc_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  itbis_retained DECIMAL(12,2) NOT NULL DEFAULT 0,
  isr_retained DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency VARCHAR(3) NOT NULL DEFAULT 'DOP',
  exchange_rate DECIMAL(12,4) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  terms TEXT NULL,
  client_name VARCHAR(190) NULL,
  client_rnc VARCHAR(40) NULL,
  client_address TEXT NULL,
  created_by INT UNSIGNED NULL,
  emitted_at DATETIME NULL,
  paid_at DATETIME NULL,
  voided_at DATETIME NULL,
  void_reason VARCHAR(255) NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_invoices_status (status),
  INDEX idx_invoices_client (client_id),
  INDEX idx_invoices_due (due_date),
  INDEX idx_invoices_ncf (ncf)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  description TEXT NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_exempt TINYINT(1) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  method VARCHAR(40) NULL,
  reference VARCHAR(120) NULL,
  paid_at DATE NULL,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
