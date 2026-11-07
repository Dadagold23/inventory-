-- Module 21: Customer Communications History
-- Extends Modules 1-20 with customer communication tracking system

-- --------------------------------------------------------
-- Table: customer_communications
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_communications (
    communication_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    customer_id BIGINT UNSIGNED NOT NULL,
    contact_name VARCHAR(100) NULL,
    communication_type ENUM('email', 'phone', 'letter', 'sms') NOT NULL DEFAULT 'email',
    subject VARCHAR(200) NULL,
    body TEXT NULL,
    sent_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `read` TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (communication_id),
    INDEX idx_communications_customer (customer_id),
    INDEX idx_communications_date (sent_date),
    CONSTRAINT fk_communications_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_communications_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Customer communication history';

-- --------------------------------------------------------
-- Insert sample customer communications
-- --------------------------------------------------------
INSERT IGNORE INTO customer_communications (company_id, customer_id, contact_name, communication_type, subject, body, sent_date, `read`, created_by) VALUES
(1, 1, 'John Doe', 'email', 'Order Confirmation', 'Your order has been shipped.', NOW(), 1, 1),
(1, 2, 'Jane Smith', 'phone', 'Payment Reminder', 'Payment overdue for invoice #123', NOW(), 0, 1);

-- -------------------------------------------------------
-- End of Module 21 schema
-- -------------------------------------------------------