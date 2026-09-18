# MPSC Practice Web 🎯

A high-performance, mobile-responsive **MPSC Practice & Mock Test Web Application** featuring topic-wise practice sets, bilingual explanations, 1-hour free practice trial with cross-browser IP synchronization, Google One-Tap sign-in, ₹10/day Daily Study Pass with top-right live hours countdown, automated Razorpay Webhook payment verification, and a real-time admin analytics dashboard.

---

## 🌟 Key Features

### 1. Multi-Exam Preparation
- **MPSC Group C Combined 2026** (महाराष्ट्र अराजपत्रित गट-क सेवा संयुक्त पूर्व परीक्षा)
- **MPSC Rajyaseva 2027** (महाराष्ट्र राजपत्रित नागरी सेवा परीक्षा)
- **Group C Practice Series 1**
- **Maharashtra GK & Talathi / Police Bharti**

### 2. Comprehensive Practice Experience
- Topic, subtopic, and practice set navigation with drawer menu.
- Complete bilingual question views with detailed Marathi solutions & explanations.
- One-click Google search helper for instant background research on question topics.
- Desktop-first wide layout (1,180px) and seamless mobile responsive UI.
- Progressive Web App (PWA) installable on Android, iOS, and desktop.

### 3. Monetization & Daily Study Pass System
- **1-Hour Free Practice Trial** (`FREE_TRIAL_SECONDS = 3600`) with smooth countdown timer (`60:00`).
- **₹10 / दिवस Daily Study Pass**: High-conversion daily micro-payment unlocking full unlimited access until **12:00 AM midnight** (`23:59:59`).
- **Top-Right Hours Remaining Display**: Live hours & minutes countdown badge (e.g. `१४ तास शिल्लक` with PRO indicator) cleanly positioned without overlapping.
- **Server-Side IP Synchronization**: Prevents timer evasion across browser profiles (Chrome, Edge, Firefox, Incognito) on the same network.
- **Non-Blocking Freemium Model**: Students can freely browse and read questions; the subscription modal triggers only when attempting an answer, toggling explanations, searching Google, or switching topics.
- **Google One-Tap Authentication**: Clean email verification without tedious manual forms for name or phone numbers.
- **Razorpay Webhook Automated Verification (`webhook_razorpay.php`)**: Instantaneous activation of 1-day access upon successful UPI/Card/Netbanking payment verified via HMAC-SHA256 signature.

### 4. Admin Portal & Live Analytics Dashboard
- Access via `admin_access.php?key=mpsc2026`.
- **Live Active Students**: Shows users studying right now with an animated green pulse indicator.
- **Engagement Duration Tracking**: Uses a lightweight 15-second heartbeat (`navigator.sendBeacon`) that pauses when tabs are hidden, measuring true active screen time.
- **Visitor Analytics Table**: Displays student name, email/IP, device type (Mobile/Desktop), total time spent, and subscription status.
- **1-Click Access Activation**: Instantly grant ₹10 1-Day Pass (until 12:00 AM tonight), 1-Month, or 1-Year access to any student email.

---

## 📁 Project Structure

```
mpscpracticeweb/
├── practice.php               # Core Practice Center (tests, questions, timer, paywall)
├── index.php                  # Web entry point (routes to practice.php)
├── webhook_razorpay.php       # Razorpay Webhook listener (HMAC verification & 1-day pass activation)
├── admin_access.php           # Admin Access & Live Engagement Analytics Dashboard
├── config.php                 # App configuration (DB, Google OAuth, Razorpay, Admin Key)
├── api.php                    # High-speed API bridge for questions and search
├── search.php                 # Advanced question search engine
├── subject.php                # Subject-wise question listing
├── manifest.json              # PWA Web App Manifest
├── sw.js                      # PWA Service Worker
├── icon-192.png               # App icon (192x192)
├── icon-512.png               # App icon (512x512)
├── .htaccess                  # Apache rewrite rules, caching & security headers
├── database/
│   └── schema.sql             # Complete database schema (all required tables)
└── README.md                  # Project documentation
```

---

## 🚀 Quick Setup & Installation

### 1. Requirements
- PHP 8.0 or higher (with `pdo_mysql`, `curl`, `json` extensions enabled)
- MySQL 5.7+ or MariaDB 10.4+
- Web server: Apache (with `mod_rewrite`) or Nginx

### 2. Import Database
Create a database (default: `BANK`) and import the schema:
```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS BANK CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p BANK < database/schema.sql
```

### 3. Configure `config.php`
Open `config.php` and verify or update your settings:
```php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'BANK');
define('DB_USER', 'root');
define('DB_PASS', '');

// Monetization & Trial Settings
define('FREE_TRIAL_SECONDS', 3600); // 1-hour free practice trial
define('DAILY_PASS_PRICE', 10.00); // ₹10 per day study pass
define('GOOGLE_CLIENT_ID', 'your-google-client-id.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'your-google-client-secret');
define('RAZORPAY_PAYMENT_URL', 'https://razorpay.com/payment-link/plink_TdDywf5XdCQKM3');
define('RAZORPAY_WEBHOOK_SECRET', 'mpsc_webhook_secret_2026'); // Secret configured in Razorpay Webhooks
define('ADMIN_SECRET_KEY', 'mpsc2026');
```

### 4. Configure Razorpay Webhook
In your [Razorpay Dashboard](https://dashboard.razorpay.com/) > Settings > Webhooks:
- **Webhook URL**: `https://yourdomain.com/webhook_razorpay.php`
- **Secret**: `mpsc_webhook_secret_2026` (or whatever set in `RAZORPAY_WEBHOOK_SECRET`)
- **Active Events**: `payment_link.paid`, `payment.captured`, `order.paid`

### 5. Run Locally
If using XAMPP:
1. Copy all repository files into `C:\xampp\htdocs\mpsc\`.
2. Start Apache and MySQL in XAMPP Control Panel.
3. Open in your browser:
   - **Practice System**: `http://localhost/mpsc/practice.php`
   - **Admin Access Dashboard**: `http://localhost/mpsc/admin_access.php?key=mpsc2026`

---

## 🔒 Security & Best Practices
- Keep your `ADMIN_SECRET_KEY` private and change the default key before deploying to production.
- Configure authorized JavaScript origins and redirect URIs in the [Google Cloud Console](https://console.cloud.google.com/apis/credentials) for your production domain.
- HTTPS is strictly required for Google One-Tap authentication in production environments.

---

## 📄 License
All rights reserved © Softweb Technologies.
