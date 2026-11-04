<?php
declare(strict_types=1);

require __DIR__ . '/app/db.php';

$key = (string)(getenv('INSTALL_KEY') ?: '');
if ($key === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Instalador desativado. Configure INSTALL_KEY temporariamente no Render para instalar o banco.\n";
    exit;
}

$message = '';
$type = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provided = (string)($_POST['install_key'] ?? '');
    if (!hash_equals($key, $provided)) {
        http_response_code(403);
        $message = 'Chave de instalação incorreta.';
        $type = 'error';
    } else {
        try {
            $pdo = nexo_db();
            $schema = file_get_contents(__DIR__ . '/database/schema.sql');
            if ($schema === false) {
                throw new RuntimeException('Não foi possível ler database/schema.sql.');
            }
            $statements = preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [];
            $count = 0;
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if ($statement === '' || preg_match('/^--\s*[^\n]*$/', $statement)) {
                    continue;
                }
                $pdo->exec($statement);
                $count++;
            }
            $message = 'Banco preparado com sucesso. Tabelas de salas, participantes e sessões foram criadas no PostgreSQL. Remova INSTALL_KEY das variáveis do Render agora.';
            $type = 'success';
        } catch (Throwable $e) {
            error_log('NEXO installer: ' . $e->getMessage());
            http_response_code(500);
            $message = 'Não foi possível preparar o banco. Confira DATABASE_URL. Veja os logs do Render para o diagnóstico técnico.';
            $type = 'error';
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Preparar banco · NEXO</title>
  <link rel="stylesheet" href="assets/css/nexo.css?v=render-1">
  <style>
    body{min-height:100vh;display:grid;place-items:center;padding:24px;background:#171012;color:#eadcc3}
    .install-card{width:min(560px,100%);padding:36px;border:1px solid #72513b;border-radius:18px;background:linear-gradient(145deg,#2b1b1c,#1b1415);box-shadow:0 28px 90px #0008}
    .install-card h1{font-family:Georgia,serif;font-size:clamp(30px,6vw,44px);margin:8px 0 12px;color:#e8d3ad}
    .install-card p{line-height:1.65;color:#c4b29d}.install-card label{display:block;margin:24px 0 10px;color:#ddc8a6}
    .install-card input{width:100%;padding:14px;border-radius:9px;border:1px solid #765640;background:#100c0d;color:#fff;font:inherit}
    .install-card button{margin-top:16px}.install-message{padding:14px;border-radius:10px;margin:18px 0;background:#382c20;color:#f2dcb5}
    .install-message.success{background:#243326;color:#d9ebcf}.install-message.error{background:#432322;color:#f0c7bd}
    .install-small{font-size:13px;color:#a99780}
  </style>
</head>
<body>
  <main class="install-card">
    <span class="eyebrow">NEXO · CONFIGURAÇÃO INICIAL</span>
    <h1>Preparar a cripta</h1>
    <p>Esta página cria as tabelas do jogo no PostgreSQL do Render. Ela só funciona enquanto a variável INSTALL_KEY estiver configurada no Render.</p>
    <?php if ($message !== ''): ?><div class="install-message <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <label for="install_key">Chave temporária de instalação</label>
      <input id="install_key" name="install_key" type="password" required autocomplete="current-password">
      <button class="btn btn-primary" type="submit">Criar tabelas do PostgreSQL ↗</button>
    </form>
    <p class="install-small">Depois de concluir, remova INSTALL_KEY no painel do Render e aguarde o novo deploy. Não compartilhe essa chave.</p>
  </main>
</body>
</html>
