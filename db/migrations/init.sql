Start Transaction;

CREATE TABLE IF NOT EXISTS users (
    id                        INT AUTO_INCREMENT PRIMARY KEY,
    email                     VARCHAR(255) UNIQUE NOT NULL,
    password                  VARCHAR(255)        NOT NULL,
    role                      ENUM('admin','user') DEFAULT 'user',
    is_verified               TINYINT(1)          DEFAULT 0,
    verification_token        VARCHAR(64)         NULL,
    email_verification_expires DATETIME           NULL,
    created_at                TIMESTAMP           DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP           DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS user_activity_logs (
    activity_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT          NULL,                     
    email        VARCHAR(255) NULL,
    action       VARCHAR(50)  NOT NULL,
    status       ENUM('success','failed') NOT NULL DEFAULT 'success',
    ip_address   VARCHAR(45)  NULL,
    user_agent   VARCHAR(255) NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

commit;