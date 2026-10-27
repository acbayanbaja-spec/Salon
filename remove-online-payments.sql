-- Run once if you used the earlier version with GCash/Maya/bank payments.
-- Removes unconfirmed online payment records (pending/rejected). Payments already marked paid are kept as history.
USE kap_salon;
DELETE FROM payment WHERE status <> 'paid';
