GLOW SMART SALON - APPOINTMENT AND SERVICE MANAGEMENT SYSTEM
(PHP + MySQL/MariaDB, converted from the Kap Boarding House project: same files layout, same
login/session/CSRF helpers, same green sidebar dashboard + split-screen login design.)

ROLES
  admin     admin.php     staff, services, schedules, all appointments, customers, notification log
  staff     staff.php     own appointments (confirm/reschedule/cancel/complete), customer records of
                          their own clients, own weekly hours, password
  customer  customer.php  register, view services/prices, pick staff + date + time, book, cancel,
                          appointment history, notifications, profile

DOUBLE-BOOKING PREVENTION (lib.php -> appt_save, database.sql -> appointment)
  1. Server checks date/time/past/service/staff assignment/working hours.
  2. Inside a DB transaction the staff row is locked (SELECT ... FOR UPDATE) and any active
     appointment of that staff that overlaps [start,end) is rejected.
  3. UNIQUE KEY no_double_booking (staff_id, appt_date, start_time, active_slot) is a database-level
     backstop for identical start times. Cancelled/completed appointments free the slot.
  The browser only offers free slots as a convenience; it is never trusted.

SETUP (XAMPP)
  1. Copy this folder to C:\xampp\htdocs\ (e.g. as "salon"). Start Apache and MySQL.
  2. phpMyAdmin > Import > database.sql
  3. Staff & admin log in at http://localhost/salon/admin-login.php  - admin / admin123 (change it under
     Password in the admin menu). Customers use http://localhost/salon/login.php (separate form).
     Already imported the older database.sql? Import upgrade.sql instead (adds the payment + review tables).
  4. As admin: Services -> add services. Staff -> add staff, tick their services, set their schedule.
     (No sample data is inserted.)
  5. Register a customer at register.php and book.

BOOKING SETUP (why "no staff" can appear)
  A customer only sees staff who (1) are assigned the service and (2) have working days/hours. New services are now
  assigned to all active staff automatically, and new staff get all services + default hours (Mon-Sat 9-5); you can
  change both under Staff. If something is missing, the admin dashboard shows a "Setup incomplete" warning with a
  "Fix automatically" button.

CHOOSING STAFF
  Customers see who is available: "Our Staff" page (services, working hours, rating, available today / next open
  day) and, when booking, each staff member's free times for the chosen date - the customer picks staff + time.

PAYMENTS (cash only)
  Online payments (GCash, Maya, bank transfer) were removed. Customers pay in cash at the salon. Admin or staff press
  "Mark paid (cash)" in Appointments; the customer is notified that the payment was received. Customers see
  paid / unpaid on My Appointments. Dashboard income = appointments marked paid this month.
  Upgrading from an older version? Import remove-online-payments.sql once (clears unconfirmed online payment records).

RATINGS
  After an appointment is marked completed the customer is notified and can rate it 1-5 stars (+ comment) under
  History. Ratings show on the staff cards; admin sees all under Ratings, staff see their own under My Ratings.

SMS SETUP (admin > SMS)
  Paste your Semaphore API key, tick "Turn on SMS notifications", Save, then use "Send test SMS". If it fails, the
  exact reason (invalid key, no credits, no internet...) is shown. SMS is OFF until you do this.

NOTIFICATIONS / SMS
  Every booking, confirmation, reschedule, cancellation, completion, reminder and payment update is saved in the customer's
  Notifications page and written to emails.log (email is OFFLINE like the original project;
  MAIL_ENABLED in config.php).
  SMS is OFF until you turn it on under admin > SMS (Semaphore API key; see SMS SETUP above). Semaphore is an
  outside paid service: the code is tested against a mock server only, so always send one test SMS to a real number first.
  Customer phone numbers are required at registration (09XXXXXXXXX or +639XXXXXXXXX).
  Reminders: admin dashboard button "Send due reminders now", or schedule reminders.php hourly
  (Windows Task Scheduler: C:\xampp\php\php.exe C:\xampp\htdocs\salon\reminders.php).

STATUS FLOW
  pending -> confirmed -> completed ; pending/confirmed/rescheduled -> cancelled ;
  rescheduling an active appointment sets it to "rescheduled". "Completed" is allowed only after the
  appointment start time has passed.

TESTING STATUS
  Run end-to-end on PHP 8.3 + MariaDB 10.11 (PHP built-in server): registration, booking, double-booking
  (incl. 12 simultaneous requests), cancel/reschedule/confirm/complete, reminders, role access, CSRF/XSS,
  separate logins, cash payments and ratings all pass. Not yet tried on Windows/XAMPP itself, and SMS (Semaphore) has never been run for real.
