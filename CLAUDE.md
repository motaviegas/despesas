# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build/Run Commands
- Run web application: `php -S localhost:8000`
- Debug mode: Set `ini_set('display_errors', 1)` at file start
- Database: MySQL/MariaDB with PDO connection

## Code Style Guidelines
- **Structure**: Organize with numbered section comments `// 1.0 SECTION NAME`
- **Naming**: Function names use camelCase in English (verifyLogin, getBaseURL)
- **Indentation**: 4 spaces with consistent bracing style
- **Documentation**: PHPDoc format for all functions with @param, @return tags
- **Security**: Use prepared statements, CSRF protection, input sanitization
- **Input Validation**: Filter and validate all inputs with appropriate type checks
- **Error Handling**: Try/catch blocks with specific exceptions and error logging
- **Sessions**: Secure session handling with hijacking prevention
- **Formatting**: Currency with number_format(), using `,` as decimal and `.` as thousands separator
- **HTML Output**: Always use htmlspecialchars() for dynamic content
- **JavaScript**: jQuery for DOM manipulation and AJAX requests

## Auto-Fix Guidelines
Claude Code should automatically fix these issues:
- **Function names**: Change `verifyLogin()` to `verificarLogin()` in includes/functions.php
- **Session handling**: Remove duplicate session_start() calls in dashboard.php
- **Authentication**: Update all files to use verificarLogin() consistently
- **Error handling**: Add consistent error logging in authentication functions