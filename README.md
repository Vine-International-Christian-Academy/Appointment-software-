# Vine Appointments on a LAMP stack

This folder provides a PHP/MySQL/Apache backend that uses the same `/api/...`
routes as the React frontend. The existing Replit Express/PostgreSQL service is
left unchanged, so this can be installed on a separate Linux server without
changing the current preview.

## Requirements

- Linux
- Apache 2.4 with `mod_rewrite`
- PHP 8.1+ with `pdo_mysql`, `mbstring`, and `openssl`
- MySQL 8.0+ or MariaDB 10.6+
- A configured PHP `mail()` transport for appointment notifications

## Installation

1. Create a MySQL database and user.
2. Import `database.sql` into that database.
3. Edit `api/config.php` with the database and Admin values.
4. Serve the `lamp/` directory as the Apache document root, or copy its `api/`
   directory into the web root beside the compiled frontend.
5. Ensure Apache allows overrides for the `api/` directory:

   ```apache
   <Directory "/var/www/vine-appointments/api">
       AllowOverride All
       Require all granted
   </Directory>
   ```

6. Build the React frontend and copy its static output into the Apache
   document root. The frontend already calls relative `/api/...` URLs.

The Admin user is created the first time the PHP API receives a request, using
`admin_email` and `admin_password` from `api/config.php`. Change the password
before deployment.

## Permissions

- Admin accounts have no `learning_center_id`.
- Supervisors have a `learning_center_id`.
- Supervisors can view only appointments, summary data, and open slots for
  their centre, and can create/delete unbooked availability only in that
  centre.
- Only Admin accounts can change appointment status, manage learning centres,
  and manage staff accounts.

The PDF calendar uses the browser's print dialog; choose **Save as PDF**.