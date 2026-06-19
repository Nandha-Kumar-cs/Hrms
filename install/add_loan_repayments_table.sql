-- ─────────────────────────────────────────────────────────────────────────────
-- Loan Repayments — port of the reference Employee_Management loan_repayments
-- table so loans/advances support a full repayment history (manual or from a
-- salary slip), running balance, and auto-completion when fully settled.
-- Idempotent.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS loan_repayments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_loan_id INT NOT NULL,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_date DATE NOT NULL,
    salary_slip_id INT NULL,
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_loan_id),
    INDEX (salary_slip_id),
    CONSTRAINT fk_loan_repay_loan FOREIGN KEY (employee_loan_id)
        REFERENCES employee_loans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
