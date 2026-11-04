<?php
declare(strict_types=1);

function nexo_cards(): array
{
    static $data = null;
    if ($data === null) {
        $raw = file_get_contents(dirname(__DIR__) . '/data/cards.json');
        $data = json_decode($raw ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    }
    return $data['modules'] ?? [];
}

function nexo_manual(): array
{
    static $data = null;
    if ($data === null) {
        $raw = file_get_contents(dirname(__DIR__) . '/data/manual.json');
        $data = json_decode($raw ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    }
    return $data;
}

function nexo_module_names(): array
{
    return [
        'F' => 'Fitas de seda',
        'B' => 'Quatro sigilos',
        'S' => 'Alavancas de papel',
        'D' => 'Astrolábio',
    ];
}

function nexo_random_item(array $items): mixed
{
    if ($items === []) {
        throw new RuntimeException('Não há cartas disponíveis para este módulo.');
    }
    $keys = array_keys($items);
    return $items[$keys[random_int(0, count($keys) - 1)]];
}

/** Sorteia uma configuração única no servidor. São apenas enigmas cenográficos. */
function nexo_build_game(int $moduleCount): array
{
    if ($moduleCount < 2 || $moduleCount > 4) {
        throw new InvalidArgumentException('A partida precisa ter de 2 a 4 módulos.');
    }

    $decks = nexo_cards();
    $codes = array_keys($decks);
    shuffle($codes);
    $codes = array_slice($codes, 0, $moduleCount);
    $names = nexo_module_names();
    $modules = [];

    foreach ($codes as $code) {
        $deck = $decks[$code]['cards'] ?? [];
        if ($deck === []) {
            throw new RuntimeException('Baralho vazio para o módulo ' . $code);
        }
        $cardIds = array_keys($deck);
        $cardId = $cardIds[random_int(0, count($cardIds) - 1)];
        $modules[] = [
            'code' => $code,
            'name' => $names[$code] ?? ($decks[$code]['name'] ?? 'Módulo'),
            'card_id' => $cardId,
            'card' => $deck[$cardId],
            'done' => false,
        ];
    }

    return ['modules' => $modules];
}

/** Dados que podem ser percebidos no painel tátil. Não inclui cores. */
function nexo_tactile_observation(array $module): array
{
    $card = $module['card'] ?? [];
    $code = $module['code'] ?? '';
    $textures = [
        'AZUL' => 'nervuras paralelas',
        'VERMELHO' => 'pontos em relevo',
        'BRANCO' => 'superfície lisa',
        'AMARELO' => 'linhas onduladas',
        'VERDE' => 'quadrículas em relevo',
        'VIOLETA' => 'superfície áspera',
        'LARANJA' => 'pequenos círculos',
    ];

    if ($code === 'F') {
        $items = [];
        foreach (($card['ribbons'] ?? []) as $i => $unusedColor) {
            $texture = $textures[$unusedColor] ?? 'textura granulada';
            $items[] = ['position' => $i + 1, 'texture' => $texture];
        }
        return ['kind' => 'fitas', 'count' => count($items), 'items' => $items, 'seal' => $card['seal'] ?? ''];
    }
    if ($code === 'B') {
        return [
            'kind' => 'sigilos',
            'count' => 4,
            'seal' => $card['seal'] ?? '',
            'items' => ['círculo', 'triângulo', 'quadrado', 'estrela'],
        ];
    }
    if ($code === 'S') {
        $states = preg_split('//u', (string)($card['base'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return [
            'kind' => 'alavancas',
            'count' => count($states),
            'seal' => $card['seal'] ?? '',
            'items' => array_map(static fn($state, $i) => [
                'position' => $i + 1,
                'state' => $state === '↑' ? 'elevada' : 'abaixada',
            ], $states, array_keys($states)),
        ];
    }
    if ($code === 'D') {
        $symbols = nexo_manual()['dialSymbols'] ?? [];
        return [
            'kind' => 'astrolabio',
            'count' => 8,
            'start' => (int)($card['start'] ?? 1),
            'sequence' => $card['sequence'] ?? [],
            'symbols' => $symbols,
        ];
    }
    return ['kind' => 'desconhecido', 'count' => 0, 'items' => []];
}

function nexo_role_label(string $role): string
{
    return [
        'surdo' => 'Surdo · Observador',
        'cego' => 'Cego · Explorador',
        'mudo' => 'Mudo · Escriba',
    ][$role] ?? 'Aventureiro';
}

function nexo_role_icon(string $role): string
{
    return ['surdo' => '◈', 'cego' => '✦', 'mudo' => '✎'][$role] ?? '◇';
}
