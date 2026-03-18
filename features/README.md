# Features Folder

This folder contains all the main features and pages of the dating website.  
Each subfolder represents a specific feature (such as login, registration, user profiles, or matches) and contains the PHP files, CSS, HTML and test scripts related to that feature.

## Folder Structure

- **/login**  
  Handles user login functionality.

- **/register**  
  Handles user registration functionality.

## Notes

- Keep feature-specific code contained within its folder to maintain organisation and scalability.
- Avoid duplicating shared code (header, footer, DB connection) across features, always include from `/includes/`.
- Use descriptive naming for new features.
