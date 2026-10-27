-- ================================================================
-- GLOW SMART SALON - POSTGRESQL SCHEMA FOR SUPABASE
-- Run this in your Supabase SQL Editor (or it runs automatically)
-- ================================================================

-- 1. Account table (login authentication for admin, staff, customer)
CREATE TABLE IF NOT EXISTS account (
  account_id SERIAL PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'customer'
);

-- 2. Customer profile table
CREATE TABLE IF NOT EXISTS customer (
  customer_id SERIAL PRIMARY KEY,
  account_id INT DEFAULT NULL REFERENCES account(account_id) ON DELETE SET NULL,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(100) NOT NULL UNIQUE,
  phone VARCHAR(30) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 3. Staff profile table
CREATE TABLE IF NOT EXISTS staff (
  staff_id SERIAL PRIMARY KEY,
  account_id INT DEFAULT NULL REFERENCES account(account_id) ON DELETE SET NULL,
  name VARCHAR(100) NOT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  active SMALLINT NOT NULL DEFAULT 1
);

-- 4. Service table
CREATE TABLE IF NOT EXISTS service (
  service_id SERIAL PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  price NUMERIC(10,2) NOT NULL CHECK (price > 0),
  duration_min INT NOT NULL CHECK (duration_min > 0),
  active SMALLINT NOT NULL DEFAULT 1
);

-- 5. Staff to Service assignment table
CREATE TABLE IF NOT EXISTS staff_service (
  staff_id INT NOT NULL REFERENCES staff(staff_id) ON DELETE CASCADE,
  service_id INT NOT NULL REFERENCES service(service_id) ON DELETE CASCADE,
  PRIMARY KEY (staff_id, service_id)
);

-- 6. Weekly working schedule (0=Sun, 6=Sat)
CREATE TABLE IF NOT EXISTS staff_schedule (
  schedule_id SERIAL PRIMARY KEY,
  staff_id INT NOT NULL REFERENCES staff(staff_id) ON DELETE CASCADE,
  weekday SMALLINT NOT NULL CHECK (weekday BETWEEN 0 AND 6),
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  CONSTRAINT staff_day UNIQUE (staff_id, weekday),
  CONSTRAINT sch_chk CHECK (end_time > start_time)
);

-- 7. Appointments table
CREATE TABLE IF NOT EXISTS appointment (
  appointment_id SERIAL PRIMARY KEY,
  customer_id INT NOT NULL REFERENCES customer(customer_id),
  staff_id INT NOT NULL REFERENCES staff(staff_id),
  service_id INT NOT NULL REFERENCES service(service_id),
  appt_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  notes VARCHAR(255) DEFAULT NULL,
  active_slot SMALLINT DEFAULT NULL,
  reminder_sent SMALLINT NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT no_double_booking UNIQUE (staff_id, appt_date, start_time, active_slot),
  CONSTRAINT appt_chk CHECK (end_time > start_time)
);

-- 8. Customer notifications & SMS delivery log
CREATE TABLE IF NOT EXISTS notification (
  notification_id SERIAL PRIMARY KEY,
  customer_id INT NOT NULL REFERENCES customer(customer_id) ON DELETE CASCADE,
  appointment_id INT DEFAULT NULL REFERENCES appointment(appointment_id) ON DELETE SET NULL,
  type VARCHAR(20) NOT NULL,
  message TEXT NOT NULL,
  sms_status VARCHAR(20) NOT NULL DEFAULT 'not_configured',
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 9. Cash payments tracking
CREATE TABLE IF NOT EXISTS payment (
  payment_id SERIAL PRIMARY KEY,
  appointment_id INT NOT NULL UNIQUE REFERENCES appointment(appointment_id) ON DELETE CASCADE,
  method VARCHAR(20) NOT NULL DEFAULT 'cash',
  amount NUMERIC(10,2) NOT NULL,
  reference_no VARCHAR(60) DEFAULT NULL,
  proof VARCHAR(100) DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  paid_at TIMESTAMPTZ DEFAULT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 10. Customer reviews and ratings
CREATE TABLE IF NOT EXISTS review (
  review_id SERIAL PRIMARY KEY,
  appointment_id INT NOT NULL UNIQUE REFERENCES appointment(appointment_id) ON DELETE CASCADE,
  customer_id INT NOT NULL REFERENCES customer(customer_id) ON DELETE CASCADE,
  staff_id INT NOT NULL REFERENCES staff(staff_id) ON DELETE CASCADE,
  rating SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
  comment VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 11. Key/value system settings (SMS provider, API keys, etc.)
CREATE TABLE IF NOT EXISTS setting (
  skey VARCHAR(40) PRIMARY KEY,
  svalue TEXT DEFAULT NULL
);
