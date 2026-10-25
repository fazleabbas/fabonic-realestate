#!/bin/bash
#
# Fabonic Real Estate — deploy from GitHub to the template, every tenant and
# the master, in the right order. Run from the cPanel Terminal:
#
#   bash ~/repo/deploy/release.sh --check            compare GitHub's code with what is live; changes nothing
#   bash ~/repo/deploy/release.sh --check <file>     show the differences in one file
#   bash ~/repo/deploy/release.sh --first --dry-run  first time: show every file that differs from GitHub
#   bash ~/repo/deploy/release.sh --first            first time: deploy every file that differs, then record
#   bash ~/repo/deploy/release.sh --baseline         first time, if the server already matches: just record
#   bash ~/repo/deploy/release.sh --dry-run          show what the next deploy would do; changes nothing
#   bash ~/repo/deploy/release.sh                    deploy (asks before changing anything)
#
# The order matters and is fixed here: database migration BEFORE code, because
# new code may read columns the migration adds. A failed migration stops the
# deploy before any code goes out.
#
# Never touched: config/ (per-tenant credentials), uploads, backups.
# Master-only files go to the master and nowhere else.
#
set -u
umask 022    # new files must be readable by the web server

REPO="$HOME/repo"
TEMPLATE="$HOME/re_tenant_template"
MASTER="$HOME/public_html"
STATE="$HOME/.fabonic_deployed"

# Live only on the master (public_html): the SaaS admin panel, the public
# website, and the scripts that run the business. A tenant must never get them.
is_master_only() {
    case "$1" in
        admin/*|public/*) return 0 ;;
        api/reset-demo.php|api/run-tenant-crons.php|api/auto-provision-cron.php|api/trial-cron.php) return 0 ;;
        api/saas-admin.php|api/sales-agent.php|api/send-trial-request.php|api/check-slug.php|api/fix-subscription.php) return 0 ;;
    esac
    return 1
}

# Diagnostic scripts kept in git but never put on a live server.
is_never_deployed() {
    case "$1" in
        api/test-cpanel.php|api/test-mail.php) return 0 ;;
    esac
    return 1
}

MODE="deploy"; FIRST=0; CHECK_FILE=""
for arg in "$@"; do
    case "$arg" in
        --check)    MODE="check" ;;
        --baseline) MODE="baseline" ;;
        --dry-run)  MODE="dry" ;;
        --first)    FIRST=1 ;;
        -*) echo "unknown option: $arg" >&2; exit 1 ;;
        *)  CHECK_FILE="$arg" ;;
    esac
done

# Kept in the template (scripts, schema, docs) but not copied to tenants.
is_template_only() {
    case "$1" in
        deploy/*|sql/*|*.md|.gitignore|.gitattributes|config/*) return 0 ;;
        "") return 0 ;;
    esac
    return 1
}

# Where a file lives on the server, for comparing.
live_path() { if is_master_only "$1"; then echo "$MASTER/$1"; else echo "$TEMPLATE/$1"; fi; }

cd "$REPO" || { echo "repository not found at $REPO" >&2; exit 1; }
git fetch -q origin main || { echo "could not reach GitHub" >&2; exit 1; }
NEW=$(git rev-parse origin/main)

# ---------------------------------------------------------------- check
if [ "$MODE" = "check" ]; then
    git merge -q --ff-only origin/main
    if [ -n "$CHECK_FILE" ]; then
        diff -u "$(live_path "$CHECK_FILE")" "$REPO/$CHECK_FILE" | head -200
        exit 0
    fi
    same=0; differ=(); missing=()
    # Temp files, not <(...): the host's restricted shell has no /dev/fd.
    FILES=$(mktemp); git ls-files > "$FILES"
    while read -r f; do
        is_never_deployed "$f" && continue
        live=$(live_path "$f")
        if [ ! -f "$live" ]; then missing+=("$f")
        elif cmp -s "$live" "$REPO/$f"; then same=$((same+1))
        else differ+=("$f"); fi
    done < "$FILES"
    rm -f "$FILES"
    echo "Same on the server and in GitHub: $same file(s)"
    echo
    echo "DIFFERENT (${#differ[@]}):"; for f in "${differ[@]}"; do echo "    $f"; done
    echo
    echo "IN GITHUB BUT NOT ON THE SERVER (${#missing[@]}):"; for f in "${missing[@]}"; do echo "    $f"; done
    echo
    echo "See one file's differences:  bash $REPO/deploy/release.sh --check <file>"
    exit 0
fi

# ---------------------------------------------------------------- baseline
if [ "$MODE" = "baseline" ]; then
    if [ -f "$STATE" ]; then echo "Already set up (last deployed: $(cut -c1-7 "$STATE")). Nothing done."; exit 0; fi
    git merge -q --ff-only origin/main
    echo "$NEW" > "$STATE"
    echo "Recorded $(git log -1 --format='%h %s' "$NEW") as the live version."
    echo "From now on, deploys copy only what changes after this."
    exit 0
fi

# ---------------------------------------------------------------- deploy / dry run
TMP=$(mktemp)
if [ ! -f "$STATE" ]; then
    if [ "$FIRST" != 1 ]; then
        rm -f "$TMP"
        echo "First run: see the differences with --check, then deploy them with --first." >&2
        exit 1
    fi
    # First deploy: everything in GitHub that is not already identical on the server.
    git merge -q --ff-only origin/main
    echo "First deploy of $(git log -1 --format='%h %s' "$NEW")"
    echo
    : > "$TMP"
    git ls-files | while read -r f; do
        is_never_deployed "$f" && continue
        cmp -s "$(live_path "$f")" "$REPO/$f" || echo "$f" >> "$TMP"
    done
    mapfile -t CHANGED < "$TMP"
    DELETED=()
else
    [ "$FIRST" = 1 ] && { rm -f "$TMP"; echo "Already set up — run without --first." >&2; exit 1; }
    LAST=$(cat "$STATE")
    if [ "$LAST" = "$NEW" ]; then rm -f "$TMP"; echo "Already up to date ($(git log -1 --format='%h %s' "$NEW"))."; exit 0; fi
    echo "New changes since the last deploy:"
    git log --format='    %h  %ad  %s' --date=short "$LAST..$NEW"
    echo
    git diff --name-only --diff-filter=ACMRT "$LAST" "$NEW" > "$TMP"; mapfile -t CHANGED < "$TMP"
    git diff --name-only --diff-filter=D "$LAST" "$NEW" > "$TMP";     mapfile -t DELETED < "$TMP"
fi
rm -f "$TMP"

TENANT_FILES=(); MASTER_FILES=(); TEMPLATE_FILES=(); MIGRATE=0
for f in "${CHANGED[@]}"; do
    if is_never_deployed "$f"; then continue
    elif is_master_only "$f"; then MASTER_FILES+=("$f")
    elif is_template_only "$f"; then TEMPLATE_FILES+=("$f")
    else TENANT_FILES+=("$f"); fi
    case "$f" in sql/*|deploy/migrate-tenants.php) MIGRATE=1 ;; esac
done

echo "To every tenant + master (${#TENANT_FILES[@]}):"; for f in "${TENANT_FILES[@]}"; do echo "    $f"; done
echo "To the master only (${#MASTER_FILES[@]}):";    for f in "${MASTER_FILES[@]}"; do echo "    $f"; done
echo "Template only — scripts/schema (${#TEMPLATE_FILES[@]}):"; for f in "${TEMPLATE_FILES[@]}"; do echo "    $f"; done
[ "$MIGRATE" = 1 ] && echo "Database migration: YES, runs first" || echo "Database migration: not needed"
if [ ${#DELETED[@]} -gt 0 ]; then
    echo "Deleted in GitHub — NOT removed from the server, do it by hand if wanted:"
    for f in "${DELETED[@]}"; do echo "    $f"; done
fi
echo

if [ "$MODE" = "dry" ]; then echo "Dry run — nothing changed."; exit 0; fi

read -r -p "Deploy this now? Type yes to continue: " answer
[ "$answer" = "yes" ] || { echo "Cancelled. Nothing changed."; exit 0; }

git merge -q --ff-only origin/main || { echo "could not update the local copy" >&2; exit 1; }

# 1. Template first: it is the source sync-tenants.sh copies from, and nothing
#    in it is live, so a failure below leaves every site as it was.
for f in "${TENANT_FILES[@]}" "${TEMPLATE_FILES[@]}"; do
    mkdir -p "$TEMPLATE/$(dirname "$f")" && cp -f "$REPO/$f" "$TEMPLATE/$f" || { echo "FAILED copying $f" >&2; exit 1; }
done

# 2. Database, before any code reaches a tenant.
if [ "$MIGRATE" = 1 ]; then
    echo; echo "== Migrating tenant databases"
    php "$TEMPLATE/deploy/migrate-tenants.php" || {
        echo; echo "MIGRATION FAILED — code was NOT sent to tenants. Fix the problem above, then run this again." >&2
        exit 1
    }
fi

# 3. Code to every tenant and the master.
if [ ${#TENANT_FILES[@]} -gt 0 ]; then
    echo; echo "== Copying code to tenants"
    LIST=$(mktemp)
    printf '%s\n' "${TENANT_FILES[@]}" > "$LIST"
    bash "$TEMPLATE/deploy/sync-tenants.sh" --from-file "$LIST" || { rm -f "$LIST"; echo "SYNC FAILED — see above" >&2; exit 1; }
    rm -f "$LIST"
fi

# 4. Master-only files last, like everything else that is live.
for f in "${MASTER_FILES[@]}"; do
    mkdir -p "$MASTER/$(dirname "$f")" && cp -f "$REPO/$f" "$MASTER/$f" || { echo "FAILED copying $f" >&2; exit 1; }
    echo "master only: $f"
done

echo "$NEW" > "$STATE"
echo; echo "Deployed $(git log -1 --format='%h %s' "$NEW")."
