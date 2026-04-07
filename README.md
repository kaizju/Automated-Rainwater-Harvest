# 💧 EcoRain — Automated Rainwater Harvesting System

EcoRain is a web-based monitoring and management system for automated rainwater harvesting. It provides real-time tank monitoring, sensor data visualization, water quality tracking, usage analytics, and map-based tank location management across multiple user roles.

---

## 🗂️ Project Structure

```
AUTOMATED-RAINWATER-HARVESTING/
│
├── app/
│   ├── admin/                          # Admin role pages
│   │   ├── admin_dashboard.php         # Main admin dashboard with fleet overview
│   │   ├── admin_map.php               # Live tank map with Leaflet.js + geocoding
│   │   ├── admin_oversight.php         # Admin oversight & monitoring panel
│   │   ├── admin_settings.php          # System settings, add/delete tanks
│   │   ├── admin_usage.php             # Water usage statistics & charts
│   │   ├── admin_userlogs.php          # User activity logs & role management
│   │   └── admin_weather.php           # Weather monitoring page
│   │
│   ├── manager/                        # Manager role pages
│   │   ├── manager.php                 # Manager dashboard
│   │   ├── map.php                     # Tank map view for managers
│   │   ├── settings.php                # Settings page (add/delete tanks)
│   │   ├── usage.php                   # Usage stats for managers
│   │   ├── user.php                    # Manager profile page
│   │   └── weather.php                 # Weather page for managers
│   │
│   └── user/                           # Regular user role pages
│       ├── dashboard.php               # User dashboard
│       ├── map.php                     # Tank map view for users
│       ├── profileinfo.php             # User profile & activity logs
│       ├── usage.php                   # Personal usage statistics
│       └── weather.php                 # Weather page for users
│
├── connections/                        # Core backend utilities
│   ├── config.php                      # PDO database connection & BASE_URL config
│   ├── functions.php                   # Shared helper functions (requireLogin, redirect)
│   └── signout.php                     # Session destroy & logout handler
│
├── others/                             # Shared assets & utilities
│   ├── activity_logger.php             # Reusable activity logging function
│   ├── all.css                         # Global shared stylesheet (sidebar, nav, layout)
│   └── map.css                         # Leaflet map overrides & map UI styles
│
├── db/                                 # Database files
│   ├── migrations/
│   │   └── init.sql                    # Initial database schema (CREATE TABLE statements)
│   └── use-cases/
│       ├── insert_tank_sensor_sensor-readings.sql   # Sample tank/sensor seed data
│       ├── insert_user_logs.sql                     # Sample activity log seed data
│       └── working_schema.sql                       # Full working schema with constraints
│
├── .gitignore                          # Git ignore rules
├── index.php                           # Entry point / login page
├── index.js                            # Frontend JS entry (if applicable)
├── package.json                        # Node dependencies (if applicable)
└── package-lock.json                   # Locked Node dependency tree
```

---

## 🗄️ Database Schema

The system uses a **MySQL** database named `automated_rainwater`.

| Table | Description |
|---|---|
| `users` | Stores all user accounts with roles (`admin`, `manager`, `user`) |
| `user_activity_logs` | Tracks every login, action, and system event per user |
| `tank` | Registered rainwater tanks with location, capacity, and status |
| `sensors` | Sensors attached to each tank (type, model, unit) |
| `sensor_readings` | Time-series readings from sensors with anomaly flags |
| `water_usage` | Records of water consumed per tank per user |
| `water_quality` | pH level, turbidity, and quality status per tank |
| `system_settings` | Key-value store for global system configuration |

### Cascade Rules
- Deleting a **tank** automatically removes its sensors, sensor readings, water usage, and water quality records (`ON DELETE CASCADE`)
- Deleting a **user** sets their foreign key references to `NULL` in logs (`ON DELETE SET NULL`)

---

## 👥 User Roles

| Role | Access |
|---|---|
| `admin` | Full access — manage tanks, users, settings, view all data |
| `manager` | Manage tanks and settings, view usage and weather |
| `user` | View dashboard, usage stats, weather, and own profile |

---

## 🔑 Key Features

- **Live Tank Map** — Leaflet.js map with Nominatim geocoding; pins exact tank locations from address text
- **Tank Management** — Add and delete tanks with cascade cleanup; registered tanks table with status badges
- **Sensor Monitoring** — Track sensor readings and anomalies per tank
- **Water Quality** — pH and turbidity monitoring with configurable alert thresholds
- **Usage Analytics** — Per-tank and per-user water consumption charts
- **Weather Integration** — Weather data display relevant to harvest conditions
- **Activity Logging** — Every user action is logged with IP, status, and timestamp
- **System Settings** — Configurable capacity, thresholds, pump schedule, notifications, and timezone
- **Role-Based Access** — Separate dashboards and navigation for admin, manager, and user roles

---

## ⚙️ Setup

### Requirements
- PHP 8.x
- MySQL 5.7+ or MariaDB
- A web server (Apache / Nginx) or PHP built-in server

### Installation

```bash
# 1. Clone the repository
git clone https://github.com/your-username/automated-rainwater-harvesting.git
cd automated-rainwater-harvesting

# 2. Import the database
mysql -u root -p < db/migrations/init.sql

# 3. (Optional) Seed sample data
mysql -u root -p automated_rainwater < db/use-cases/insert_tank_sensor_sensor-readings.sql
mysql -u root -p automated_rainwater < db/use-cases/insert_user_logs.sql

# 4. Configure database connection
# Edit Connections/config.php and set your DB credentials and BASE_URL
```

### `Connections/config.php` example
```php
define('BASE_URL', 'http://localhost/automated-rainwater-harvesting');

$pdo = new PDO(
    'mysql:host=localhost;dbname=automated_rainwater;charset=utf8mb4',
    'your_db_user',
    'your_db_password',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
```

---

## 🗺️ Tank Location Geocoding

Tank locations stored in `location_add` (e.g. `"Brgy. Poblacion, Manolo Fortich, Bukidnon"`) are automatically geocoded using the **OpenStreetMap Nominatim API** when the map page loads. For best accuracy, use specific barangay-level addresses.

> **Tip:** For production use with many tanks, consider caching `lat` and `lng` columns directly in the `tank` table to avoid repeated API calls.

---

## 📁 Naming Conventions

| Pattern | Meaning |
|---|---|
| `admin_*.php` | Admin-only pages under `App/Admin/` |
| `App/Manager/*.php` | Manager-role pages |
| `App/User/*.php` | Regular user pages |
| `Connections/*.php` | Database and session utilities |
| `Others/*.css` | Shared stylesheets loaded across all roles |

---

## 🛠️ Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.x (procedural + PDO) |
| Database | MySQL / MariaDB |
| Frontend | HTML5, CSS3, Vanilla JavaScript |
| Maps | Leaflet.js + OpenStreetMap + Nominatim |
| Fonts | Google Fonts (Inter, Space Grotesk, DM Mono, Sora) |
| Charts | Chart.js |

---

## 📄 License

This project is for academic / internal use. All rights reserved.
