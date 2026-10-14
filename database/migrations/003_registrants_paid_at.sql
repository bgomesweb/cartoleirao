-- Data/hora em que o pagamento foi marcado como pago.
ALTER TABLE registrants ADD COLUMN paid_at DATETIME NULL AFTER paid;

-- Inscrições já pagas antes desta migração ficam sem data conhecida (NULL).
