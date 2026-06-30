-- Migration: Add transactions table and users.payment_status

-- Create transactions table used by legacy UI flows
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tx_reference` varchar(150) NOT NULL,
  `nin` varchar(20) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('PENDING','VERIFIED','REJECTED','REVERTED') NOT NULL DEFAULT 'PENDING',
  `receipt_path` varchar(255) DEFAULT NULL,
  `payment_channel` varchar(100) DEFAULT NULL,
  `payment_date` datetime DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tx_reference` (`tx_reference`),
  KEY `nin` (`nin`),
  KEY `processed_by` (`processed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add foreign keys if users/admin tables exist
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`nin`) REFERENCES `users` (`nin`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `admin_settings` (`id`) ON DELETE SET NULL;

-- Add payment_status column to users if not present (keeps idempotent)
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `payment_status` enum('UNPAID','PARTIAL','PAID','EXEMPTED','DEFAULTING') NOT NULL DEFAULT 'UNPAID';
