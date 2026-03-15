#!/bin/bash
  
red='\033[0;31m'
green='\033[0;32m'
yellow='\033[0;33m'
plain='\033[0m'

# check root
[[ $EUID -ne 0 ]] && echo -e "${red}Error: ${plain} This script must be run with the root user！\n" && exit 1

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

api() {
	check_status
	if [[ $? == 1 ]]; then
		cd /home/XMPlusPanel
		docker compose logs -f api
	else
		echo -e "${red}Unable to tail API logs${plain}"
	fi

    if [[ $# == 0 ]]; then
        before_show_menu
    fi
}

ui() {
	check_status
	if [[ $? == 1 ]]; then
		cd /home/XMPlusPanel
		docker compose logs -f ui
	else
		echo -e "${red}Unable to tail UI logs${plain}"
	fi

    if [[ $# == 0 ]]; then
        before_show_menu
    fi
}

mariadb() {
	check_status
	if [[ $? == 1 ]]; then
		cd /home/XMPlusPanel
		docker compose logs -f mariadb
	else
		echo -e "${red}Unable to tail Mariadb logs${plain}"
	fi

    if [[ $# == 0 ]]; then
        before_show_menu
    fi
}

redis() {
	check_status
	if [[ $? == 1 ]]; then
		cd /home/XMPlusPanel
		docker compose logs -f redis
	else
		echo -e "${red}Unable to tail redis logs${plain}"
	fi

    if [[ $# == 0 ]]; then
        before_show_menu
    fi
}

npm() {
	check_status
	if [[ $? == 1 ]]; then
		cd /home/XMPlusPanel
		docker compose logs -f npm
	else
		echo -e "${red}Unable to tail npm logs${plain}"
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
	if [ -e "/etc/systemd/system/" ] ; then
		systemctl stop XMPlusPanel
		systemctl disable XMPlusPanel
		rm /etc/systemd/system/XMPlusPanel.service -f
		systemctl daemon-reload
		systemctl reset-failed
	fi
	
    rm /home/XMPlusPanel/ -rf
	rm -rf /usr/bin/XMPanel -f

    echo ""
    echo -e "${green}Panel successfully disabled and remove. If you want to delete this script ${plain}"
    echo ""

    if [[ $# == 0 ]]; then
        before_show_menu
    fi
}

stop() {
	systemctl stop XMPlusPanel
	sleep 2
	check_status
	if [[ $? == 1 ]]; then
		echo -e "${green}Panel successfully stopped${plain}"
	else
		echo -e "${red}Panel failed to stop, probably because the stop time exceeded two seconds, please check the log information later${plain}"
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
		echo -e "${green}Panel restart is successful, please use `xmpanel log` to view the operation log${plain}"
	else
		echo -e "${red}Panel may fail to start, please use `xmpanel log` to check the log information later${plain}"
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
		echo -e "${green}Auto-start paenl on system boot successful${plain}"
	else
		echo -e "${red}Auto-start paenl on system boot failed${plain}"
	fi

    if [[ $# == 0 ]]; then
        before_show_menu
    fi
}

disable() {
	systemctl disable XMPlusPanel
	if [[ $? == 0 ]]; then
		echo -e "${green}Diable panel auto-start on system boot successfull${plain}"
	else
		echo -e "${red}Diable panel auto-start on system boot failed${plain}"
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

before_show_menu() {
   echo && echo -n -e "${yellow}Press enter to return to the main menu: ${plain} " && read temp
   show_menu
}

check_enabled() {
	temp=$(systemctl is-enabled XMPlusPanel)
	if [[ x"${temp}" == x"enabled" ]]; then
		return 0
	else
		return 1;
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
  ${green}XMPlus Panel Management usage method${plain}

————————————————
  ${green}1.${plain} Update Panel
  ${green}2.${plain} Uninstall Panel
————————————————
  ${green}3.${plain} Start Panel
  ${green}4.${plain} Stop Panel
  ${green}5.${plain} Restart Panel
  ${green}6.${plain} View Panel Status
  ${green}7.${plain} View Panel log
————————————————
  ${green}8.${plain} Enable Panel auto-satrt
  ${green}9.${plain} Disable Panel auto-satrt
————————————————
  ${green}10.${plain} View panel api docker logs
  ${green}11.${plain} View panel ui docker logs
  ${green}12.${plain} View mariadb docker logs
  ${green}13.${plain} View redis docker logs
  ${green}14.${plain} View nginx manager docker logs
————————————————
 "
    show_status
    echo && read -p "Please enter selection [0-13]: " num

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
        *) echo -e "${red}Please enter the correct number [0-9]${plain}"
        ;;
    esac
}