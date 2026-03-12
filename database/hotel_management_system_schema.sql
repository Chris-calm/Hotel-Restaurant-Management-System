CREATE DATABASE IF NOT EXISTS hotel_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE hotel_system;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(30) NOT NULL DEFAULT 'staff',
  email VARCHAR(120) NULL,
  profile_picture VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_otps (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  username VARCHAR(60) NOT NULL,
  otp_code VARCHAR(10) NOT NULL,
  email VARCHAR(120) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_otps_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS guests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  email VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  id_type VARCHAR(40) NULL,
  id_number VARCHAR(60) NULL,
  id_photo_path VARCHAR(255) NULL,
  preferences TEXT NULL,
  notes TEXT NULL,
  loyalty_tier VARCHAR(20) NULL,
  loyalty_points INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('Lead','Booked','Checked In','Checked Out','Blacklisted') NOT NULL DEFAULT 'Lead',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS room_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  base_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  max_adults TINYINT UNSIGNED NOT NULL DEFAULT 2,
  max_children TINYINT UNSIGNED NOT NULL DEFAULT 0,
  image_path VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rooms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_no VARCHAR(20) NOT NULL UNIQUE,
  room_type_id INT UNSIGNED NOT NULL,
  floor VARCHAR(10) NULL,
  image_path VARCHAR(255) NULL,
  lock_provider VARCHAR(40) NULL,
  lock_device_id VARCHAR(80) NULL,
  lock_status ENUM('Locked','Unlocked','Offline') NOT NULL DEFAULT 'Locked',
  lock_battery TINYINT UNSIGNED NULL,
  lock_last_sync_at DATETIME NULL,
  status ENUM('Vacant','Occupied','Cleaning','Out of Order') NOT NULL DEFAULT 'Vacant',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rooms_room_type
    FOREIGN KEY (room_type_id) REFERENCES room_types(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS room_lock_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id INT UNSIGNED NOT NULL,
  action VARCHAR(30) NOT NULL,
  actor_user_id INT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_room_lock_logs_room
    FOREIGN KEY (room_id) REFERENCES rooms(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference_no VARCHAR(30) NOT NULL UNIQUE,
  guest_id INT UNSIGNED NOT NULL,
  source ENUM('Walk-in','Phone','Website','OTA','Agent') NOT NULL DEFAULT 'Walk-in',
  status ENUM('Pending','Confirmed','Upcoming','Checked In','Completed','Cancelled','No Show') NOT NULL DEFAULT 'Pending',
  checkin_date DATE NOT NULL,
  checkout_date DATE NOT NULL,
  deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_method ENUM('Cash','Card','GCash','Bank Transfer') NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservations_guest
    FOREIGN KEY (guest_id) REFERENCES guests(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservation_rooms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT UNSIGNED NOT NULL,
  room_id INT UNSIGNED NULL,
  room_type_id INT UNSIGNED NOT NULL,
  rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  adults TINYINT UNSIGNED NOT NULL DEFAULT 1,
  children TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_res_rooms_reservation
    FOREIGN KEY (reservation_id) REFERENCES reservations(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_res_rooms_room
    FOREIGN KEY (room_id) REFERENCES rooms(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_res_rooms_room_type
    FOREIGN KEY (room_type_id) REFERENCES room_types(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS housekeeping_tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id INT UNSIGNED NOT NULL,
  task_type ENUM('Cleaning','Inspection') NOT NULL DEFAULT 'Cleaning',
  status ENUM('Open','In Progress','Done') NOT NULL DEFAULT 'Open',
  priority ENUM('Low','Normal','High') NOT NULL DEFAULT 'Normal',
  assigned_to INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_housekeeping_room
    FOREIGN KEY (room_id) REFERENCES rooms(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_housekeeping_assigned
    FOREIGN KEY (assigned_to) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_housekeeping_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  asset_type ENUM('Room','Facility','Equipment') NOT NULL DEFAULT 'Equipment',
  room_id INT UNSIGNED NULL,
  location VARCHAR(120) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_assets_room
    FOREIGN KEY (room_id) REFERENCES rooms(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vendors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  contact_person VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(120) NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_no VARCHAR(30) NOT NULL UNIQUE,
  room_id INT UNSIGNED NULL,
  asset_id INT UNSIGNED NULL,
  category_id INT UNSIGNED NULL,
  priority ENUM('Low','Normal','High','Urgent') NOT NULL DEFAULT 'Normal',
  status ENUM('Open','Assigned','In Progress','On Hold','Resolved','Closed','Cancelled') NOT NULL DEFAULT 'Open',
  title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  reported_by INT UNSIGNED NULL,
  assigned_to INT UNSIGNED NULL,
  vendor_id INT UNSIGNED NULL,
  requires_downtime TINYINT(1) NOT NULL DEFAULT 0,
  room_out_of_order_from DATETIME NULL,
  room_out_of_order_to DATETIME NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  closed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_maint_ticket_room
    FOREIGN KEY (room_id) REFERENCES rooms(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_maint_ticket_asset
    FOREIGN KEY (asset_id) REFERENCES assets(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_maint_ticket_category
    FOREIGN KEY (category_id) REFERENCES maintenance_categories(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_maint_ticket_reported_by
    FOREIGN KEY (reported_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_maint_ticket_assigned_to
    FOREIGN KEY (assigned_to) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_maint_ticket_vendor
    FOREIGN KEY (vendor_id) REFERENCES vendors(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_work_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_order_no VARCHAR(30) NOT NULL UNIQUE,
  ticket_id INT UNSIGNED NOT NULL,
  assigned_to INT UNSIGNED NULL,
  vendor_id INT UNSIGNED NULL,
  scheduled_at DATETIME NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  status ENUM('Planned','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Planned',
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_maint_wo_ticket
    FOREIGN KEY (ticket_id) REFERENCES maintenance_tickets(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_maint_wo_assigned
    FOREIGN KEY (assigned_to) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_maint_wo_vendor
    FOREIGN KEY (vendor_id) REFERENCES vendors(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  work_order_id INT UNSIGNED NULL,
  log_type ENUM('Note','Status Change','Assignment','Vendor','Downtime','Cost') NOT NULL DEFAULT 'Note',
  message TEXT NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_maint_logs_ticket
    FOREIGN KEY (ticket_id) REFERENCES maintenance_tickets(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_maint_logs_work_order
    FOREIGN KEY (work_order_id) REFERENCES maintenance_work_orders(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_maint_logs_user
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_costs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  work_order_id INT UNSIGNED NULL,
  cost_type ENUM('Labor','Part','Vendor','Other') NOT NULL,
  description VARCHAR(200) NOT NULL,
  qty DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  reference_no VARCHAR(60) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_maint_costs_ticket
    FOREIGN KEY (ticket_id) REFERENCES maintenance_tickets(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_maint_costs_work_order
    FOREIGN KEY (work_order_id) REFERENCES maintenance_work_orders(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_maint_costs_user
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS room_status_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id INT UNSIGNED NOT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NOT NULL,
  source VARCHAR(30) NOT NULL,
  source_id INT UNSIGNED NULL,
  changed_by INT UNSIGNED NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_room_status_hist_room
    FOREIGN KEY (room_id) REFERENCES rooms(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_room_status_hist_user
    FOREIGN KEY (changed_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pos_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(30) NOT NULL UNIQUE,
  order_type ENUM('Dine-in','Takeout','Delivery','Room Charge') NOT NULL DEFAULT 'Dine-in',
  status ENUM('Open','Paid','Voided') NOT NULL DEFAULT 'Open',
  reservation_id INT UNSIGNED NULL,
  guest_id INT UNSIGNED NULL,
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  service_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pos_orders_reservation
    FOREIGN KEY (reservation_id) REFERENCES reservations(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_pos_orders_guest
    FOREIGN KEY (guest_id) REFERENCES guests(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS menu_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS menu_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_menu_items_category
    FOREIGN KEY (category_id) REFERENCES menu_categories(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pos_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pos_order_id INT UNSIGNED NOT NULL,
  menu_item_id INT UNSIGNED NOT NULL,
  qty INT UNSIGNED NOT NULL DEFAULT 1,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  CONSTRAINT fk_pos_items_order
    FOREIGN KEY (pos_order_id) REFERENCES pos_orders(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_pos_items_menu
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NULL,
  sku VARCHAR(40) NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
  quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reorder_level DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_items_category
    FOREIGN KEY (category_id) REFERENCES inventory_categories(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_movements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inventory_item_id INT UNSIGNED NOT NULL,
  movement_type ENUM('IN','OUT','ADJUST') NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  reference VARCHAR(60) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_move_item
    FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_inv_move_user
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;
