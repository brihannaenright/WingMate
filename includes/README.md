# Includes Folder

This folder contains reusable PHP code and components that are shared across multiple pages of the WingMate dating website. The main use of these files is to reduce boiler plate code across the project.

## Contents

- **session.php**
  Core security functions for session management and CSRF protection. Must be included on all pages.
  - `wingmate_start_secure_session()` - Initializes secure session with HTTPOnly, Secure, and SameSite flags. Auto-regenerates session ID every 15 minutes.
  - `wingmate_get_csrf_token()` - Generates and retrieves CSRF token (creates new token if none exists)
  - `wingmate_validate_csrf_token()` - Validates submitted CSRF token and generates new one on success

- **auth-header.php**  
  Reusable HTML header for authorisation pages, such as login and register. Contains opening HTML5 doctype, meta tags, Bootstrap CSS link, and Global CSS link.

- **nav-header.php**
  Reusable HTML header for authenticated pages across WingMate. Contains opening HTML tags, links to Bootstrap CSS and Global CSS, as well as the WingMate Navigation bar for logged-in users.

- **footer.php**
  Reusable HTML footer for all pages across WingMate. Includes closing `</body>` and `</html>` tags. Also includes Bootstrap JavaScript bundle, loaded at the end to prevent blocking page rendering.
