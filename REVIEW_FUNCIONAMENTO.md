# Revisão de Funcionamento

## O que foi validado
- Sintaxe PHP dos ficheiros principais (`index.php`, `dashboard.php`, `artigo.php`, `functions.php`, `db.php`, `logout.php`).
- Estrutura geral do fluxo: autenticação, dashboard, pedidos/contactos, artigos e uploads.

## Pontos fortes
- Uso consistente de `prepare/execute` com PDO para reduzir risco de SQL injection.
- Presença de token CSRF e validação em ações sensíveis.
- `password_hash/password_verify` para gestão de passwords.
- Validações de upload com tipo MIME + magic bytes + limite de tamanho.

## Problemas encontrados
1. **Credenciais da base de dados hardcoded** em `db.php` (risco de segurança e dificuldade de deploy em ambientes diferentes).
2. **Acoplamento de ambiente**: sem fallback claro para variáveis de ambiente obrigatórias.

## Melhorias aplicadas nesta revisão
- `db.php` passou a usar variáveis de ambiente (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`) com fallback local explícito.
- Mensagens de erro no arranque da DB foram mantidas genéricas para o utilizador final, com detalhe apenas em `error_log`.

## Recomendações seguintes
- Criar ficheiro `.env` (não versionado) e documentação de setup.
- Separar lógica de negócio das views PHP em camadas (controllers/services).
- Adicionar testes automatizados básicos para fluxos críticos (auth + criação de pedido).
