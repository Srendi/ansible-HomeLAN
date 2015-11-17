#!/bin/sh
#set -x  ## uncomment for a trace

#   Create a setip net:hash to include in the iptabels firewall
/sbin/ipset create blackholenets hash:net hashsize 1048000 maxelem 2000000
RETVAL=$?
[ $RETVAL -eq 0 ] && {

#Success so...
# Massage the cidr filter files...
/bin/cat /var/www/html/BlockLists/*.txt | /usr/bin/sort | /usr/bin/uniq | while read address; do

#And add them to the ipset
  /sbin/ipset add blackholenets "$address"
done
echo ipset blackholenets first created and  populated

#Cleaning
}
# The set already existed.
[ $RETVAL -ne 0 ] && {
/sbin/ipset destroy blackholenets2
/sbin/ipset create blackholenets2 hash:net hashsize 1048000 maxelem 2000000

# Massage the cidr filter files...
/bin/cat /var/www/html/BlockLists/*.txt | /usr/bin/sort | /usr/bin/uniq | while read address2; do

#And add them to the ipset
  /sbin/ipset add blackholenets2 "$address2"
done
  /sbin/ipset swap blackholenets2 blackholenets
  /sbin/ipset destroy blackholenets2
  echo ipset blackholenets2 created populated and swapped to blackholenets
}



