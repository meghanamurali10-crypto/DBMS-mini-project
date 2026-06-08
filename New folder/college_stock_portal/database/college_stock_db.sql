CREATE DATABASE IF NOT EXISTS college_stock_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE college_stock_db;

SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS pdf_export_logs, excel_import_logs, notifications, login_history, activity_logs, stock_book, invoices, approvals, department_inventory, request_items, requests, stock_transactions, items, categories, users, departments;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  code VARCHAR(20) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  department_id INT NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('GSSSR','IETW','DEPARTMENT') NOT NULL,
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

CREATE TABLE categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  description TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_code VARCHAR(60) NOT NULL UNIQUE,
  item_name VARCHAR(180) NOT NULL,
  category_id INT NOT NULL,
  quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
  unit VARCHAR(40) NOT NULL DEFAULT 'Nos',
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  minimum_stock DECIMAL(12,2) NOT NULL DEFAULT 0,
  storage_location VARCHAR(160) NULL,
  description TEXT NULL,
  status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  archive_reason TEXT NULL,
  archived_by INT NULL,
  archived_at DATETIME NULL,
  deletion_approval_status ENUM('NOT_REQUESTED','PENDING_GSSSR','APPROVED_BY_GSSSR','REJECTED_BY_GSSSR') NOT NULL DEFAULT 'NOT_REQUESTED',
  invoice_path VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_items_category (category_id),
  INDEX idx_items_low_stock (quantity, minimum_stock),
  FOREIGN KEY (category_id) REFERENCES categories(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (archived_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE stock_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transaction_no VARCHAR(80) NOT NULL UNIQUE,
  item_id INT NOT NULL,
  request_item_id INT NULL,
  type ENUM('INWARD','OUTWARD','RETURN','ADJUSTMENT') NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  previous_quantity DECIMAL(12,2) NOT NULL,
  new_quantity DECIMAL(12,2) NOT NULL,
  remarks TEXT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_txn_item (item_id),
  INDEX idx_txn_type_date (type, created_at),
  FOREIGN KEY (item_id) REFERENCES items(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Requests now go: DEPARTMENT -> IETW (consolidates) -> GSSSR (final approval & issue)
CREATE TABLE requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_no VARCHAR(80) NOT NULL UNIQUE,
  department_id INT NOT NULL,
  requested_by INT NOT NULL,
  purpose TEXT NOT NULL,
  status ENUM('PENDING_IETW','CONSOLIDATED_BY_IETW','APPROVED_BY_GSSSR','PARTIALLY_APPROVED_BY_GSSSR','REJECTED_BY_GSSSR','ISSUED','PARTIALLY_ISSUED') NOT NULL DEFAULT 'PENDING_IETW',
  ietw_remarks TEXT NULL,
  ietw_processed_by INT NULL,
  ietw_processed_at DATETIME NULL,
  gsssr_remarks TEXT NULL,
  gsssr_approved_by INT NULL,
  gsssr_approved_at DATETIME NULL,
  admin_issued_by INT NULL,
  admin_issued_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_req_status (status),
  INDEX idx_req_dept_date (department_id, created_at),
  FOREIGN KEY (department_id) REFERENCES departments(id),
  FOREIGN KEY (requested_by) REFERENCES users(id),
  FOREIGN KEY (ietw_processed_by) REFERENCES users(id),
  FOREIGN KEY (gsssr_approved_by) REFERENCES users(id),
  FOREIGN KEY (admin_issued_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE request_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  item_id INT NOT NULL,
  requested_quantity DECIMAL(12,2) NOT NULL,
  justification TEXT NULL,
  ietw_recommended_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
  gsssr_approved_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
  issued_quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
  FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB;

ALTER TABLE stock_transactions ADD FOREIGN KEY (request_item_id) REFERENCES request_items(id);

CREATE TABLE department_inventory (
  department_id INT NOT NULL,
  item_id INT NOT NULL,
  quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (department_id, item_id),
  FOREIGN KEY (department_id) REFERENCES departments(id),
  FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB;

CREATE TABLE invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NULL,
  invoice_no VARCHAR(100) NULL,
  file_path VARCHAR(255) NOT NULL,
  uploaded_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id) REFERENCES items(id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE stock_book (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  transaction_type ENUM('INWARD','OUTWARD','RETURN','ADJUSTMENT') NOT NULL,
  inward_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
  outward_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
  balance_qty DECIMAL(12,2) NOT NULL,
  remarks TEXT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_stock_book_date (created_at),
  FOREIGN KEY (item_id) REFERENCES items(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(255) NOT NULL,
  ip_address VARCHAR(60) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activity_user_date (user_id, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE login_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  email VARCHAR(160) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  ip_address VARCHAR(60) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  title VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE excel_import_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  file_name VARCHAR(255) NOT NULL,
  action_type ENUM('IMPORT','EXPORT') NOT NULL,
  status ENUM('SUCCESS','FAILED') NOT NULL,
  rows_processed INT NOT NULL DEFAULT 0,
  remarks TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE pdf_export_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  report_type VARCHAR(80) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  ip_address VARCHAR(60) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Departments
INSERT INTO departments (name, code) VALUES
('Computer Science and Engineering','CSE'),
('Electronics and Communication Engineering','ECE'),
('Electrical and Electronics Engineering','EEE'),
('Artificial Intelligence and Machine Learning','AIML'),
('Artificial Intelligence and Data Science','AIDS'),
('Information Science and Engineering','ISE'),
('Central Administrative Cell','CAC'),
('Administration','ADMIN');

-- Categories
INSERT INTO categories (name, description) VALUES
('Stationary','Office and academic stationery'),
('Housekeeping','Cleaning and maintenance supplies'),
('Electrical','Electrical stock and fixtures'),
('Electronics & Lab','Lab equipment and consumables'),
('Computer & IT','Computer peripherals and IT supplies'),
('Furniture','Tables, chairs, cabinets and fixtures'),
('Pooja Items','Ceremonial inventory'),
('Others','Miscellaneous stock');

-- Users: GSSSR = super admin, IETW = middleman/consolidator
-- Password for all demo accounts: "password"
INSERT INTO users (department_id, name, email, password_hash, role) VALUES
(NULL, 'GSSSR Admin', 'gsssr@college.test', '$2y$10$pzPy3XX7zXIj8sB4lx2BVefK9hHGmMPnMFqXlcuYWAA9OndlOeg4a', 'GSSSR'),
(NULL, 'IETW Admin', 'ietw@college.test', '$2y$10$pzPy3XX7zXIj8sB4lx2BVefK9hHGmMPnMFqXlcuYWAA9OndlOeg4a', 'IETW'),
(1, 'CSE Department User', 'cse@college.test', '$2y$10$pzPy3XX7zXIj8sB4lx2BVefK9hHGmMPnMFqXlcuYWAA9OndlOeg4a', 'DEPARTMENT'),
(2, 'ECE Department User', 'ece@college.test', '$2y$10$pzPy3XX7zXIj8sB4lx2BVefK9hHGmMPnMFqXlcuYWAA9OndlOeg4a', 'DEPARTMENT'),
(3, 'EEE Department User', 'eee@college.test', '$2y$10$pzPy3XX7zXIj8sB4lx2BVefK9hHGmMPnMFqXlcuYWAA9OndlOeg4a', 'DEPARTMENT'),
(4, 'AIML Department User', 'aiml@college.test', '$2y$10$pzPy3XX7zXIj8sB4lx2BVefK9hHGmMPnMFqXlcuYWAA9OndlOeg4a', 'DEPARTMENT'),
(5, 'AIDS Department User', 'aids@college.test', '$2y$10$pzPy3XX7zXIj8sB4lx2BVefK9hHGmMPnMFqXlcuYWAA9OndlOeg4a', 'DEPARTMENT'),
(6, 'ISE Department User', 'ise@college.test', '$2y$10$pzPy3XX7zXIj8sB4lx2BVefK9hHGmMPnMFqXlcuYWAA9OndlOeg4a', 'DEPARTMENT'),
(7, 'CAC Department User', 'cac@college.test', '$2y$10$pzPy3XX7zXIj8sB4lx2BVefK9hHGmMPnMFqXlcuYWAA9OndlOeg4a', 'DEPARTMENT');

-- Seed items
INSERT INTO items (item_code,item_name,category_id,quantity,unit,unit_price,minimum_stock,storage_location,description,created_by) VALUES
('ST-A4-001','A4 Sheets Bundle',1,50,'Bundle',280,10,'Store Room A','Printer and office paper',1),
('HK-PH-001','Phenyl Can',2,12,'Can',180,5,'Housekeeping Rack','Floor cleaning liquid',1),
('EL-BL-001','LED Bulb 12W',3,40,'Nos',95,10,'Electrical Shelf','Replacement bulbs',1),
('LAB-ARD-001','Arduino Kit',4,8,'Kit',1450,3,'CSE Lab Store','Microcontroller kit',1),
('IT-MSE-001','USB Optical Mouse',5,25,'Nos',350,8,'IT Store','Computer peripheral',1),
('FUR-CHR-001','Classroom Chair',6,60,'Nos',750,10,'Furniture Bay','Student chair',1);

INSERT INTO stock_book (item_id, transaction_type, inward_qty, outward_qty, balance_qty, remarks, created_by)
SELECT id, 'INWARD', quantity, 0, quantity, 'Opening stock', 1 FROM items;
