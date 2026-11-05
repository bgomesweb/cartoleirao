<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

use Cartoleirao\Models\League;
use Cartoleirao\Models\Score;

/**
 * What the home page shows when the market opens: how the round that just ended went, per league.
 * Classic leagues show the top 8 and the bottom 2; round-robin ones (Glória Eterna) the top 2 and
 * bottom 2. Also writes the (random, jokey) closing text praising the leaders and mocking the last ones.
 */
class RoundRecap
{
    private const CLASSIC_TOP = 8;
    private const ROUND_ROBIN_TOP = 2;
    private const BOTTOM = 2;

    private const PRAISE = [
        '{team} fez {pts} pontos na rodada {round}. {pres}, devolve a bola, que o resto da liga quer jogar também! 👑',
        'Ajoelhou, tem que rezar! {team} ({pres}) meteu {pts} pontos e passou o trator na rodada {round}. 🚜',
        'Se o Cartola tivesse Bola de Ouro, {team} levava com {pts} pontos. Os outros só participam pra fazer número. 🏆',
        '{pres}, qual é o segredo do {team}? {pts} pontos! Vendeu a alma pro VAR ou só tem sorte mesmo? 😈',
        'Atenção, atenção: {team} fez {pts} pontos e já pode pedir música no Fantástico. A resenha de hoje é por conta de {pres}! 🍻',
        '{team} resolveu jogar de verdade na rodada {round}: {pts} pontos. Enquanto isso, o resto escalava no chute e na fé. 🔥',
        'Chama o Tite, chama o Ancelotti, chama quem for: {pres} fez {pts} pontos com o {team} e ninguém explica. 🧠',
    ];

    private const ROAST = [
        '{team} fez só {pts} pontos. Dizem que a escalação foi feita no escuro, sem celular e de ressaca. 🙈',
        'Lá no fundo, {team} com {pts} pontos: se o time fosse um carro, estava sem gasolina, com o pneu furado e o estepe emprestado. 🛞',
        '{pres}, {pts} pontos com o {team}? Pelo amor de Deus, larga essa prancheta e vai assistir de camarote! 📋',
        'Quem escalou o {team}? {pts} pontos é caso pra chamar o Sherlock. Ou o exorcista. 🔎',
        '{team} fechou a rodada com {pts} pontos. A boa notícia: não tem como piorar. A má: tem sim, é só esperar a próxima. 😬',
        'O {team} foi tão mal que o goleiro adversário pediu desculpas. {pts} pontos, {pres}... respeita o Cartola! 🧤',
        '{pres}, o {team} fez {pts} pontos e o banco de reservas já tá pedindo pra sair mais cedo. Segura a onda! 🪑',
        'Alerta de lanterna: {team} com {pts} pontos. Foi aquela rodada em que até o capitão pediu pra não ser capitão. 🔦',
    ];

    /**
     * @return array{round: int, leagues: array[], text: string[]}|null null when there's nothing to show yet
     */
    public static function forMarketOpening(?int $currentRound): ?array
    {
        $round = ($currentRound ?? 0) - 1; // the market opens for round N when N-1 has just ended
        if ($round < 1) {
            return null;
        }

        $leagues = [];
        foreach (League::all() as $league) {
            if (empty($league['is_active'])) {
                continue;
            }
            $results = Score::roundResults((int) $league['id'], $round);
            if (!$results) {
                continue;
            }
            $isRoundRobin = $league['type'] === 'round_robin';
            $topSize = $isRoundRobin ? self::ROUND_ROBIN_TOP : self::CLASSIC_TOP;
            $top = array_slice($results, 0, $topSize);
            $bottom = array_slice($results, max($topSize, count($results) - self::BOTTOM));
            $leagues[] = ['name' => $league['name'], 'top' => $top, 'bottom' => $bottom, 'last' => end($results), 'bottom_from' => count($results) - count($bottom) + 1];
        }
        if (!$leagues) {
            return null;
        }

        $leagues = self::withCrests($leagues);

        return ['round' => $round, 'leagues' => $leagues, 'text' => self::text($leagues, $round)];
    }

    /**
     * The roster table often has no crest saved, so the teams shown without one get it from the
     * Cartola API (kept on disk for a day, since crests rarely change).
     */
    private static function withCrests(array $leagues): array
    {
        $dir = sys_get_temp_dir() . '/cartoleirao-crests';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = "{$dir}/crests.json";
        $known = [];
        if (is_file($file) && time() - (int) filemtime($file) < 86400) {
            $known = json_decode((string) file_get_contents($file), true) ?: [];
        }

        $missing = [];
        foreach ($leagues as $l) {
            foreach (array_merge($l['top'], $l['bottom']) as $t) {
                if (empty($t['crest_url']) && !isset($known[$t['cartola_team_id']])) {
                    $missing[] = (int) $t['cartola_team_id'];
                }
            }
        }
        if ($missing) {
            foreach ((new CartolaApiClient())->teamLineups(array_unique($missing)) as $id => $response) {
                $known[$id] = $response['time']['url_escudo_png'] ?? $response['time']['url_escudo_svg'] ?? null;
            }
            @file_put_contents($file, json_encode($known));
        }

        foreach ($leagues as &$l) {
            foreach (['top', 'bottom'] as $part) {
                foreach ($l[$part] as &$t) {
                    $t['crest_url'] = $t['crest_url'] ?: ($known[$t['cartola_team_id']] ?? null);
                }
                unset($t);
            }
        }
        unset($l);

        return $leagues;
    }

    /** One praise line + one roast line per league, picked at random. */
    private static function text(array $leagues, int $round): array
    {
        $vars = fn(array $t): array => [
            '{team}' => $t['team_name'] ?: 'Time ' . $t['cartola_team_id'],
            '{pres}' => $t['owner_name'] ?: 'presidente',
            '{pts}' => number_format((float) $t['points'], 2, ',', '.'),
            '{round}' => (string) $round,
        ];
        $lines = [];
        foreach ($leagues as $l) {
            $lines[] = strtr(self::PRAISE[array_rand(self::PRAISE)], $vars($l['top'][0]));
            $lines[] = strtr(self::ROAST[array_rand(self::ROAST)], $vars($l['last']));
        }

        return $lines;
    }
}
