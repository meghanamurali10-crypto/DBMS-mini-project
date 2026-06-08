USE college_stock_db;

CREATE TABLE IF NOT EXISTS department_inventory (
  department_id INT NOT NULL,
  item_id INT NOT NULL,
  quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (department_id, item_id),
  FOREIGN KEY (department_id) REFERENCES departments(id),
  FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB;
