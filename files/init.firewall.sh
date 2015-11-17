#!/bin/bash

# Configure kernel
echo 1 > /proc/sys/net/ipv4/tcp_syncookies                          # enable syn cookies (prevent against the common 'syn flood attack')
echo 0 > /proc/sys/net/ipv4/ip_forward                              # disable Packet forwarning between interfaces
echo 1 > /proc/sys/net/ipv4/icmp_echo_ignore_broadcasts             # ignore all ICMP ECHO and TIMESTAMP requests sent to it via broadcast/multicast
echo 1 > /proc/sys/net/ipv4/conf/all/log_martians                   # log packets with impossible addresses to kernel log
echo 1 > /proc/sys/net/ipv4/icmp_ignore_bogus_error_responses       # disable logging of bogus responses to broadcast frames
echo 1 > /proc/sys/net/ipv4/conf/all/rp_filter                      # do source validation by reversed path (Recommended option for single homed hosts)
echo 0 > /proc/sys/net/ipv4/conf/all/send_redirects                 # don't send redirects
echo 0 > /proc/sys/net/ipv4/conf/all/accept_source_route            # don't accept packets with SRR option

#

