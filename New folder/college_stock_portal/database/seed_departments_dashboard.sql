USE college_stock_db;

INSERT INTO departments (name, code)
SELECT 'Central Administrative Cell', 'CAC'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM departments WHERE code = 'CAC' OR name = 'Central Administrative Cell');

INSERT INTO departments (name, code)
SELECT 'Artificial Intelligence and Machine Learning', 'AIML'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM departments WHERE code = 'AIML' OR name = 'Artificial Intelligence and Machine Learning');

INSERT INTO departments (name, code)
SELECT 'Artificial Intelligence and Data Science', 'AIDS'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM departments WHERE code = 'AIDS' OR name = 'Artificial Intelligence and Data Science');

INSERT INTO departments (name, code)
SELECT 'Information Science and Engineering', 'ISE'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM departments WHERE code = 'ISE' OR name = 'Information Science and Engineering');

INSERT INTO departments (name, code)
SELECT 'Electronics and Communication Engineering', 'ECE'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM departments WHERE code = 'ECE' OR name = 'Electronics and Communication Engineering');

INSERT INTO departments (name, code)
SELECT 'Electrical and Electronics Engineering', 'EEE'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM departments WHERE code = 'EEE' OR name = 'Electrical and Electronics Engineering');

INSERT INTO users (department_id, name, email, password_hash, role, status)
SELECT d.id, CONCAT(d.code, ' Department User'), LOWER(CONCAT(d.code, '@college.test')),
       '$2y$10$pzPy3XX7zXIj8sB4lx2BVefK9hHGmMPnMFqXlcuYWAA9OndlOeg4a', 'DEPARTMENT', 'ACTIVE'
FROM departments d
WHERE d.code IN ('CAC','AIML','AIDS','ISE','ECE','EEE')
  AND NOT EXISTS (
      SELECT 1
      FROM users u
      WHERE u.email = LOWER(CONCAT(d.code, '@college.test'))
  );

INSERT INTO items (item_code,item_name,category_id,quantity,unit,unit_price,minimum_stock,storage_location,description,created_by)
SELECT 'ST-FILE-001','Box File', c.id, 120, 'Nos', 65, 20, 'Store Room A', 'Department office files', 1
FROM categories c
WHERE c.name='Stationary'
  AND NOT EXISTS (SELECT 1 FROM items WHERE item_code = 'ST-FILE-001');

INSERT INTO items (item_code,item_name,category_id,quantity,unit,unit_price,minimum_stock,storage_location,description,created_by)
SELECT 'IT-KBD-001','USB Keyboard', c.id, 35, 'Nos', 520, 8, 'IT Store', 'Computer lab keyboard', 1
FROM categories c
WHERE c.name='Computer & IT'
  AND NOT EXISTS (SELECT 1 FROM items WHERE item_code = 'IT-KBD-001');

INSERT INTO items (item_code,item_name,category_id,quantity,unit,unit_price,minimum_stock,storage_location,description,created_by)
SELECT 'LAB-BRD-001','Breadboard', c.id, 75, 'Nos', 120, 12, 'ECE Lab Store', 'Electronics lab breadboard', 1
FROM categories c
WHERE c.name='Electronics & Lab'
  AND NOT EXISTS (SELECT 1 FROM items WHERE item_code = 'LAB-BRD-001');

INSERT INTO items (item_code,item_name,category_id,quantity,unit,unit_price,minimum_stock,storage_location,description,created_by)
SELECT 'EL-WIR-001','Electrical Wire Roll', c.id, 45, 'Roll', 780, 6, 'Electrical Shelf', 'Electrical lab and maintenance wire', 1
FROM categories c
WHERE c.name='Electrical'
  AND NOT EXISTS (SELECT 1 FROM items WHERE item_code = 'EL-WIR-001');

INSERT INTO items (item_code,item_name,category_id,quantity,unit,unit_price,minimum_stock,storage_location,description,created_by)
SELECT 'HK-SAN-001','Sanitizer Bottle', c.id, 90, 'Bottle', 85, 15, 'Housekeeping Rack', 'Department sanitizer stock', 1
FROM categories c
WHERE c.name='Housekeeping'
  AND NOT EXISTS (SELECT 1 FROM items WHERE item_code = 'HK-SAN-001');

INSERT INTO requests (request_no, department_id, requested_by, purpose, status, ietw_remarks, ietw_processed_by, ietw_processed_at, gsssr_remarks, gsssr_approved_by, gsssr_approved_at, admin_issued_by, admin_issued_at, created_at)
SELECT CONCAT('SAMPLE-', d.code, '-2022'), d.id, u.id, 'Sample annual department stock requirement', 'ISSUED', 'Consolidated sample', i.id, '2022-08-10 09:45:00', 'Approved for academic use', g.id, '2022-08-10 10:00:00', g.id, '2022-08-11 12:00:00', '2022-08-10 09:30:00'
FROM departments d
JOIN users u ON u.department_id=d.id AND u.role='DEPARTMENT'
JOIN users i ON i.role='IETW'
JOIN users g ON g.role='GSSSR'
WHERE d.code IN ('CAC','AIML','AIDS','ISE','ECE','EEE')
  AND NOT EXISTS (SELECT 1 FROM requests r WHERE r.request_no = CONCAT('SAMPLE-', d.code, '-2022'));

INSERT INTO requests (request_no, department_id, requested_by, purpose, status, ietw_remarks, ietw_processed_by, ietw_processed_at, gsssr_remarks, gsssr_approved_by, gsssr_approved_at, admin_issued_by, admin_issued_at, created_at)
SELECT CONCAT('SAMPLE-', d.code, '-2023'), d.id, u.id, 'Sample annual department stock requirement', 'ISSUED', 'Consolidated sample', i.id, '2023-07-12 09:45:00', 'Approved for academic use', g.id, '2023-07-12 10:00:00', g.id, '2023-07-13 12:00:00', '2023-07-12 09:30:00'
FROM departments d
JOIN users u ON u.department_id=d.id AND u.role='DEPARTMENT'
JOIN users i ON i.role='IETW'
JOIN users g ON g.role='GSSSR'
WHERE d.code IN ('CAC','AIML','AIDS','ISE','ECE','EEE')
  AND NOT EXISTS (SELECT 1 FROM requests r WHERE r.request_no = CONCAT('SAMPLE-', d.code, '-2023'));

INSERT INTO requests (request_no, department_id, requested_by, purpose, status, ietw_remarks, ietw_processed_by, ietw_processed_at, gsssr_remarks, gsssr_approved_by, gsssr_approved_at, admin_issued_by, admin_issued_at, created_at)
SELECT CONCAT('SAMPLE-', d.code, '-2024'), d.id, u.id, 'Sample annual department stock requirement', 'ISSUED', 'Consolidated sample', i.id, '2024-06-15 09:45:00', 'Approved for academic use', g.id, '2024-06-15 10:00:00', g.id, '2024-06-16 12:00:00', '2024-06-15 09:30:00'
FROM departments d
JOIN users u ON u.department_id=d.id AND u.role='DEPARTMENT'
JOIN users i ON i.role='IETW'
JOIN users g ON g.role='GSSSR'
WHERE d.code IN ('CAC','AIML','AIDS','ISE','ECE','EEE')
  AND NOT EXISTS (SELECT 1 FROM requests r WHERE r.request_no = CONCAT('SAMPLE-', d.code, '-2024'));

INSERT INTO requests (request_no, department_id, requested_by, purpose, status, ietw_remarks, ietw_processed_by, ietw_processed_at, gsssr_remarks, gsssr_approved_by, gsssr_approved_at, admin_issued_by, admin_issued_at, created_at)
SELECT CONCAT('SAMPLE-', d.code, '-2025'), d.id, u.id, 'Sample annual department stock requirement', 'ISSUED', 'Consolidated sample', i.id, '2025-05-14 09:45:00', 'Approved for academic use', g.id, '2025-05-14 10:00:00', g.id, '2025-05-15 12:00:00', '2025-05-14 09:30:00'
FROM departments d
JOIN users u ON u.department_id=d.id AND u.role='DEPARTMENT'
JOIN users i ON i.role='IETW'
JOIN users g ON g.role='GSSSR'
WHERE d.code IN ('CAC','AIML','AIDS','ISE','ECE','EEE')
  AND NOT EXISTS (SELECT 1 FROM requests r WHERE r.request_no = CONCAT('SAMPLE-', d.code, '-2025'));

INSERT INTO requests (request_no, department_id, requested_by, purpose, status, ietw_remarks, ietw_processed_by, ietw_processed_at, gsssr_remarks, gsssr_approved_by, gsssr_approved_at, admin_issued_by, admin_issued_at, created_at)
SELECT CONCAT('SAMPLE-', d.code, '-2026'), d.id, u.id, 'Sample annual department stock requirement', 'ISSUED', 'Consolidated sample', i.id, '2026-04-10 09:45:00', 'Approved for academic use', g.id, '2026-04-10 10:00:00', g.id, '2026-04-11 12:00:00', '2026-04-10 09:30:00'
FROM departments d
JOIN users u ON u.department_id=d.id AND u.role='DEPARTMENT'
JOIN users i ON i.role='IETW'
JOIN users g ON g.role='GSSSR'
WHERE d.code IN ('CAC','AIML','AIDS','ISE','ECE','EEE')
  AND NOT EXISTS (SELECT 1 FROM requests r WHERE r.request_no = CONCAT('SAMPLE-', d.code, '-2026'));

INSERT INTO request_items (request_id, item_id, requested_quantity, ietw_recommended_qty, gsssr_approved_qty, issued_quantity, created_at)
SELECT r.id, i.id,
       CASE d.code WHEN 'CAC' THEN 40 WHEN 'AIML' THEN 22 WHEN 'AIDS' THEN 22 WHEN 'ISE' THEN 24 WHEN 'ECE' THEN 30 ELSE 28 END,
       CASE d.code WHEN 'CAC' THEN 40 WHEN 'AIML' THEN 22 WHEN 'AIDS' THEN 22 WHEN 'ISE' THEN 24 WHEN 'ECE' THEN 30 ELSE 28 END,
       CASE d.code WHEN 'CAC' THEN 36 WHEN 'AIML' THEN 20 WHEN 'AIDS' THEN 20 WHEN 'ISE' THEN 22 WHEN 'ECE' THEN 27 ELSE 25 END,
       CASE d.code WHEN 'CAC' THEN 36 WHEN 'AIML' THEN 20 WHEN 'AIDS' THEN 20 WHEN 'ISE' THEN 22 WHEN 'ECE' THEN 27 ELSE 25 END,
       r.created_at
FROM requests r
JOIN departments d ON d.id=r.department_id
JOIN items i ON i.item_code='ST-A4-001'
WHERE r.request_no LIKE 'SAMPLE-%'
  AND NOT EXISTS (SELECT 1 FROM request_items x WHERE x.request_id=r.id AND x.item_id=i.id);

INSERT INTO request_items (request_id, item_id, requested_quantity, ietw_recommended_qty, gsssr_approved_qty, issued_quantity, created_at)
SELECT r.id, i.id,
       CASE d.code WHEN 'CAC' THEN 10 WHEN 'AIML' THEN 16 WHEN 'AIDS' THEN 16 WHEN 'ISE' THEN 14 WHEN 'ECE' THEN 18 ELSE 20 END,
       CASE d.code WHEN 'CAC' THEN 10 WHEN 'AIML' THEN 16 WHEN 'AIDS' THEN 16 WHEN 'ISE' THEN 14 WHEN 'ECE' THEN 18 ELSE 20 END,
       CASE d.code WHEN 'CAC' THEN 8 WHEN 'AIML' THEN 14 WHEN 'AIDS' THEN 14 WHEN 'ISE' THEN 12 WHEN 'ECE' THEN 16 ELSE 18 END,
       CASE d.code WHEN 'CAC' THEN 8 WHEN 'AIML' THEN 14 WHEN 'AIDS' THEN 14 WHEN 'ISE' THEN 12 WHEN 'ECE' THEN 16 ELSE 18 END,
       r.created_at
FROM requests r
JOIN departments d ON d.id=r.department_id
JOIN items i ON i.item_code=CASE d.code WHEN 'CAC' THEN 'ST-FILE-001' WHEN 'AIML' THEN 'IT-KBD-001' WHEN 'AIDS' THEN 'IT-KBD-001' WHEN 'ISE' THEN 'IT-MSE-001' WHEN 'ECE' THEN 'LAB-BRD-001' ELSE 'EL-WIR-001' END
WHERE r.request_no LIKE 'SAMPLE-%'
  AND NOT EXISTS (SELECT 1 FROM request_items x WHERE x.request_id=r.id AND x.item_id=i.id);
