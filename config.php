<?php
/**
 * Vine Appointments - LAMP configuration
 *
 * Copy this file with the project and replace the placeholder values before
 * deploying. Keep this file outside public version control when it contains
 * real credentials.
 */

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'vine_appointments',
        'user' => 'vine_app',
        'password' => 'CHANGE_THIS_DATABASE_PASSWORD',
    ],

    // The first request to the PHP API creates this Admin account if the
    // staff_users table is empty.
    'admin_email' => 'admin@example.org',
    'admin_password' => 'CHANGE_THIS_ADMIN_PASSWORD',

    // PHP mail() uses the server's configured MTA/sendmail service.
    'mail_from' => 'appointments@example.org',
    'mail_from_name' => 'Vine Appointments',

    'session_name' => 'vine_appointments_session',
    'session_lifetime' => 86400,
];