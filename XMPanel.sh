#!/bin/bash

red='\033[0;31m'
green='\033[0;32m'
yellow='\033[0;33m'
plain='\033[0m'

CONTAINERS=(
  npm
  ai
  ui
  phpmyadmin
  redis
  xmplus
  mariadbxmplus
)

IMAGES=(
  xmplusdev/xmplus-api:latest
  xmplusdev/xmplus-ui:latest
  mariadb:12.2
  redis:8.4-alpine
  phpmyadmin:latest
  jc21/nginx-proxy-manager:latest
)

check_docker() {
  if ! command -v docker &>/dev/null; then
    error "Docker is not installed or not in PATH."
    exit 1
  fi
  if ! docker info &>/dev/null; then
    error "Docker daemon is not running."
    exit 1
  fi
}

# check root
[[ $EUID -ne 0 ]] && echo -e "${red}Error: ${plain} This script must be run with the root user！\n" && exit 1

remove_containers() {
  info "Processing containers..."
  for name in "${CONTAINERS[@]}"; do
    if docker ps -a --format '{{.Names}}' | grep -q "^${name}$"; then
      # Stop if running
      if docker ps --format '{{.Names}}' | grep -q "^${name}$"; then
        docker stop "$name" &>/dev/null
        warn "Stopped: $name"
      fi
      docker rm -f "$name" &>/dev/null
      log "Removed container: $name"
    else
      warn "Container not found, skipping: $name"
    fi
  done
}

remove_images() {
  info "Processing images..."
  for image in "${IMAGES[@]}"; do
    if docker image inspect "$image" &>/dev/null; then
      docker rmi -f "$image" &>/dev/null
      log "Removed image: $image"
    else
      warn "Image not found, skipping: $image"
    fi
  done
}

remove_dangling_images() {
  # Remove untagged (dangling) images
  info "Removing untagged (dangling) images..."
  dangling=$(docker images -qf "dangling=true")
  if [[ -n "$dangling" ]]; then
    echo "$dangling" | xargs docker rmi -f &>/dev/null
    log "Untagged images removed."
  else
    warn "No untagged images found, skipping."
  fi
}

check_status() {
	if [[ ! -f /etc/systemd/system/XMPlusPanel.service ]]; then
		return 2
	fi
	
	temp=$(systemctl status XMPlusPanel | grep Active | awk '{print $3}' | cut -d "(" -f2 | cut -d ")" -f1)
	if [[ x"${temp}" == x"running" ]]; then
		return 0
	else
		return 1
	fi
}

check_install() {
	if [[ ! -f /etc/systemd/system/XMPlusPanel.service ]]; then
		echo -e "${red}Panel is not installed. Please run the installer first.${plain}"
		if [[ $# == 0 ]]; then
			before_show_menu
		fi
		return 1
	fi
}

api() {
	check_status
	if [[ $? == 0 ]]; then
		cd /home/XMPlusPanel
		docker compose logs -f api
	else
		echo -e "${red}Unable to tail API logs. Panel is not running.${plain}"
	fi

	if [[ $# == 0 ]]; then
		before_show_menu
	fi
}

ui() {
	check_status
	if [[ $? == 0 ]]; then
		cd /home/XMPlusPanel
		docker compose logs -f ui
	else
		echo -e "${red}Unable to tail UI logs. Panel is not running.${plain}"
	fi

	if [[ $# == 0 ]]; then
		before_show_menu
	fi
}

mariadb() {
	check_status
	if [[ $? == 0 ]]; then
		cd /home/XMPlusPanel
		docker compose logs -f mariadbxmplus
	else
		echo -e "${red}Unable to tail MariaDB logs. Panel is not running.${plain}"
	fi

	if [[ $# == 0 ]]; then
		before_show_menu
	fi
}

redis() {
	check_status
	if [[ $? == 0 ]]; then
		cd /home/XMPlusPanel
		docker compose logs -f redisxmplus
	else
		echo -e "${red}Unable to tail Redis logs. Panel is not running.${plain}"
	fi

	if [[ $# == 0 ]]; then
		before_show_menu
	fi
}

npm() {
	check_status
	if [[ $? == 0 ]]; then
		cd /home/XMPlusPanel
		docker compose logs -f npm
	else
		echo -e "${red}Unable to tail Nginx Proxy Manager logs. Panel is not running.${plain}"
	fi

	if [[ $# == 0 ]]; then
		before_show_menu
	fi
}

uninstall() {
	confirm "Are you sure you want to uninstall XMPlus Panel? " "n"
	if [[ $? != 0 ]]; then
		if [[ $# == 0 ]]; then
			show_menu
		fi
		return 0
	fi
	if [ -e "/etc/systemd/system/XMPlusPanel.service" ]; then
		systemctl stop XMPlusPanel
		systemctl disable XMPlusPanel
		rm /etc/systemd/system/XMPlusPanel.service -f
		systemctl daemon-reload
		systemctl reset-failed
	fi
	
	rm /home/XMPlusPanel/ -rf
	rm -rf /usr/bin/XMPanel -f
	rm -f /usr/bin/xmpanel
	
	#uninstall images and containers
	check_docker
	remove_containers
	remove_dangling_images
	remove_images

	echo ""
	echo -e "${green}Panel successfully disabled and removed.${plain}"
	echo ""

	if [[ $# == 0 ]]; then
		before_show_menu
	fi
}

start() {
	systemctl start XMPlusPanel
	sleep 2
	check_status
	if [[ $? == 0 ]]; then
		echo -e "${green}Panel started successfully.${plain}"
	else
		echo -e "${red}Panel failed to start. Please check the log information.${plain}"
	fi

	if [[ $# == 0 ]]; then
		before_show_menu
	fi
}

stop() {
	systemctl stop XMPlusPanel
	sleep 2
	check_status
	if [[ $? == 1 ]]; then
		echo -e "${green}Panel successfully stopped.${plain}"
	else
		echo -e "${red}Panel failed to stop, probably because the stop time exceeded two seconds. Please check the log information.${plain}"
	fi

	if [[ $# == 0 ]]; then
		before_show_menu
	fi
}

restart() {
	systemctl restart XMPlusPanel
	sleep 2
	check_status
	if [[ $? == 0 ]]; then
		echo -e "${green}Panel restarted successfully. Use 'xmpanel log' to view the operation log.${plain}"
	else
		echo -e "${red}Panel may have failed to start. Use 'xmpanel log' to check the log information.${plain}"
	fi

	if [[ $# == 0 ]]; then
		before_show_menu
	fi
}

status() {
	systemctl status XMPlusPanel --no-pager -l
	if [[ $# == 0 ]]; then
		before_show_menu
	fi
}

enable() {
	systemctl enable XMPlusPanel
	if [[ $? == 0 ]]; then
		echo -e "${green}Auto-start panel on system boot enabled successfully.${plain}"
	else
		echo -e "${red}Failed to enable panel auto-start on system boot.${plain}"
	fi

	if [[ $# == 0 ]]; then
		before_show_menu
	fi
}

disable() {
	systemctl disable XMPlusPanel
	if [[ $? == 0 ]]; then
		echo -e "${green}Panel auto-start on system boot disabled successfully.${plain}"
	else
		echo -e "${red}Failed to disable panel auto-start on system boot.${plain}"
	fi

	if [[ $# == 0 ]]; then
		before_show_menu
	fi
}

show_log() {
	journalctl -u XMPlusPanel.service -e --no-pager -f
	if [[ $# == 0 ]]; then
		before_show_menu
	fi
}

update() {
	echo -e "${yellow}Pulling latest Docker images...${plain}"
	cd /home/XMPlusPanel
	docker compose pull
	if [[ $? == 0 ]]; then
		docker compose up -d
		remove_dangling_images
		echo -e "${green}Panel updated and restarted successfully.${plain}"
	else
		echo -e "${red}Failed to pull latest images. Please check your network or image registry.${plain}"
	fi

	if [[ $# == 0 ]]; then
		before_show_menu
	fi
}

config() {
	if [[ -f /home/XMPlusPanel/.env ]]; then
		echo -e "${green}Current configuration (/home/XMPlusPanel/.env):${plain}"
		echo "--------------------------------------------"
		cat /home/XMPlusPanel/.env
		echo "--------------------------------------------"
	else
		echo -e "${red}Configuration file not found at /home/XMPlusPanel/.env${plain}"
	fi

	if [[ $# == 0 ]]; then
		before_show_menu
	fi
}

confirm() {
	if [[ $# > 1 ]]; then
		echo && read -p "$1 [${2}]: " temp
		if [[ x"${temp}" == x"" ]]; then
			temp=${2}
		fi
	else
		echo && read -p "$1 [y/n]: " temp
	fi
	if [[ x"${temp}" == x"y" || x"${temp}" == x"Y" ]]; then
		return 0
	else
		return 1
	fi
}

before_show_menu() {
	echo && echo -n -e "${yellow}Press enter to return to the main menu: ${plain}" && read temp
	show_menu
}

check_enabled() {
	temp=$(systemctl is-enabled XMPlusPanel)
	if [[ x"${temp}" == x"enabled" ]]; then
		return 0
	else
		return 1
	fi
}

show_status() {
	check_status
	case $? in
		0)
			echo -e "Panel Status: ${green}Running${plain}"
			show_enable_status
			;;
		1)
			echo -e "Panel Status: ${yellow}Not Running${plain}"
			show_enable_status
			;;
		2)
			echo -e "Panel Status: ${red}Not Installed${plain}"
	esac
}

show_enable_status() {
	check_enabled
	if [[ $? == 0 ]]; then
		echo -e "Automatically start on boot: ${green}Yes${plain}"
	else
		echo -e "Automatically start on boot: ${red}No${plain}"
	fi
}

show_menu() {
	echo -e "
  ${green}XMPlus Panel Management${plain}

————————————————
  ${green}0.${plain} Show Configuration
  ${green}1.${plain} Update Panel
  ${green}2.${plain} Uninstall Panel
————————————————
  ${green}3.${plain} Start Panel
  ${green}4.${plain} Stop Panel
  ${green}5.${plain} Restart Panel
  ${green}6.${plain} View Panel Status
  ${green}7.${plain} View Panel Log
————————————————
  ${green}8.${plain} Enable Panel Auto-Start
  ${green}9.${plain} Disable Panel Auto-Start
————————————————
  ${green}10.${plain} View API Docker Logs
  ${green}11.${plain} View UI Docker Logs
  ${green}12.${plain} View MariaDB Docker Logs
  ${green}13.${plain} View Redis Docker Logs
  ${green}14.${plain} View Nginx Proxy Manager Docker Logs
————————————————
 "
	show_status
	echo && read -p "Please enter selection [0-14]: " num

	case "${num}" in
		0) config
		;;
		1) check_install && update
		;;
		2) check_install && uninstall
		;;
		3) check_install && start
		;;
		4) check_install && stop
		;;
		5) check_install && restart
		;;
		6) check_install && status
		;;
		7) check_install && show_log
		;;
		8) check_install && enable
		;;
		9) check_install && disable
		;;
		10) check_install && api
		;;
		11) check_install && ui
		;;
		12) check_install && mariadb
		;;
		13) check_install && redis
		;;
		14) check_install && npm
		;;
		*) echo -e "${red}Please enter the correct number [0-14]${plain}"
		;;
	esac
}

# Entry point — handle CLI args or show interactive menu
case "$1" in
	start)         check_install 1 && start 1 ;;
	stop)          check_install 1 && stop 1 ;;
	restart)       check_install 1 && restart 1 ;;
	status)        check_install 1 && status 1 ;;
	enable)        check_install 1 && enable 1 ;;
	disable)       check_install 1 && disable 1 ;;
	log)           check_install 1 && show_log 1 ;;
	update)        check_install 1 && update 1 ;;
	config)        config 1 ;;
	uninstall)     check_install 1 && uninstall 1 ;;
	api)           check_install 1 && api 1 ;;
	ui)            check_install 1 && ui 1 ;;
	redis)         check_install 1 && redis 1 ;;
	mariadb)       check_install 1 && mariadb 1 ;;
	npm)           check_install 1 && npm 1 ;;
	*)             show_menu ;;
esac