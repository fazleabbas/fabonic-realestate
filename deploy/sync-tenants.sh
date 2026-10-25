#!/bin/bash
#
# FIS Real Estate — push tenant-side code to the template and every live tenant.
#
# Tenant-side code (includes/, modules/, api/, lang/, uploads/.htaccess) runs from
# each tenant's OWN copy under public_html/re_tenants/<slug>/. Uploading only to
# public_html changes nothing for existing tenants — this script is the fan-out.
#
# Usage:
#   ./sync-tenants.sh <file> [file...]      paths relative to the app root
#   ./sync-tenants.sh --dry-run <file>      show what would happen, change nothing
#   ./sync-tenants.sh --from-file list.txt  read the file list from a text file
#
# Example:
#   ./sync-tenants.sh api/file.php includes/functions.php lang/en.php
#
# Source of truth is the TEMPLATE, so upload your changed files there first.
#
set -u

HOME_DIR="/home/realcotz"
TEMPLATE="$HOME_DIR/re_tenant_template"
TENANTS="$HOME_DIR/public_html/re_tenants"
MASTER="$HOME_DIR/public_html"

DRY=0
FILES=()

while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run) DRY=1; shift ;;
        --from-file)
            [ -f "${2:-}" ] || { echo "list file not found: ${2:-}" >&2; exit 1; }
            while read -r line; do
                [ -n "$line" ] && [ "${line#\#}" = "$line" ] && FILES+=("$line")
            done < "$2"
            shift 2 ;;
        -*) echo "unknown option: $1" >&2; exit 1 ;;
        *) FILES+=("$1"); shift ;;
    esac
done

if [ ${#FILES[@]} -eq 0 ]; then
    echo "usage: $0 [--dry-run] <file> [file...]" >&2
    exit 1
fi

[ -d "$TEMPLATE" ] || { echo "template not found: $TEMPLATE" >&2; exit 1; }
[ -d "$TENANTS" ]  || { echo "tenants dir not found: $TENANTS" >&2; exit 1; }

# Never let this script touch per-tenant configuration or uploaded data.
# The .htaccess guards inside uploads/ are code, not data, so those are allowed.
for f in "${FILES[@]}"; do
    case "$f" in
        /*|*..*)
            echo "REFUSING '$f' — paths must be relative to the app root." >&2
            exit 1 ;;
        config/*)
            echo "REFUSING '$f' — per-tenant config must stay local." >&2
            exit 1 ;;
        uploads/*)
            case "$f" in
                */.htaccess) ;;
                *)  echo "REFUSING '$f' — uploaded data must stay local." >&2
                    exit 1 ;;
            esac ;;
    esac
done

# Every file must exist in the template: that is what we are propagating.
missing=0
for f in "${FILES[@]}"; do
    if [ ! -f "$TEMPLATE/$f" ]; then
        echo "MISSING in template: $f  (upload it to $TEMPLATE first)" >&2
        missing=1
    fi
done
[ $missing -eq 0 ] || exit 1

[ $DRY -eq 1 ] && echo "=== DRY RUN — nothing will be written ==="
echo "Syncing ${#FILES[@]} file(s) from $TEMPLATE"
echo

targets=()
for d in "$TENANTS"/*/; do
    [ -d "$d" ] && targets+=("${d%/}")
done
targets+=("$MASTER")

if [ ${#targets[@]} -eq 1 ]; then
    echo "No tenant directories found under $TENANTS" >&2
fi

errors=0
for target in "${targets[@]}"; do
    name=$(basename "$target")
    [ "$target" = "$MASTER" ] && name="public_html (master)"
    echo "--> $name"

    for f in "${FILES[@]}"; do
        dest="$target/$f"
        destdir=$(dirname "$dest")

        if [ $DRY -eq 1 ]; then
            if [ -f "$dest" ] && cmp -s "$TEMPLATE/$f" "$dest"; then
                echo "    same      $f"
            elif [ -f "$dest" ]; then
                echo "    WOULD UPDATE $f"
            else
                echo "    WOULD CREATE $f"
            fi
            continue
        fi

        mkdir -p "$destdir" || { errors=$((errors+1)); continue; }
        if cp -f "$TEMPLATE/$f" "$dest"; then
            echo "    ok        $f"
        else
            echo "    FAILED    $f" >&2
            errors=$((errors+1))
        fi
    done
done

echo
if [ $DRY -eq 1 ]; then
    echo "Dry run complete — re-run without --dry-run to apply."
elif [ $errors -eq 0 ]; then
    echo "Done: ${#FILES[@]} file(s) synced to ${#targets[@]} location(s)."
else
    echo "Finished with $errors error(s)." >&2
    exit 1
fi
