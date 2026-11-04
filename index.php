<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

require __DIR__ . '/app/db.php';
require __DIR__ . '/app/session_handler.php';
require __DIR__ . '/app/helpers.php';
require __DIR__ . '/app/game_logic.php';

session_name('NEXO_TRIO');
$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '604800');

// Em produção, as sessões ficam no PostgreSQL e sobrevivem a reinícios do Render.
// Se o banco ainda não estiver configurado, mantém o handler padrão para exibir a tela de configuração.
try {
    $sessionPdo = nexo_db();
    $sessionTable = $sessionPdo->query("SELECT to_regclass('public.nexo_sessions')")->fetchColumn();
    if ($sessionTable) {
        session_set_save_handler(new NexoDbSessionHandler($sessionPdo), true);
    }
} catch (Throwable $sessionSetupError) {
    // Antes de instalar o schema ou configurar o banco, usa sessões locais temporárias.
}
session_start();

function nexo_post_string(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function nexo_process_action(PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    nexo_check_csrf();
    $action = nexo_post_string('action');

    if ($action === 'create_room') {
        $name = nexo_limit_name(nexo_post_string('player_name'), 24);
        $role = nexo_post_string('role');
        $count = (int)($_POST['module_count'] ?? 3);
        if ($name === '' || !in_array($role, ['surdo', 'cego', 'mudo'], true) || $count < 2 || $count > 4) {
            throw new RuntimeException('Preencha o nome, escolha um papel e selecione de 2 a 4 módulos.');
        }

        $pdo->beginTransaction();
        try {
            $code = '';
            for ($attempt = 0; $attempt < 12; $attempt++) {
                $code = nexo_room_code();
                $check = $pdo->prepare('SELECT id FROM rooms WHERE room_code = ?');
                $check->execute([$code]);
                if (!$check->fetch()) {
                    break;
                }
                $code = '';
            }
            if ($code === '') {
                throw new RuntimeException('Não foi possível gerar um código de sala. Tente novamente.');
            }
            $stmt = $pdo->prepare("INSERT INTO rooms (room_code, module_count, duration_seconds, status) VALUES (?, ?, 480, 'lobby') RETURNING id");
            $stmt->execute([$code, $count]);
            $roomId = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare('INSERT INTO players (room_id, player_name, role, is_host, ready) VALUES (?, ?, ?, 1, 0) RETURNING id');
            $stmt->execute([$roomId, $name, $role]);
            $playerId = (int)$stmt->fetchColumn();
            $pdo->prepare('UPDATE rooms SET host_player_id = ? WHERE id = ?')->execute([$playerId, $roomId]);
            $pdo->commit();

            $_SESSION['player_id'] = $playerId;
            nexo_flash('success', 'Sala criada. Compartilhe o código com os outros dois participantes.');
            nexo_redirect('index.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    if ($action === 'join_room') {
        $name = nexo_limit_name(nexo_post_string('player_name'), 24);
        $role = nexo_post_string('role');
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', nexo_post_string('room_code')) ?? '');
        if ($name === '' || strlen($code) !== 6 || !in_array($role, ['surdo', 'cego', 'mudo'], true)) {
            throw new RuntimeException('Confira o nome, o código de seis caracteres e o papel escolhido.');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM rooms WHERE room_code = ? FOR UPDATE');
            $stmt->execute([$code]);
            $room = $stmt->fetch();
            if (!$room) throw new RuntimeException('Sala não encontrada. Confira o código.');
            if ($room['status'] !== 'lobby') throw new RuntimeException('Esta partida já começou ou foi encerrada.');

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM players WHERE room_id = ?');
            $stmt->execute([(int)$room['id']]);
            if ((int)$stmt->fetchColumn() >= 3) throw new RuntimeException('A sala já está completa.');

            $stmt = $pdo->prepare('SELECT id FROM players WHERE room_id = ? AND role = ?');
            $stmt->execute([(int)$room['id'], $role]);
            if ($stmt->fetch()) throw new RuntimeException('Esse papel já foi ocupado. Escolha um dos outros papéis.');

            $stmt = $pdo->prepare('INSERT INTO players (room_id, player_name, role, is_host, ready) VALUES (?, ?, ?, 0, 0) RETURNING id');
            $stmt->execute([(int)$room['id'], $name, $role]);
            $playerId = (int)$stmt->fetchColumn();
            $pdo->commit();

            $_SESSION['player_id'] = $playerId;
            nexo_flash('success', 'Você entrou na sala. Confirme sua presença quando estiver pronto.');
            nexo_redirect('index.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    if ($action === 'ready') {
        [$player, $room] = nexo_current($pdo);
        if (!$player || !$room || $room['status'] !== 'lobby') throw new RuntimeException('Não há uma sala aguardando confirmação.');
        $pdo->prepare('UPDATE players SET ready = 1 WHERE id = ? AND room_id = ?')->execute([$player['id'], $room['id']]);
        nexo_flash('success', 'Presença confirmada. Atualize a sala para ver os outros participantes.');
        nexo_redirect('index.php');
    }

    if ($action === 'start_game') {
        [$player, $room] = nexo_current($pdo);
        if (!$player || !$room || !$player['is_host']) throw new RuntimeException('Somente quem criou a sala pode iniciar a partida.');
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ? FOR UPDATE');
            $stmt->execute([$room['id']]);
            $lockedRoom = $stmt->fetch();
            if (!$lockedRoom || $lockedRoom['status'] !== 'lobby') throw new RuntimeException('A sala não está mais no lobby.');
            $stmt = $pdo->prepare('SELECT * FROM players WHERE room_id = ? FOR UPDATE');
            $stmt->execute([$room['id']]);
            $players = $stmt->fetchAll();
            $roles = array_column($players, 'role');
            $expected = ['surdo', 'cego', 'mudo'];
            sort($roles);
            sort($expected);
            if (count($players) !== 3 || $roles !== $expected) throw new RuntimeException('A sala precisa ter exatamente um participante em cada papel.');
            foreach ($players as $participant) {
                if ((int)$participant['ready'] !== 1) throw new RuntimeException('Todos os participantes precisam confirmar presença.');
            }
            $game = nexo_build_game((int)$lockedRoom['module_count']);
            $startedAt = time();
            $stmt = $pdo->prepare("UPDATE rooms SET status = 'active', started_at = ?, game_json = ?, errors = 0 WHERE id = ?");
            $stmt->execute([$startedAt, json_encode($game, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $room['id']]);
            $pdo->commit();
            nexo_flash('success', 'A partida começou. O horário inicial foi gravado para os três participantes.');
            nexo_redirect('index.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    if ($action === 'mark_done') {
        [$player, $room] = nexo_current($pdo);
        if (!$player || !$room || $player['role'] !== 'cego') throw new RuntimeException('Somente o Explorador registra a conclusão física do módulo.');
        if ($room['status'] !== 'active') throw new RuntimeException('A partida não está ativa.');
        $moduleCode = nexo_post_string('module_code');
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT game_json, status, started_at, duration_seconds FROM rooms WHERE id = ? FOR UPDATE');
            $stmt->execute([$room['id']]);
            $locked = $stmt->fetch();
            if (!$locked || $locked['status'] !== 'active') throw new RuntimeException('A partida não está ativa.');
            if ((int)$locked['started_at'] + (int)$locked['duration_seconds'] <= time()) {
                $pdo->prepare("UPDATE rooms SET status = 'failed' WHERE id = ?")->execute([$room['id']]);
                $pdo->commit();
                nexo_flash('error', 'O tempo terminou. A rodada foi encerrada.');
                nexo_redirect('index.php');
            }
            $state = json_decode((string)$locked['game_json'], true, 512, JSON_THROW_ON_ERROR);
            $found = false;
            foreach ($state['modules'] as &$module) {
                if (($module['code'] ?? '') === $moduleCode) {
                    $module['done'] = true;
                    $found = true;
                    break;
                }
            }
            unset($module);
            if (!$found) throw new RuntimeException('Módulo não encontrado nesta partida.');
            $allDone = true;
            foreach ($state['modules'] as $module) $allDone = $allDone && !empty($module['done']);
            $newStatus = $allDone ? 'won' : 'active';
            $stmt = $pdo->prepare('UPDATE rooms SET game_json = ?, status = ? WHERE id = ?');
            $stmt->execute([json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $newStatus, $room['id']]);
            $pdo->commit();
            nexo_flash($allDone ? 'success' : 'success', $allDone ? 'Todos os módulos foram concluídos. A equipe venceu!' : 'Módulo registrado como concluído.');
            nexo_redirect('index.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    if ($action === 'report_error') {
        [$player, $room] = nexo_current($pdo);
        if (!$player || !$room || $player['role'] !== 'cego') throw new RuntimeException('Somente o Explorador registra erros da tentativa física.');
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT errors, status, started_at, duration_seconds FROM rooms WHERE id = ? FOR UPDATE');
            $stmt->execute([$room['id']]);
            $locked = $stmt->fetch();
            if (!$locked || $locked['status'] !== 'active') throw new RuntimeException('A partida não está ativa.');
            $errors = (int)$locked['errors'] + 1;
            $status = ($errors >= 3 || (int)$locked['started_at'] + (int)$locked['duration_seconds'] <= time()) ? 'failed' : 'active';
            $pdo->prepare('UPDATE rooms SET errors = ?, status = ? WHERE id = ?')->execute([$errors, $status, $room['id']]);
            $pdo->commit();
            nexo_flash($status === 'failed' ? 'error' : 'warning', $status === 'failed' ? 'A rodada terminou ao atingir o limite de erros.' : 'Erro registrado. Restam ' . (3 - $errors) . ' tentativas.');
            nexo_redirect('index.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    if ($action === 'leave_room') {
        $playerId = (int)($_SESSION['player_id'] ?? 0);
        $notice = 'Você saiu da sala.';
        if ($playerId > 0) {
            $stmt = $pdo->prepare('SELECT room_id, is_host, role FROM players WHERE id = ? LIMIT 1');
            $stmt->execute([$playerId]);
            $leaving = $stmt->fetch();
            if ($leaving) {
                $roomId = (int)$leaving['room_id'];
                $roomStmt = $pdo->prepare('SELECT status FROM rooms WHERE id = ? LIMIT 1');
                $roomStmt->execute([$roomId]);
                $roomStatus = (string)$roomStmt->fetchColumn();
                if ((int)$leaving['is_host'] === 1 && $roomStatus === 'lobby') {
                    $pdo->prepare('DELETE FROM rooms WHERE id = ?')->execute([$roomId]);
                    $notice = 'Você saiu e a sala de espera foi encerrada.';
                } else {
                    $pdo->prepare('DELETE FROM players WHERE id = ?')->execute([$playerId]);
                    if ((int)$leaving['is_host'] === 1) {
                        $next = $pdo->prepare('SELECT id FROM players WHERE room_id = ? ORDER BY joined_at ASC LIMIT 1');
                        $next->execute([$roomId]);
                        $nextId = (int)$next->fetchColumn();
                        if ($nextId > 0) {
                            $pdo->prepare('UPDATE players SET is_host = 1 WHERE id = ?')->execute([$nextId]);
                            $pdo->prepare('UPDATE rooms SET host_player_id = ? WHERE id = ?')->execute([$nextId, $roomId]);
                        }
                    }
                }
            }
        }
        unset($_SESSION['player_id']);
        nexo_flash('success', $notice);
        nexo_redirect('index.php');
    }
}

function nexo_render_header(string $title, ?string $code = null, ?string $role = null): void
{
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#171012">
        <title><?= h($title) ?> | NEXO — A Cripta das Relíquias</title>
        <link rel="stylesheet" href="assets/css/nexo.css?v=render-1.0">
        <script defer src="assets/js/nexo.js?v=render-1.0"></script>
    </head>
    <body>
    <div class="page-grain" aria-hidden="true"></div>
    <header class="site-header">
        <a class="brand" href="index.php" aria-label="NEXO, início">
            <span class="brand-mark">✧</span>
            <span><strong>NEXO</strong><small>A CRIPTA DAS RELÍQUIAS</small></span>
        </a>
        <div class="header-right">
            <?php if ($code): ?><span class="room-chip"><span class="chip-dot"></span>SALA <strong><?= h($code) ?></strong></span><?php endif; ?>
            <?php if ($role): ?><span class="role-chip"><?= h(nexo_role_icon($role)) ?> <?= h(nexo_role_label($role)) ?></span><?php endif; ?>
            <a class="text-link" href="index.php">Início</a>
        </div>
    </header>
    <main class="site-main">
    <?php
}

function nexo_render_footer(): void
{
    ?>
    </main>
    <footer class="site-footer">
        <span>NEXO · Jogo cooperativo de enigmas cenográficos</span>
        <span>Feito para três perspectivas. Uma só jornada.</span>
    </footer>
    </body>
    </html>
    <?php
}

function nexo_render_flash(?array $flash): void
{
    if (!$flash) return;
    $type = in_array($flash['type'] ?? '', ['success', 'warning', 'error'], true) ? $flash['type'] : 'success';
    ?>
    <div class="flash flash-<?= h($type) ?>" role="status"><?= h($flash['message'] ?? '') ?></div>
    <?php
}

function nexo_render_landing(?array $flash): void
{
    nexo_render_header('A Cripta das Relíquias');
    nexo_render_flash($flash);
    ?>
    <section class="hero-grid">
        <div class="hero-copy">
            <div class="eyebrow"><span class="eyebrow-line"></span> EXPERIÊNCIA COOPERATIVA · 3 JOGADORES</div>
            <h1>Três destinos.<br><em>Uma única relíquia.</em></h1>
            <p class="hero-lead">Um observa os sinais. Outro consulta os registros. O último explora o artefato pelo tato. Só a equipe completa consegue abrir o selo.</p>
            <div class="hero-stats">
                <div><strong>02–04</strong><span>módulos por partida</span></div>
                <div><strong>08:00</strong><span>tempo de jogo</span></div>
                <div><strong>03</strong><span>papéis exclusivos</span></div>
            </div>
            <p class="safe-note"><span>✦</span> Jogo de enigmas cenográficos: fitas, peças, relevos e papel. Nenhum circuito ou mecanismo real.</p>
        </div>
        <div class="hero-emblem-wrap" aria-hidden="true">
            <div class="orbit orbit-a"></div><div class="orbit orbit-b"></div>
            <div class="hero-emblem"><span class="emblem-star">✧</span><span class="emblem-gem">◆</span><span class="emblem-caption">NEXO</span></div>
            <span class="floating-rune rune-one">ᚷ</span><span class="floating-rune rune-two">✦</span><span class="floating-rune rune-three">⌘</span>
        </div>
    </section>

    <section class="role-strip" aria-label="Papéis da equipe">
        <article class="role-mini role-observer"><span class="role-glyph">◈</span><div><small>01 · VISÃO</small><strong>Observador</strong><p>Enxerga os sinais e descreve o artefato.</p></div></article>
        <article class="role-mini role-scribe"><span class="role-glyph">✎</span><div><small>02 · MANUAL</small><strong>Escriba</strong><p>Consulta o grimório e interpreta as regras.</p></div></article>
        <article class="role-mini role-explorer"><span class="role-glyph">✦</span><div><small>03 · TATO</small><strong>Explorador</strong><p>Reconhece formas, texturas e posições.</p></div></article>
    </section>

    <section class="lobby-forms" id="salas">
        <div class="section-heading"><div><span class="eyebrow">REÚNA SUA EQUIPE</span><h2>Entre na cripta</h2></div><span class="heading-ornament">✧ ━━━ ✧</span></div>
        <div class="form-grid">
            <form class="parchment-card form-card" method="post" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= h(nexo_csrf_token()) ?>">
                <input type="hidden" name="action" value="create_room">
                <span class="card-kicker">ANFITRIÃO</span><h3>Erguer uma sala</h3>
                <label>Seu nome<input name="player_name" maxlength="24" required placeholder="Nome ou apelido"></label>
                <label>Seu papel<select name="role" required><option value="">Escolha sua função</option><option value="surdo">Surdo · Observador · painel visual</option><option value="mudo">Mudo · Escriba · manual</option><option value="cego">Cego · Explorador · painel tátil</option></select></label>
                <label>Quantidade de módulos<select name="module_count"><option value="2">2 módulos · jornada curta</option><option value="3" selected>3 módulos · jornada média</option><option value="4">4 módulos · jornada completa</option></select></label>
                <button class="btn btn-primary" type="submit">Criar sala <span>↗</span></button>
                <p class="form-footnote">Você ocupará um dos três papéis da equipe.</p>
            </form>
            <form class="parchment-card form-card join-card" method="post" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= h(nexo_csrf_token()) ?>">
                <input type="hidden" name="action" value="join_room">
                <span class="card-kicker">CONVIDADO</span><h3>Entrar numa sala</h3>
                <label>Seu nome<input name="player_name" maxlength="24" required placeholder="Nome ou apelido"></label>
                <label>Código da sala<input class="room-code-input" name="room_code" minlength="6" maxlength="6" required placeholder="ABC234" autocomplete="off"></label>
                <label>Seu papel<select name="role" required><option value="">Escolha sua função</option><option value="surdo">Surdo · Observador · painel visual</option><option value="mudo">Mudo · Escriba · manual</option><option value="cego">Cego · Explorador · painel tátil</option></select></label>
                <button class="btn btn-secondary" type="submit">Entrar na sala <span>↗</span></button>
                <p class="form-footnote">Cada papel só pode ser ocupado por uma pessoa.</p>
            </form>
        </div>
    </section>
    <section class="how-section">
        <div class="section-heading"><div><span class="eyebrow">COMO FUNCIONA</span><h2>Uma equipe, três perspectivas</h2></div></div>
        <div class="how-grid">
            <div><b>Ⅰ</b><strong>Montem a equipe</strong><p>Uma pessoa em cada papel. Todos confirmam presença antes do início.</p></div>
            <div><b>Ⅱ</b><strong>O servidor sorteia</strong><p>A configuração é sorteada uma única vez e guardada no PostgreSQL para a sala inteira.</p></div>
            <div><b>Ⅲ</b><strong>Resolvam juntos</strong><p>O horário inicial é compartilhado. O Explorador registra a conclusão dos módulos cenográficos.</p></div>
        </div>
    </section>
    <?php
    nexo_render_footer();
}

function nexo_render_lobby(PDO $pdo, array $player, array $room, ?array $flash): void
{
    $players = nexo_get_players($pdo, $room['id']);
    $byRole = [];
    foreach ($players as $p) $byRole[$p['role']] = $p;
    $roles = ['surdo' => ['Surdo · Observador', '◈', 'Painel visual'], 'mudo' => ['Mudo · Escriba', '✎', 'Manual de regras'], 'cego' => ['Cego · Explorador', '✦', 'Painel tátil']];
    $readyCount = count(array_filter($players, static fn($p) => (int)$p['ready'] === 1));
    $canStart = count($players) === 3 && $readyCount === 3;
    nexo_render_header('Sala ' . $room['code'], $room['code'], $player['role']);
    nexo_render_flash($flash);
    ?>
    <span id="nexo-sync" data-sync-stamp="<?= h(nexo_room_sync_stamp($pdo, (int)$room['id'])) ?>" hidden></span>
    <section class="room-heading">
        <div><span class="eyebrow">SALA DE CONVOCAÇÃO</span><h1>Reúna os três <em>viajantes.</em></h1><p>A partida só começa quando cada papel estiver ocupado e confirmado.</p></div>
        <div class="room-code-panel"><small>CÓDIGO PARA COMPARTILHAR</small><strong><?= h($room['code']) ?></strong><span>Toque em atualizar para rever o lobby.</span></div>
    </section>
    <div class="lobby-summary"><span>✧ <?= (int)$room['module_count'] ?> módulos sorteados antes da partida</span><span>◷ 08 minutos</span><span>♜ limite de 3 erros</span></div>
    <section class="participant-grid">
        <?php foreach ($roles as $role => $meta): $p = $byRole[$role] ?? null; ?>
            <article class="participant-card <?= $p ? 'occupied' : 'vacant' ?>">
                <div class="participant-top"><span class="role-glyph"><?= h($meta[1]) ?></span><span class="slot-state"><?= $p ? ((int)$p['ready'] ? 'PRONTO' : 'PRESENTE') : 'AGUARDANDO' ?></span></div>
                <small><?= h(strtoupper($meta[0])) ?></small><h3><?= $p ? h($p['player_name']) : 'Lugar disponível' ?></h3><p><?= h($meta[2]) ?></p>
                <?php if ($p && (int)$p['is_host'] === 1): ?><span class="host-label">ANFITRIÃO</span><?php endif; ?>
                <?php if ($p && (int)$p['ready'] === 1): ?><span class="ready-mark">✓ confirmado</span><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
    <section class="lobby-actions">
        <div class="lobby-status"><strong><?= $readyCount ?>/3</strong><span>participantes confirmados</span><div class="progress-track"><i style="width:<?= (int)round($readyCount / 3 * 100) ?>%"></i></div></div>
        <div class="action-stack">
            <?php if (!$player['ready']): ?>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= h(nexo_csrf_token()) ?>"><input type="hidden" name="action" value="ready"><button class="btn btn-primary" type="submit">Estou pronto <span>✓</span></button></form>
            <?php else: ?><span class="ready-confirmation">✓ Sua presença está confirmada</span><?php endif; ?>
            <?php if ($player['is_host']): ?>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= h(nexo_csrf_token()) ?>"><input type="hidden" name="action" value="start_game"><button class="btn btn-gold" type="submit" <?= $canStart ? '' : 'disabled' ?>>Iniciar jornada <span>✧</span></button></form>
            <?php endif; ?>
            <a class="btn btn-quiet" href="index.php">↻ Atualizar sala</a>
        </div>
    </section>
    <p class="sync-note">O servidor guarda o sorteio e o horário único de início. As três telas acompanham o lobby automaticamente enquanto esta página estiver aberta.</p>
    <form class="leave-form" method="post" onsubmit="return confirm('Sair desta sala?');"><input type="hidden" name="csrf_token" value="<?= h(nexo_csrf_token()) ?>"><input type="hidden" name="action" value="leave_room"><button class="text-link" type="submit">Sair da sala</button></form>
    <?php
    nexo_render_footer();
}

function nexo_color_hex(string $color): string
{
    return [
        'AZUL' => '#426879', 'VERMELHO' => '#8f3435', 'BRANCO' => '#e8dfc7',
        'AMARELO' => '#d0a348', 'VERDE' => '#526b46', 'VIOLETA' => '#765278', 'LARANJA' => '#b96331',
    ][$color] ?? '#987d54';
}

function nexo_symbol_icon(string $symbol): string
{
    return [
        'SOL' => '☼', 'LUA' => '☾', 'TRIÂNGULO' => '△', 'QUADRADO' => '▣',
        'ESTRELA' => '✦', 'ONDA' => '≋', 'FOLHA' => '❧', 'RAIO' => 'ϟ',
        'CÍRCULO' => '○',
    ][$symbol] ?? '◇';
}

function nexo_render_surdo(array $state): void
{
    ?>
    <section class="role-intro intro-observer"><div><span class="eyebrow">01 · SURDO / OBSERVADOR</span><h1>O painel das <em>aparências.</em></h1><p>Leia os sinais visuais e transmita as informações ao Escriba. Não há respostas nesta tela.</p></div><div class="intro-seal">◈</div></section>
    <div class="communication-tip"><strong>Seu papel:</strong> descreva cores, símbolos, números e posições. O Escriba consulta o grimório e devolve a orientação para a equipe.</div>
    <section class="artifact-board">
        <div class="artifact-topline"><span>ARTEFATO CENOGRÁFICO · DOSSIÊ VISUAL</span><span><?= count($state['modules'] ?? []) ?> SELOS ATIVOS</span></div>
        <div class="module-grid">
        <?php foreach (($state['modules'] ?? []) as $module): $card = $module['card'] ?? []; ?>
            <article class="module-card visual-module <?= !empty($module['done']) ? 'module-done' : '' ?>">
                <div class="module-card-head"><span class="module-index">SELO <?= h($module['code']) ?></span><span class="module-code"><?= h($module['card_id']) ?></span></div>
                <h3><?= h($module['name']) ?></h3>
                <?php if ($module['code'] === 'F'): ?>
                    <div class="clue-seal"><small>MARCA</small><strong><?= h($card['seal'] ?? '') ?></strong></div>
                    <div class="ribbon-row">
                        <?php foreach (($card['ribbons'] ?? []) as $i => $color): ?>
                            <div class="ribbon-item"><span class="ribbon-swatch" style="--ribbon:<?= h(nexo_color_hex($color)) ?>"></span><small><?= $i + 1 ?></small><b><?= h($color) ?></b></div>
                        <?php endforeach; ?>
                    </div>
                    <p class="module-caption">Fitas cenográficas em sequência. Relate a marca, as cores e a posição de cada fita.</p>
                <?php elseif ($module['code'] === 'B'): ?>
                    <div class="clue-seal"><small>MARCA EM RELEVO</small><strong><?= h($card['seal'] ?? '') ?></strong></div>
                    <div class="sigil-row"><span>○</span><span>△</span><span>▣</span><span>✦</span></div>
                    <div class="serial-plaque"><small>REGISTRO</small><strong><?= h($card['serial'] ?? '') ?></strong></div>
                    <p class="module-caption">Quatro peças soltas com símbolos. Relate a marca e o registro.</p>
                <?php elseif ($module['code'] === 'S'): ?>
                    <div class="clue-seal"><small>MARCA</small><strong><?= h($card['seal'] ?? '') ?></strong></div>
                    <div class="switch-row">
                        <?php foreach (preg_split('//u', (string)($card['base'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $i => $stateChar): ?>
                            <div class="switch-item"><span class="paper-toggle <?= $stateChar === '↑' ? 'up' : 'down' ?>"><i></i></span><small><?= $i + 1 ?></small><b><?= $stateChar === '↑' ? 'ALTA' : 'BAIXA' ?></b></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="serial-plaque"><small>REGISTRO</small><strong><?= h($card['serial'] ?? '') ?></strong></div>
                    <p class="module-caption">Linguetas de papel. Informe quantas existem e quais estão elevadas ou abaixadas.</p>
                <?php elseif ($module['code'] === 'D'): ?>
                    <div class="dial-preview"><div class="dial-center">✧</div>
                        <?php foreach ((nexo_manual()['dialSymbols'] ?? []) as $i => $symbol): ?>
                            <span class="dial-symbol" style="--angle:<?= (int)($i * 45) ?>deg"><?= h(nexo_symbol_icon($symbol)) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="dial-data"><span>INÍCIO <b><?= (int)($card['start'] ?? 1) ?></b></span><span>REGISTRO <b><?= h($card['serial'] ?? '') ?></b></span></div>
                    <div class="sequence-row"><?php foreach (($card['sequence'] ?? []) as $symbol): ?><span><?= h(nexo_symbol_icon($symbol)) ?> <?= h($symbol) ?></span><?php endforeach; ?></div>
                    <p class="module-caption">Disco de símbolos. Relate a posição inicial e a sequência gravada.</p>
                <?php endif; ?>
                <?php if (!empty($module['done'])): ?><div class="done-ribbon">SELO CONCLUÍDO ✓</div><?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>
    </section>
    <div class="role-bottom-note"><span>✧</span> O painel mostra apenas os dados visuais. A interpretação pertence ao Escriba; a execução é feita com peças cenográficas pelo Explorador.</div>
    <?php
}

function nexo_render_mudo(array $state): void
{
    $manual = nexo_manual();
    $selected = array_column($state['modules'] ?? [], 'code');
    $labels = nexo_module_names();
    ?>
    <section class="role-intro intro-scribe"><div><span class="eyebrow">02 · MUDO / ESCRIBA</span><h1>O grimório das <em>quatro provas.</em></h1><p>Este é seu manual. Receba os dados do Observador, consulte a regra correspondente e mostre a orientação à equipe.</p></div><div class="intro-seal">✎</div></section>
    <div class="communication-tip"><strong>Seu papel:</strong> você tem as regras, não os sinais do dossiê. Peça ao Observador para apontar o selo e ler os dados. Use gestos, escrita ou mostre esta tela para transmitir a interpretação.</div>
    <section class="manual-shelf">
        <div class="shelf-heading"><span class="eyebrow">PÁGINAS DESTA PARTIDA</span><h2>Capítulos selecionados</h2></div>
        <div class="chapter-pills"><?php foreach ($selected as $code): ?><span><?= h($code) ?> · <?= h($labels[$code] ?? '') ?></span><?php endforeach; ?></div>
        <?php foreach ($selected as $code): ?>
            <article class="manual-chapter" id="manual-<?= h($code) ?>">
                <div class="chapter-number">CAPÍTULO <?= h($code) ?></div>
                <?php if ($code === 'F'): ?>
                    <h3>As fitas de seda</h3><p class="chapter-lead">A marca define uma ordem de preferência. Percorra a ordem da marca e localize a primeira cor dessa lista que aparece entre as fitas.</p>
                    <div class="rule-grid">
                        <?php foreach (($manual['ribbonPriority'] ?? []) as $seal => $colors): ?><div class="rule-card"><b><?= h($seal) ?></b><ol><?php foreach ($colors as $color): ?><li><?= h($color) ?></li><?php endforeach; ?></ol></div><?php endforeach; ?>
                    </div>
                    <div class="rule-callout"><strong>Desempate:</strong> se a última casa do registro for par, considere a primeira ocorrência da cor escolhida. Se for ímpar, considere a última ocorrência.</div>
                <?php elseif ($code === 'B'): ?>
                    <h3>Os quatro sigilos</h3><p class="chapter-lead">A marca e a paridade da última casa do registro indicam uma sequência de quatro símbolos. O Explorador deve reorganizar as peças soltas, sem conectá-las a qualquer mecanismo.</p>
                    <div class="table-wrap"><table class="manual-table"><thead><tr><th>Marca</th><th>Final par</th><th>Final ímpar</th></tr></thead><tbody>
                    <?php foreach (['CÍRCULO','TRIÂNGULO','QUADRADO','ESTRELA'] as $seal): ?>
                        <tr><th><?= h($seal) ?></th><td><?= h(implode(' → ', $manual['buttonSequences'][$seal . '|0'] ?? [])) ?></td><td><?= h(implode(' → ', $manual['buttonSequences'][$seal . '|1'] ?? [])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php elseif ($code === 'S'): ?>
                    <h3>As alavancas de papel</h3><p class="chapter-lead">Converta o estado inicial em uma linha de 1 e 0. Se a primeira casa do registro for ímpar, inverta a ordem. Depois, combine com a máscara correspondente à marca e à paridade final.</p>
                    <div class="table-wrap"><table class="manual-table"><thead><tr><th>Marca</th><th>Final par</th><th>Final ímpar</th></tr></thead><tbody>
                    <?php foreach (['CÍRCULO','TRIÂNGULO','QUADRADO','ESTRELA'] as $seal): ?>
                        <tr><th><?= h($seal) ?></th><td><?= h($manual['switchMasks'][$seal . '|0'] ?? '') ?></td><td><?= h($manual['switchMasks'][$seal . '|1'] ?? '') ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <div class="rule-callout"><strong>Leitura:</strong> 1 significa lingueta elevada; 0 significa lingueta abaixada. Para três linguetas, use a tabela compacta: <?php foreach (($manual['switchMasks3'] ?? []) as $key => $mask): ?><span class="inline-mask"><?= h(str_replace('|', ' · ', $key)) ?>: <?= h($mask) ?></span> <?php endforeach; ?></div>
                <?php elseif ($code === 'D'): ?>
                    <h3>O astrolábio</h3><p class="chapter-lead">O disco contém oito símbolos em posições fixas. Para cada símbolo da sequência, escolha o caminho mais curto a partir da posição atual. Em um empate de quatro casas, o final par indica sentido horário e o ímpar, anti-horário.</p>
                    <div class="dial-manual-grid"><?php foreach (($manual['dialSymbols'] ?? []) as $i => $symbol): ?><div><b><?= $i + 1 ?></b><span><?= h(nexo_symbol_icon($symbol)) ?></span><small><?= h($symbol) ?></small></div><?php endforeach; ?></div>
                    <div class="rule-callout"><strong>Importante:</strong> após alcançar um símbolo, ele passa a ser a posição atual para o próximo trecho da sequência.</div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
    <section class="gesture-board"><div class="eyebrow">FRASES PARA MOSTRAR À EQUIPE</div><h3>Quadro de comunicação</h3><div class="gesture-grid"><span>APONTE O SELO</span><span>LEIA O REGISTRO</span><span>REPITA A SEQUÊNCIA</span><span>CONFIRME A POSIÇÃO</span><span>AGUARDE UM INSTANTE</span><span>ORIENTAÇÃO CONCLUÍDA</span></div></section>
    <?php
}

function nexo_render_cego(array $state): void
{
    ?>
    <section class="role-intro intro-explorer"><div><span class="eyebrow">03 · CEGO / EXPLORADOR</span><h1>A superfície <em>escondida.</em></h1><p>Explore o painel sem depender de cores. Tudo que aparece aqui corresponde a forma, textura, quantidade ou posição que poderia ser reconhecida pelo tato.</p></div><div class="intro-seal">✦</div></section>
    <div class="blind-controls"><span><i class="lantern-dot"></i> LANTERNA ATIVA · MOVA O CURSOR</span><button class="btn btn-quiet btn-small" type="button" data-accessible-toggle>Modo leitura acessível</button></div>
    <section class="blind-stage" data-lantern-stage tabindex="0" aria-label="Painel tátil. Mova o cursor para revelar detalhes; use Tab para percorrer os módulos ou ative o modo de leitura.">
        <div class="blind-content" data-lantern-content>
            <div class="blind-stage-title"><span>MAPA TÁTIL</span><b>✧</b><span><?= count($state['modules'] ?? []) ?> ÁREAS</span></div>
            <div class="tactile-grid">
            <?php foreach (($state['modules'] ?? []) as $module): $touch = nexo_tactile_observation($module); ?>
                <article class="tactile-card" tabindex="0" data-light-target>
                    <div class="tactile-card-head"><span class="raised-index"><?= h($module['code']) ?></span><span><?= h($module['name']) ?></span></div>
                    <?php if ($module['code'] === 'F'): ?>
                        <div class="touch-summary"><strong><?= (int)$touch['count'] ?></strong><span>fitas em sequência</span></div>
                        <div class="texture-list"><?php foreach ($touch['items'] as $item): ?><div class="texture-item"><b><?= (int)$item['position'] ?></b><span class="texture-sample texture-<?= (int)$item['position'] ?>" aria-hidden="true"></span><span><?= h($item['texture']) ?></span></div><?php endforeach; ?></div>
                        <p class="touch-note">Marca elevada: <b><?= h($touch['seal']) ?></b></p>
                    <?php elseif ($module['code'] === 'B'): ?>
                        <div class="touch-summary"><strong><?= (int)$touch['count'] ?></strong><span>peças soltas com contornos diferentes</span></div>
                        <div class="touch-shapes"><?php foreach ($touch['items'] as $shape): ?><span class="touch-shape" aria-label="<?= h($shape) ?>"><?= $shape === 'círculo' ? '○' : ($shape === 'triângulo' ? '△' : ($shape === 'quadrado' ? '□' : '✦')) ?></span><?php endforeach; ?></div>
                        <p class="touch-note">Marca elevada no cartão: <b><?= h($touch['seal']) ?></b></p>
                    <?php elseif ($module['code'] === 'S'): ?>
                        <div class="touch-summary"><strong><?= (int)$touch['count'] ?></strong><span>linguetas de papel</span></div>
                        <div class="touch-switches"><?php foreach ($touch['items'] as $item): ?><div><b><?= (int)$item['position'] ?></b><span class="touch-switch <?= $item['state'] === 'elevada' ? 'touch-up' : 'touch-down' ?>"></span><small><?= h(strtoupper($item['state'])) ?></small></div><?php endforeach; ?></div>
                        <p class="touch-note">Marca elevada: <b><?= h($touch['seal']) ?></b></p>
                    <?php elseif ($module['code'] === 'D'): ?>
                        <div class="touch-summary"><strong>8</strong><span>símbolos em círculo</span></div>
                        <div class="touch-dial"><div class="touch-dial-center">✧</div><?php foreach ($touch['symbols'] as $i => $symbol): ?><span style="--slot:<?= (int)$i ?>"><?= h(nexo_symbol_icon($symbol)) ?></span><?php endforeach; ?></div>
                        <div class="touch-note"><span>Posição inicial tátil: <b><?= (int)$touch['start'] ?></b></span><span>Sequência: <b><?= h(implode(' · ', $touch['sequence'])) ?></b></span></div>
                    <?php endif; ?>
                    <?php if (!empty($module['done'])): ?><div class="blind-done">ÁREA CONCLUÍDA ✓</div><?php endif; ?>
                </article>
            <?php endforeach; ?>
            </div>
            <div class="blind-footer-line">Apenas texturas, formas, relevos, quantidades e posições. Nenhuma cor é exibida.</div>
        </div>
        <div class="blind-stage-hint" aria-hidden="true">MOVA O CURSOR PARA REVELAR</div>
    </section>
    <p class="accessible-help">Para leitores de tela: o conteúdo está disponível na ordem de leitura. Use Tab para percorrer os módulos ou ative “Modo leitura acessível” para remover a máscara visual.</p>
    <section class="explorer-actions"><div><span class="eyebrow">REGISTRO DO EXPLORADOR</span><h3>Peça por peça, selo por selo.</h3><p>Depois de executar a etapa correspondente na prancha cenográfica, registre o módulo abaixo.</p></div><div class="explorer-buttons">
        <?php foreach (($state['modules'] ?? []) as $module): if (!empty($module['done'])) continue; ?>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= h(nexo_csrf_token()) ?>"><input type="hidden" name="action" value="mark_done"><input type="hidden" name="module_code" value="<?= h($module['code']) ?>"><button class="btn btn-primary btn-small" type="submit">Concluir <?= h($module['code']) ?> <span>✓</span></button></form>
        <?php endforeach; ?>
        <form method="post" onsubmit="return confirm('Registrar um erro nesta rodada?');"><input type="hidden" name="csrf_token" value="<?= h(nexo_csrf_token()) ?>"><input type="hidden" name="action" value="report_error"><button class="btn btn-danger btn-small" type="submit">Registrar erro <span>!</span></button></form>
    </div></section>
    <?php
}

function nexo_render_game(PDO $pdo, array $player, array $room, ?array $flash): void
{
    $state = json_decode((string)$room['game_json'], true) ?: ['modules' => []];
    $modules = $state['modules'] ?? [];
    $completed = count(array_filter($modules, static fn($m) => !empty($m['done'])));
    $total = count($modules);
    $serverNow = time();
    $remaining = max(0, (int)$room['started_at'] + $room['duration_seconds'] - $serverNow);
    if ($room['status'] === 'active' && $remaining <= 0) {
        $pdo->prepare("UPDATE rooms SET status = 'failed' WHERE id = ? AND status = 'active'")->execute([$room['id']]);
        $room['status'] = 'failed';
    }
    nexo_render_header('Partida em andamento', $room['code'], $player['role']);
    nexo_render_flash($flash);
    ?>
    <span id="nexo-sync" data-sync-stamp="<?= h(nexo_room_sync_stamp($pdo, (int)$room['id'])) ?>" hidden></span>
    <section class="game-topbar">
        <div class="game-status"><span class="status-live"><i></i> JORNADA ATIVA</span><span>SALA <?= h($room['code']) ?></span></div>
        <div class="timer-box" data-countdown data-started-at="<?= (int)$room['started_at'] ?>" data-duration="<?= (int)$room['duration_seconds'] ?>" data-server-now="<?= $serverNow ?>"><small>TEMPO RESTANTE</small><strong data-time-display><?= sprintf('%02d:%02d', (int)floor($remaining / 60), $remaining % 60) ?></strong></div>
        <div class="progress-box"><small>SELOS CONCLUÍDOS</small><strong><?= $completed ?> / <?= $total ?></strong><div class="progress-track"><i style="width:<?= $total ? (int)round($completed / $total * 100) : 0 ?>%"></i></div></div>
        <div class="errors-box"><small>ERROS</small><strong><?= (int)$room['errors'] ?> / 3</strong></div>
    </section>
    <?php if ($room['status'] === 'won'): ?>
        <section class="result-panel result-win"><div class="result-emblem">✧</div><span class="eyebrow">MISSÃO CONCLUÍDA</span><h1>A relíquia foi <em>libertada.</em></h1><p>Os três papéis trabalharam em conjunto. Todos os módulos foram registrados como concluídos.</p><a href="index.php" class="btn btn-primary">Voltar ao início</a></section>
    <?php elseif ($room['status'] === 'failed'): ?>
        <section class="result-panel result-fail"><div class="result-emblem">◇</div><span class="eyebrow">JORNADA ENCERRADA</span><h1>O selo permaneceu <em>fechado.</em></h1><p>O tempo terminou ou o limite de erros foi alcançado. Atualize a sala ou inicie outra jornada.</p><a href="index.php" class="btn btn-primary">Voltar ao início</a></section>
    <?php else: ?>
        <div class="refresh-strip"><span>As alterações da equipe são sincronizadas automaticamente enquanto a partida está aberta.</span><a class="btn btn-quiet btn-small" href="index.php">↻ Atualizar agora</a></div>
        <?php if ($player['role'] === 'surdo'): nexo_render_surdo($state); endif; ?>
        <?php if ($player['role'] === 'mudo'): nexo_render_mudo($state); endif; ?>
        <?php if ($player['role'] === 'cego'): nexo_render_cego($state); endif; ?>
        <section class="team-board"><div><span class="eyebrow">ESTADO DA EQUIPE</span><h3>Uma jornada compartilhada</h3></div><div class="team-mini-grid">
            <?php foreach (nexo_get_players($pdo, $room['id']) as $p): ?><div><span><?= h(nexo_role_icon($p['role'])) ?></span><b><?= h(nexo_role_label($p['role'])) ?></b><small><?= h($p['player_name']) ?></small></div><?php endforeach; ?>
        </div></section>
    <?php endif; ?>
    <?php
    nexo_render_footer();
}

if (isset($_GET['sync'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    try {
        $syncPdo = nexo_db();
        [$syncPlayer, $syncRoom] = nexo_current($syncPdo);
        if (!$syncPlayer || !$syncRoom) {
            echo json_encode(['stamp' => 'NO_ACTIVE_PLAYER', 'view' => 'landing']);
        } else {
            if ($syncRoom['status'] === 'active'
                && (int)$syncRoom['started_at'] + (int)$syncRoom['duration_seconds'] <= time()) {
                $syncPdo->prepare("UPDATE rooms SET status = 'failed' WHERE id = ? AND status = 'active'")->execute([$syncRoom['id']]);
            }
            echo json_encode([
                'stamp' => nexo_room_sync_stamp($syncPdo, (int)$syncRoom['id']),
                'view' => $syncRoom['status'] === 'lobby' ? 'lobby' : 'game',
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
    } catch (Throwable $syncError) {
        http_response_code(503);
        echo json_encode(['error' => 'Estado temporariamente indisponível.']);
    }
    exit;
}

try {
    $pdo = nexo_db();
    $schemaReady = $pdo->query("SELECT to_regclass('public.rooms')")->fetchColumn();
    if (!$schemaReady) {
        throw new RuntimeException('O PostgreSQL conectou, mas ainda não foi instalado. Configure INSTALL_KEY no Render e abra /install.php para criar as tabelas.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            nexo_process_action($pdo);
        } catch (Throwable $e) {
            nexo_flash('error', $e->getMessage());
            nexo_redirect('index.php');
        }
    }

    [$player, $room] = nexo_current($pdo);
    $flash = nexo_take_flash();
    if (!$player || !$room) {
        nexo_render_landing($flash);
    } elseif ($room['status'] === 'lobby') {
        nexo_render_lobby($pdo, $player, $room, $flash);
    } else {
        nexo_render_game($pdo, $player, $room, $flash);
    }
} catch (Throwable $e) {
    http_response_code(500);
    nexo_render_header('Configuração necessária');
    ?>
    <section class="setup-panel">
        <div class="setup-icon">⚒</div><span class="eyebrow">PRIMEIRO ACESSO</span><h1>Falta preparar a <em>cripta.</em></h1>
        <p><?= h($e->getMessage()) ?></p>
        <ol><li>Vincule DATABASE_URL ao PostgreSQL pelo render.yaml.</li><li>Configure INSTALL_KEY temporariamente.</li><li>Abra <code>/install.php</code> e informe a chave para criar as tabelas.</li><li>Remova INSTALL_KEY do Render e atualize esta página.</li></ol>
        <a class="btn btn-primary" href="index.php">Tentar novamente ↻</a>
    </section>
    <?php
    nexo_render_footer();
}
