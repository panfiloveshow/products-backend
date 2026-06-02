#!/usr/bin/env bash
set -euo pipefail

# Smoke-check for UE Excel export contract.
# Required env vars:
#   BASE_URL (example: https://products.sellico.ru/api)
#   TOKEN
#   INTEGRATION_ID
# Optional:
#   MARKETPLACE (default: ozon)
#   FULFILLMENT_TYPE (default: FBO)
#   SEARCH (default: 9137/black)
#   WORKSPACE_ID (adds workspace headers when provided)

BASE_URL="${BASE_URL:-}"
TOKEN="${TOKEN:-}"
INTEGRATION_ID="${INTEGRATION_ID:-}"
MARKETPLACE="${MARKETPLACE:-ozon}"
FULFILLMENT_TYPE="${FULFILLMENT_TYPE:-FBO}"
SEARCH="${SEARCH:-9137/black}"
WORKSPACE_ID="${WORKSPACE_ID:-}"

if [[ -z "$BASE_URL" || -z "$TOKEN" || -z "$INTEGRATION_ID" ]]; then
  echo "ERROR: BASE_URL, TOKEN and INTEGRATION_ID are required."
  exit 2
fi

BASE_URL="${BASE_URL%/}"
URL="${BASE_URL}/unit-economics/${MARKETPLACE}/export/excel?integration_id=${INTEGRATION_ID}&fulfillment_type=${FULFILLMENT_TYPE}&search=${SEARCH}"

TMP_HEADERS="$(mktemp)"
trap 'rm -f "$TMP_HEADERS"' EXIT

COMMON_HEADERS=(
  -H "Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
  -H "Authorization: Bearer ${TOKEN}"
  -H "X-Sellico-Token: ${TOKEN}"
)

if [[ -n "$WORKSPACE_ID" ]]; then
  COMMON_HEADERS+=(
    -H "X-Sellico-Workspace: ${WORKSPACE_ID}"
    -H "X-Workspace-Id: ${WORKSPACE_ID}"
    -H "X-Account-Id: ${WORKSPACE_ID}"
  )
fi

HTTP_CODE="$(
  curl -sS -D "$TMP_HEADERS" -o /dev/null -w "%{http_code}" \
    "${COMMON_HEADERS[@]}" \
    "$URL"
)"

VERSION="$(awk -F': ' 'BEGIN{IGNORECASE=1} /^X-Unit-Economics-Export-Version:/ {gsub("\r","",$2); print $2}' "$TMP_HEADERS" | tail -n1)"
FORMAT="$(awk -F': ' 'BEGIN{IGNORECASE=1} /^X-Unit-Economics-Export-Format:/ {gsub("\r","",$2); print $2}' "$TMP_HEADERS" | tail -n1)"
SOURCE="$(awk -F': ' 'BEGIN{IGNORECASE=1} /^X-Unit-Economics-Export-Source:/ {gsub("\r","",$2); print $2}' "$TMP_HEADERS" | tail -n1)"

echo "HTTP: ${HTTP_CODE}"
echo "Version: ${VERSION:-<missing>}"
echo "Format: ${FORMAT:-<missing>}"
echo "Source: ${SOURCE:-<missing>}"

if [[ "$HTTP_CODE" != "200" ]]; then
  echo "ERROR: export endpoint returned ${HTTP_CODE}"
  exit 1
fi

if [[ -z "$VERSION" || "$VERSION" == "legacy" ]]; then
  echo "ERROR: legacy or missing export version header"
  exit 1
fi

if [[ "$FORMAT" != "v2" ]]; then
  echo "ERROR: unexpected export format header: ${FORMAT:-<missing>}"
  exit 1
fi

if [[ -z "$SOURCE" ]]; then
  echo "ERROR: missing export source header"
  exit 1
fi

echo "OK: export contract is valid."
