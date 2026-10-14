-- Inscrições 2027: Clássica R$ 60,00 e Glória Eterna R$ 20,00, abertas (prazo de 26/01/2027 é aplicado em public/inscricao.php).
UPDATE league_settings ls JOIN leagues l ON l.id = ls.league_id
SET ls.registrations_open = 1, ls.registration_fee = 60.00 WHERE l.is_default = 1;

UPDATE league_settings ls JOIN leagues l ON l.id = ls.league_id
SET ls.registrations_open = 1, ls.registration_fee = 20.00 WHERE l.is_default = 0 AND l.name LIKE '%Gl_ria%';
