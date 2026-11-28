#!/usr/bin/env bash
# WOODEX OS: tell an installed admin that it takes quotation baskets from the shop, and by which key it knows
# the shop's server. Two lines of its settings file (the .env the installer wrote, outside the website's folder).
#
#   WOODEX_MODULES            names the part "quote_requests" beside what it named before. Without it the
#                             address POST /api/v1/quote-requests answers 404, as it ships (config/modules.php).
#   PUBLIC_LEADS_BRIDGE_KEY   the key the shop's server sends with what it hands on. With it the admin does not
#                             ask that request for the robot check's token (it checks one the visitor's page
#                             made), and counts its limits by the visitor the shop names
#                             (app/Support/BridgedVisitor.php). The shop's server holds the same value as
#                             NATIVE_BRIDGE_KEY.
#
#   bash platform/tools/hostinger/take-baskets.sh <settings file> <file that holds the key>
#
# The key is read from a file and written to a file: it is on no command line and in no output. It must be 32
# characters or more of letters and digits. The settings file keeps its owner and its mode. The admin reads the
# file on every request, so nothing is restarted. Run twice, it changes nothing the second time.
#
# The rehearsals call it (rehearse-with-shop.sh, the job "hostinger" of os-ci.yml). On the host it is one way to
# do what docs/woodex-os/24 describes; the other is the host's file manager and the same two lines by hand.
set -euo pipefail

settings="${1:-}"
key_file="${2:-}"
[ -f "$settings" ] && [ -w "$settings" ] || { echo "no settings file to change: ${settings:-none named}" >&2; exit 2; }
[ -s "$key_file" ] || { echo "no file that holds the key: ${key_file:-none named}" >&2; exit 2; }
key="$(tr -d '\r\n' < "$key_file")"
case "$key" in
  *[!A-Za-z0-9]*) echo "the key may hold letters and digits only" >&2; exit 2 ;;
esac
[ "${#key}" -ge 32 ] || { echo "the key is shorter than 32 characters" >&2; exit 2; }

# The parts that are on now: the line of the file, or what the admin takes when there is none.
parts="$(grep -m1 '^WOODEX_MODULES=' "$settings" | cut -d= -f2- | tr -d '"' || true)"
[ -n "$parts" ] || parts="lead_api"
case ",$parts," in
  *,quote_requests,* | ,\*,) ;;
  *) parts="$parts,quote_requests" ;;
esac

# Written beside the file and moved over it: a reader never finds half a file. The first line of a name is the
# one the admin reads, so the two names are replaced where they stand and added only if they are not there.
copy="$(mktemp "$settings.XXXXXX")"
trap 'rm -f "$copy"' EXIT
chmod --reference="$settings" "$copy"
# The key reaches awk through its environment, not as an argument: an argument can be read in the list of
# processes while the command runs.
WOODEX_TAKE_BASKETS_KEY="$key" awk -v parts="$parts" '
  BEGIN { key = ENVIRON["WOODEX_TAKE_BASKETS_KEY"] }
  /^WOODEX_MODULES=/          { if (!seen_parts++) print "WOODEX_MODULES=" parts; next }
  /^PUBLIC_LEADS_BRIDGE_KEY=/ { if (!seen_key++) print "PUBLIC_LEADS_BRIDGE_KEY=" key; next }
  { print }
  END {
    if (!seen_parts) print "WOODEX_MODULES=" parts
    if (!seen_key) print "PUBLIC_LEADS_BRIDGE_KEY=" key
  }
' "$settings" > "$copy"
mv "$copy" "$settings"
trap - EXIT
echo "the admin takes baskets: WOODEX_MODULES=$parts, and the shop's key is set (${#key} characters)"
