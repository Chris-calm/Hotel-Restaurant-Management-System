USE hotel_system;

INSERT INTO room_types (code, name, base_rate, max_adults, max_children)
VALUES
('STD','Standard',1500,2,1),
('DLX','Deluxe',2200,2,2)
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO rooms (room_no, room_type_id, floor, status)
SELECT '101', id, '1', 'Vacant' FROM room_types WHERE code='STD'
ON DUPLICATE KEY UPDATE status=VALUES(status);

INSERT INTO guests (first_name, last_name, email, phone, status)
VALUES ('Juan','Dela Cruz','juan@email.com','09170000000','Checked In');

INSERT INTO reservations (reference_no, guest_id, source, status, checkin_date, checkout_date, notes)
VALUES ('RES-2026-0001', 1, 'Walk-in', 'Confirmed', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Demo reservation');

INSERT INTO menu_categories (name)
VALUES ('Main')
ON DUPLICATE KEY UPDATE name=name;

INSERT INTO menu_items (category_id, name, price, is_active)
VALUES (1,'Chicken Meal',199.00,1);

INSERT INTO pos_orders (order_no, order_type, status, guest_id, subtotal, tax, service_charge, total)
VALUES ('ORD-2026-0001','Dine-in','Open',1,199.00,23.88,0.00,222.88);

INSERT INTO pos_order_items (pos_order_id, menu_item_id, qty, price, line_total)
VALUES (1,1,1,199.00,199.00);

INSERT INTO inventory_categories (name)
VALUES ('Kitchen')
ON DUPLICATE KEY UPDATE name=name;

INSERT INTO inventory_items (category_id, sku, name, unit, quantity, reorder_level)
VALUES (1,'SKU-001','Cooking Oil','L',5,10);
