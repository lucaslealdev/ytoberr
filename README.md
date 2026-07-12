# Ytoberr

Painel web self-hosted voltado para arquivamento local e monitoramento automatizado de canais do YouTube.

## 🚀 Funcionalidades

- **Monitoramento Automatizado:** Verifica novos envios periodicamente.
- **Arquivamento Organizado:** Vídeos baixados são organizados em diretórios: `{canal}/{ano}/{mes}/{video}.{ext}` (arquivo salvo como baixado pelo yt-dlp, sem reprocessamento).
- **Compatibilidade com Plex:** Nomenclatura Plex-friendly com thumbnail salva como arquivo companheiro (`{video}-thumb.jpg`).
- **Gestão de Qualidade:** Definição de qualidade de download por canal.

## 🛠️ Stack Tecnológica

- **Backend:** Laravel 13.x (PHP 8.4)
- **Banco de Dados:** SQLite
- **Download:** `yt-dlp` (o `ffmpeg` é usado apenas internamente pelo yt-dlp para mesclar formatos de áudio/vídeo separados; a aplicação não invoca o ffmpeg diretamente)

## ⚙️ Instalação

1. Clone o repositório.
2. Instale as dependências: `composer install`.
3. Configure o arquivo `.env` (baseado no `.env.example`).
4. Execute as migrações: `php artisan migrate`.
5. Baixe e configure as dependências binárias (`yt-dlp`, `ffmpeg`, `ffprobe`): `make setup-bins`.

## 🖥️ Comandos de Desenvolvimento (Makefile)

 O projeto inclui um `Makefile` com atalhos úteis:

- `make setup-bins`: Baixa e configura as dependências binárias (`yt-dlp`, `ffmpeg`, `ffprobe`) na pasta `bin/`.
- `make serve`: Inicia o servidor de desenvolvimento.
- `make queue-bg`: Inicia o worker de filas em background.
- `make queue-stop`: Para o worker de filas.
- `make migrate`: Executa as migrações.
- `make cache-clear`: Limpa os caches do Laravel.

## 🐳 Docker (Recomendado)

A forma mais simples de instalar e rodar o Ytoberr é via Docker. A imagem já inclui o cron do scheduler do Laravel, o worker de filas e faz o download automático de `yt-dlp`/`ffmpeg`/`ffprobe` na primeira execução, além de rodar as migrações automaticamente.

Uma imagem pronta (multi-arquitetura, `amd64`/`arm64`) é publicada automaticamente no GitHub Container Registry a cada push na `main` (tag `latest`) e a cada release `vX.Y.Z` (tags de versão), via [`.github/workflows/docker-publish.yml`](.github/workflows/docker-publish.yml). Para usá-la sem clonar o repositório, basta:

```bash
docker run -d --name ytoberr -p 8080:8080 \
  -v ytoberr-database:/var/www/html/database \
  -v ytoberr-storage:/var/www/html/storage/app \
  -v ytoberr-bin:/var/www/html/bin \
  ghcr.io/lucaslealdev/ytoberr:latest
```

Se preferir clonar o repositório e usar Docker Compose (builda localmente por padrão; use `YTOBERR_IMAGE=ghcr.io/lucaslealdev/ytoberr:latest docker compose up -d` para baixar a imagem publicada em vez de buildar):

```bash
docker compose up -d --build
```

A aplicação sobe em `http://localhost:8080` (ajustável via `APP_PORT`/`APP_URL`).

Dados persistentes (definidos no `docker-compose.yml`):

- `ytoberr-database` (volume nomeado): banco SQLite (`database/database.sqlite`).
- `ytoberr-storage` (volume nomeado): vídeos, thumbnails e demais arquivos gerados (`storage/app`).
- `./bin` (bind mount para a pasta `bin/` do projeto): se `yt-dlp`/`ffmpeg`/`ffprobe` já existirem aí (por exemplo, de um `make setup-bins` local anterior), o container os reaproveita direto, sem baixar nada. Se a pasta estiver vazia, o container baixa os binários automaticamente e os deixa salvos ali para as próximas subidas.

Variáveis úteis (podem ser definidas num `.env` ao lado do `docker-compose.yml` ou exportadas no shell):

- `APP_URL` / `APP_PORT`: URL e porta pública da aplicação (padrão `http://localhost:8080` / `8080`).
- `APP_KEY`: chave de criptografia do Laravel. Se omitida, é gerada automaticamente no primeiro boot (recomenda-se fixá-la para persistir entre recriações do container).
- `TZ`: fuso horário do container (padrão `UTC`).

Sem Docker Compose, o mesmo resultado pode ser obtido com:

```bash
docker build -t ytoberr .
docker run -d --name ytoberr -p 8080:8080 \
  -v ytoberr-database:/var/www/html/database \
  -v ytoberr-storage:/var/www/html/storage/app \
  -v "$(pwd)/bin:/var/www/html/bin" \
  ytoberr
```

## 📅 Agendamentos & Filas (Produção sem Docker)

Se preferir rodar fora de Docker, configure manualmente os serviços abaixo para garantir que o Ytoberr monitore novos vídeos e processe os downloads em segundo plano de forma contínua (ao usar Docker, ambos já vêm configurados dentro do container):

### 1. Agendador de Tarefas (Cron Job)
O Laravel utiliza um único Cron Job para gerenciar todos os agendamentos internos (como a verificação de novos vídeos de 3 em 3 horas).
Abra o crontab do Linux (`crontab -e`) e adicione a seguinte linha:

```bash
* * * * * cd /home/lucas/ytoberr && php artisan schedule:run >> /dev/null 2>&1
```

*(Substitua `/home/lucas/ytoberr` pelo caminho absoluto correto da instalação do seu projeto).*

### 2. Processamento de Filas (Queue Worker)
Os downloads pesados de vídeos e thumbnails são despachados para filas em segundo plano para não travar a interface web.

*   **Desenvolvimento:** Utilize `make queue-bg` para ligar o worker em background e `make queue-stop` para pará-lo.
*   **Produção (Supervisor):** É altamente recomendado rodar o gerenciador de processos **Supervisor** para manter o worker de filas ativo constantemente e reiniciar automaticamente caso falhe.

Exemplo de configuração do Supervisor (`/etc/supervisor/conf.d/ytoberr-worker.conf`):

```ini
[program:ytoberr-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /home/lucas/ytoberr/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=lucas
numprocs=1
redirect_stderr=true
stdout_logfile=/home/lucas/ytoberr/storage/logs/worker.log
stopwaitsecs=3600
```

*(Ajuste o `user`, caminhos absolutos e logs conforme as permissões de ambiente do seu servidor).*

## 📄 Licença

Este projeto é open-sourced sob a [MIT license](https://opensource.org/licenses/MIT).

