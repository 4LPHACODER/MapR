#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 1 ] || [ ! -f "$1" ]; then
    echo "usage: $0 <commit-message-file>" >&2
    exit 2
fi

. "$(dirname "$0")/change-types.sh"

subject=$({ grep -v '^#' "$1" || true; } | sed -n '/[^[:space:]]/{p;q;}')

case "$subject" in
    'Merge '* | 'Revert "'*) exit 0 ;;
esac

if [[ ${#subject} -le 72 && $subject =~ ^($CHANGE_TYPES)/[a-z0-9](.*[^.[:space:]])?$ ]]; then
    exit 0
fi

cat >&2 <<MSG
Invalid commit message: "$subject"

Use <type>/<description>: lowercase start, at most 72 characters, no trailing period.
Types: ${CHANGE_TYPES//|/, }
Example: feature/add resident export to cdrrmo dashboard
MSG
exit 1
