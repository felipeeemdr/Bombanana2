# NEXO TRIO — edição Render PostgreSQL

**Tecnologias:** PHP 8.4 + Apache + Docker + PostgreSQL gerenciado pelo Render.

## Publicação em resumo

1. Extraia o ZIP e envie o conteúdo para a raiz de um repositório GitHub.
2. No Render, escolha **New + → Blueprint** e conecte esse repositório.
3. O `render.yaml` cria o Web Service e o PostgreSQL gratuito, vinculando o banco pela variável `DATABASE_URL`.
4. Defina `INSTALL_KEY` quando o Render solicitar.
5. Depois do deploy, abra `/install.php`, informe a chave e instale as tabelas.
6. Apague `INSTALL_KEY` no painel do Render.
7. Acesse o domínio `onrender.com` e teste com três navegadores.

Leia `README_RENDER.md` para o passo a passo completo e as limitações do PostgreSQL gratuito.

**Atenção:** o PostgreSQL Free do Render expira após 30 dias e não tem backups. Para preservar os dados depois desse prazo, faça upgrade ou exporte o banco antes do vencimento.

Este jogo é uma simulação de enigmas cenográficos não funcional.
