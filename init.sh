#!/bin/bash 

set -e

# Colors
RED='\033[0;31m'
CYAN='\033[0;36m'
YELLOW='\033[0;33m'
RESET='\033[0m'
GREEN='\033[0;32m'

[[ $EUID -ne 0 ]] && echo -e "${RED}Error: ${RESET} This script must be run with the root user！\n" && exit 1

get_script() {
	if [[ -f /usr/bin/XMPanel ]]; then
	  rm -rf /usr/bin/XMPanel /usr/bin/xmpanel 
	fi
	 
	curl -o /usr/bin/XMPanel -Ls https://raw.githubusercontent.com/XMPlusDev/XMPlusRelease/scripts/XMPanel.sh
	chmod +x /usr/bin/XMPanel
	ln -s /usr/bin/XMPanel /usr/bin/xmpanel 
	chmod +x /usr/bin/xmpanel

	echo -e ""
	echo "XMPlus Panel Management usage method: "
	echo "------------------------------------------"
	echo "XMPanel                    - Show menu"
	echo "XMPanel start              - Start Panel"
	echo "XMPanel stop               - Stop Panel"
	echo "XMPanel restart            - Restart Panel"
	echo "XMPanel status             - View Panel status"
	echo "XMPanel enable             - Enable Panel auto-start"
	echo "XMPanel disable            - Disable Panel auto-start"
	echo "XMPanel log                - View Panel logs"
	echo "XMPanel update             - Update Panel"
	echo "XMPanel config             - Show configuration content"
	echo "XMPanel install            - Install Panel"
	echo "XMPanel uninstall          - Uninstall Panel"
	echo "--------------------------------------------"
	echo "XMPanel api                - View panel api docker logs"
	echo "XMPanel ui                 - View panel ui docker logs"
	echo "XMPanel redis              - View redis docker logs"
	echo "XMPanel mariadb            - View mariadb docker logs"
	echo "XMPanel npm                - View nginx manager docker logs"
	echo "------------------------------------------"
}

echo -e "${GREEN}==> Updating packages and installing dependencies...${RESET}"
apt-get update -y 2>/dev/null
apt-get install -y wget bash zip curl unzip git ca-certificates openssl 2>/dev/null

echo -e "${GREEN}==> Checking for existing Docker installation...${RESET}"
if command -v docker &>/dev/null; then
    echo -e "${GREEN}==> Docker already installed: $(docker --version). Skipping...${RESET}"
else
    echo -e "${GREEN}==> Installing Docker...${RESET}"
    curl -fsSL https://get.docker.com -o get-docker.sh
    sh get-docker.sh
    rm get-docker.sh

    echo -e "${GREEN}==> Enabling Docker on boot...${RESET}"
    systemctl enable docker
    systemctl start docker
fi

echo -e "${GREEN}==> Checking for existing Docker Compose installation...${RESET}"
if docker compose version &>/dev/null 2>&1 || command -v docker-compose &>/dev/null; then
    echo -e "${GREEN}==> Docker Compose already installed: $(docker compose version 2>/dev/null || docker-compose --version). Skipping...${RESET}"
else
    echo -e "${GREEN}==> Installing Docker Compose...${RESET}"
    curl -L "https://github.com/docker/compose/releases/download/v5.1.0/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
    chmod +x /usr/local/bin/docker-compose
    ln -sf /usr/local/bin/docker-compose /usr/bin/docker-compose
fi

docker --version
docker compose version 2>/dev/null || docker-compose --version

echo -e "${GREEN}==> Checking for existing XMPlus-Panel installation...${RESET}"
if [ -d "/home/XMPlusPanel" ]; then
    echo ""
    echo -e "${YELLOW}⚠️  Directory /home/XMPlusPanel already exists.${RESET}"
    read -p "$(echo -e ${CYAN}Do you want to delete it and reinstall? [y/N]: ${RESET})" confirm
    echo ""
    if [[ "$confirm" =~ ^[Yy]$ ]]; then
        echo -e "${GREEN}==> Stopping XMPlusPanel service if running...${RESET}"
        systemctl stop XMPlusPanel.service 2>/dev/null || true
        echo -e "${RED}==> Deleting /home/XMPlusPanel...${RESET}"
        rm -rf /home/XMPlusPanel
		
		if [ -e "/usr/bin/XMPanel" ] ; then
			rm -rf /usr/bin/XMPanel -f
		fi
		
		mkdir -p /home/XMPlusPanel
    else
        echo -e "${RED}⏭️  Installation cancelled. Existing directory kept.${RESET}"
        exit 0
    fi
else
    mkdir -p /home/XMPlusPanel
fi

echo -e "${GREEN}==> Configuring .env file...${RESET}"

# Generate all random keys
APP_KEY="base64:$(openssl rand -base64 32)"
DB_ROOT_PASSWORD=$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | head -c 24)
REVERB_APP_KEY=$(openssl rand -hex 12)
REVERB_APP_SECRET=$(openssl rand -hex 12)

# Prompt user for inputs with defaults
echo ""
echo -e "${GREEN}📝 Please provide database and Redis configuration (press Enter to use default):${RESET}"
echo ""

read -p "$(echo -e "${CYAN}Enter a database name${RESET}    [${YELLOW}(Default: xmplus)${RESET}]:        ")" DB_DATABASE
DB_DATABASE=${DB_DATABASE:-xmplus}

read -p "$(echo -e "${CYAN}Enter a database username${RESET}    [${YELLOW}(Default: xmplus)${RESET}]:        ")" DB_USERNAME
DB_USERNAME=${DB_USERNAME:-xmplus}

read -sp "$(echo -e "${CYAN}Enter a database password for the username${RESET}    [${YELLOW}(Default: auto-generate)${RESET}]:  ")" DB_PASSWORD
echo ""
DB_PASSWORD=${DB_PASSWORD:-$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | head -c 20)}

read -p "$(echo -e "${CYAN}Enter a database port${RESET}        [${YELLOW}(Default: 3306)${RESET}]:          ")" DB_PORT
DB_PORT=${DB_PORT:-3306}

read -sp "$(echo -e "${CYAN}Enter a redis password${RESET} [${YELLOW}(Default: auto-generate)${RESET}]:  ")" REDIS_PASSWORD
echo ""
REDIS_PASSWORD=${REDIS_PASSWORD:-$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | head -c 24)}

read -p "$(echo -e "${CYAN}Enter a redis port${RESET}     [${YELLOW}(Default: 6379)${RESET}]:          ")" REDIS_PORT
REDIS_PORT=${REDIS_PORT:-6379}

REDIS_MAX_MEMORY="512mb"

read -p "$(echo -e "${CYAN}Enter a panel api host address to use without http:// or https:// ${RESET}       [${YELLOW}(Example: api.tld.com)${RESET}]:   ")" API_HOST
API_HOST=${API_HOST:-api.tld.com}

echo ""
echo -e "${GREEN}==> Writing .env file to /home/XMPlusPanel/.env...${RESET}"
cat > /home/XMPlusPanel/.env <<EOF
APP_NAME=XMPlus
APP_ENV=local
API_HOST=${API_HOST}
APP_PORT=9000
APP_KEY=${APP_KEY}
APP_URL="https://\${API_HOST}"
SANCTUM_STATEFUL_DOMAINS=localhost:3005

# database
DB_CONNECTION=mysql
DB_HOST=mariadbxmplus
DB_PORT=${DB_PORT}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}

# remote or local db or migration command only
SOURCE_DB_HOST=0.0.0.0
SOURCE_DB_PORT=3306
SOURCE_DB_DATABASE=old_database
SOURCE_DB_USERNAME=root
SOURCE_DB_PASSWORD=secret

# octane
OCTANE_PORT=\${APP_PORT}
OCTANE_WORKER_NUM=2
OCTANE_TASK_WORKERS=2
OCTANE_MAX_REQUESTS=250
OCTANE_TASK_MAX_REQUESTS=250
OCTANE_MAX_CONNECTION=500

# redis
REDIS_HOST=redisxmplus
REDIS_PASSWORD=${REDIS_PASSWORD}
REDIS_PORT=${REDIS_PORT}
REDIS_MAX_MEMORY=${REDIS_MAX_MEMORY}

# reverb
REVERB_APP_ID=10000
REVERB_APP_KEY=${REVERB_APP_KEY}
REVERB_APP_SECRET=${REVERB_APP_SECRET}
REVERB_HOST=${API_HOST}
REVERB_PORT=443
REVERB_SCHEME=https

LOG_DEPRECATIONS_CHANNEL=null

EOF

echo -e "${GREEN}==> Writing ecosystem.config.cjs to /home/XMPlusPanel/ecosystem.config.cjs${RESET}"
cat > /home/XMPlusPanel/ecosystem.config.cjs <<EOF
module.exports = {
  apps: [
    {
      name: 'XMPlus',
      script: '.output/server/index.mjs',
      instances: 'max',
      exec_mode: 'cluster',
      watch: false,
      env: {
        API_URL: 'https://${API_HOST}',
        PORT: 3005,
        DEBUG: false,
        SESSION_HTTPONLY: true,
        SESSION_SECURE: true,
        SESSION_SAME_SITE: 'lax'
      }
    }
  ]
}
EOF

echo -e "${GREEN}==> Writing docker-compose.yml to /home/XMPlusPanel/docker-compose.yml${RESET}"
cat > /home/XMPlusPanel/docker-compose.yml <<EOF
services:
  api:
    container_name: api
    image: xmplusdev/xmplus-api:latest
    env_file: .env
    networks:
      - app_network
    depends_on:
      mariadbxmplus:
        condition: service_healthy
      redisxmplus:
        condition: service_healthy
    restart: unless-stopped
    volumes:
      - ./.env:/app/.env
      - ./logs:/app/storage/logs
	  - ./uploads:/app/storage/public/uploads
    healthcheck:
      test: ["CMD-SHELL", "curl -f http://127.0.0.1:9000/status"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 30s

  ui:
    container_name: ui
    image: xmplusdev/xmplus-ui:latest
    volumes:
      - ./ecosystem.config.cjs:/app/ecosystem.config.cjs
    networks:
      - app_network
    depends_on:
      api:
        condition: service_healthy
    restart: unless-stopped

  mariadbxmplus:
    container_name: mariadbxmplus
    image: mariadb:12.2
    environment:
      MYSQL_ROOT_PASSWORD: \${DB_ROOT_PASSWORD}
      MYSQL_DATABASE: \${DB_DATABASE}
      MYSQL_USER: \${DB_USERNAME}
      MYSQL_PASSWORD: \${DB_PASSWORD}
      MYSQL_TCP_PORT: \${DB_PORT:-3306}
    networks:
      - app_network
    volumes:
      - ./mysql/mysql_data:/var/lib/mysql
      - ./mysql/conf.d:/etc/mysql/conf.d
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 30s

  redisxmplus:
    container_name: redisxmplus
    image: redis:8.4-alpine
    command: >
      redis-server
      --requirepass \${REDIS_PASSWORD}
      --port \${REDIS_PORT:-6379}
      --maxmemory ${REDIS_MAX_MEMORY}
      --maxmemory-policy allkeys-lru
      --appendonly yes
      --appendfsync everysec
      --save 60 1
      --save 300 100
      --bind 0.0.0.0
      --protected-mode no
    ports:
      - "\${REDIS_PORT:-6379}:\${REDIS_PORT:-6379}"
    networks:
      - app_network
    volumes:
      - ./redis:/data
    restart: unless-stopped
    healthcheck:
      test: ["CMD-SHELL", "redis-cli -p \${REDIS_PORT:-6379} -a \${REDIS_PASSWORD} ping"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 10s

  phpmyadmin:
    container_name: phpmyadmin
    image: phpmyadmin:latest
    environment:
      PMA_HOST: mariadbxmplus
      PMA_PORT: \${DB_PORT:-3306}
      MYSQL_ROOT_PASSWORD: \${DB_ROOT_PASSWORD}
      PMA_ARBITRARY: 0
      UPLOAD_LIMIT: 128M
    ports:
      - "8081:80"
    networks:
      - app_network
    depends_on:
      mariadbxmplus:
        condition: service_healthy
    restart: unless-stopped

  npm:
    container_name: npm
    image: jc21/nginx-proxy-manager:latest
    restart: unless-stopped
    environment:
      DISABLE_IPV6: 'true'
    ports:
      - '80:80'
      - '81:81'
      - '443:443'
    volumes:
      - ./nginx-proxy-manager:/data
      - ./letsencrypt:/etc/letsencrypt
    networks:
      - app_network

networks:
  app_network:
    driver: bridge
EOF


echo ""
echo "   ┌──────────────────────────────────────────────────────┐"
echo "   │              Configuration Summary                   │"
echo "   ├──────────────────────────────────────────────────────┤"
printf  "   │  DB_DATABASE:      %-34s│\n" "${DB_DATABASE}"
printf  "   │  DB_USERNAME:      %-34s│\n" "${DB_USERNAME}"
printf  "   │  DB_PASSWORD:      %-34s│\n" "${DB_PASSWORD}"
printf  "   │  DB_ROOT_PASSWORD: %-34s│\n" "${DB_ROOT_PASSWORD}"
printf  "   │  DB_PORT:          %-34s│\n" "${DB_PORT}"
printf  "   │  REDIS_PASSWORD:   %-34s│\n" "${REDIS_PASSWORD}"
printf  "   │  REDIS_PORT:       %-34s│\n" "${REDIS_PORT}"
printf  "   │  API_HOST:         %-34s│\n" "${API_HOST}"
echo "   └──────────────────────────────────────────────────────┘"
echo ""

if systemctl is-active --quiet XMPlusPanel.service 2>/dev/null; then
    systemctl stop XMPlusPanel.service
fi
if systemctl is-enabled --quiet XMPlusPanel.service 2>/dev/null; then
    systemctl disable XMPlusPanel.service
fi
if [ -f "/etc/systemd/system/XMPlusPanel.service" ]; then
    rm -f /etc/systemd/system/XMPlusPanel.service
fi
systemctl daemon-reload

cat > /etc/systemd/system/XMPlusPanel.service <<EOF
[Unit]
Description=XMPlusPanel
Requires=docker.service
After=docker.service network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=/home/XMPlusPanel
ExecStart=/usr/bin/docker compose up -d
ExecStop=/usr/bin/docker compose down
TimeoutStartSec=0

[Install]
WantedBy=multi-user.target
EOF

echo -e "${GREEN}==> Enabling and starting XMPlusPanel service...${RESET}"
systemctl daemon-reload
systemctl enable XMPlusPanel.service
systemctl start XMPlusPanel.service

echo -e "${GREEN}==> Waiting for API service to become healthy...${RESET}"
MAX_WAIT=120
WAITED=0
INTERVAL=5

get_script

cd /home/XMPlusPanel

while true; do
    STATUS=$(docker inspect --format='{{.State.Health.Status}}' api 2>/dev/null || echo "not_found")

    if [ "$STATUS" = "healthy" ]; then
        echo -e "${GREEN}✅ API service is healthy.${RESET}"
        break
    elif [ "$STATUS" = "unhealthy" ]; then
        echo -e "${RED}❌ API service is unhealthy. Aborting migration.${RESET}"
        docker logs api --tail 50
        exit 1
    fi

    if [ "$WAITED" -ge "$MAX_WAIT" ]; then
        echo -e "${RED}❌ Timed out waiting for API service after ${MAX_WAIT}s. Aborting.${RESET}"
        docker logs api --tail 50
        exit 1
    fi

    echo -e "${YELLOW}⏳ API status: ${STATUS}. Waiting... (${WAITED}s/${MAX_WAIT}s)${RESET}"
    sleep $INTERVAL
    WAITED=$((WAITED + INTERVAL))
done

echo -e "${GREEN}==> Running panel database migrations...${RESET}"
docker exec -it api php artisan migrate --seed 
echo -e "${GREEN}✅ Migrations complete.${RESET}"

echo -e "${GREEN}==> Creating admin account...${RESET}"
docker exec -it api php artisan xmplus:create-admin-account

echo ""
echo -e "${GREEN}✅ Done! XMPlus Panel is fully installed and running. Configure Nginx Proxy Manager{RESET}"
echo -e "${CYAN}Nginx Proxy Manager:   http://<your-server-ip>:81{RESET}"
echo ""
echo "Use 'systemctl status XMPlusPanel' to check the service status."
echo "Use 'systemctl stop XMPlusPanel' to stop the service."
echo "Use 'systemctl start XMPlusPanel' to start the service."
echo ""
echo -e "${YELLOW}Enable port ${RESET}80, 81, 8081, 443 ${YELLOW}and ${RESET}${REDIS_PORT} ${YELLOW}on your firewall${RESET}"
