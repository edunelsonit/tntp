
CREATE TABLE `admin_action_logs` (
  `id` int NOT NULL,
  `admin_id` int NOT NULL,
  `action_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_settings`
--

CREATE TABLE `admin_settings` (
  `id` int NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `surname` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `admin_role` enum('super_admin','admin') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'admin',
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_modified` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_settings`
--

INSERT INTO `admin_settings` (`id`, `username`, `first_name`, `surname`, `email`, `phone`, `admin_role`, `password_hash`, `created_at`, `last_modified`) VALUES
(2, 'EGSHEY', 'Edward', 'Gilbert', 'edwardgilbertshey@gmail.com', '07060820077', 'super_admin', '$2y$10$WvwyOyQX.iBtH1R5ltENi.T6ZbFay8cM.zg3TqJPcxgz0pjaRHStK', '2026-06-14 17:43:46', '2026-06-14 17:43:46'),
(3, 'VANSO', 'EVANS', 'NGORAN', 'ayunneevans@gmail.com', '08164348789', 'super_admin', '$2y$10$KmuPmDHZEL.UaRcaWg0M3uNjbvqAWr82NsNKwze0mU243MW9DNrnK', '2026-06-17 17:14:12', '2026-06-17 17:14:12'),
(4, 'BASHD', 'Ibrahim', 'Babaji', 'ibrahimbabaji1994@gmail.com', '09029373382', 'admin', '$2y$10$dWXBrkntsu0O3UeoVNqwCONadF3bXiwBOiGtV3ADY6RHXklRZi6aS', '2026-06-19 17:50:41', '2026-06-19 17:50:41'),
(5, 'Barrister', 'BARRISTER', 'HUSSEINI', 'aliyen4u@gmail.com', '07036367332', 'admin', '$2y$10$ER5QfLVi3QeeqUKKKDLw9eHO4Kwr2p7pWhspPvtGPv0/DEMy5PP4a', '2026-06-25 08:45:26', '2026-06-25 08:45:26');

-- --------------------------------------------------------

--
-- Table structure for table `clusters`
--

CREATE TABLE `clusters` (
  `id` int NOT NULL,
  `cluster_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `cluster_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `manager_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `manager_email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `cluster_location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `manager_password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_by_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
--
-- Table structure for table `disputes`
--

CREATE TABLE `disputes` (
  `id` int NOT NULL,
  `userid` int NOT NULL,
  `remittance_id` int NOT NULL,
  `dispute_type` enum('NO_SALARY','WEBHOOK_FAILED') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `dispute_status` enum('PENDING','APPROVED_ADJUSTED','REJECTED') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'PENDING',
  `dispute_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `proof_receipt_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `admin_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `processed_by` int DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `grace_period_expiry` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_history`
--

CREATE TABLE `payment_history` (
  `id` int NOT NULL,
  `remittance_id` int NOT NULL,
  `tx_reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `payment_method` enum('MONNIFY_WEBHOOK','MANUAL_ADMIN') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `receipt_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `processed_by_admin_id` int DEFAULT NULL,
  `paid_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `remittance`
--

CREATE TABLE `remittance` (
  `id` int NOT NULL,
  `cycle_id` int NOT NULL,
  `userid` int NOT NULL,
  `expected_amount` decimal(10,2) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT '0.00',
  `payment_status` enum('UNPAID','PARTIAL','FULLY_PAID','EXEMPTED') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'UNPAID',
  `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `remittance_cycles`
--

CREATE TABLE `remittance_cycles` (
  `id` int NOT NULL,
  `cycle_period` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `salary_declared_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `declared_by_id` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `nin` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `nin_verified` tinyint(1) NOT NULL DEFAULT '0',
  `first_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `surname` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `other_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `gender` varchar(16) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `virtual_account` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `salary_account_number` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `salary_bank_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `bank_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `cluster_code` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `host_organization` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `resumption_date` date DEFAULT NULL,
  `state_of_origin` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `lga` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_by` enum('ADMIN','SUPER_ADMIN','CLUSTER_MANAGER') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'ADMIN',
  `expected_remittance_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `approval_status` enum('PENDING','APPROVED','REJECTED') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'PENDING',
  `amount_paid` decimal(10,2) NOT NULL DEFAULT '0.00',
  `payment_status` enum('UNPAID','PARTIAL','PAID','EXEMPTED','DEFAULTING') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'UNPAID',
  `monnify_reference` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_modified` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--
-- --------------------------------------------------------

--
-- Table structure for table `user_change_requests`
--

CREATE TABLE `user_change_requests` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `requested_by_id` int NOT NULL,
  `change_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PROFILE_UPDATE',
  `proposed_data` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('PENDING','APPROVED','REJECTED') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `rejection_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `processed_by` int DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexes for dumped tables
--

--
-- Indexes for table `admin_action_logs`
--
ALTER TABLE `admin_action_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `admin_settings`
--
ALTER TABLE `admin_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `clusters`
--
ALTER TABLE `clusters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cluster_code` (`cluster_code`),
  ADD UNIQUE KEY `manager_email` (`manager_email`),
  ADD KEY `created_by_id` (`created_by_id`);

--
-- Indexes for table `disputes`
--
ALTER TABLE `disputes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `userid` (`userid`),
  ADD KEY `remittance_id` (`remittance_id`),
  ADD KEY `processed_by` (`processed_by`);

--
-- Indexes for table `payment_history`
--
ALTER TABLE `payment_history`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tx_reference` (`tx_reference`),
  ADD KEY `remittance_id` (`remittance_id`),
  ADD KEY `processed_by_admin_id` (`processed_by_admin_id`),
  ADD KEY `idx_payment_tx` (`tx_reference`);

--
-- Indexes for table `remittance`
--
ALTER TABLE `remittance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_cycle` (`cycle_id`,`userid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `idx_remittance_status` (`payment_status`);

--
-- Indexes for table `remittance_cycles`
--
ALTER TABLE `remittance_cycles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_cycle` (`cycle_period`),
  ADD KEY `declared_by_id` (`declared_by_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nin` (`nin`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `virtual_account` (`virtual_account`),
  ADD KEY `cluster_code` (`cluster_code`),
  ADD KEY `idx_users_email` (`email`),
  ADD KEY `idx_users_approval` (`approval_status`);

--
-- Indexes for table `user_change_requests`
--
ALTER TABLE `user_change_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_action_logs`
--
ALTER TABLE `admin_action_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admin_settings`
--
ALTER TABLE `admin_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `clusters`
--
ALTER TABLE `clusters`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `disputes`
--
ALTER TABLE `disputes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_history`
--
ALTER TABLE `payment_history`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `remittance`
--
ALTER TABLE `remittance`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `remittance_cycles`
--
ALTER TABLE `remittance_cycles`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=490;

--
-- AUTO_INCREMENT for table `user_change_requests`
--
ALTER TABLE `user_change_requests`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=493;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_action_logs`
--
ALTER TABLE `admin_action_logs`
  ADD CONSTRAINT `admin_action_logs_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admin_settings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `clusters`
--
ALTER TABLE `clusters`
  ADD CONSTRAINT `clusters_ibfk_1` FOREIGN KEY (`created_by_id`) REFERENCES `admin_settings` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `disputes`
--
ALTER TABLE `disputes`
  ADD CONSTRAINT `disputes_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `disputes_ibfk_2` FOREIGN KEY (`remittance_id`) REFERENCES `remittance` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `disputes_ibfk_3` FOREIGN KEY (`processed_by`) REFERENCES `admin_settings` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `payment_history`
--
ALTER TABLE `payment_history`
  ADD CONSTRAINT `payment_history_ibfk_1` FOREIGN KEY (`remittance_id`) REFERENCES `remittance` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payment_history_ibfk_2` FOREIGN KEY (`processed_by_admin_id`) REFERENCES `admin_settings` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `remittance`
--
ALTER TABLE `remittance`
  ADD CONSTRAINT `remittance_ibfk_1` FOREIGN KEY (`cycle_id`) REFERENCES `remittance_cycles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `remittance_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `remittance_cycles`
--
ALTER TABLE `remittance_cycles`
  ADD CONSTRAINT `remittance_cycles_ibfk_1` FOREIGN KEY (`declared_by_id`) REFERENCES `admin_settings` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`cluster_code`) REFERENCES `clusters` (`cluster_code`) ON DELETE SET NULL;

--
-- Constraints for table `user_change_requests`
--
ALTER TABLE `user_change_requests`
  ADD CONSTRAINT `user_change_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;
