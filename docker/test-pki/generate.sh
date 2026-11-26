#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 2 ] || [ -z "${PKI_PASSWORD:-}" ]; then
  echo "Usage: PKI_PASSWORD=<password> generate.sh <directory> <base-url>" >&2
  exit 2
fi
PKI_DIRECTORY="$1"
BASE_URL="$2"

if [ -e "$PKI_DIRECTORY" ]; then
  echo "$PKI_DIRECTORY already exists" >&2
  exit 2
fi
mkdir -p "$PKI_DIRECTORY"/root/newcerts "$PKI_DIRECTORY"/intermediate/newcerts
chmod 700 "$PKI_DIRECTORY"
cd "$PKI_DIRECTORY"
exec 2>>"$PKI_DIRECTORY/generate.log"

REQUEST_CONFIGURATION="$PKI_DIRECTORY/req.cnf"
printf '[req]\ndistinguished_name=dn\nprompt=no\n[dn]\n' > "$REQUEST_CONFIGURATION"

for authority in root intermediate; do
  : > "$authority/index.txt"
  echo 1000 > "$authority/serial"
  echo 1000 > "$authority/crlnumber"
  cat > "$authority/ca.cnf" <<EOF
[ ca ]
default_ca = CA_default
[ CA_default ]
dir              = $PKI_DIRECTORY/$authority
database         = \$dir/index.txt
new_certs_dir    = \$dir/newcerts
serial           = \$dir/serial
crlnumber        = \$dir/crlnumber
default_md       = sha256
default_days     = 3650
default_crl_days = 3650
policy           = policy_any
unique_subject   = no
copy_extensions  = none
[ policy_any ]
countryName      = optional
organizationName = optional
commonName       = supplied
EOF
done

cat > ext.cnf <<EOF
[ v3_intermediate ]
basicConstraints       = critical, CA:TRUE, pathlen:0
keyUsage               = critical, keyCertSign, cRLSign
subjectKeyIdentifier   = hash
authorityKeyIdentifier = keyid:always
crlDistributionPoints  = URI:$BASE_URL/crl/root.crl
authorityInfoAccess    = caIssuers;URI:$BASE_URL/certs/root.crt

[ v3_signer ]
basicConstraints       = critical, CA:FALSE
keyUsage               = critical, digitalSignature, nonRepudiation
subjectKeyIdentifier   = hash
authorityKeyIdentifier = keyid:always
crlDistributionPoints  = URI:$BASE_URL/crl/intermediate.crl
authorityInfoAccess    = OCSP;URI:$BASE_URL/ocsp, caIssuers;URI:$BASE_URL/certs/intermediate.crt
certificatePolicies    = 2.16.76.1.2.1.999

[ v3_tsa ]
basicConstraints       = critical, CA:FALSE
keyUsage               = critical, digitalSignature, nonRepudiation
extendedKeyUsage       = critical, timeStamping
subjectKeyIdentifier   = hash
authorityKeyIdentifier = keyid:always
crlDistributionPoints  = URI:$BASE_URL/crl/intermediate.crl
authorityInfoAccess    = OCSP;URI:$BASE_URL/ocsp, caIssuers;URI:$BASE_URL/certs/intermediate.crt

[ v3_ocsp ]
basicConstraints       = critical, CA:FALSE
keyUsage               = critical, digitalSignature
extendedKeyUsage       = critical, OCSPSigning
subjectKeyIdentifier   = hash
authorityKeyIdentifier = keyid:always
1.3.6.1.5.5.7.48.1.5   = ASN1:NULL
EOF

key() { openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:"${2:-2048}" -out "$1.key"; }
csr() { openssl req -new -config "$REQUEST_CONFIGURATION" -key "$1.key" -subj "$2" -out "$1.csr"; }
issue() {
  openssl ca -batch -notext -config "$2/ca.cnf" -cert "$2.crt" -keyfile "$2.key" \
    -in "$1.csr" -out "$1.crt" -extfile ext.cnf -extensions "$3" -days "$4"
}

ORGANIZATION="/C=BR/O=SignerPHP Qualification Test PKI"

key root 4096
openssl req -x509 -new -config "$REQUEST_CONFIGURATION" -key root.key -sha256 -days 7300 \
  -subj "$ORGANIZATION/CN=Qualification Test Root CA" \
  -addext "basicConstraints=critical,CA:TRUE" \
  -addext "keyUsage=critical,keyCertSign,cRLSign" \
  -addext "subjectKeyIdentifier=hash" \
  -out root.crt

key intermediate 3072
csr intermediate "$ORGANIZATION/CN=Qualification Test Intermediate CA"
issue intermediate root v3_intermediate 5475

for signer in signer-a:"Signer A" signer-b:"Signer B" signer-revoked:"Signer Revoked"; do
  name="${signer%%:*}"; common_name="${signer#*:}"
  key "$name"
  csr "$name" "$ORGANIZATION/CN=$common_name"
  issue "$name" intermediate v3_signer 1825
done

key tsa
csr tsa "$ORGANIZATION/CN=Qualification Test TSA"
issue tsa intermediate v3_tsa 1825

key ocsp
csr ocsp "$ORGANIZATION/CN=Qualification Test OCSP Responder"
issue ocsp intermediate v3_ocsp 1825

openssl ca -config intermediate/ca.cnf -cert intermediate.crt -keyfile intermediate.key \
  -revoke signer-revoked.crt -crl_reason keyCompromise

for authority in root intermediate; do
  openssl ca -gencrl -config "$authority/ca.cnf" -cert "$authority.crt" -keyfile "$authority.key" -out "$authority.crl.pem"
  openssl crl -in "$authority.crl.pem" -outform DER -out "$authority.crl"
  openssl x509 -in "$authority.crt" -outform DER -out "$authority.der"
done

cat intermediate.crt root.crt > chain.pem

for name in signer-a signer-b signer-revoked; do
  openssl pkcs12 -export -inkey "$name.key" -in "$name.crt" -certfile chain.pem \
    -name "$name" -passout env:PKI_PASSWORD -out "$name.pfx"
done

echo 01 > tsa-serial
cat > tsa.cnf <<EOF
[ tsa ]
default_tsa = tsa_config
[ tsa_config ]
dir                    = $PKI_DIRECTORY
serial                 = \$dir/tsa-serial
crypto_device          = builtin
signer_cert            = \$dir/tsa.crt
certs                  = \$dir/chain.pem
signer_key             = \$dir/tsa.key
signer_digest          = sha256
default_policy         = 1.3.6.1.4.1.99999.1
other_policies         = 1.3.6.1.4.1.99999.2
digests                = sha1, sha256, sha384, sha512
accuracy               = secs:1
ordering               = yes
tsa_name               = no
ess_cert_id_chain      = no
ess_cert_id_alg        = sha256
EOF

rm -f ./*.csr
touch .done
