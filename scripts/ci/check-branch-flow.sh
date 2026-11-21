#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 2 ]; then
    echo "usage: $0 <base-branch> <head-branch>" >&2
    exit 2
fi

. "$(dirname "$0")/change-types.sh"

base=$1
head=$2

case "$base" in
    develop)
        [[ $head =~ ^($CHANGE_TYPES)/[a-z0-9][a-z0-9._-]*$ ]] && exit 0
        echo "Pull requests into develop must come from a <type>/<name> branch (types: ${CHANGE_TYPES//|/, }); got '$head'." >&2
        ;;
    testing)
        [ "$head" = develop ] && exit 0
        echo "Pull requests into testing must come from develop; got '$head'." >&2
        ;;
    main)
        [ "$head" = testing ] && exit 0
        echo "Pull requests into main must come from testing; got '$head'." >&2
        ;;
    *)
        exit 0
        ;;
esac
exit 1
