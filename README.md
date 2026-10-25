# LavaLust Framework

> A lightweight, fast PHP framework built for developers who want clean MVC architecture without unnecessary complexity or performance overhead.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-8892BF)](https://www.php.net/)
[![GitHub Stars](https://img.shields.io/github/stars/ronmarasigan/lavalust?style=flat)](https://github.com/ronmarasigan/lavalust/stargazers)

---

## Overview

**LavaLust** is an open-source PHP framework that follows the **MVC (Model–View–Controller)** architectural pattern. It is designed for developers who need a structured, maintainable, and scalable foundation — without the bloat of heavier modern frameworks.

Whether you are building a simple web application, a REST API, or a teaching project, LavaLust provides the right tools with minimal friction.

---

## Features

| Feature | Description |
|---|---|
| **MVC Architecture** | Clean separation of Models, Views, and Controllers for organized, maintainable code |
| **Built-in Routing** | Flexible URL routing that maps requests to controllers with minimal configuration |
| **Libraries & Helpers** | Reusable components for sessions, forms, validation, and database access |
| **Modular Design** | Scalable structure that supports clean organization as your application grows |
| **REST API Support** | First-class support for building RESTful APIs using LavaLust conventions |
| **ORM-like Models** | Simplified, readable database interaction without a heavy abstraction layer |

---

## Requirements

- PHP 7.4 or higher
- A web server with URL rewriting support (Apache `.htaccess` or Nginx config)
- Composer (optional, for dependency management)

---

## Installation

**Clone the repository:**

```bash
git clone https://github.com/ronmarasigan/lavalust.git
cd lavalust
```

**Or download a release directly:**

```bash
wget https://github.com/ronmarasigan/lavalust/archive/refs/heads/main.zip
unzip main.zip
```

Configure your web server to point to the project root and ensure `mod_rewrite` (Apache) or equivalent is enabled.

---

## Quick Start

### 1. Define a Route

**File:** `app/config/routes.php`

```php
$router->get('/', 'Welcome::index');
$router->get('/about', 'Welcome::about');
$router->post('/users/store', 'Users::store');
```

### 2. Create a Controller

**File:** `app/controllers/Welcome.php`

```php
<?php

class Welcome extends Controller
{
    public function index()
    {
        $data['title'] = 'Home';
        $this->call->view('welcome', $data);
    }

    public function about()
    {
        $this->call->view('about');
    }
}
```

### 3. Create a View

**File:** `app/views/welcome.php`

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?></title>
</head>
<body>
    <h1>Welcome to LavaLust Framework</h1>
    <p>Lightweight. Fast. MVC.</p>
</body>
</html>
```

### 4. Create a Model

**File:** `app/models/User_model.php`

```php
<?php

class User_model extends Model
{
    protected $table = 'users';

    public function getAll()
    {
        return $this->db->table($this->table)->get()->getResult();
    }

    public function findById(int $id)
    {
        return $this->db->table($this->table)
                        ->where('id', $id)
                        ->get()
    }
}
```

---

## Project Structure

```
lavalust/
├── app/
│   ├── config/          # Application configuration (database, routes, etc.)
│   ├── controllers/     # Controller classes
│   ├── models/          # Model classes
│   ├── views/           # View templates
│   └── libraries/       # Custom libraries and helpers
├── scheme/              # Core framework files (do not modify)
├── public/              # Publicly accessible entry point
│   └── index.php
└── runtime/            # Cache, logs, and uploads (must be writable)
```

Car listing and car/image create, read, update, and delete queries are handled
by `app/models/CarModel.php`. The model is registered in
`app/config/autoload.php` and is available to `ApiController` as `$this->carModel`;
request validation, authorization, and HTTP responses remain in the controller.

---

## Configuration

### Database

**File:** `app/config/database.php`

```php
$database['main'] = array(
    'driver'	=> getenv('DB_DRIVER') ?: '',
    'hostname'	=> getenv('DB_HOST') ?: '',
    'port'		=> getenv('DB_PORT') ?: '',
    'username'	=> getenv('DB_USER') ?: '',
    'password'	=> getenv('DB_PASSWORD') ?: '',
    'database'	=> getenv('DB_NAME') ?: '',
    'charset'	=> getenv('DB_CHARSET') ?: '',
    'dbprefix'	=> getenv('DB_PREFIX') ?: '',
    // Optional for SQLite
    'path'      => ''
);
```

### Base URL

**File:** `app/config/config.php`

```php
$config['base_url'] = 'http://localhost:3000/';
```

---

## Building a REST API

LavaLust supports REST API development out of the box. Controllers can return JSON responses for API endpoints.

```php
<?php

class ApiController extends Controller
{
    $this->call->library('api');

    public function users()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt(); 

        $this->call->model('User_model');
        $users = $this->User_model->getAll();

        $this->api->respond(['data' => $users]);
    }
}
```

Route definition:

```php
$router->get('/api/users', 'ApiController::users');
```

---

## DriveRent API setup

The React application uses the LavaLust API at `http://127.0.0.1:3000/`.

1. Copy `.env.example` to `.env` and set `DB_HOST`, `DB_PORT`, `DB_USER`, and
   `DB_PASSWORD` for your local MySQL instance. Keep `DB_NAME=car_rental_db`
   and `FRONTEND_ORIGIN=http://127.0.0.1:5173` for the supplied development
   configuration. `FRONTEND_ORIGIN` accepts a comma-separated list when you
   need to allow more than one frontend origin. LavaLust loads `.env` before
   its config files; `database.php` only maps those environment variables to
   the framework's connection settings. Do not commit `.env`.
2. Import `database/schema.sql` into MySQL. Its `CREATE ... IF NOT EXISTS`
   statements preserve existing tables and rows.
3. From `Back-End`, start LavaLust on the configured API address:

   ```powershell
   php lava serve
   ```

   LavaLust serves on `http://127.0.0.1:3000` by default. Use
   `php lava serve --port=3000` to specify the port explicitly.
4. In another terminal, start the React development server:

   ```powershell
   cd ..\Front-End
   npm install
   npm run dev
   ```

   Open `http://127.0.0.1:5173/`. The API allows the configured origins for
   credentialed session requests. Set `FRONTEND_ORIGIN` in the backend
   environment to the exact frontend origin (scheme and host, without a path),
   including on the hosting provider. For the deployed frontend, use
   `https://carenfrontend.vercel.app`; add a comma-separated local origin too
   if both local and deployed frontends should be allowed. On HTTPS requests,
   the API sets its session cookie with `SameSite=None; Secure` so browsers can
   send it from the separately hosted Vercel frontend. If a browser blocks
   third-party cookies, use a custom domain that places the frontend and API
   on the same site.
5. No default administrator account or password is created. Provision an
   administrator in the `users` table with a password hash generated by PHP's
   `password_hash()` and set its `role` to `admin`. Public account registration
   creates regular `user` accounts only; submitted role values are ignored.
   Never store a plaintext password in the database.

The API includes `POST /api/register` (JSON `full_name`, `email`, and
`password`), `POST /api/login`, `POST /api/logout`, `GET /api/me`,
`GET /api/cars/available`, vehicle detail/listing endpoints, and admin-only
vehicle CRUD and image-management endpoints. Authenticated customers can use
`POST /api/reservations` with `car_id`, `start_date`, and `end_date` to request
a booking; the backend calculates the price and checks overlapping pending or
confirmed bookings while locking the vehicle row. `GET /api/reservations`
returns the signed-in customer's records and returns all records to
administrators. `GET /api/reservations/{id}` enforces ownership, and
`PATCH /api/reservations/{id}` permits customers to cancel their own pending or
confirmed booking and administrators to confirm, reject, cancel, or complete
valid reservations. Reservation creation and status changes require the
`reservations` table from `database/schema.sql` (also defined by migration
`005_create_reservations_table.php`). Importing the schema uses
`CREATE TABLE IF NOT EXISTS` and does not replace existing tables or records.
For date-aware browsing, pass both `start_date=YYYY-MM-DD` and
`end_date=YYYY-MM-DD` to `GET /api/cars/available`; vehicles with overlapping
pending or confirmed reservations are excluded. The date range must start
today or later and end after pickup.

Registration does not sign a new user in; sign in at `/auth/login` after
creating an account. Session cookies are HttpOnly and SameSite=Strict. Payment,
maintenance, forecasting, and risk-analysis providers/routes are not configured
by this project and are not represented as completed features.

### Chatbot integration

The browser sends chat requests to this API, which forwards them to the
Chatbot service at `CHATBOT_API_URL` (default
`http://127.0.0.1:3001/api/chat`). Keep `AI_ROUTER_TOKEN` identical in
`Back-End/.env` and `Chatbot/.env`; do not expose or commit this token.
Start the Chatbot service from `..\chatbot` with:

```powershell
cd ..\chatbot
php lava serve --port=3001
```

The React app loads its widget from `..\chatbot\frontend`. Configure at least
one provider in `Chatbot/.env` or make the configured Ollama service available.

---

## Philosophy

LavaLust is built on a single principle: **minimal core, maximum control.**

Modern frameworks often add layers of abstraction that benefit large enterprise teams but get in the way of developers who want to understand exactly what their code is doing. LavaLust provides structure and utilities without hiding the underlying logic — making it an excellent choice for:

- **Rapid prototyping** — Get an application running in minutes
- **Learning MVC** — Understand how each architectural layer works
- **Lightweight production apps** — Deploy without dragging in unused dependencies
- **Teaching PHP development** — Clear conventions, readable source code

---

## Documentation

Full documentation is available at **[https://lavalust.netlify.app](https://lavalust.netlify.app)**

Topics covered include:

- Installation and server configuration
- Routing: static, dynamic, and grouped routes
- Controllers and request handling
- Models and query builder
- Views, layouts, and partials
- Built-in libraries (sessions, form validation, file upload)
- Helper functions
- REST API development
- Security best practices

---

## Contributing

Contributions are welcome. To contribute:

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature-name`
3. Commit your changes: `git commit -m "Add your feature description"`
4. Push to your branch: `git push origin feature/your-feature-name`
5. Open a pull request against `main`

Please ensure your code follows the existing style conventions and includes relevant documentation or comments where appropriate.

---

## Roadmap

- [ ] CLI tool for generating controllers, models, and migrations
- [ ] Middleware support
- [ ] Improved query builder with relationship support
- [ ] Enhanced error handling and debugging tools

---

## License

LavaLust Framework is open-source software licensed under the **[MIT License](https://opensource.org/licenses/MIT)**.

---

## Links

- **GitHub Repository:** [https://github.com/ronmarasigan/lavalust](https://github.com/ronmarasigan/lavalust)
- **Documentation:** [https://lavalust.netlify.app](https://lavalust.netlify.app)
- **Report an Issue:** [https://github.com/ronmarasigan/lavalust/issues](https://github.com/ronmarasigan/lavalust/issues)