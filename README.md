# Your Link Shortener

A PHP and MySQL-based URL Shortener application designed specifically to run on the `s.yourdomain.com` domain.
This application provides an interactive web interface for users and an API endpoint for automation integrations such as **n8n**.

## Features
1. **Premium Web Interface**: Minimalist and modern design based on native HTML/CSS for high performance and interactivity.
2. **API Endpoint (`api.php`)**: Allows URL shortening via webhooks/HTTP requests programmatically.
3. **URL Rewriting**: Automatic routing from `s.yourdomain.com/xyz` to the original URL address using `.htaccess` configuration.
4. **Click Tracking**: Calculates how many times a link has been clicked.

---

## 🛠️ Installation Guide (Setup)

### 1. Database
- Create a MySQL database (e.g., `url_shortener`).
- Import the table by executing the `schema.sql` file in that database.
  ```sql
  -- Example command if using the command line:
  mysql -u root -p url_shortener < schema.sql
  ```

### 2. Configuration
Open the `config.php` file and adjust your database access credentials:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'your_password');
define('DB_NAME', 'url_shortener');

// Ensure this base URL matches your domain and ends with a trailing slash (/)
define('BASE_URL', 'https://s.yourdomain.com/');
```

### 3. Domain Settings (DNS)
- Go to the DNS management panel for the `yourdomain.com` domain.
- Add an **A Record** with the *Host/Name* `s`.
- Point the *A Record* to your server's **Public IP Address**.

### 4. Web Server
- Point the VirtualHost/Server Block configuration for the `s.yourdomain.com` domain to the directory (*document root*) where you placed this source code.

**If using Apache:**
- Ensure the `mod_rewrite` module is enabled. This application is equipped with an `.htaccess` file for automatic routing.

**If using Nginx:**
- Since Nginx does not read `.htaccess`, you need to add a rewrite block to your Nginx configuration file (e.g., `/etc/nginx/sites-available/s.yourdomain.com`) inside the `server { ... }` section:
  ```nginx
  location / {
      try_files $uri $uri/ @shortener;
  }

  location @shortener {
      rewrite ^/([a-zA-Z0-9]+)$ /redirect.php?code=$1 last;
  }
  ```

---

## 🚀 Usage

### 1. Using the Web UI
Simply access `https://s.yourdomain.com/` (or `index.php`) through a web browser.
1. Enter your *Username* (`admin`) and *Password* (`rahasia123` - can be changed in `config.php`).
2. Enter the long URL into the provided box, and click **Shorten**.

### 2. Using the API (n8n Integration)
You can call this API using the **HTTP Request** node in n8n with the following details:

- **Method**: `POST`
- **URL**: `https://s.yourdomain.com/api.php`
- **Headers**:
  - `Content-Type`: `application/json`
  - `X-API-Key`: `your_api_key_secret` (Replace with your API Key in `config.php`)
- **Body Content Type**: `JSON` (or form-data)
- **Parameters / Body**:
  ```json
  {
      "url": "https://www.google.com/search?q=ministry+of+finance"
  }
  ```
  *Note: The API key can also be sent in the body with the property name `api_key` if you cannot send headers.*

**Expected JSON Response:**
```json
{
    "success": true,
    "original_url": "https://www.google.com/search?q=ministry+of+finance",
    "short_code": "aB3xYz",
    "short_url": "https://s.yourdomain.com/aB3xYz"
}
```

## File Structure
- `index.php` : The front-end interface of the application.
- `api.php` : The API endpoint script for processing requests.
- `redirect.php`: The main routing script that looks up the code in the database and performs a `Location:` redirect.
- `config.php`: System configuration and PDO database connection.
- `schema.sql`: The query for creating the main table (`links`).
- `.htaccess` : Apache Server rewrite configuration rules.
