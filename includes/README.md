# Includes Folder

This folder contains reusable PHP code and components that are shared across multiple pages of the WingMate dating website. The main use of these files is to reduce boiler plate code across the project.

## Contents

- **auth-header.php**  
  Reusable HTML header for authorisation pages, such as login and register. Contains opening HTML tags and links Bootstrap CSS and Global CSS.

- **header.php**
  Reusable HTML header for majority of pages across Wingmate. Contains opening HTML tags, links to Bootstrap CSS and Global CSS, as well as the WingMate Navigation bar.

- **footer.php**
  Reusable HTML footer for all pages across WingMate, includes closing body and HTML tags. Also includes the Bootstrap JS bundle, which will optionally be used. Bootstrap JS is loaded at the end, so the rest of the page does not have to wait for it.
