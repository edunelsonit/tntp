# The New Tomorrow's Project

The New Tomorrow's Project is a PHP-based payment reconciliation and identity access gateway built to support three user roles: administrators, cluster managers, and regular users. It integrates with Monnify for generating virtual bank accounts, tracks user payment status, manages salary dispute workflows, and provides a single entry login router for multiple authentication flows.

## Project Description

The New Tomorrow's Project centralizes salary payment tracking, virtual account creation, and reconciliation for a cluster-based organization. Users authenticate with their NIN to view their payment statement and upload proof of payment. Cluster managers and administrators control clusters, users, and Monnify account management.

## Core Functions

- **Centralized login router** (`index.php`)
  - Supports three authentication modes:
    - `user` — NIN-only login
    - `cluster_manager` — cluster code + password
    - `admin` — admin username + password
- **User registration and profile**
  - New users can register through `register.php`
  - Users can view personal transaction statements and upload payment proofs in `users/index.php`
  - Users can submit salary dispute claims with supporting proof
- **Admin dashboard** (`admin/index.php`)
  - Shows metrics for expected revenue, reconciled settlements, defaulting balance, and payment completion
  - Lists outstanding payees and offers links to cluster and user management pages
- **Monnify virtual account management**
  - Admin features to generate reserved Monnify accounts for users (`admin/monnify_accounts.php`)
  - Bulk generation of missing virtual accounts and single-user account assignment
- **User management** (`admin/users.php`)
  - Register users manually, assign clusters, set expected payment amounts, due dates, and payment rules
  - Generate Monnify payment accounts for users
  - Mark salary remittance events and manage pending payment status
  - Review pending payment proofs, pending approvals, and salary disputes
- **Cluster management** (`admin/clusters.php`)
  - Manage payment clusters and cluster manager credentials
- **Webhook listener** (`api/webhook.php`)
  - Endpoint for Monnify payment event callbacks to receive and reconcile incoming payments
- **Reusable configuration and database utility** (`config/config.php`)
  - Starts sessions, defines database and Monnify credentials, and provides helper functions
  - Automatically updates database schema for required columns and tables
- **Secure session and access control**
  - `checkRouteAccess()` ensures pages are protected by role
  - `logout.php` terminates active sessions

## Project Structure

- `index.php` — main login router page
- `register.php` — user registration page
- `logout.php` — session logout endpoint
- `config/config.php` — shared configuration, DB connection, and helper functions
- `core/auth_handler.php` — login processing and session initialization
- `core/register_handler.php` — user registration handler
- `core/upload_payment_proof.php` — payment proof upload handler
- `core/dispute_salary.php` — salary dispute submission handler
- `api/webhook.php` — Monnify webhook listener
- `admin/` — administrator pages and management tools
- `manager/` — cluster manager dashboard pages
- `users/` — regular user dashboard pages
- `partials/` — shared header/footer UI templates
- `uploads/` — file uploads storage for proofs

## Roles and Permissions

- `user`
  - Access to personal payment dashboard
  - Can upload payment proof and submit disputes
- `cluster_manager`
  - Access to manager dashboard and assigned cluster data
  - Can review cluster-specific payment activity
- `admin`
  - Full access to platform administration
  - Manage users, clusters, Monnify accounts, reports, approvals, and disputes

## Monnify Integration

The project uses Monnify sandbox credentials for generating reserved accounts:

- `MONNIFY_API_KEY`
- `MONNIFY_SECRET_KEY`
- `MONNIFY_BASE_URL`
- `MONNIFY_CONTRACT_CODE`

The admin pages call Monnify APIs to:

- authenticate using API key and secret
- create reserved bank accounts for users
- store generated account numbers in the `users` table

## Installation and Setup

1. Place the project in your PHP web root, e.g. `c:\xampp\htdocs\PayCluster`
2. Create a MySQL database named in `config/config.php`, by default `tntp_db`
3. Update `config/config.php` with your database credentials
4. Ensure the webserver can write to the `uploads/` directory for uploaded proofs
5. Open the application in a browser using your web root path, for example:
   - `http://tntp.com.ng/` or `http://localhost/`
6. Create the first admin account using `admin/register.php`
7. Register users, assign clusters, and generate Monnify accounts as needed

## Database Notes

- The database is connected through PDO in `config/config.php`
- `ensureDatabaseSchema()` dynamically alters the schema to keep required columns and support tables in place
- Key tables likely include:
  - `users`
  - `clusters`
  - `transactions`
  - `admin_settings`
  - `user_change_requests`
  - `admin_action_logs`

## Usage Workflow

1. Admin creates an admin login and cluster manager accounts
2. Admin registers users and assigns them to clusters
3. Admin generates Monnify virtual accounts for users
4. Users log in with NIN, see their account reference, and make bank transfers
5. Users upload proof of payment or dispute unpaid salary
6. Admin verifies payments and resolves disputes

## Notes

- This system uses role-based session control instead of a full framework
- Sensitive data such as passwords are stored with `password_hash()`/`password_verify()` for admin and manager accounts
- Regular users authenticate passwordless using NIN only
- The app is built for XAMPP / Apache + PHP + MySQL environments

## Recommended Improvements

- Add CSRF protection for forms
- Add input validation and sanitization for all POST data
- Improve error handling for webhook and Monnify API failures
- Add migrations or explicit schema SQL for repeatable deployments
