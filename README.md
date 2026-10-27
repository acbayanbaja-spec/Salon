# Glow Smart Salon - Appointment & Service Management System

A modern PHP & MySQL web application for salon appointment bookings, customer management, staff scheduling, cash payment tracking, and SMS notifications via **PhilSMS** (or Semaphore).

---

## 🚀 Live Cloud Deployment Stack

| Component | Provider | Role |
| :--- | :--- | :--- |
| **Source Control** | [GitHub](https://github.com/acbayanbaja-spec/Salon) | Central repository & automated CI/CD deployments |
| **Web Service** | [Render](https://render.com) (Recommended) / [Vercel](https://vercel.com) | Production PHP web hosting & Docker runtime |
| **Database** | [Aiven](https://aiven.io) (MySQL) | Cloud-managed MySQL database with SSL encryption |
| **SMS Gateway** | [PhilSMS](https://philsms.com) | Automated SMS for bookings, confirmations, reminders |

---

## ⚙️ Environment Variables Reference

Configure these in your Render / Vercel Environment Variables dashboard or in a local `.env` file:

| Variable | Description | Example / Default |
| :--- | :--- | :--- |
| `DB_HOST` | MySQL hostname from Aiven or cloud host | `mysql-xxxx-project.aivencloud.com` |
| `DB_PORT` | MySQL port (Aiven uses a custom 5-digit port) | `12345` (default `3306`) |
| `DB_NAME` | Database name | `defaultdb` or `kap_salon` |
| `DB_USER` | Database username | `avnadmin` or `root` |
| `DB_PASS` | Database password | `YOUR_SECURE_PASSWORD` |
| `DATABASE_URL` | *(Alternative)* Full connection URI from Aiven | `mysql://avnadmin:pass@host:port/defaultdb` |
| `SMS_PROVIDER` | Active SMS provider | `philsms` (or `semaphore`) |
| `SMS_ENABLED` | Enable outbound SMS notifications | `true` |
| `PHILSMS_API_KEY` | Bearer API token from PhilSMS Dashboard | `123\|abcdef...` |
| `PHILSMS_SENDER_ID` | Sender name shown to customers | `PhilSMS` (or registered alphanumeric) |
| `BRAND` | Brand name in SMS and page headers | `Glow Smart Salon` |
| `CRON_KEY` | Secret token to trigger appointment reminders | `your-secret-cron-key` |

---

## 🛠️ Step-by-Step Connection & Deployment Guide

### 1. Database Setup on Aiven (Free / Starter Cloud MySQL)

1. Sign up / Log in to [Aiven.io](https://aiven.io).
2. Click **Create Service** -> Select **MySQL** -> Choose a Free / Hobby plan and region (e.g. Singapore / US).
3. Once the database status shows **Running**, go to the **Overview** tab:
   - Copy **Host**, **Port**, **User**, **Password**, and **Database Name** (typically `defaultdb`).
4. Import the database schema:
   - In Aiven, open the **Query Editor** tab (or connect via MySQL Workbench / CLI using the Service URI).
   - Copy the SQL statements from [`database.sql`](database.sql) and execute them to create all required tables.

---

### 2. Deploy on Render (Recommended for PHP & Apache)

Render builds and runs the application via Docker with full Apache rewrite and MySQL extensions.

1. Log in to [Render.com](https://render.com) and click **New +** -> **Web Service**.
2. Connect your GitHub repository: `https://github.com/acbayanbaja-spec/Salon`.
3. Choose **Docker** as the Runtime (it will automatically detect the [`Dockerfile`](Dockerfile)).
4. Under **Environment Variables**, add:
   - `DB_HOST`: *(Your Aiven Host)*
   - `DB_PORT`: *(Your Aiven Port)*
   - `DB_NAME`: *(Your Aiven Database Name, e.g. `defaultdb`)*
   - `DB_USER`: *(Your Aiven User, e.g. `avnadmin`)*
   - `DB_PASS`: *(Your Aiven Password)*
   - `SMS_PROVIDER`: `philsms`
   - `SMS_ENABLED`: `true`
   - `PHILSMS_API_KEY`: *(Your PhilSMS API Token)*
   - `PHILSMS_SENDER_ID`: `PhilSMS`
   - `BRAND`: `Glow Smart Salon`
   - `CRON_KEY`: `salon-cron-secret-2026`
5. Click **Create Web Service**. Render will build and launch your application at a `https://<app-name>.onrender.com` URL.

---

### 3. Deploy on Vercel (Alternative Serverless)

1. Log in to [Vercel.com](https://vercel.com) and click **Add New...** -> **Project**.
2. Import the `Salon` GitHub repository.
3. In **Environment Variables**, add the same database and SMS variables listed above.
4. Click **Deploy**. Vercel will process the serverless PHP functions configured in [`vercel.json`](vercel.json).

---

### 4. PhilSMS Gateway Setup

1. Register or log in to [PhilSMS](https://philsms.com).
2. Go to **API & Developers** / **API Tokens** -> Generate a new API token.
3. Ensure your PhilSMS account has active credits.
4. Set `PHILSMS_API_KEY` in Render/Vercel environment variables, OR log in to the salon admin dashboard:
   - Navigate to **Admin Dashboard** (`/admin-login.php`) -> Login with `admin` / `admin123`.
   - Go to **SMS** menu in the sidebar.
   - Choose **PhilSMS (philsms.com)**.
   - Paste your PhilSMS API Token and set Sender ID (`PhilSMS`).
   - Check **Turn on SMS notifications** and click **Save**.
   - Use the **Send a test SMS** tool to verify delivery to your phone.

---

### 5. Automated Reminders Cron Job

To automatically send reminders 24 hours before appointments:
- Set up an hourly HTTP GET request to:
  `https://your-domain.com/reminders.php?key=YOUR_CRON_KEY`
  (using Render Cron Jobs or free tools like [cron-job.org](https://cron-job.org)).

---

## 🔐 Default Admin Account

- **URL**: `/admin-login.php`
- **Username**: `admin`
- **Password**: `admin123`
*(Change password immediately under Settings > Change Password after your first login)*
