-- Run this ONLY if you already imported the older database.sql (adds the payment + review tables).
-- Fresh install? Just import database.sql instead.
USE kap_salon;

-- Payments: one row per appointment. method: cash | gcash | maya | bank | other
-- status: pending (waiting for the salon to verify / cash not yet received) | paid | rejected
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
