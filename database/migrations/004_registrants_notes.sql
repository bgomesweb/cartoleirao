-- Campo de observações do admin na tela de inscrições.
ALTER TABLE registrants ADD COLUMN notes VARCHAR(255) NULL AFTER paid_at;
