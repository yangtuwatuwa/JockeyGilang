# AGENTS.md

## Project Overview

This project is a simple grocery store Point of Sale (POS) application developed for educational purposes.

The application uses PHP Native, MySQL, HTML, CSS, and JavaScript.

The project must remain simple, maintainable, readable, and easy to understand by a vocational high school student.

## Tech Stack

- PHP Native
- MySQL
- PDO
- HTML
- CSS
- Vanilla JavaScript

Do not use Laravel, CodeIgniter, React, Vue, Node.js, ORM, or unnecessary frameworks.

## Coding Rules

- Use modular PHP files.
- Do not place the entire application in a single file.
- Keep files focused on their responsibilities.
- Avoid unnecessary abstraction.
- Avoid duplicate code.
- Use clear variable and function names.
- Keep code readable and maintainable.
- Do not overengineer.

## File Organization

Separate:

- Database connection.
- Authentication.
- Authorization.
- Reusable functions.
- Layout components.
- Business logic.
- Role-specific pages.
- CSS.
- JavaScript.

Reuse existing project structure whenever possible.

Do not reorganize the entire project unless explicitly requested or necessary.

## Database Rules

- Use PDO.
- Use prepared statements for database queries.
- Avoid unsafe SQL query construction.
- Preserve database relationships.
- Do not modify schema unnecessarily.
- Never silently remove existing data or tables.

## Security Rules

- Passwords must use password_hash().
- Password verification must use password_verify().
- Never store plaintext passwords.
- Never use MD5 or SHA1 for password storage.
- Protect pages using authentication and role authorization.
- Validate all input on the server.
- Do not trust frontend validation alone.
- Prevent unauthorized access by modifying URLs.
- Use session_regenerate_id(true) after successful login.

## Business Rules

- Admin manages products, categories, accounts, discounts, and stock adjustments.
- Cashier handles cash-only sales.
- Supplier can only add stock to assigned products.
- Stock must never become negative.
- Transaction data must preserve historical price and discount values.
- Cashier cannot modify product prices or discounts.
- Supplier stock additions must be recorded.
- Successful sales must update stock consistently.

Do not change these business rules without explicit instructions.

## UI Rules

- Use a simple, modern, clean, and readable interface.
- Prioritize usability over visual decoration.
- Keep layouts consistent across modules.
- Make the interface responsive.
- Avoid excessive animation and unnecessary visual effects.
- Reuse existing styles and layout components.

## Development Workflow

Before implementing a feature:

1. Inspect the existing project structure.
2. Understand the existing database schema.
3. Identify relevant files.
4. Explain which files need to be created or modified.
5. Implement only the requested feature.
6. Preserve existing functionality.
7. Test the implementation.
8. Report any remaining issues.

## Scope Control

Do not introduce features that are not requested.

Do not expand the project into an ERP or enterprise application.

Do not create unnecessary APIs, services, abstractions, or dependencies.

When requirements are ambiguous, choose the simplest reasonable implementation and explain the decision.

## Output Rules

When writing code:

- Specify each file path.
- Provide complete code for files being created or modified.
- Do not omit required implementation details.
- Ensure imports, includes, and paths are correct.
- Do not provide pseudocode for requested functional features.
- Avoid regenerating unrelated files.
- Keep modifications minimal and focused.