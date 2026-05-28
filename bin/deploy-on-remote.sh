#!/usr/bin/env bash

set -o errexit -o nounset -o pipefail

deploy() {
    local sVersion="${1:?One parameter required: <version-tag>}"

    if [[ "${sVersion}" == '-h' || "${sVersion}" == '--help' ]]; then
        cat <<EOF
Usage: $(basename "$0") <version-tag>

Deploys the specified version tag to the remote server.
The version tag should correspond to a git tag in the repository.

Example:
  $(basename "$0") v0.4.0
EOF
        if git rev-parse --is-inside-work-tree &>/dev/null; then
            echo -e "\nAvailable tags in the repository:\n"
            git ls-remote  --refs --sort='version:refname' --tags origin | cut -d/ -f3- | cut -d$'\t' -f1
        fi
        echo -e "\nAvailable remote tags in /opt/meent/webhook:\n"
        ssh dev.muze.nl -p2222 ls -lA /opt/meent/webhook | grep -v 'repo\|total' | cut -d: -f2 | cut -d' ' -f2-

    else
        ssh -t dev.muze.nl -p2222 bash /opt/meent/deploy.sh "$@"
    fi
}

if [[ "${BASH_SOURCE[0]}" != "${0}" ]]; then
    export -f deploy
else
    deploy "$@"
fi
