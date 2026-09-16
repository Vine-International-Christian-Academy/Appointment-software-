-- Vine Appointments MySQL schema
-- Import this file into the database named in api/config.php.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS learning_centers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  campus VARCHAR(255) NOT NULL,
  contact_person VARCHAR(255) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointment_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_name VARCHAR(255) NOT NULL,
  parent_email VARCHAR(320) NOT NULL,
  parent_phone VARCHAR(80) NOT NULL,
  notes TEXT NOT NULL,
  status ENUM('pending', 'confirmed', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_requests_status (status),
  INDEX idx_requests_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointment_availability_slots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  learning_center_id INT UNSIGNED NOT NULL,
  date DATE NOT NULL,
  time CHAR(5) NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  is_booked TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  INDEX idx_slots_open (is_booked, date, time),
  INDEX idx_slots_center (learning_center_id, date, time),
  CONSTRAINT fk_slots_center
    FOREIGN KEY (learning_center_id) REFERENCES learning_centers(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointment_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  appointment_request_id INT UNSIGNED NOT NULL,
  availability_slot_id INT UNSIGNED NULL,
  student_name VARCHAR(255) NOT NULL,
  grade_level VARCHAR(100) NOT NULL,
  learning_center_id INT UNSIGNED NOT NULL,
  preferred_date DATE NOT NULL,
  preferred_time CHAR(5) NOT NULL,
  reason TEXT NOT NULL,
  PRIMARY KEY (id),
  INDEX idx_items_request (appointment_request_id),
  INDEX idx_items_center (learning_center_id, preferred_date),
  CONSTRAINT fk_items_request
    FOREIGN KEY (appointment_request_id) REFERENCES appointment_requests(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_items_slot
    FOREIGN KEY (availability_slot_id) REFERENCES appointment_availability_slots(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_items_center
    FOREIGN KEY (learning_center_id) REFERENCES learning_centers(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_emails (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(320) NOT NULL,
  added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_staff_emails_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(320) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  email VARCHAR(320) NULL,
  learning_center_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_staff_users_username (username),
  INDEX idx_staff_users_center (learning_center_id),
  CONSTRAINT fk_staff_users_center
    FOREIGN KEY (learning_center_id) REFERENCES learning_centers(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO learning_centers (name, campus, contact_person)
SELECT 'Early Learning Center', 'Vine Main Campus', 'Admissions Office'
WHERE NOT EXISTS (SELECT 1 FROM learning_centers WHERE name = 'Early Learning Center');

INSERT INTO learning_centers (name, campus, contact_person)
SELECT 'Elementary Learning Center', 'Vine Main Campus', 'Elementary Coordinator'
WHERE NOT EXISTS (SELECT 1 FROM learning_centers WHERE name = 'Elementary Learning Center');

INSERT INTO learning_centers (name, campus, contact_person)
SELECT 'Junior High Learning Center', 'Vine Academic Wing', 'Junior High Coordinator'
WHERE NOT EXISTS (SELECT 1 FROM learning_centers WHERE name = 'Junior High Learning Center');

INSERT INTO learning_centers (name, campus, contact_person)
SELECT 'Senior High Learning Center', 'Vine Academic Wing', 'Senior High Coordinator'
WHERE NOT EXISTS (SELECT 1 FROM learning_centers WHERE name = 'Senior High Learning Center');

SET FOREIGN_KEY_CHECKS = 1;