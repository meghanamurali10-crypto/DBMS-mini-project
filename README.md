# DBMS Mini Project — College Stock Management

A web-based **College Stock Management System** built as a DBMS mini project. It helps a college track inventory/stock items (e.g., lab equipment, stationery, furniture) — recording what's added, issued, and remaining — using a simple PHP + MySQL backend with a JavaScript/CSS frontend.

##  Tech Stack

- **Backend:** PHP
- **Frontend:** HTML, CSS, JavaScript
- **Database:** MySQL

##  Project Structure

```
DBMS-mini-project/
├── New folder/
│   └── college_stock_portal/   # Main application source (PHP, JS, CSS)
├── LICENSE
└── README.md
```

##  Features

- Admin login / authentication
- Add new stock items to the database
- Update / edit existing stock records
- Issue stock and track remaining quantity
- View and search stock inventory
- Delete obsolete stock entries

*(Adjust this list to match the actual modules implemented in `college_stock_portal`.)*

##  Prerequisites

- [XAMPP](https://www.apachefriends.org/) / WAMP / LAMP (Apache + PHP + MySQL)
- PHP 7.x or later
- MySQL / MariaDB
- A web browser

##  Getting Started

1. **Clone the repository**
   ```bash
   git clone https://github.com/meghanamurali10-crypto/DBMS-mini-project.git
   ```

2. **Move the project into your server's web root**
   - For XAMPP: copy the `New folder/college_stock_portal` directory into `htdocs/`
   - For WAMP: copy it into `www/`

3. **Create the database**
   - Open phpMyAdmin (`http://localhost/phpmyadmin`)
   - Create a new database (e.g., `college_stock`)
   - Import the project's `.sql` file if one is provided in the repo

4. **Configure the database connection**
   - Open the PHP config/connection file (commonly `config.php` or `db.php`) inside `college_stock_portal`
   - Update the credentials:
     ```php
     $host = "localhost";
     $user = "root";
     $password = "";
     $database = "college_stock";
     ```

5. **Start Apache & MySQL** from your XAMPP/WAMP control panel.

6. **Run the app** by visiting:
   ```
   http://localhost/college_stock_portal/
   ```

##  Usage

- Log in as admin.
- Add, update, issue, or remove stock items through the dashboard.
- View reports/lists of current stock levels.

##  Contributing

Contributions, issues, and feature requests are welcome. Feel free to fork the repo and submit a pull request.

## 📄 License

This project is licensed under the **MIT License** — see the [LICENSE](LICENSE) file for details.
