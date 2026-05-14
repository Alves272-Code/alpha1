# O que falta para isto ser funcional e comercializável

## 1) Segurança e conformidade (bloqueador de lançamento)
- **Remover credenciais de fallback em código** (`db.php`) e exigir `.env` em produção.
- **Hardening de sessão**: `session.cookie_httponly=1`, `session.cookie_secure=1` (HTTPS), `SameSite=Lax/Strict`.
- **Rate limit + anti-bruteforce** em login, recuperação e endpoints AJAX.
- **Política de passwords** + fluxo de reset por email com token expirável.
- **RGPD/privacidade**: consentimento explícito, retenção de dados, direito ao esquecimento, exportação de dados.
- **Logs/auditoria**: trilha de ações admin (alterar artigo, fechar pedido, apagar ficheiro).
- **Backups automáticos** e procedimento de restore testado.

## 2) Produto (MVP comercial)
- **Onboarding real**: wizard inicial para configuração do negócio/branding.
- **Gestão de utilizadores**: perfis, permissões granulares (admin/editor/suporte).
- **SLA de pedidos**: prioridade, tags, atribuição de responsável, histórico de estado.
- **Notificações**: email transacional para novos pedidos e respostas.
- **Pesquisa e filtros avançados**: por estado, data, cliente, prioridade.
- **Dashboard executivo**: métricas úteis (tempo médio de resposta/fecho, taxa de reabertura).

## 3) Operação e fiabilidade
- **Ambientes separados** (dev/staging/prod) com deploy controlado.
- **CI/CD** com lint, testes e bloqueio de merge em caso de falha.
- **Monitorização**: uptime, erros PHP, latência, alertas.
- **Gestão de uploads**: antivírus, storage externo (S3 compatível), limpeza e quotas.
- **Versionamento da BD** com migrações (ex.: Phinx, Laravel migrations, etc.).

## 4) Qualidade técnica
- **Arquitetura**: separar controllers/services/repositories (atualmente muito concentrado em ficheiros únicos).
- **Testes automatizados**:
  - unitários para validações e permissões,
  - integração para autenticação e pedidos,
  - smoke tests de rotas críticas.
- **Performance**: paginação de pedidos/artigos, índices adicionais e cache de consultas caras.
- **Normalização UX**: componentes reutilizáveis, estados vazios, feedback de loading.

## 5) Comercialização
- **Modelo de preços**: planos (Starter/Pro/Business) com limites claros.
- **Checkout e faturação**: Stripe/MBWay/PayPal + faturas/recibos.
- **Landing comercial** com proposta de valor, prova social e CTA.
- **Suporte**: base de conhecimento, SLA por plano, canal de tickets.
- **Métricas de negócio**: CAC, churn, LTV, taxa de ativação.

## 6) Prioridade prática (ordem recomendada)
1. Segurança + `.env` obrigatório + backups + HTTPS.
2. Notificações email + reset password + rate limit.
3. Filtros avançados e SLA nos pedidos.
4. CI/CD + testes mínimos + staging.
5. Billing + planos + landing comercial.

## Definição de “pronto para vender”
Considerar pronto quando:
- não há segredos em código,
- existe pipeline de deploy/testes,
- há recuperação de conta,
- tickets têm ciclo de vida completo com notificações,
- pagamentos e termos legais estão ativos,
- monitorização e backups estão validados.
