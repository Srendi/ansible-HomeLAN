#!/bin/sh
#set -x  ## uncomment for a trace

# gets various IPv4 blocking lists and conditions them for retrieval/use to
# create an alias containing the IPs (in CIDR format) via a URL in pfSense

#===============================================================================
# the following items need to be set to match your setup conditions
# (ideally, these would be in some configuration file or setup mechanism)

FSNUM=6  # the number of split ForumSpammers#.txt files expected to be generated

# a comma-separated list of email addresses to be sent notifications
EMAIL_RECIPIENTS='Just Me <srendi@gmail.com>'

# location where retrieved/processed files are placed, before being validated
# and moved to the web server
FILESDIR='/var/BlockLists'
# NOTE: the directory (MyDir, in this example) must be manually created

# location to place processed block-list files to be retrieved via web page
SERVERDIR='/var/www/html/BlockLists'
# NOTE: also create a directory/folder named "Previous" inside SERVERDIR -- this
#       is used to store the previous version of the block-list files and can be
#       useful if a block-list supplying site has certain problems that causes a
#       reduced set of entries to be generated

# location of the IPrangeToCIDRfromSTDIN.php script
CIDRDIR='/usr/local/bin'

# location of the log file that's updated by this process
LOGFILE='/var/log/getRemoteBlockLists.log'

# an unused private address that can be used as a "placeholder" to avoid having
# an empty file served if the number of split files becomes fewer than currently
# set -- this allows the pfSense URL Alias and corresponding rules to remain
# "working" until they're manually removed (otherwise, things can break)
UNUSEDADDR='198.51.100.255/32'
#===============================================================================

# the name of this running process
SNAME=`/usr/bin/basename $0`

MESSAGE_END="\n\nValidator: checkBlockfileValidation.sh\nLogfile: $LOGFILE"
MESSAGE=''

dateTime()
{
/bin/date '+%Y-%m-%d %H:%M:%S : '$SNAME' : '
}

# helps prevent "sed: RE error: illegal byte sequence" errors (at least in OS X)
LC_CTYPE=C

# ------------------------------------------------------------------------------
# get public block-list files

# for each block-list file, the strategy is:
# - retrieve the file and process it by piping the file contents through various
#   processing utilities to, as required :
#   * unzip
#   * remove content other than the IP address information
#   * change IP addresses to CIDR format
#   * remove emply lines
#   * sort the entries by IP address
# - test the resulting list to ensure that all entries appear to be valid:
#   * if the list does not appear to be valid, send an email and skip the file
#   * if the list appears to be valid, move it into position and set the
#     permissions so the block-list file can be served by the web server
# - log errors to the LOGFILE

# for lists that are not too long (i.e., always less than about 70K entries),
# the code/script is simple -- here's an annotated example:
#
# set the name of the block-list file
# FILENAME='BluetackAdsList'
#
# start the logging for this item
# echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
#
# fetch the file and log any error information
# /usr/bin/curl -LsS \
#   "http://list.iblocklist.com/?list=bt_ads&fileformat=p2p&archiveformat=gz" \
#   2>>$LOGFILE \
#
# unzip the file -- if the file's not in gz format, eliminate this line
#   | /bin/gunzip \
#
# use a collection of sed filters to:
# - remove comment lines: -e '/^#/d'
# - remove blank lines : -e '/^$/d'
# - remove non-IP content: -e 's/^.*://'
#   * this is for a file where each entry has the format:
#     some text info:IP-address info
#   * this filter will need to be adjusted for different formats -- e.g., if the
#     format is:
#     IP-address info:some text info
#     then the filter would be:
#     -e 's/:.*$//'
# - remove the IP-range dash-separator character: -e 's/\-/ /'
#   * this is for a file where each entry has the IP information as a range:
#     1.2.3.4 - 6.7.8.9
#     or
#     1.2.3.4-6.7.8.9
# - if the IP-address information is not a range, eliminate this filter
#   | /bin/sed -e '/^#/d' -e '/^$/d' -e 's/^.*://' -e 's/\-/ /' \
#
# convert the IP-address information into CIDR format -- if it's already in CIDR
# format, eliminate this line
#   | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
#
# use a sed filter to remove any resulting blank lines : -e '/^$/d'
#   | /bin/sed -e '/^$/d' \
#
# sort the block-file entries so they're in increasing IP-address order
#   | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
#
# save the processed block-list file
#   > /tmp/${FILENAME}.txt-$$
#
# if the block-list file appears to be valid:
# - the processed temp-file exists and ...
# if test -s /tmp/${FILENAME}.txt-$$ -a \
# - when the block-file's entries are filtered ...
#         "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
#   to remove all entries that look like:
#   * starting at the beginning of the line: ^
#   * three sets of one or more digits followed by a dot: [0-9]+\.
#   * followed by a slash followed by one or more digits: \/[0-9]+
#   * followed by an end-of-line: $
#           /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
#   and any blank lines are removed: -e '/^$/d'
#   the result is empty: = ""
#                     -e '/^$/d'`" = ""
#
# then
# file is OK, so ...
# move the current block-list file to a previous/save location
#   /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
#
# move the processed block-list file to its web-server location
#   /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
#
# set the ownership and permissions on the web-server's block-list file
#   /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
#   /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
#
# else
# file is not OK so accumulate a message string that'll be sent via an email
#   MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
#
# log an explanatory message
#   echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
# fi
#
# end the logging for this block-list file
# echo "" >> $LOGFILE


FILENAME='BluetackAdsList'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS \
  "http://list.iblocklist.com/?list=bt_ads&fileformat=p2p&archiveformat=gz" \
  2>>$LOGFILE \
  | /bin/gunzip \
  | /bin/sed -e '/^#/d' -e '/^$/d' -e 's/^.*://' -e 's/\-/ /' \
  | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
  | /bin/sed -e '/^$/d' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > /tmp/${FILENAME}.txt-$$
#
if test -s /tmp/${FILENAME}.txt-$$ -a \
        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" = ""
then
  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
else
  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
fi
#
echo "" >> $LOGFILE


FILENAME='iBlockPedoList'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS \
  "http://list.iblocklist.com/?list=dufcxgnbjsdwmwctgfuj&fileformat=p2p&archiveformat=gz" \
  2>>$LOGFILE \
  | /bin/gunzip \
  | /bin/sed -e '/^#/d' -e '/^$/d' -e 's/^.*://' -e 's/\-/ /' \
  | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
  | /bin/sed -e '/^$/d' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > /tmp/${FILENAME}.txt-$$
#
if test -s /tmp/${FILENAME}.txt-$$ -a \
        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" = ""
then
  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
else
  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
fi
#
echo "" >> $LOGFILE


FILENAME='BluetackL1P2PList'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS \
  "http://list.iblocklist.com/lists/bluetack/level-1" \
  2>>$LOGFILE \
  | /bin/gunzip \
  | /bin/sed -e '/^#/d' -e '/^$/d' -e 's/^.*://' -e 's/\-/ /' \
  | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
  | /bin/sed -e '/^$/d' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > /tmp/${FILENAME}.txt-$$
#
if test -s /tmp/${FILENAME}.txt-$$ -a \
        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" = ""
then
  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
else
  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
fi
#
echo "" >> $LOGFILE


#FILENAME='BluetackL2P2PList'
#echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
#/usr/bin/curl -LsS \
#  "http://list.iblocklist.com/?list=gyisgnzbhppbvsphucsw&fileformat=p2p&archiveformat=gz" \
#  2>>$LOGFILE \
#  | /bin/gunzip \
#  | /bin/sed -e '/^#/d' -e '/^$/d' -e 's/^.*://' -e 's/\-/ /' \
#  | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
#  | /bin/sed -e '/^$/d' \
#  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
#  > /tmp/${FILENAME}.txt-$$
#
#if test -s /tmp/${FILENAME}.txt-$$ -a \
#        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
#          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
#                    -e '/^$/d'`" = ""
#then
#  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
#  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
#  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
#  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
#else
#  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
#  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
#fi
#
#echo "" >> $LOGFILE


FILENAME='BluetackFspammerList'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS \
  "http://list.iblocklist.com/?list=ficutxiwawokxlcyoeye&fileformat=p2p&archiveformat=gz" \
  2>>$LOGFILE \
  | /bin/gunzip \
  | /bin/sed -e '/^#/d' -e '/^$/d' -e 's/^.*://' -e 's/\-/ /' \
  | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
  | /bin/sed -e '/^$/d' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > /tmp/${FILENAME}.txt-$$
#
if test -s /tmp/${FILENAME}.txt-$$ -a \
        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" = ""
then
  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
else
  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
fi
#
echo "" >> $LOGFILE


FILENAME='BluetackHijackedList'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS \
  "http://list.iblocklist.com/?list=bt_hijacked&fileformat=p2p&archiveformat=gz" \
  2>>$LOGFILE \
  | /bin/gunzip \
  | /bin/sed -e '/^#/d' -e '/^$/d' -e 's/^.*://' -e 's/\-/ /' \
  | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
  | /bin/sed -e '/^$/d' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > /tmp/${FILENAME}.txt-$$
#
if test -s /tmp/${FILENAME}.txt-$$ -a \
        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" = ""
then
  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
else
  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
fi
#
echo "" >> $LOGFILE


FILENAME='BluetackShieldList'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS \
  "http://list.iblocklist.com/?list=bt_dshield&fileformat=p2p&archiveformat=gz" \
  2>>$LOGFILE \
  | /bin/gunzip \
  | /bin/sed -e '/^#/d' -e '/^$/d' -e 's/^.*://' -e 's/\-/ /' \
  | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
  | /bin/sed -e '/^$/d' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > /tmp/${FILENAME}.txt-$$
#
if test -s /tmp/${FILENAME}.txt-$$ -a \
        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" = ""
then
  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
else
  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
fi
#
echo "" >> $LOGFILE


FILENAME='BluetackSpiderList'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS \
  "http://list.iblocklist.com/?list=bt_spider&fileformat=p2p&archiveformat=gz" \
  2>>$LOGFILE \
  | /bin/gunzip \
  | /bin/sed -e '/^#/d' -e '/^$/d' -e 's/^.*://' -e 's/\-/ /' \
  | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
  | /bin/sed -e '/^$/d' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > /tmp/${FILENAME}.txt-$$
#
if test -s /tmp/${FILENAME}.txt-$$ -a \
        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" = ""
then
  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
else
  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
fi
#
echo "" >> $LOGFILE


FILENAME='BluetackSpywareList'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS \
  "http://list.iblocklist.com/?list=bt_spyware&fileformat=p2p&archiveformat=gz" \
  2>>$LOGFILE \
  | /bin/gunzip \
  | /bin/sed -e '/^#/d' -e '/^$/d' -e 's/^.*://' -e 's/\-/ /' \
  | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
  | /bin/sed -e '/^$/d' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > /tmp/${FILENAME}.txt-$$
#
if test -s /tmp/${FILENAME}.txt-$$ -a \
        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" = ""
then
  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
else
  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
fi
#
echo "" >> $LOGFILE


FILENAME='BluetackWebexploitList'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS \
  "http://list.iblocklist.com/?list=ghlzqtqxnzctvvajwwag&fileformat=p2p&archiveformat=gz" \
  2>>$LOGFILE \
  | /bin/gunzip \
  | /bin/sed -e '/^#/d' -e '/^$/d' -e 's/^.*://' -e 's/\-/ /' \
  | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
  | /bin/sed -e '/^$/d' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > /tmp/${FILENAME}.txt-$$
#
if test -s /tmp/${FILENAME}.txt-$$ -a \
        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" = ""
then
  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
else
  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
fi
#
echo "" >> $LOGFILE


FILENAME='CI-ArmySentinelList'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS \
  "http://list.iblocklist.com/?list=npkuuhuxcsllnhoamkvm&fileformat=p2p&archiveformat=gz" \
  2>>$LOGFILE \
  | /bin/gunzip \
  | /bin/sed -e '/^#/d' -e '/^$/d' -e 's/^.*://' -e 's/\-/ /' \
  | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
  | /bin/sed -e '/^$/d' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > /tmp/${FILENAME}.txt-$$
#
if test -s /tmp/${FILENAME}.txt-$$ -a \
        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" = ""
then
  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
else
  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
fi
#
echo "" >> $LOGFILE


FILENAME='MalcodeList'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS \
  "http://list.iblocklist.com/?list=pbqcylkejciyhmwttify&fileformat=p2p&archiveformat=gz" \
  2>>$LOGFILE \
  | /bin/gunzip \
  | /bin/sed -e '/^#/d' -e '/^$/d' -e 's/^.*://' -e 's/\-/ /' \
  | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
  | /bin/sed -e '/^$/d' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > /tmp/${FILENAME}.txt-$$
#
if test -s /tmp/${FILENAME}.txt-$$ -a \
        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" = ""
then
  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
else
  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
fi
#
echo "" >> $LOGFILE


FILENAME='DDOS-canList'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS \
  "http://list.iblocklist.com/?list=czvaehmjpsnwwttrdoyl&fileformat=p2p&archiveformat=gz" \
  2>>$LOGFILE \
  | /bin/gunzip \
  | /bin/sed -e '/^#/d' -e '/^$/d' -e 's/^.*://' -e 's/\-/ /' \
  | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
  | /bin/sed -e '/^$/d' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > /tmp/${FILENAME}.txt-$$
#
if test -s /tmp/${FILENAME}.txt-$$ -a \
        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" = ""
then
  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
else
  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
fi
#
echo "" >> $LOGFILE


FILENAME='SpamhausDROPlist'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS "http://www.spamhaus.org/drop/drop.txt" \
  2>>$LOGFILE \
  | /bin/sed -e '/^;/d' -e '/^$/d' -e 's/ ; .*$//' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > /tmp/${FILENAME}.txt-$$
#
if test -s /tmp/${FILENAME}.txt-$$ -a \
        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" = ""
then
  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
else
  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
fi
#
echo "" >> $LOGFILE


FILENAME='SpamhausEDROPlist'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS "http://www.spamhaus.org/drop/edrop.txt" \
  2>>$LOGFILE \
  | /bin/sed -e '/^;/d' -e '/^$/d' -e 's/ ; .*$//' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > /tmp/${FILENAME}.txt-$$
#
if test -s /tmp/${FILENAME}.txt-$$ -a \
        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" = ""
then
  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
else
  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
fi
#
echo "" >> $LOGFILE


# a section of this list is removed (via: sed -e '/Spamhaus DROP/, /Dshield/d')
# because that section is a list we've already retrieved from Spamhaus
FILENAME='EmergingThreatsList'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS "http://rules.emergingthreats.net/fwrules/emerging-Block-IPs.txt" \
  2>>$LOGFILE \
  | /bin/sed -e '/^#/d' -e '/^$/d' \
  | /bin/sed -e '/Spamhaus DROP/, /Dshield/d' \
  | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
  | /bin/sed -e '/^$/d' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > /tmp/${FILENAME}.txt-$$
#
if test -s /tmp/${FILENAME}.txt-$$ -a \
        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" = ""
then
  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
else
  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
fi
#
echo "" >> $LOGFILE


FILENAME='ForumSpammerNets'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS "http://www.stopforumspam.com/downloads/toxic_ip_cidr.txt" \
  2>>$LOGFILE \
  | /bin/sed -e '/^#/d' -e '/^$/d' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > /tmp/${FILENAME}.txt-$$
#
if test -s /tmp/${FILENAME}.txt-$$ -a \
        "`/bin/cat /tmp/${FILENAME}.txt-$$ | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" = ""
then
  /bin/mv -f ${SERVERDIR}/${FILENAME}.txt ${SERVERDIR}/Previous/${FILENAME}.txt
  /bin/mv -f /tmp/${FILENAME}.txt-$$ ${SERVERDIR}/${FILENAME}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}.txt
else
  MESSAGE="${MESSAGE}\n/tmp/${FILENAME}.txt-$$ did not validate & was skipped"
  echo "/tmp/${FILENAME}.txt-$$ did not validate -- update skipped" >> $LOGFILE
fi
#
echo "" >> $LOGFILE


# ------------------------------------------------------------------------------
# process the list file from stopforumspam.com

# this file is a list containing hundreds of thousands of entries so, after
# being retrieved and processed the same way other files are processed, it
# undergoes more processing to split it into multiple files where each contains
# a maximum of 70K entries -- this is because the pfSense web browser will not
# display the entries for a table if it contains much more than 70K entries

FILENAME='ForumSpammers'
echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
/usr/bin/curl -LsS "http://www.stopforumspam.com/downloads/bannedips.zip" \
  2>>$LOGFILE \
  | /bin/gunzip \
  | /bin/sed -e '/^#/d' -e '/^$/d' \
  | /usr/bin/tr ',' '\n' \
  | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
  | /bin/sed -e '/^$/d' \
  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
  > ${FILESDIR}/${FILENAME}.txt
#
#FILENAME='ForumSpammers'
#echo `dateTime`"Getting $FILENAME ..." >> $LOGFILE
#/usr/bin/curl -LsS "http://www.stopforumspam.com/downloads/listed_ip_365.zip" \
#  2>>$LOGFILE \
#  | /bin/gunzip \
#  | /bin/sed -e '/^#/d' -e '/^$/d' \
#  | $CIDRDIR/IPrangeToCIDRfromSTDIN.php \
#  | /bin/sed -e '/^$/d' \
#  | /usr/bin/sort -u -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4 \
#  > ${FILESDIR}/${FILENAME}.txt
#
if test ! -s ${FILESDIR}/${FILENAME}.txt -o \
        "`/bin/cat ${FILESDIR}/${FILENAME}.txt | \
          /bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
                    -e '/^$/d'`" != ""
then
  MESSAGE="${MESSAGE}\n${FILESDIR}/${FILENAME}.txt did not validate (exited)"
  echo "${FILESDIR}/${FILENAME}.txt did not validate (exited)" >> $LOGFILE
  echo "" >> $LOGFILE
  echo "From $0:\n---${MESSAGE}${MESSAGE_END}" | \
    /usr/bin/mail -nN -s 'Remote Blocklist Update Problem(s)' \
                  "$EMAIL_RECIPIENTS"
  exit 1
fi

# ------------------------------------------------------------------------------
# generate split ForumSpammers#.txt files

# now split the file into a collection of files each containing a maxumum of 70K
# entries
echo `dateTime`"Generating split ${FILENAME}#.txt files ..." >> $LOGFILE
/usr/bin/split -a 1 -l 70000 \
                ${FILESDIR}/${FILENAME}.txt /tmp/${FILENAME} 2>>$LOGFILE

# if there's a problem, log an expanatory message and send an email
if test $? -ne 0
then
  MESSAGE="${MESSAGE}\nCould not split the $FILENAME file (exited)"
  echo "Could not split the $FILENAME file (exited)" >> $LOGFILE
  echo "From $0:\n---${MESSAGE}${MESSAGE_END}" | \
    /usr/bin/mail -nN -s 'Remote Blocklist Update Problem(s)' \
                  "$EMAIL_RECIPIENTS"
  exit 1
fi

# ensure we're still getting the number of split files that are expected --
#  i.e., that's set as the FSNUM variable, above
COUNT=0

for FILE in `ls /tmp/${FILENAME}[a-z]`
do
  COUNT=`expr $COUNT + 1`
  /bin/mv -f ${SERVERDIR}/${FILENAME}${COUNT}.txt \
                      ${SERVERDIR}/Previous/${FILENAME}${COUNT}.txt
  /bin/mv -f $FILE ${SERVERDIR}/${FILENAME}${COUNT}.txt
  /bin/chown www-data:www-data ${SERVERDIR}/${FILENAME}${COUNT}.txt
  /bin/chmod 660 ${SERVERDIR}/${FILENAME}${COUNT}.txt
done

# send an email if a different number of split files is being generated
if test $COUNT -ne $FSNUM
then
  MESSAGE="${MESSAGE}\n$COUNT $FILENAME files were generated (set for $FSNUM)"
  echo "$COUNT $FILENAME files were generated via $0 (set for $FSNUM)" >> $LOGFILE
fi

# if there are now fewer files being generated than expected, "empty" the
# previous (now-unused) files being served by the web server (because pfSense
# rule-defining URLs likely still exist for these files) and log and assemble an
# explanatory message -- to "empty" the file(s), a single unused private IP
# address entry ($UNUSEDADDR) is inserted into the file
if test $COUNT -lt $FSNUM
then
  COUNT=`/bin/expr $COUNT + 1`

  while (test $COUNT -le $FSNUM)
  do
    echo $UNUSEDADDR > ${SERVERDIR}/${FILENAME}${COUNT}.txt  # placeholder
    MESSAGE="${MESSAGE}\nEmptied ${SERVERDIR}/${FILENAME}${COUNT}.txt"
    echo "Emptied ${SERVERDIR}/${FILENAME}${COUNT}.txt" >> $LOGFILE
    COUNT=`/bin/expr $COUNT + 1`
  done
fi

echo "" >> $LOGFILE

# ------------------------------------------------------------------------------
# send an email if there were any accumulated explanatory messages
if test "$MESSAGE" != ""
then
  echo "From $0:\n---${MESSAGE}${MESSAGE_END}" | \
    /usr/bin/mail -nN -s 'Remote Blocklist Update Problem(s)' \
                  "$EMAIL_RECIPIENTS"
else
  /bin/rm -f /tmp/${FILENAME1}.txt-$$ /tmp/${FILENAME2}.txt-$$ \
          /tmp/${FILENAME3}.txt-$$ /tmp/${FILENAME}.txt
fi
