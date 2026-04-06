# Assets Folder

This folder contains all shared static files for WingMate, including stylesheets, images, icons, and logos. These files are used across multiple pages and features to maintain a consistent look and feel and brand identity.

## Contents

- **global.css**  
  Global stylesheet applied to all pages via `auth-header.php` and `nav-header.php`.

  **Includes:**
  - CSS variables for colors (primary `#C30E59`, secondary `#F2AE66`), fonts, and spacing
  - Global font import (Inter from Google Fonts)
  - Box-sizing reset for consistent layout
  - Default styling for buttons, forms, text, and common elements
  - Should be included on every page for consistent styling

- **/images**  
  Contains all site-wide images, icons, and logos used throughout WingMate.

  **Image inventory:**
  - **wingmate-logo.png** - Main WingMate logo (used on auth pages)
  - **wingmate-navbar.png** - Navbar branding icon (used on authenticated pages)
  - **mail-icon.svg** - Email input icon (used in login/register forms)
  - **lock-icon.svg** - Password input icon (used in login/register forms)
  - **edit-icon.svg** - Text input icon for profile fields (used in register form)
  - **calendar-icon.svg** - Date input icon (used in register form for DOB)

## Usage

- Always link `global.css` in the `<head>` section of pages (automatically included in `auth-header.php` and `nav-header.php`)
- Reference images with absolute path `/assets/images/filename.ext` from any page
- Use SVG icons for form inputs (preferred for scalability)
- Maintain CSS custom properties in `global.css` for consistent theming across the application
