#!/usr/bin/env bash
#
# Starts the ragbridge service for the integration tests, creates an API key and prints the
# environment variables the tests need. Diagnostics go to stderr, so this works:
#
#   eval "$(tests/Integration/start.sh)" && vendor/bin/pest --group=integration
#
# Models are served by an Ollama container by default. To use an Ollama that already runs on
# the host, and skip downloading models:
#
#   RAGBRIDGE_INTEGRATION_OLLAMA=host tests/Integration/start.sh
#
# Settings (environment variables):
#   RAGBRIDGE_INTEGRATION_PORT     port the service is published on          (default 18000)
#   RAGBRIDGE_INTEGRATION_OLLAMA   "container" or "host"                     (default container)
#   EMBEDDING_MODEL, CHAT_MODEL    LiteLLM model names, see the service docs (default ollama/nomic-embed-text, ollama/llama3.2)

set -euo pipefail

cd "$(dirname "$0")"

port="${RAGBRIDGE_INTEGRATION_PORT:-18000}"
mode="${RAGBRIDGE_INTEGRATION_OLLAMA:-container}"
embedding_model="${EMBEDDING_MODEL:-ollama/nomic-embed-text}"
chat_model="${CHAT_MODEL:-ollama/llama3.2}"

export RAGBRIDGE_INTEGRATION_PORT="$port" EMBEDDING_MODEL="$embedding_model" CHAT_MODEL="$chat_model"

compose=(docker compose -f compose.yaml)

case "$mode" in
  container)
    compose+=(--profile ollama)
    export OLLAMA_BASE_URL="http://ollama:11434"
    ;;
  host)
    export OLLAMA_BASE_URL="${OLLAMA_BASE_URL:-http://host.docker.internal:11434}"
    ;;
  *)
    echo "RAGBRIDGE_INTEGRATION_OLLAMA must be \"container\" or \"host\", got \"$mode\"." >&2
    exit 1
    ;;
esac

echo "Starting the service (this builds it from source the first time)..." >&2
"${compose[@]}" up --detach --build --wait >&2

if [ "$mode" = "container" ]; then
  for model in "$embedding_model" "$chat_model"; do
    case "$model" in
      ollama/*)
        echo "Pulling ${model#ollama/}..." >&2
        "${compose[@]}" exec -T ollama ollama pull "${model#ollama/}" >&2
        ;;
    esac
  done
fi

echo "Creating an API key..." >&2
key="$("${compose[@]}" exec -T app ragbridge-admin create-tenant --name "php-client-$(date +%s)" | sed -n 's/^API key: //p')"

if [ -z "$key" ]; then
  echo "Could not create an API key." >&2
  exit 1
fi

echo "The service is ready at http://localhost:${port}." >&2

echo "export RAGBRIDGE_INTEGRATION_URL=http://localhost:${port}"
echo "export RAGBRIDGE_INTEGRATION_API_KEY=${key}"
