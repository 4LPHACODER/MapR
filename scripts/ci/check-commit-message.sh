#!/usr/bin/env bash
set -euo pipefail

pr_title=false
if [ "${1:-}" = --pr-title ]; then
    pr_title=true
    shift
fi

if [ "$#" -ne 1 ] || [[ $1 == -* ]] || [ ! -f "$1" ]; then
    echo "usage: $0 [--pr-title] <commit-message-file>" >&2
    exit 2
fi

. "$(dirname "$0")/change-types.sh"

subject=$({ grep -v '^#' "$1" || true; } | sed -n '/[^[:space:]]/{p;q;}')

if [ "$pr_title" = false ]; then
    case "$subject" in
        'Merge '* | 'Revert "'*) exit 0 ;;
    esac
fi

if [[ ${#subject} -le 72 && $subject =~ ^($CHANGE_TYPES)/[a-z0-9](.*[^.[:space:]])?$ ]]; then
    exit 0
fi

label='commit message'
if [ "$pr_title" = true ]; then
    label='pull request title'
fi

cat >&2 <<MSG
Invalid $label: "$subject"

Use <type>/<description>: lowercase start, at most 72 characters, no trailing period.
Types: ${CHANGE_TYPES//|/, }
Example: feature/add resident export to cdrrmo dashboard
MSG
exit 1
