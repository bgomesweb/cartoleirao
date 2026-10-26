-- App Android nativo: aparelhos registrados no Firebase Cloud Messaging (FCM).
CREATE TABLE IF NOT EXISTS fcm_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    token TEXT NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fcm_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
