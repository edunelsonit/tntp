-- Migration: Add missing columns to users table
ALTER TABLE `users`
  ADD COLUMN `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN `monnify_reference` varchar(150) DEFAULT NULL,
  ADD COLUMN `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp();

-- Add index for monnify_reference to speed up webhook lookups
ALTER TABLE `users`
  ADD KEY `idx_monnify_reference` (`monnify_reference`);

-- Optional: keep schema consistent with exported schema.sql
-- Run this migration against your database using your preferred MySQL client.
