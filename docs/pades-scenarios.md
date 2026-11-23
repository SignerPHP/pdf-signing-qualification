# PAdES, timestamp, revocation and ICP-Brasil scenarios

The scenarios of version 3 qualify what the basic scenarios leave out: signature timestamps, revocation data, document timestamps, certification signatures, several signers, a signature policy, and an implementation's refusal to sign with a revoked certificate. They come from a local conformance harness of `pdf-signer` and keep its coverage inside this repository's model: one scenario per configuration, one attempt per input, and independent observations, never a combined verdict.

## Test PKI

Every such run generates its own PKI in the sandbox scratch directory with `docker/test-pki/generate.sh`: a root CA, an intermediate CA, signers A and B, a signer revoked before anything is signed, a time-stamping authority and a delegated OCSP responder, all under `O=SignerPHP Qualification Test PKI`. `docker/test-pki/server.php` answers RFC 3161 requests, OCSP requests (from the intermediate CA's index) and the CRL and AIA URLs that the certificates carry, with `php -S` on a free port of the loopback interface. The sandbox therefore keeps `network_mode: none`, no key is committed and none outlives the run. The signer certificates carry the placeholder policy OID `2.16.76.1.2.1.999` and do not chain to the ICP-Brasil root.

## Scenarios

| Scenario | Signers | Level | Certification | Policy | Expected |
| -------- | ------- | ----- | ------------- | ------ | -------- |
| `pades-baseline-b` | valid | B | – | – | signed |
| `pades-baseline-t` | valid | T | – | – | signed |
| `pades-baseline-lt` | valid | LT | – | – | signed |
| `pades-baseline-lta` | valid | LTA | – | – | signed |
| `pades-certification-docmdp-2` | valid | B | DocMDP 2 | – | signed |
| `pades-two-approval-signatures` | valid, valid | B | – | – | signed |
| `pades-certification-then-approval` | valid, valid | B | DocMDP 2 on the first | – | signed |
| `icp-brasil-ad-rt` | valid | LTA | DocMDP 2 | ICP-Brasil AD-RT | signed |
| `pades-revoked-signer` | revoked | LT | – | – | rejected (`expected_rejection`) |

`pdf-signer` supports all of them through `bin/signer-sign`; JSignPdf signs `adbe.pkcs7.detached` only, so every one is `unsupported` for it.

## Analyzers

Every analyzer of the basic scenarios still runs. A version 3 scenario adds:

- `eu-dss` (`profile`): EU DSS 6.2, trusting the run's root only, must find one added signature per signer, each `TOTAL_PASSED` at the expected PAdES baseline format.
- `qpdf-pades-structure` (`structure`): the certification signature is `/Perms /DocMDP` with the scenario's level; document timestamps are `/Type /DocTimeStamp` fields of the form.
- `document-timestamp-imprint` (`timestamp`), when the scenario adds a document timestamp: its RFC 3161 imprint is the digest of its own byte range.
- `demoiselle-signer` (`profile`), under `icp-brasil-ad-rt`: each added signature commits to the AD-RT policy and has no Demoiselle error other than the untrusted chain of the test PKI.

Their exact policies are in the [schemas README](../schemas/README.md).

## Running

```sh
bin/pilot --scenario=pades-baseline-lta
```

On Docker Desktop for macOS the attempts do not start: its kernel creates down fallback tunnel devices (`gre0`, `sit0`, ...) in every network namespace, which the isolation check refuses. That host limitation predates these scenarios and is not worked around here.

## Pilot results
