# PKNSTAN Link Shortener

A PHP and SQLite-based URL Shortener and QR Code Manager designed to run on `s.pknstan.id`.
This application provides an interactive web interface for administration and an API endpoint for automation integrations such as **n8n**.

## Features
1. **Craft Web Interface**: Built with modern, accessible HTML/CSS adhering to antislop principles, complete with Light and Dark themes.
2. **Link Metadata**: Supports Title/Description, Custom Alias, and Destination URLs.
3. **Interactive Actions**: View/Download QR codes, Edit existing short links, and Delete links.
4. **API Endpoint (`api.php`)**: Allows programmatic URL shortening with optional title and custom alias.
5. **URL Rewriting**: Automatic routing from `s.pknstan.id/xyz` to destination URLs via `.htaccess` or Nginx rewrite rules.
6. **Click Tracking**: Tracks total clicks per short link.

---

## 🛠️ Installation & Setup

### 1. Database
- Uses SQLite with automatic table creation and schema migration.

### 2. Configuration
Open `config.php` to customize:
```php
// Database Configuration
define('DB_FILE', __DIR__ . '/database.sqlite');

// Base URL for shortened links
define('BASE_URL', 'https://s.pknstan.id/');

// Authentication Credentials
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'your_password');
define('API_KEY', 'your_secret_api_key');
```

---

## 🚀 API Usage (n8n & HTTP Clients)

### Create Short Link
- **Endpoint**: `POST /api.php`
- **Headers**:
  - `Content-Type: application/json`
  - `X-API-Key: your_secret_api_key`
- **Body**:
  ```json
  {
      "url": "https://pknstan.ac.id/pengumuman-pendaftaran-2026",
      "title": "Panduan Pendaftaran STAN 2026",
      "custom_code": "stan-2026"
  }
  ```

**Sample Response**:
```json
{
    "success": true,
    "title": "Panduan Pendaftaran STAN 2026",
    "original_url": "https://pknstan.ac.id/pengumuman-pendaftaran-2026",
    "short_code": "stan-2026",
    "short_url": "https://s.pknstan.id/stan-2026"
}
```
