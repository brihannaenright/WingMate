<!--Requirements
1. Passwords not stored in plain text (Password hashing)
2. Secure session handling
3. Input validation and sanitization
4. Protection against basic SQL Injection
5. No hard-coded credentials 

Extras to remember:
1. Check to ensure user is over 18
2. Regexes to validate email and password formats
-->

<?php
// PHP code for handling user registeration/form submission here
?>

<?php include '../../includes/auth-header.php'; ?>
<link rel="stylesheet" href="register.css">
<h1>Register</h1>

<?php include '../../includes/footer.php'; ?>