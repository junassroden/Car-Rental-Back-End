# 🔥 LavaLust Framework  
**Lightweight • Fast • MVC for PHP Developers**

LavaLust is a lightweight PHP framework built for developers who want a
**structured, modular, and scalable** development experience — without
unnecessary complexity or performance overhead.

---

## ❓ What is LavaLust?

**LavaLust** is a PHP framework that follows the  
**MVC (Model–View–Controller)** architectural pattern.

It is designed for developers who want:
- Clean project structure
- Modular and maintainable code
- Built-in routing and database tools
- REST API–ready applications

---

## 🚀 Key Features

### 🧠 MVC Architecture  
Clear separation between **Models**, **Views**, and **Controllers**.

### ⚙️ Built-in Routing  
Clean and flexible routing system for mapping URLs to controllers.

### 📦 Libraries & Helpers  
Reusable helpers and libraries for sessions, forms, validation, database access,
and more.

### 📁 Modular Design  
Supports scalable development and clean organization of application logic.

### 🔗 REST API Support  
Easily build RESTful APIs using LavaLust conventions and tools.

### 📘 ORM-like Models  
Simplified database interaction with structured, readable model methods.

---

## 🧪 Quick Example

### Route Definition  
**File:** `app/config/routes.php`

```php
$router->get('/', 'Welcome::index');
```
### Controller
**File:** `app/controllers/Welcome.php`

```php
class WelcomeController extends Controller
{
    public function index()
    {
        $this->call->view('welcome');
    }
}
```

### View
**File:** `app/views/welcome.php`

### Output
<h1>Welcome to LavaLust Framework</h1>
<p>Lightweight. Fast. MVC.</p>

## 🎯 Philosophy
Minimal core. Maximum control.

LavaLust avoids heavy abstractions while giving developers the tools they need
to build clean, scalable applications.

## 📚 Learn More
📦 GitHub Repository
👉 https://github.com/ronmarasigan/lavalust

📖 Official Documentation
👉 https://lavalust.netlify.app

## 🤝 Contributing
Contributions are welcome and appreciated.

Fork the repository

Create a feature branch

Commit your changes

Open a pull request

## 📜 License
LavaLust Framework is open-source software licensed under the MIT License.

# 🔥 LavaLust — Clean structure. Real control.

