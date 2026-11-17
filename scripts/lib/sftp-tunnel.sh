# shellcheck shell=bash
#
# Optional SSH tunnel for the SFTP upload. Sourced by deploy-rezervacie.sh
# and redeploy-test.sh — not meant to be executed.
#
# Why: many VPNs block outbound port 22, so the Websupport SFTP host is
# unreachable from the operator's machine. If some other host IS reachable
# over SSH and CAN reach the SFTP host, we forward a local port through it
# and point lftp at 127.0.0.1:<port>. The upload then runs exactly as before.
#
# Enable with `--tunnel USER@JUMPHOST` or the DEPLOY_SSH_TUNNEL variable
# (shell env or a secrets file). Nothing about the jump host lives in the
# repo — it's the operator's own.
#
# After sftp_tunnel_open:
#   DEPLOY_SFTP_HOST / DEPLOY_SFTP_PORT  point at the local end
#   LFTP_CONNECT                         ssh command for `sftp:connect-program`
#                                        (keeps the real host name for the
#                                        known_hosts check)

LFTP_CONNECT="${LFTP_CONNECT:-ssh -a -x}"
_TUNNEL_CTL=""
_TUNNEL_JUMP=""

sftp_tunnel_close() {
    if [[ -n "$_TUNNEL_CTL" && -S "$_TUNNEL_CTL" ]]; then
        ssh -S "$_TUNNEL_CTL" -O exit "$_TUNNEL_JUMP" >/dev/null 2>&1 || true
    fi
    _TUNNEL_CTL=""
}

# sftp_tunnel_open USER@JUMPHOST  — needs DEPLOY_SFTP_HOST/PORT to be set.
sftp_tunnel_open() {
    local jump="$1" host="$DEPLOY_SFTP_HOST" port="${DEPLOY_SFTP_PORT:-22}" lport
    command -v ssh >/dev/null 2>&1 || { echo "[fail] ssh is required for --tunnel" >&2; return 1; }
    [[ -z "$_TUNNEL_CTL" ]] || return 0   # already open

    lport="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1])')" \
        || { echo "[fail] could not find a free local port" >&2; return 1; }
    _TUNNEL_CTL="$(mktemp -u /tmp/lodenica-tunnel.XXXXXX)"
    _TUNNEL_JUMP="$jump"

    printf '\033[1;36m==>\033[0m SSH tunnel: 127.0.0.1:%s → %s:%s via %s\n' "$lport" "$host" "$port" "$jump"
    if ! ssh -f -N -M -S "$_TUNNEL_CTL" \
            -o ExitOnForwardFailure=yes -o ConnectTimeout=15 \
            -o ServerAliveInterval=15 -o ServerAliveCountMax=6 -o TCPKeepAlive=yes \
            -L "127.0.0.1:$lport:$host:$port" "$jump"; then
        _TUNNEL_CTL=""
        echo "[fail] could not open the SSH tunnel via $jump" >&2
        return 1
    fi
    trap sftp_tunnel_close EXIT

    LFTP_CONNECT="ssh -a -x -o HostKeyAlias=$host"
    DEPLOY_SFTP_HOST="127.0.0.1"
    DEPLOY_SFTP_PORT="$lport"
}
