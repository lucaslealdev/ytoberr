# Ytoberr — Painel de Arquivamento e Monitoramento do YouTube

Este arquivo serve como contexto central para o projeto **Ytoberr**. Ele resume os objetivos, requisitos funcionais, stack tecnológica e a arquitetura planejada para guiar o desenvolvimento nas próximas sessões.

---

## 📌 Visão Geral do Projeto

O **Ytoberr** é um painel web self-hosted voltado para arquivamento local e monitoramento automatizado de canais do YouTube. Ele monitora novos envios periodicamente (a cada hora), baixa os vídeos na resolução desejada, organiza os arquivos em uma estrutura organizada de diretórios e embute metadados/thumbnails para compatibilidade imediata com media servers como o **Plex**.

---

## ⚙️ Requisitos Funcionais

### 1. Cadastro e Galeria de Canais
- **Cadastro de Canal:** Permite cadastrar canais informando a URL ou identificador do canal do YouTube.
- **Download de Imagem de Perfil:** No ato do cadastro, a imagem de perfil do canal no YouTube é baixada para armazenamento local.
- **Perfil de Download:** Cada canal deve ser configurado com uma qualidade de download padrão:
  - `480p` (Baixa qualidade / economia de espaço)
  - `720p` (Qualidade média / HD padrão)
  - `1080p` (Alta qualidade / Full HD)
- **Interface de Galeria:** Exibição visual de todos os canais cadastrados utilizando suas respectivas fotos de perfil salvas localmente.

### 2. Monitoramento e Polling Automatizado
- **Agendamento:** Verificação horária (a cada 1 hora) de novos vídeos enviados por cada canal.
- **Processamento em Fila:** Os downloads devem ser despachados para filas de processamento assíncronas para não travar a interface.

### 3. Armazenamento e Organização de Arquivos
Os vídeos baixados devem ser salvos seguindo estritamente a estrutura:
```text
/storage/app/public/downloads/{nome-do-canal}/{ano}/{mes}/{video}.{ext}
```
`{ext}` é a extensão nativa entregue pelo yt-dlp (tipicamente `mp4`), sem remuxing.
- `{nome-do-canal}`: Nome sanitizado do canal.
- `{ano}`: Ano de publicação do vídeo (Ex: `2026`).
- `{mes}`: Mês de publicação do vídeo (Ex: `06` ou `Junho`).

### 4. Processamento de Vídeo e Metadados para o Plex
- **Download de Capa (Thumbnail):** A imagem de miniatura de cada vídeo deve ser baixada no ato do download do vídeo.
- **Sem reprocessamento:** O vídeo é salvo como baixado pelo yt-dlp (sem remuxing/transcodificação própria). A thumbnail é salva como arquivo companheiro (`{nome-do-arquivo}-thumb.jpg`) ao lado do vídeo para compatibilidade com o **Plex**, em vez de embutida no container.
- **Metadados:** Título, descrição, data de publicação e ID do vídeo ficam registrados no banco de dados e em arquivos `.nfo` (ver Roadmap).

---

## 🛠️ Stack Tecnológica

### Backend & Core
- **Framework:** Laravel 13.x (PHP 8.4)
- **Banco de Dados:** SQLite (Armazenando canais, logs de download, fila de jobs e metadados dos vídeos).
- **Processamento de Vídeo:** `yt-dlp` (utilitário para download e extração de metadados do YouTube). O `ffmpeg` é mantido apenas como dependência interna do yt-dlp (mesclagem de streams de vídeo/áudio separados e conversão de thumbnail); a aplicação não chama o ffmpeg diretamente nem faz remuxing/transcodificação própria.

### Infraestrutura
- **Ambiente:** Servidor web local com PHP 8.4 e SQLite.
- **Gerenciador de Processos:** Supervisor (para gerenciar os queue workers do Laravel).

---

## 📁 Estado Atual do Projeto

- **Fase:** Autenticação & Configuração Inicial.
- **Infraestrutura:** Servidor web local configurado com Nginx, PHP-FPM 8.4 e Supervisor.
- **Banco de dados:** Tabelas `channels`, `videos` e `download_logs` criadas e estruturadas conforme requisitos via SQLite.
- **Segurança:** Implementado fluxo de configuração inicial (`/setup`) para criação do usuário administrador, restrição de acesso e sistema de login/logout.
- **Makefile:** Disponível com comandos utilitários.

---

## 📋 Roadmap & Pendências (Estado Atual)

### 1. Infraestrutura & Backend
- [x] Configuração de ambiente local (Nginx, PHP, Supervisor, DB)
- [x] Migrations base (channels, videos, logs)
- [x] Fluxo de Auth & Setup inicial
- [x] Configurar Driver de Fila (Queue) para workers assíncronos. (Utilizando `database` driver)

### 2. Gestão de Canais
- [x] Cadastro básico via URL
- [x] Implementar download e armazenamento local da imagem de perfil (avatar, banner, fanart).
- [x] Criar UI para listagem/gerenciamento de canais (Galeria).
- [x] Criar página interna para o canal com link no título e thumbnail do card. Nessa página mostrar breadcrumb para voltar para a listagem de canais, mostrar em cards os vídeos do canal do mais recente ao mais antigo. Utilizar as imagens que são baixadas para o canal nessa tela.

### 3. Monitoramento & Downloads (Núcleo)
- [x] **TODO:** Implementar `Artisan Command` para Polling de novos vídeos (3h) com pré-check de `live_status`.
- [x] **TODO:** Desenvolver wrapper para `yt-dlp` utilizando `--print-to-file` com templates seletivos (campos específicos em JSON) para evitar falhas de parsing.
- [x] **TODO:** Implementar lógica de estruturação de arquivos (`/storage/app/public/downloads/{canal}/Season {YYYY}/{nome-do-arquivo}.{ext}`) visando Plex.
- [x] **TODO:** No cadastro do canal deve ter uma data onde é a data de corte que a partir daquela data os vídeos são baixados. Ao incluir um canal, a data deve ser a de hoje mas deve ser possível alterar na página interna do canal. Faça também um backfill no banco que já existe, colocando a data de hoje nos canais que lá já estão.
- [x] **TODO:** Deve haver uma tabela com cache/histórico do que foi obtido de dados com o yt-dlp de modo que requisições idênticas subsequentes utilizem esse cache do banco de dados com TTL de 30 minutos.
- [x] **TODO:** Na tela settings ter opção de resetar esse cache.
- [x] **TODO:** Implementar uma fila de vídeos que devem ser baixados no banco de dados com todas as colunas importantes para debug e controle eficiente da fila, como status, retries, etc.
- [x] **TODO:** Criar um job que vai, de forma agendada de 3 em 3 horas, obter dos channels quais vídeos devem ser baixados e incluí-los na fila de vídeos do banco de dados. Essa fila deve respeitar o cut-off de data dos canais.
- [x] **TODO:** Implementar `Job` de download resiliente:
    - [x] Obtém a fila direto do banco de dados
    - [x] Roda a cada 2 minutos, mas não permite overlap (rodar dois ao mesmo tempo) então só 1 vídeo é baixado por vez, com um intervalo de 2 minutos entre os downloads.
    - [x] Implementar retry automático com cookies quando necessário, o vídeo vai para o fim da fila
    - [x] Implementar tratamento de erros para vídeos removidos/privados (marcar como `prevent_download` e registrar `unavailable_reason`).
    - [x] Implementar recuperação parcial de download em falhas de SponsorBlock.
    - [x] Caso 3 downloads falhem em sequência, marca estes e todos os próximos da lista como status failed para não ficar insistindo em um problema que parece ser generalizado.
    - [x] O limite de tentativas de baixar um único vídeo é 3 vezes antes de ser marcado como failed.

### 4. Metadados & Estética (Plex/Local Media Assets)
- [x] **TODO:** Implementar gerador de `tvshow.nfo` na raiz do canal com título e descrição.
- [x] **TODO:** Implementar gerador de `{nome-do-arquivo}.nfo` por vídeo com lógica `Season YYYY / Episode MMDD`.
- [x] **TODO:** Implementar extração e renomeação de artes do canal (`poster.jpg`, `fanart.jpg`, `banner.jpg`).
- [x] **TODO:** Implementar download e renomeação de thumbnail de vídeo (`{nome-do-arquivo}-thumb.jpg`).

### 5. Interface (Frontend)
- [x] **TODO:** Página de Dashboard (visualização dos vídeos recentes).
