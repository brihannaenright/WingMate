# Config Folder

This folder contains configuration files for WingMate. Configuration files manage environment variables, database connections, and global settings used throughout the project.

## Contents

- **config.php**  
  Initializes database connection and loads environment variables from `.env` file.

  **What it does:**
  - Reads environment variables from `../.env` (host, database name, username, password)
  - Creates a MySQLi connection to the database
  - Enables strict error reporting for debugging (`MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`)
  - Dies with connection error message if database connection fails

  **Usage:**
  - Must be included at the top of any PHP file that needs database access
  - Makes `$conn` variable available globally for prepared statements and queries
  - All database queries should use prepared statements via `$conn->prepare()`

## Environment Variables

The `.env` file (not tracked in version control) must contain:

```
DB_HOST=localhost
DB_USER=wingmate_user
DB_PASS=your_secure_password
DB_NAME=wingmate_db
```

**Security Note:** The `.env` file should never be committed to version control. Add it to `.gitignore` to prevent accidentally exposing database credentials.
