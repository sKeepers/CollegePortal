#!/usr/bin/env sh
set -eu

CERT_DIR="${DEV_HTTPS_CERT_DIR:-infra/dev-https/certs}"
DEV_HOST="${DEV_HTTPS_HOST:-college-dev.local}"
DEV_IP="${DEV_HTTPS_IP:-192.168.34.114}"

mkdir -p "$CERT_DIR"
chmod 700 "$CERT_DIR"

CA_KEY="$CERT_DIR/college-dev-root-ca.key"
CA_CERT="$CERT_DIR/college-dev-root-ca.crt"
SERVER_KEY="$CERT_DIR/college-dev.local.key"
SERVER_CERT="$CERT_DIR/college-dev.local.crt"
SERVER_CSR="$CERT_DIR/college-dev.local.csr"
OPENSSL_CNF="$CERT_DIR/college-dev-openssl.cnf"

if [ ! -f "$CA_KEY" ] || [ ! -f "$CA_CERT" ]; then
  openssl genrsa -out "$CA_KEY" 4096
  openssl req -x509 -new -nodes -key "$CA_KEY" -sha256 -days 825 \
    -subj "/CN=CollegePortal DEV Local CA" \
    -out "$CA_CERT"
fi

cat > "$OPENSSL_CNF" <<CNF
[req]
default_bits = 2048
prompt = no
default_md = sha256
distinguished_name = dn
req_extensions = req_ext

[dn]
CN = $DEV_HOST

[req_ext]
subjectAltName = @alt_names

[alt_names]
DNS.1 = $DEV_HOST
IP.1 = $DEV_IP
CNF

openssl genrsa -out "$SERVER_KEY" 2048
openssl req -new -key "$SERVER_KEY" -out "$SERVER_CSR" -config "$OPENSSL_CNF"
openssl x509 -req -in "$SERVER_CSR" -CA "$CA_CERT" -CAkey "$CA_KEY" -CAcreateserial \
  -out "$SERVER_CERT" -days 397 -sha256 -extensions req_ext -extfile "$OPENSSL_CNF"

chmod 600 "$CA_KEY" "$SERVER_KEY"
chmod 644 "$CA_CERT" "$SERVER_CERT"
rm -f "$SERVER_CSR"

printf 'DEV HTTPS certificate created.\n'
printf 'CA certificate for devices: %s\n' "$CA_CERT"
printf 'HTTPS endpoint: https://%s:5443 or https://%s:5443\n' "$DEV_HOST" "$DEV_IP"
