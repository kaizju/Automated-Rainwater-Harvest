# 📘 EcoRain — PHP PDO Role-Based Application

A simple role-based PHP PDO web application with **email verification**, **activity logging**, and **basic authentication**.

Supports three roles:

| Role    | Accessible Area     |
|---------|---------------------|
| Admin   | `/admin`, `/users`  |
| Manager | `/manager`          |
| User    | `/user`             |

> Unauthorized access redirects to the login page.

---

## 📂 Project Structure

```
app/
├── Admin/
│   └── admin_dashboard.php
│   └── admin_settings.php
│   └── admin_usage.php
│   └── admin_userlogs.php
│   └── admin_weather.php
│   └── admin.php
├── Manager/
│   └── Manager.php
├── Settings/
│   └── settings.php
├── Usage/
│   └── usage.php
├── User/
│   ├── dashboard.php
│   ├── profileinfo.php
│   ├── usage.php
│   ├── weather.php
├── Connections/
│   ├── config.php
│   └── functions.php
│   └── signout.php
├── db/
│   └── migrations/
│       └── init.sql
│   └── use-cases/
│       └── insert_tables.sql
│       └── insert_user_logs.sql
│   └── working_schema.sql
├── Others/
│   └── activity-logger.php
│   └── all.css
├── Users/
│   ├── user.php
└── index.php   ← login page
