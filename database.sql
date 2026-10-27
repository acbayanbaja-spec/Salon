-- Database schema for Glow Smart Salon
-- Note: If importing into local MySQL/XAMPP, uncomment the next two lines:
-- CREATE DATABASE IF NOT EXISTS kap_salon CHARACTER SET utf8mb4;
-- USE kap_salon;
-- If importing into cloud databases like Aiven ('defaultdb'), run the queries below directly in your database.

CREATE TABLE `account` (
  `account_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'customer',   -- admin | staff | customer
  PRIMARY KEY (`account_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `customer` (
  `customer_id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`customer_id`),
  UNIQUE KEY `email` (`email`),
  KEY `account_id` (`account_id`),
  CONSTRAINT `customer_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `account` (`account_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `staff` (
  `staff_id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,           -- deactivate instead of delete (keeps history)
  PRIMARY KEY (`staff_id`),
  KEY `account_id` (`account_id`),
  CONSTRAINT `staff_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `account` (`account_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `service` (
  `service_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `duration_min` int(11) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`service_id`),
  UNIQUE KEY `name` (`name`),
  CONSTRAINT `service_chk` CHECK (`price` > 0 AND `duration_min` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which services each staff member can perform
CREATE TABLE `staff_service` (
  `staff_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  PRIMARY KEY (`staff_id`,`service_id`),
  KEY `service_id` (`service_id`),
  CONSTRAINT `ss_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`staff_id`) ON DELETE CASCADE,
  CONSTRAINT `ss_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `service` (`service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Weekly working hours. weekday: 0=Sunday ... 6=Saturday. No row = day off.
CREATE TABLE `staff_schedule` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) NOT NULL,
  `weekday` tinyint(4) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  PRIMARY KEY (`schedule_id`),
  UNIQUE KEY `staff_day` (`staff_id`,`weekday`),
  CONSTRAINT `sch_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`staff_id`) ON DELETE CASCADE,
  CONSTRAINT `sch_chk` CHECK (`weekday` BETWEEN 0 AND 6 AND `end_time` > `start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customer -> Appointment -> Staff -> Service
-- status: pending | confirmed | rescheduled | completed | cancelled
-- active_slot: 1 while the appointment holds a time slot (pending/confirmed/rescheduled), NULL otherwise.
-- The UNIQUE key below stops two active appointments for the same staff, date and start time at the
-- database level. Overlapping (different start time) bookings are blocked in lib.php inside a
-- transaction that locks the staff row (SELECT ... FOR UPDATE).
CREATE TABLE `appointment` (
  `appointment_id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `appt_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `notes` varchar(255) DEFAULT NULL,
  `active_slot` tinyint(1) DEFAULT NULL,
  `reminder_sent` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`appointment_id`),
  UNIQUE KEY `no_double_booking` (`staff_id`,`appt_date`,`start_time`,`active_slot`),
  KEY `customer_id` (`customer_id`),
  KEY `service_id` (`service_id`),
  KEY `appt_date` (`appt_date`),
  CONSTRAINT `appt_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`),
  CONSTRAINT `appt_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`staff_id`),
  CONSTRAINT `appt_ibfk_3` FOREIGN KEY (`service_id`) REFERENCES `service` (`service_id`),
  CONSTRAINT `appt_chk` CHECK (`end_time` > `start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every notification sent to a customer (also shown in the customer's Notifications page).
-- sms_status: sent | failed | not_configured
CREATE TABLE `notification` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `type` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `sms_status` varchar(20) NOT NULL DEFAULT 'not_configured',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `customer_id` (`customer_id`),
  KEY `appointment_id` (`appointment_id`),
  CONSTRAINT `notif_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`) ON DELETE CASCADE,
  CONSTRAINT `notif_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `appointment` (`appointment_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payments: one row per appointment, created when the salon marks it paid (cash). status = paid.
-- (reference_no / proof / pending / rejected are left over from the removed online-payment feature.)
CREATE TABLE IF NOT EXISTS `payment` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) NOT NULL,
  `method` varchar(20) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reference_no` varchar(60) DEFAULT NULL,
  `proof` varchar(100) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `appointment_id` (`appointment_id`),
  CONSTRAINT `pay_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointment` (`appointment_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customer rating of a completed appointment (one per appointment).
CREATE TABLE IF NOT EXISTS `review` (
  `review_id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL,
  `comment` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`review_id`),
  UNIQUE KEY `appointment_id` (`appointment_id`),
  KEY `staff_id` (`staff_id`),
  KEY `customer_id` (`customer_id`),
  CONSTRAINT `rev_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointment` (`appointment_id`) ON DELETE CASCADE,
  CONSTRAINT `rev_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`) ON DELETE CASCADE,
  CONSTRAINT `rev_ibfk_3` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`staff_id`),
  CONSTRAINT `rev_chk` CHECK (`rating` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Small key/value store (SMS settings entered on the admin SMS page).
CREATE TABLE IF NOT EXISTS `setting` (
  `skey` varchar(40) NOT NULL,
  `svalue` text DEFAULT NULL,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- No sample services/staff are inserted. The admin account is created automatically on first run (config.php).
