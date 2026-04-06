# Authentication Module

This folder contains all authentication-related functionality including user registration, login, and session management.

## Overview

The authentication system implements a secure multi-step flow:

1. **Registration** (`register.php`) - New users create accounts with validated data
2. **Login** (`login.php`) - Existing users authenticate with email and password
3. **Session Management** - Secure session handling with CSRF protection and regeneration

## Security Features

### CSRF Token Protection

- **What it does**: Prevents Cross-Site Request Forgery attacks by validating a unique, one-time token
- **How it works**: Each form submission requires a valid CSRF token from the session. After successful validation, a new token is generated (one-time use)
- **Implementation**: `wingmate_get_csrf_token()` and `wingmate_validate_csrf_token()` functions from `/includes/session.php`

### POST-Redirect-GET Pattern

- **What it does**: Prevents form resubmission issues and ensures CSRF tokens stay fresh
- **Flow**: Form submission (POST) → Process → Redirect (GET) → Display page with new CSRF token
- **Result**: Refresh only reloads page (GET), never resubmits form (POST)

### Password Security

- **Hashing**: Passwords are hashed with `PASSWORD_BCRYPT` algorithm using `password_hash()`
- **Verification**: Login uses `password_verify()` to check submitted password against stored hash
- **Never stored**: Plain-text passwords are never stored in the database

### Input Validation & Sanitization

- **Email validation**: Uses `filter_var()` with `FILTER_VALIDATE_EMAIL`
- **Password validation**: Requires minimum 8 characters, uppercase, lowercase, number, and special character (no whitespace)
- **Input cleaning**: `clean_input()` function sanitizes all user input using `htmlspecialchars()` with `ENT_QUOTES` for HTML entity encoding
- **XSS Prevention**: All output is escaped with `htmlspecialchars()` before rendering in HTML

### SQL Injection Protection

- **Prepared Statements**: All database queries use prepared statements with `?` placeholders
- **Parameter Binding**: User input is bound as parameters using `bind_param()`, never concatenated into queries

### Information Disclosure Prevention

- **Generic Error Messages**: Both invalid email and invalid password show the same error message to prevent attackers from enumerating valid email addresses
- **Hidden Session Data**: Temporary form data and errors are stored in session (server-side), never in URLs

### Session Security

- **Regeneration**: `session_regenerate_id(true)` is called on successful login to prevent session fixation attacks
- **Secure Flags**: Sessions are started with `wingmate_start_secure_session()` which sets HTTPOnly and Secure flags
- **Auto-Cleanup**: Session data is automatically unset after first display to prevent persistence

## File Descriptions

### `register.php`

Handles user registration with comprehensive form validation and database transactions.

**Features:**

- Email validation and duplicate checking
- Password strength requirements
- Date of birth validation (18+ age requirement, realistic values)
- First/last name requirement
- Database transaction handling (atomic user and profile creation)
- Form data persistence on validation failure
- Detailed field-level error messages

**Key Functions:**

- `clean_input()` - Sanitizes user input
- `has_field_errors()` - Validates field array for errors
- `password_hash()` - Hashes password securely
- `$conn->begin_transaction()` / `commit()` / `rollback()` - Atomic operations

### `login.php`

Handles user authentication and session initiation.

**Features:**

- Email and password verification
- Secure password comparison with `password_verify()`
- Generic error messages to prevent email enumeration
- Form email persistence on failed login
- POST-Redirect-GET pattern for fresh CSRF tokens

**Key Functions:**

- `clean_input()` - Sanitizes user input
- `password_verify()` - Safely verifies password against hash
- Session regeneration on successful login

### `auth.css`

Shared styling component for authentication pages.

**Includes:**

- Form layout styles
- Input field styling (with error states)
- Button styling
- Error message styling
- Responsive design for mobile/tablet/desktop

## Dependencies

All files require:

- `/includes/session.php` - Secure session handling functions
- `/includes/auth-header.php` - Header component
- `/includes/footer.php` - Footer component
- `/config/config.php` - Database connection

## Session Flow

### Registration Flow

```
User submits form (POST)
↓
CSRF token validated
↓
Input sanitized and validated
↓
Database check (email exists?)
↓
Transaction starts: Insert User + Update Profile
↓
Success → Regenerate session → Redirect to /friends
↓
Fail → Store errors/form in session → Redirect back to register form
```

### Login Flow

```
User submits form (POST)
↓
CSRF token validated
↓
Input sanitized
↓
Database query (find user by email)
↓
Password verified with password_verify()
↓
Success → Regenerate session → Redirect to /friends
↓
Fail → Store error in session → Redirect back to login form
```

## Error Handling

Errors are handled in three categories:

1. **General Errors** (`$registerError`, `$loginError`) - Server or logic errors
2. **Field Errors** (`$fieldErrors[]`) - Specific field validation failures
3. **Session Errors** - Stored in `$_SESSION` and cleared after display

Errors are stored in session and the page redirects, ensuring:

- Errors persist through page refresh
- CSRF tokens are always fresh
- No sensitive data in URLs

## Notes

- Never modify CSRF tokens during validation; they are handled by `wingmate_validate_csrf_token()`
- Always use prepared statements for database queries
- Always sanitize user input with `clean_input()` before output
- Always escape output with `htmlspecialchars()` in HTML contexts
- Keep authentication logic centralized; avoid duplicating validation logic across pages
