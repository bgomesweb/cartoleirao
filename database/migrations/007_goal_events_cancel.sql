-- Gol anulado (VAR): guarda o autor de cada gol avisado e o tipo do evento
-- ('goal' = gol, 'cancelled' = gol que foi anulado depois, 'cancel' = aviso de anulação).
ALTER TABLE goal_events
    ADD COLUMN scorer VARCHAR(100) NULL,
    ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'goal';
