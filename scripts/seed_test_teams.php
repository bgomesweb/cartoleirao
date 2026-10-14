<?php

declare(strict_types=1);

/**
 * Popula uma liga com inscritos de TESTE, usando os times reais da liga
 * correspondente no Cartola (lista copiada da página da competição).
 * Cada time é vinculado ao time_id da API pública (busca por nome, desempate
 * pelo nome do cartoleiro). E-mails fictícios: teste+N@cartoleirao.test.
 *
 * Uso (slug da liga local; padrão: cartoleirao):
 *   docker compose exec app php scripts/seed_test_teams.php
 *   docker compose exec app php scripts/seed_test_teams.php cartoleirao-gloria-eterna
 *   docker compose exec app php scripts/backfill_scores.php   (puxa as pontuações)
 */

require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Database\Connection;
use Cartoleirao\Models\Registrant;
use Cartoleirao\Models\Season;
use Cartoleirao\Services\CartolaApiClient;

// [nome do time, nome do cartoleiro]
$lists = [];

$lists['cartoleirao'] = [
    ['Verdinho RB', 'Rafael'], ['MARLBORO S.C.C.P', 'Felipe Della'], ['F@mili@ CvZ FC', 'Gilson Felici@no'],
    ['MAUDUCARMO', 'Mauro Sergio'], ['PARCELONA S E', 'Reginaldo Correia'], ['piracartoloucos', 'preto caxambu'],
    ['GREMIO DE CONVENTOS', 'Jonatan Dornelles'], ['Timãoeo F.C', 'Rodrigo(SASHA)'], ['Empreendedor da Bola', 'Ricardo Reginato'],
    ['Os Pés de R@tos FC', 'Jeferson'], ['WenzelFC', 'Carlão Wenzel'], ['finoGORDO F.C', 'Toni'],
    ['MONTPELLIER SP0RT CLUB', 'Mauricio'], ['Chico Futebol Artes', 'Chico Freire'], ['mister guilherme', 'Guilherme'],
    ['Menudos do Morumtri', 'Flavio Zani'], ['X BURGUER PRO 26', 'MARQUINHO'], ['Velo Clube 1910 RC', 'Gilson Felici@no'],
    ['MadeinAraxa', 'Rodolfo Rodriguinho'], ['Barbieri*s Kalê Team', 'Marcos Barbiéri'], ['Alfanabeto', 'Caxumba no Saco'],
    ['Máquina Verdeee', 'James SEP'], ['CANAL FLALANDO DE FLAMENGO', 'CIVAN NUNES'], ['LOS_GALÁTICOS PM', 'LOPES10'],
    ['Piripaque10 FC', 'Jader Luis'], ['Corintivelo', 'Pedro Maia Jr - PITÓ'], ['Todo poderoso 101', 'claudinei luciano'],
    ['XV DE PIRIPIRI 2.0', 'Julio Bonato'], ['Paseto F.C.', 'Rogério Paseto'], ['thi.miguel', 'miguel'],
    ['R Benda FC', 'Rodrigo Bendande'], ['La Arca', 'Jorge Noé'], ['BAM Football Club', 'Alex moura'],
    ['Parmerinha Vga', 'Felipe Mendes'], ['Konyaspor F.C.', 'Leo'], ['ECP Nexus', 'Gui'],
    ['Ousaaado F C', 'Bruno Rocha'], ['Phanton X FC', 'Leonardo Marcondes'], ['BONDE SEM FREIO FC', 'Yago Souza'],
    ['FC Trovo', 'Mateus Trovó'], ['Cruzeirinho.RC', 'Rafael'], ['VL.ViDa LoKa.FC', 'NeToOoO'],
    ['SAB0r ENERGÉTIC0 FC', 'Felipe Quilici'], ['Azzoni FC', 'Rodrigo'], ['Hasdrubal FC', 'Bruno Gomes'],
    ['Leo Peroto F.C', 'Leonardo Peroto'], ['Perebas da Mitada', 'Sir Phill'], ['SEM LIMITES C', 'MARCELO LANDIN'],
    ['Radio Brasil Sbo', "Paulo D'Elboux"], ['Vini Sanches FC', 'Ricardo Sanches'], ['OcarecaLoko', 'Waner'],
    ['São Picolin F.C.', 'João Carlos Picolin'], ['Sow Seguro FC', 'Ricardo Sanches'], ['Jardim independência e.c', 'Luiz sampaio'],
    ['Bubas9 F.C', 'Bruno Luciano'], ['Badox_FC', 'Danilo Pelegrino'], ['Real Silveira Club', 'Mariano Silveira'],
    ['Palestrino 84 FC', 'João Pizzirani'], ['S.C Mitovisk', 'Matheus Seneme'], ['Boleanos FC', 'Pedro Pilz'],
    ['Tratorando RC', 'Ivan Mina'], ['Shakhtar Dos Lek 69', 'Pierre Henrique'], ['Esporte Clube XV de Novembro de Jaú', 'Marcelo Macedo'],
];

$lists['cartoleirao-gloria-eterna'] = [
    ['F@mili@ CvZ FC', 'Gilson Felici@no'], ['WenzelFC', 'Carlão Wenzel'], ['ECP Nexus', 'Gui'],
    ['Empreendedor da Bola', 'Ricardo Reginato'], ['CANAL FLALANDO DE FLAMENGO', 'CIVAN NUNES'], ['Hasdrubal FC', 'Bruno Gomes'],
    ['Azzoni FC', 'Rodrigo'], ['SEM LIMITES C', 'MARCELO LANDIN'], ['Chico Futebol Artes', 'Chico Freire'],
    ['Badox_FC', 'Danilo Pelegrino'], ['Konyaspor F.C.', 'Leo'], ['Leo Peroto F.C', 'Leonardo Peroto'],
    ['Corintivelo', 'Pedro Maia Jr - PITÓ'], ['CricoCreco SEP', 'Layla'], ['Real Silveira Club', 'Mariano Silveira'],
    ['S.C Mitovisk', 'Matheus Seneme'], ['Boleanos FC', 'Pedro Pilz'], ['OcarecaLoko', 'Waner'],
    ['Tratorando RC', 'Ivan Mina'], ['Paseto F.C.', 'Rogério Paseto'],
];

$leagueSlug = $argv[1] ?? 'cartoleirao';
if (!isset($lists[$leagueSlug])) {
    fwrite(STDERR, 'Liga desconhecida. Opções: ' . implode(', ', array_keys($lists)) . "\n");
    exit(1);
}
$teams = $lists[$leagueSlug];

$pdo = Connection::get();
$stmt = $pdo->prepare('SELECT id FROM leagues WHERE slug = ?');
$stmt->execute([$leagueSlug]);
$leagueId = (int) $stmt->fetchColumn();
if ($leagueId === 0) {
    fwrite(STDERR, "Liga '{$leagueSlug}' não encontrada no banco.\n");
    exit(1);
}

$api = new CartolaApiClient();
$norm = static fn (string $s): string => mb_strtolower(trim($s));
$created = $linked = 0;

foreach ($teams as $i => [$teamName, $owner]) {
    $n = $i + 1;
    $email = "teste+{$n}@cartoleirao.test";

    $exists = $pdo->prepare('SELECT 1 FROM registrants WHERE league_id = ? AND season_id = ? AND email = ?');
    $exists->execute([$leagueId, Season::activeId(), $email]);
    if ($exists->fetchColumn()) {
        echo "[{$n}] já existe: {$teamName}\n";
        continue;
    }

    $matches = array_values(array_filter(
        $api->searchTeamsByName($teamName),
        static fn ($c) => $norm($c['name']) === $norm($teamName)
    ));
    if (count($matches) > 1) {
        $byOwner = array_values(array_filter($matches, static fn ($c) => $norm($c['cartola_name']) === $norm($owner)));
        if ($byOwner) {
            $matches = $byOwner;
        }
    }
    $match = count($matches) === 1 ? $matches[0] : null;

    Registrant::create([
        'league_id' => $leagueId,
        'name' => $owner,
        'email' => $email,
        'whatsapp' => '00000000000',
        'cartola_team_name' => $teamName,
        'cartola_team_id' => $match['team_id'] ?? null,
        'cartola_team_slug' => $match['slug'] ?? null,
    ]);
    $created++;
    $match ? $linked++ : null;
    echo "[{$n}] {$teamName} -> " . ($match ? "time_id {$match['team_id']}" : 'NÃO vinculado (' . count($matches) . ' candidatos)') . "\n";
    usleep(300000);
}

echo "\nCriados: {$created} | Vinculados: {$linked}\n";
