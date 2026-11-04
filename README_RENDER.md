# NEXO TRIO — Render + PostgreSQL

Esta edição publica o NEXO TRIO no **Render usando PHP 8.4, Apache, Docker e Render PostgreSQL**. Não usa MySQL, Aiven, Python, Node.js nem Composer.

## O que o pacote configura

- **Web Service:** executa PHP 8.4 + Apache por Docker.
- **PostgreSQL:** o próprio `render.yaml` cria um banco Render PostgreSQL na mesma região do site.
- **Conexão:** o Render injeta `DATABASE_URL` automaticamente no serviço, usando a URL interna da rede privada.
- **Extensão PHP:** o Docker instala `pdo_pgsql` e as bibliotecas `libpq` necessárias.
- **Instalador:** `install.php` cria as tabelas após você informar uma chave temporária.
- **Sessões:** guardadas no PostgreSQL, para preservar a identidade dos participantes após reinícios do container.
- **Sincronização:** as três interfaces consultam o estado compartilhado a cada 3,5 segundos enquanto a aba está visível.

O sorteio é feito uma única vez pelo servidor. Os três papéis recebem os mesmos módulos, as mesmas cartas e o mesmo horário inicial.

> O jogo é uma experiência de enigmas cenográficos não funcional. As peças físicas são cartões, fitas, relevos e seletores manuais; não use circuitos, baterias, mecanismos de acionamento ou materiais perigosos.

---

## Antes de publicar: atenção ao PostgreSQL gratuito

O Render oferece PostgreSQL gratuito com **1 GB**, mas a instância gratuita **expira 30 dias após a criação**. Após expirar, há 14 dias para fazer upgrade antes da exclusão dos dados. A camada gratuita também não oferece backups. Para testar o jogo, serve; para manter salas e dados por mais tempo, escolha um plano pago ou faça exportações periódicas antes do vencimento. Consulte as regras atuais: <https://render.com/docs/free>.

O Web Service gratuito também pode dormir após 15 minutos sem tráfego e levar cerca de um minuto para voltar a responder. Durante uma partida, os navegadores mantêm a sincronização automática enquanto as abas estão abertas.

---

## Etapa 1 — Colocar o projeto no GitHub

1. Baixe e extraia `NEXO_TRIO_RENDER_POSTGRES_v2.zip`.
2. Entre em <https://github.com/> e crie um repositório vazio.
3. Envie **o conteúdo** da pasta `NEXO_TRIO_RENDER_PG` para a raiz do repositório.
4. Verifique que `render.yaml` e `Dockerfile` aparecem diretamente na raiz, e não dentro de uma subpasta.
5. Não envie senhas, `.env`, dumps de banco ou certificados para o GitHub.

## Etapa 2 — Criar o serviço e o banco no Render

1. Acesse <https://render.com/> e entre na sua conta.
2. No painel, clique em **New + → Blueprint**.
3. Conecte o repositório GitHub que contém o NEXO.
4. O Render vai ler `render.yaml` e mostrar dois recursos:
   - `nexo-trio-cripta` — Web Service Docker;
   - `nexo-trio-postgres` — banco PostgreSQL.
5. Confirme a criação dos recursos. Mantenha ambos na região `Ohio`, como definido no arquivo.
6. Quando o Render solicitar `INSTALL_KEY`, informe uma chave temporária longa, com pelo menos 32 caracteres. Não a coloque no GitHub.
7. Aguarde o primeiro deploy terminar.

**Não crie um segundo banco manualmente.** O Blueprint já declara o PostgreSQL e conecta o serviço por `DATABASE_URL`.

## Etapa 3 — Conferir a conexão

No painel do Render, abra o Web Service `nexo-trio-cripta` e vá a **Environment**. Deve existir a variável `DATABASE_URL`, preenchida a partir do recurso `nexo-trio-postgres`.

Ela é criada automaticamente por esta parte do `render.yaml`:

```yaml
envVars:
  - key: DATABASE_URL
    fromDatabase:
      name: nexo-trio-postgres
      property: connectionString
```

Use a **Internal Database URL** quando configurar manualmente. O serviço web e o banco precisam estar na mesma conta e região para usar o endereço interno. Não é necessário certificado CA para esta configuração do Blueprint.

## Etapa 4 — Instalar as tabelas

1. Abra `https://SEU-SITE.onrender.com/install.php`.
2. Digite a chave temporária que você definiu em `INSTALL_KEY`.
3. Clique em **Criar tabelas do PostgreSQL**.
4. Ao aparecer a confirmação, volte a **Environment** no Web Service.
5. Apague `INSTALL_KEY` e salve. O Render fará um novo deploy; o instalador ficará desativado.
6. Abra `https://SEU-SITE.onrender.com`.

Se falhar, confira **Logs** no Render. Não compartilhe publicamente a URL completa do banco, a senha nem a chave temporária.

## Etapa 5 — Testar uma partida

1. Abra o endereço em três navegadores ou dispositivos diferentes.
2. A primeira pessoa cria a sala, escolhe o papel e define 2, 3 ou 4 módulos.
3. Compartilhe o código de seis caracteres.
4. As outras duas pessoas entram e escolhem os papéis que faltam.
5. Todos marcam **Estou pronto**.
6. O anfitrião inicia. O servidor sorteia uma configuração única e grava o horário oficial.
7. As três telas recebem o mesmo estado automaticamente a cada 3,5 segundos.

## Estrutura técnica

- `Dockerfile` — PHP 8.4 + Apache + PDO PostgreSQL (`pdo_pgsql`).
- `render.yaml` — Blueprint que cria o Web Service e o PostgreSQL.
- `config.php` — lê `DATABASE_URL` e `APP_ENV` do ambiente.
- `app/db.php` — converte a URL PostgreSQL do Render em conexão PDO.
- `database/schema.sql` — tabelas de salas, participantes e sessões.
- `app/session_handler.php` — sessões persistidas com `ON CONFLICT` do PostgreSQL.
- `install.php` — instalação inicial protegida por `INSTALL_KEY`.
- `health.php` — Health Check do Web Service.

## Teste local da lógica

Com PHP CLI, rode:

```bash
php tests/smoke.php
```

Esse teste valida os baralhos e sorteios, sem precisar conectar ao PostgreSQL. A conexão real será confirmada no primeiro deploy do Render.

## Segurança e manutenção

- Nunca coloque `DATABASE_URL` ou `INSTALL_KEY` no GitHub.
- Apague `INSTALL_KEY` depois de instalar o banco.
- Não altere a URL interna do banco para uma URL pública sem necessidade.
- O PostgreSQL gratuito do Render expira em 30 dias e não oferece backups. Exporte os dados ou faça upgrade antes do prazo se quiser preservá-los.
- Para jogar um evento importante, prefira Web Service e banco pagos, com disponibilidade e backups adequados.
