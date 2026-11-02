<?php
// Gmail SMTP settings used to send verification codes.
// 1) Turn on 2-Step Verification for the Gmail account that will SEND the codes.
// 2) Create an App Password at https://myaccount.google.com/apppasswords
// 3) Paste it below (16 letters, spaces are fine). Never use your normal Gmail password.
return [
    'host'         => 'smtp.gmail.com',
    'port'         => 587,
    'username'     => 'dagonzales@ccc.edu.ph',
    'app_password' => 'sfpq bizc gxrr vtdw',
    'from_email'   => 'dagonzales@ccc.edu.ph',   // must be the same as username
    'from_name'    => 'HelmetWatch Verification',

    // true  = do NOT send mail; write the code to mail_outbox.log (for testing only)
    // false = really send through Gmail
    'dev_mode'     => false,
];
