# Glow Smart Salon - Appointment & Service Management System

A modern PHP web application for salon appointment bookings, customer management, staff scheduling, cash payment tracking, and SMS notifications via **PhilSMS**.

Supports both **Supabase (PostgreSQL)** and **MySQL (Aiven / Local XAMPP)** out of the box!

---

## 🚀 Live Cloud Deployment Stack

| Component | Provider | Role |
| :--- | :--- | :--- |
| **Source Control** | [GitHub](https://github.com/acbayanbaja-spec/Salon) | Central repository & automated CI/CD deployments |
| **Database** | [Supabase](https://supabase.com) (PostgreSQL) | Cloud-managed PostgreSQL database with connection pooling |
| **Web Service** | [Render](https://render.com) (Recommended) / [Vercel](https://vercel.com) | Production PHP web hosting & Docker runtime |
| **SMS Gateway** | [PhilSMS](https://philsms.com) | Automated SMS for bookings, confirmations, reminders |

---

## ⚙️ Environment Variables Reference

Configure these in your Render / Vercel Environment Variables dashboard or in a local `.env` file:

### Option A: Supabase (PostgreSQL - Recommended)
| Variable | Value | Description |
| :--- | :--- | :--- |
| `DATABASE_URL` | `postgresql://postgres.pgiluecdzlwnguimafxb:46IUe9Ik9p2Tkh6S@aws-0-ap-south-1.pooler.supabase.com:6543/postgres?pgbouncer=true` | Supabase Transaction Pooler URL |
| `DIRECT_URL` | `postgresql://postgres.pgiluecdzlwnguimafxb:46IUe9Ik9p2Tkh6S@aws-0-ap-south-1.pooler.supabase.com:5432/postgres` | Supabase Session Pooler URL |

### SMS & Application Settings
| Variable | Value | Description |
| :--- | :--- | :--- |
| `SMS_PROVIDER` | `philsms` | Active SMS provider (`philsms` or `semaphore`) |
| `SMS_ENABLED` | `true` | Enable outbound SMS notifications |
| `PHILSMS_API_KEY` | *(Your PhilSMS API Token)* | Bearer API token from PhilSMS Dashboard |
| `PHILSMS_SENDER_ID`| `PhilSMS` | Sender name shown to customers |
| `BRAND` | `Glow Smart Salon` | Brand name in SMS and page headers |
| `CRON_KEY` | `salon-cron-secret-2026` | Secret token to trigger appointment reminders |

---

## 🛠️ Step-by-Step Connection & Deployment Guide

### 1. Database Setup on Supabase (PostgreSQL)

Your Supabase project schema is already provided in [`supabase.sql`](supabase.sql).
All tables are already deployed to your Supabase project!
If you ever create a new Supabase project:
1. Open the **SQL Editor** in your Supabase dashboard.
2. Copy and paste the contents of [`supabase.sql`](supabase.sql).
3. Click **Run** to create all tables and constraints.
4. Go to **Project Settings** -> **Database** -> **Connection string** -> Copy the **Transaction-mode pooler** URL (port 6543).

---

### 2. Deploy on Render (Recommended for PHP & Apache)

Render builds and runs the application via Docker with full Apache rewrite and PostgreSQL + MySQL extensions.

1. Log in to [Render.com](https://render.com) and click **New +** -> **Web Service**.
2. Connect your GitHub repository: `https://github.com/acbayanbaja-spec/Salon`.
3. Choose **Docker** as the Runtime (it will automatically detect the [`Dockerfile`](Dockerfile)).
4. Under **Environment Variables**, add:
   - `DATABASE_URL`: `postgresql://postgres.pgiluecdzlwnguimafxb:46IUe9Ik9p2Tkh6S@aws-0-ap-south-1.pooler.supabase.com:6543/postgres?pgbouncer=true`
   - `SMS_PROVIDER`: `philsms`
   - `SMS_ENABLED`: `true`
   - `PHILSMS_API_KEY`: *(Your PhilSMS API Token)*
   - `PHILSMS_SENDER_ID`: `PhilSMS`
   - `BRAND`: `Glow Smart Salon`
   - `CRON_KEY`: `salon-cron-secret-2026`
5. Click **Create Web Service**. Render will deploy your live application!

---

### 3. Deploy on Vercel (Alternative Serverless)

1. Log in to [Vercel.com](https://vercel.com) and click **Add New...** -> **Project**.
2. Import the `Salon` GitHub repository.
3. In **Environment Variables**, add `DATABASE_URL` and the SMS variables listed above.
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
*(Change password immediately under Password in the admin menu after first login)*
