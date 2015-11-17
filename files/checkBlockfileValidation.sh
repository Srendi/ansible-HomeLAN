#!/bin/sh
#set -x  ## uncomment for a trace

VALIDITY_CHECK=`\
  /bin/cat "$1" 2>/dev/null | \
    /usr/bin/sed -E -e '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/d' \
             -e '/^$/d'`

if test ! -s "$1"
then
  echo "The file $1 either does not exist or is empty"
  echo "Validity check failed"
elif test "$VALIDITY_CHECK" != ""
then
  echo '$VALIDITY_CHECK result is '"'"$VALIDITY_CHECK"'"
  echo "Validity check failed"
else
  echo "Validity check passed"
fi