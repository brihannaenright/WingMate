# WingMate

WingMate is a secure PHP-based dating web application with a focus on user authentication, session management, and data security. The application provides user registration, login, profile management, and social features with enterprise-level security practices.

## Technology Stack

- **Backend**: PHP 8.0+
- **Database**: MySQL/MariaDB
- **Frontend**: HTML5, CSS3, Bootstrap 5
- **Icons**: SVG for scalability
- **Fonts**: Google Fonts (Inter)

## Project Structure

```
WingMate/
├── index.php                    # Entry point - redirects to registration
├── config/                      # Configuration files
│   └── config.php               # Database connection and environment variables
├── includes/                    # Shared/reusable PHP components
│   ├── session.php              # Secure session management and CSRF protection
│   ├── auth-header.php          # HTML header for authentication pages
│   ├── nav-header.php           # HTML header for authenticated pages
│   └── footer.php               # HTML footer for all pages
├── assets/                      # Static files (CSS, images, icons)
│   ├── global.css               # Global stylesheet with theming variables
│   └── images/                  # Logos, icons, and media
├── features/                    # Application features and modules
│   ├── auth/                    # User authentication (login/register)
│   │   ├── register.php         # User registration with validation
│   │   ├── login.php            # User login with secure authentication
│   │   └── auth.css             # Shared auth page styling
│   ├── friends/                 # Friends/connections management
│   ├── profile/                 # User profiles
│   └── README.md                # Feature module documentation
└── README.md                    # This file
```

## Key Features

### 1. User Authentication

- **Secure Registration** - Email validation, password strength requirements, age verification
- **Login System** - Email/password authentication with hashed password comparison
- **Session Management** - Secure session handling with automatic regeneration every 15 minutes
- **Account Creation** - Atomic database transactions ensuring data consistency

### 2. Security Architecture

#### CSRF Protection

- One-time use tokens generated per form
- Automatic token regeneration after validation
- POST-Redirect-GET pattern prevents form resubmission attacks

#### Password Security

- Passwords hashed with BCRYPT algorithm using `password_hash()`
- Verification uses `password_verify()` for safe comparison
- Plain-text passwords never stored in database

#### Input Validation & Sanitization

- **Email validation**: Uses PHP's `FILTER_VALIDATE_EMAIL`
- **Password requirements**: 8+ characters, uppercase, lowercase, number, special character (no whitespace)
- **HTML sanitization**: All user input escaped with `htmlspecialchars()` before output
- **Email/name validation**: Required fields, proper formatting

#### SQL Injection Prevention

- All queries use prepared statements with parameter binding
- User input never concatenated into SQL queries
- Parameterized queries prevent malicious SQL execution

#### XSS Prevention

- All dynamic content escaped with `htmlspecialchars(ENT_QUOTES, 'UTF-8')`
- Proper escaping in HTML attributes, text content, and JavaScript contexts
- Sanitization at input and output layers

#### Session Security

- **HTTPOnly flag**: Prevents JavaScript access to session cookies
- **Secure flag**: Ensures cookies only sent over HTTPS
- **SameSite**: Set to 'Lax' to prevent CSRF attacks
- **Automatic Regeneration**: Session ID changed every 15 minutes
- **Strict Mode**: Rejects invalid/fake session IDs

#### Information Disclosure Prevention

- Generic error messages for authentication failures prevent email enumeration
- Sensitive data stored server-side in sessions, never in URLs
- Database errors caught and presented as generic messages to users

### 3. Form Persistence

- Form data preserved in session when validation fails
- Users don't lose input on form submission errors
- Data automatically cleared after display to prevent persistence

### 4. Error Handling

- **General Errors**: Server/logic errors shown at top of form
- **Field Errors**: Specific validation errors per field with highlighting
- **Session Errors**: Errors stored server-side and cleared after display
- **Database Errors**: Caught and presented as generic messages

## Setup Instructions

### Prerequisites

- PHP 8.0 or higher
- MySQL 5.7+ or MariaDB 10.2+
- Web server (Apache, Nginx, etc.)
- Composer (optional, for future dependency management)

### Environment Configuration

1. Create a `.env` file in the project root:

```
DB_HOST=localhost
DB_USER=wingmate_user
DB_PASS=your_secure_password
DB_NAME=wingmate_db
```

## Security Best Practices

1. **Always use prepared statements** for database queries
2. **Always sanitize user input** with `clean_input()` function
3. **Always escape output** with `htmlspecialchars()` before rendering
4. **Never store plain-text passwords** - always hash with `password_hash()`
5. **Never put sensitive data in URLs** - use sessions instead
6. **Always regenerate sessions** after important operations (login, form submission)
7. **Keep `.env` file out of version control** - add to `.gitignore`
8. **Validate on both client and server** - never trust client-side validation alone
9. **Use generic error messages** for authentication to prevent user enumeration
10. **Log security events** for monitoring and auditing (future enhancement)

## Development Guidelines

### Adding New Features

1. Create a new folder in `/features/` with descriptive name
2. Include a `README.md` documenting the feature
3. Use consistent security patterns from auth module
4. Always validate and sanitize user input
5. Use prepared statements for all database queries
6. Test across different browsers and devices

### Code Style

- Use `declare(strict_types=1)` at the top of PHP files
- Use prepared statements exclusively
- Follow PSR-4 autoloading conventions
- Use consistent indentation (4 spaces)
- Add comments for complex logic

### Session Management

- Always include `session.php` at the top of authenticated pages
- Call `wingmate_start_secure_session()` immediately after includes
- Use session variables for temporary data (errors, form data, user info)
- Clear sensitive session data after use with `unset()`

## File Organization

- **Global code** → `/includes/`
- **Feature code** → `/features/[feature-name]/`
- **Styling** → Global in `/assets/global.css`, feature-specific in feature folder
- **Configuration** → `/config/`
- **Documentation** → `README.md` files in relevant folders

## Known Limitations

- No email verification for registration (future enhancement)
- No password reset functionality (future enhancement)
- No rate limiting on login attempts (security enhancement)
- No two-factor authentication (security enhancement)
- No activity logging (auditing enhancement)

## Future Enhancements

- Email verification system
- Password reset flow
- User profile customization
- Matching algorithm
- Messaging system
- Rate limiting and brute force protection
- Two-factor authentication
- Activity/security logs
- Admin panel
- User moderation tools

## License

Private project. All rights reserved.

## Contact

For questions or issues, contact the development team.
