# Análise de melhorias — Painel Ytoberr

Levantamento feito lendo o código atual do painel (controllers, models, views e serviços) para
mapear o que existe hoje e onde há espaço para novas features ou correções relevantes. Cada item
indica o estado atual (com referência de arquivo) e a sugestão de melhoria.

Prioridade: 🔴 Alta · 🟡 Média · 🟢 Baixa

---

## 1. Dashboard (`/`)

O dashboard hoje (`DashboardController`, `resources/views/dashboard.blade.php`) mostra só dois
contadores (canais e vídeos) e uma lista dos 10 últimos vídeos baixados. Não há nada além disso.

- 🔴 **Painel de saúde do sistema.** Não há nenhum indicador, na tela inicial, de fila
  travada, warnings recentes, ou disco cheio — o usuário só descobre isso entrando em
  Settings/Processes. Vale trazer para o dashboard: nº de vídeos pendentes/falhos, warnings não
  lidos, uso de disco, e se a fila está suspensa (`consecutive_failures` em
  `DownloadNextVideo::incrementConsecutiveFailures`).
- 🟡 **Gráfico de crescimento.** Nenhum histórico de armazenamento/downloads ao longo do tempo
  (vídeos por dia/semana, espaço ocupado). Um gráfico simples ajudaria a prever quando o disco vai
  encher.
- 🟡 **Próxima verificação agendada.** O agendamento roda a cada 3h (`routes/console.php`), mas o
  usuário não vê quando foi a última execução nem quando será a próxima.
- 🟢 **Atalhos de ação rápida.** Adicionar canal, forçar verificação geral, etc. direto do
  dashboard.

## 2. Canais (`/channels`)

`ChannelController`, `channels/index.blade.php`, `channels/show.blade.php`, `_channel-modals.blade.php`.

- 🔴 **Sem ações em lote.** Não dá pra selecionar vários canais e mudar qualidade, cutoff date ou
  excluir de uma vez — só um por um via o menu kebab.
- 🔴 **Intervalo de verificação fixo e global.** `Schedule::command('app:check-channels')
  ->everyThreeHours()` (routes/console.php) é o único agendamento; não existe por-canal (ex.:
  canal que posta 1x/semana não precisa checar a cada 3h, canal de notícias diárias talvez
  precise mais frequente). Vale um campo opcional de intervalo por canal.
- 🟡 **Só suporta canal inteiro, não playlists.** `CheckChannelsForNewVideos` sempre aponta para
  a aba `/videos` do canal (linha ~107-110). Não há como monitorar uma playlist específica ou
  adicionar um vídeo avulso por URL.
- 🟡 **Qualidade limitada a 480p/720p/1080p.** `_channel-modals.blade.php` e
  `DownloadNextVideo::processVideo` (heightLimit) não oferecem "melhor disponível", 4K, nem
  áudio-only (útil para podcasts/música). Também não há opção de baixar legendas
  (`--write-subs`/`--write-auto-subs` do yt-dlp não é usado em nenhum lugar).
- 🟡 **Sem tags/grupos de canais.** Com muitos canais cadastrados, a única organização é busca por
  nome/ID e 3 ordenações (`ChannelController::index`). Categorias ou tags ajudariam a organizar
  (ex. "Notícias", "Gaming", "Kids").
- 🟢 **Filtro por status.** Não dá pra filtrar canais que têm vídeos falhos ou warnings pendentes.
- 🟢 **Editar URL do canal.** Se o handle do YouTube mudar (`@canal` renomeado), não há como
  atualizar a URL sem recriar o canal do zero.

## 3. Vídeos (`/videos`)

`VideoController`, `videos/index.blade.php`, `videos/show.blade.php`.

- 🔴 **Sem filtro por canal na listagem geral.** `VideoController::index` só aceita `search` e
  `sort` — para ver os vídeos de um canal específico é preciso ir na página do canal, que por sua
  vez não tem busca textual. Não dá pra combinar "buscar X dentro do canal Y" em lugar nenhum.
- 🟡 **Sem progresso de visualização.** Não existe marcação de assistido/não assistido nem
  "continuar de onde parou" — o `<video>` em `videos/show.blade.php` não salva posição.
- 🟡 **Sem favoritos/watch later.** Nenhuma forma de marcar vídeos para assistir depois.
- 🟡 **Sem ações em lote.** Não há seleção múltipla para apagar vários vídeos de uma vez (fora da
  tela de Processos, que só cobre pendentes/falhos).
- 🟢 **Sem filtro por duração ou intervalo de datas** na busca.
- 🟢 **Sem exibição de legendas**, mesmo que fossem baixadas (depende do item de legendas em
  Canais acima).

## 4. Processos / Fila (`/processes`)

`ProcessesController`, `processes/index.blade.php`. Só aparece com "Advanced Mode" ligado
(`Setting::advancedModeEnabled`).

- 🔴 **Sem progresso do download atual.** "Live Activity" só mostra *qual* vídeo está baixando,
  sem porcentagem/velocidade — o yt-dlp já emite isso no stdout, mas `runCommand` em
  `YtDlpWrapper`/`DownloadNextVideo` não captura progresso incremental, só o resultado final.
- 🟡 **Sem reordenar a fila.** Pendentes são processados estritamente por `created_at` — não dá
  pra priorizar um vídeo manualmente.
- 🟡 **Sem pausar a fila manualmente.** Só existe suspensão automática após 3 falhas consecutivas
  (`incrementConsecutiveFailures`); não há um botão "pausar downloads" para manutenção.
- 🟢 **Advanced Mode esconde informação útil por padrão.** Fila pendente/falha só some da UI, mas
  continua existindo — um usuário comum não teria como saber que existe um vídeo travado sem
  ativar o modo avançado. Vale expor um resumo básico (contador) fora do modo avançado.

## 5. Configurações (`/settings`)

`SettingsController`, `settings/index.blade.php`.

- 🔴 **Nenhuma notificação externa.** Não existe integração de e-mail, Discord, Telegram ou
  webhook para avisar sobre: novo vídeo baixado, fila suspensa por falhas consecutivas
  (`Warning::log('queue_suspended', ...)`), disco quase cheio, ou atualização disponível
  (`UpdateChecker`). Hoje tudo isso só aparece se o usuário entrar no painel.
- 🔴 **Backup só manual, sem rotação.** `BackupService` cria snapshots via `VACUUM INTO`, mas não
  há agendamento automático (`Schedule::` não referencia backups em `routes/console.php`) nem
  limite/expiração de backups antigos — a pasta `storage/app/backups` cresce indefinidamente.
  Também não há opção de enviar o backup para um destino externo (S3, disco remoto).
- 🟡 **"Update Index" (`checkMissingVideos`/`cleanMissingVideos`) varre todos os vídeos e faz
  `file_exists()` um por um a cada clique** (`SettingsController::checkMissingVideos`, linha
  145-171) — sem cache/paginação, isso fica lento com bibliotecas grandes. Vale rodar como job em
  background com resultado assíncrono, similar ao `checkNewVideos` de canais.
- 🟡 **Usuário único, sem gestão de contas.** `EnsureUserExists` só permite exatamente 1 usuário
  (setup inicial); não há convite de segundo usuário, papéis (admin/leitor) nem log de auditoria
  das ações administrativas (restaurar backup, apagar canal com arquivos, etc.).
- 🟢 **Sem tema claro.** O layout é fixo em dark mode (`bg-gray-950` hardcoded em
  `layouts/app.blade.php`), sem toggle de tema.
- 🟢 **Sem visualizador de logs no painel.** O projeto já usa `laravel/pail`, mas não há uma tela
  para consultar logs recentes sem acessar o servidor.

## 6. Base técnica que trava features futuras

- 🔴 **Tailwind carregado via CDN em produção**, apesar de o projeto já ter um pipeline Vite +
  `@tailwindcss/vite` configurado e pronto (`vite.config.js`, `package.json`). O layout principal
  (`resources/views/layouts/app.blade.php:10`) usa `<script src="https://cdn.tailwindcss.com">`
  em vez de `@vite(...)`. Isso:
  - quebra a promessa de "self-hosted" (o painel depende de um CDN externo pra sequer renderizar
    o CSS);
  - impede customização de tema, dark/light mode, purge/minificação e cache local dos assets;
  - é a própria documentação do Tailwind que desaconselha essa forma de uso para produção.
  Corrigir isso é pré-requisito prático para vários itens de UI acima (tema, performance).

## 7. Segurança (features relacionadas, não bugs)

- 🟡 **Sem 2FA** para a conta única de admin.
- 🟡 **Sem lista de sessões ativas** nem opção de "sair de todos os dispositivos".
- 🟢 **Caminho de armazenamento (`storage_path`) é um texto livre** salvo direto em
  `SettingsController::updateStoragePath` sem validação de que o diretório é gravável/existe antes
  de salvar — um erro de digitação só aparece na próxima tentativa de download.

## 8. Consistência de UX

- 🟢 Mistura de padrões de confirmação: alguns fluxos usam `confirm()` nativo do navegador
  (excluir backup, limpar cache, remover cookies), outros usam modal customizado (excluir canal).
  Vale padronizar em um único componente de confirmação.
- 🟢 Mistura de feedback: `session('status')` com banner verde em algumas páginas, toast via JS
  em outras (`_channel-modals.blade.php`). Vale um componente de toast único reaproveitado em
  todo o app.
- 🟢 Sem onboarding para o estado vazio (zero canais cadastrados) além da mensagem simples em
  `channels/index.blade.php` — um wizard curto (colar URL → escolher qualidade → pronto) reduziria
  fricção para novos usuários.

---

## Resumo priorizado

| # | Item | Prioridade | Área |
|---|------|------------|------|
| 1 | Notificações externas (Discord/Telegram/e-mail/webhook) | 🔴 | Settings |
| 2 | Corrigir Tailwind via CDN → build local (`@vite`) | 🔴 | Infra |
| 3 | Backups automáticos com rotação | 🔴 | Settings |
| 4 | Progresso em tempo real do download atual | 🔴 | Processos |
| 5 | Filtro por canal na listagem de Vídeos | 🔴 | Vídeos |
| 6 | Ações em lote em Canais | 🔴 | Canais |
| 7 | Intervalo de verificação configurável por canal | 🔴 | Canais |
| 8 | Painel de saúde no Dashboard | 🔴 | Dashboard |
| 9 | Qualidade "melhor disponível"/áudio-only + legendas | 🟡 | Canais |
| 10 | Progresso de visualização / watch later nos vídeos | 🟡 | Vídeos |
| 11 | Gestão multiusuário com papéis | 🟡 | Settings |
| 12 | "Update Index" assíncrono em background | 🟡 | Settings |
| 13 | Tags/grupos de canais | 🟡 | Canais |
| 14 | Reordenar/pausar fila manualmente | 🟡 | Processos |
| 15 | Tema claro | 🟢 | UX |
| 16 | Padronização de confirmações e toasts | 🟢 | UX |
| 17 | 2FA / sessões ativas | 🟢 | Segurança |
