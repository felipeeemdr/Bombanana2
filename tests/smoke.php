<?php
declare(strict_types=1);
require __DIR__ . '/../app/game_logic.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

$decks = nexo_cards();
check(count($decks) === 4, 'devem existir quatro tipos de módulo');
foreach ($decks as $code => $module) {
    check(count($module['cards'] ?? []) === 8, "o módulo {$code} deve conter oito cartas");
}

foreach ([2, 3, 4] as $count) {
    for ($round = 0; $round < 80; $round++) {
        $game = nexo_build_game($count);
        check(count($game['modules']) === $count, "sorteio com {$count} módulos");
        $codes = array_column($game['modules'], 'code');
        check(count(array_unique($codes)) === $count, 'não repetir módulo na mesma partida');
        foreach ($game['modules'] as $module) {
            check(isset($decks[$module['code']]['cards'][$module['card_id']]), 'carta deve pertencer ao baralho correto');
            $touch = nexo_tactile_observation($module);
            $serialized = strtoupper(json_encode($touch, JSON_UNESCAPED_UNICODE) ?: '');
            foreach (['AZUL','VERMELHO','BRANCO','AMARELO','VERDE','VIOLETA','LARANJA'] as $color) {
                check(!str_contains($serialized, $color), 'painel tátil não deve revelar cores');
            }
        }
    }
}

$manual = nexo_manual();
check(count($manual['dialSymbols'] ?? []) === 8, 'astrolábio deve ter oito símbolos');
check(count($manual['ribbonPriority'] ?? []) === 4, 'grimório deve conter quatro ordens de fitas');
echo "OK: quatro baralhos, 80 sorteios para cada quantidade (2/3/4), cartas válidas e observações táteis sem cores.\n";
