#!/bin/bash
sudo apt-get update
sudo apt-get upgrade
sudo apt-get install python-httplib2 sshpass python-yaml python-jinja2 python-paramiko python-software-properties
sudo apt-add-repository ppa:ansible/ansible -y
sudo apt-get update
sudo apt-get upgrade

sudo apt-get install software-properties-common python-pip
sudo apt-get install ansible

