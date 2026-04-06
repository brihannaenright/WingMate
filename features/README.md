# Features Folder

This folder contains all the main features and pages of the dating website.  
Each subfolder represents a specific feature (such as login, registration, user profiles, or matches) and contains the PHP files, CSS, HTML and test scripts related to that feature.

## Folder Structure

- **/auth**  
  Handles user authentication (login and registration).
  - `login.php` - User login page with secure session handling, CSRF protection, and password verification
  - `register.php` - User registration page with form validation, email verification, and secure password hashing
  - `auth.css` - Shared styling component for authentication pages

- **/friends**  
  Handles user friends/connections functionality.

- **/profile**  
  Handles user profile pages and settings.

## Notes

- Keep feature-specific code contained within its folder to maintain organisation and scalability.
- Avoid duplicating shared code (header, footer, DB connection) across features, always include from `/includes/`.
- Use descriptive naming for new features.
