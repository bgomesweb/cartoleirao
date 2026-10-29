-- Estado das notificações periódicas (status do mercado, jogos do dia) para não repetir avisos.
CREATE TABLE IF NOT EXISTS notification_state (
    name VARCHAR(50) PRIMARY KEY,
    value VARCHAR(100) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
