# PAdES, timestamp, revocation and ICP-Brasil scenarios

The scenarios of version 3 qualify what the basic scenarios leave out: signature timestamps, revocation data, document timestamps, certification signatures, several signers, a signature policy, and an implementation's refusal to sign with a revoked certificate. They follow this repository's model: one scenario per configuration, one attempt per input, and independent observations, never a combined verdict.

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

Pilot sample (`config/inputs.json`, 27 failure-enriched inputs, so no count here is a compatibility rate), `pdf-signer` at the pinned commit `f27e4064be107b8a662f370a9acdcecd786da9f7`, 23 November 2026. The runs were made on Docker Desktop for macOS (arm64) with a local, uncommitted Compose override that mounts an empty `tmpfs` over `/sys/class/net` in the sandbox to hide the fallback tunnel devices; `network_mode: none` was unchanged. In every scenario the same 4 inputs are refused (no `startxref`, not a PDF, a broken object stream, an undecodable stream) and 23 are signed.

Verdicts on the 23 outputs, valid / invalid / inconclusive:

| Scenario | `eu-dss` | `qpdf-pades-structure` | `document-timestamp-imprint` | `demoiselle-signer` |
| -------- | -------- | ---------------------- | ---------------------------- | ------------------- |
| `pades-baseline-b` | 0 / 20 / 3 | 17 / 0 / 6 | – | – |
| `pades-baseline-t` | 0 / 20 / 3 | 0 / 22 / 1 | – | – |
| `pades-baseline-lt` | 0 / 20 / 3 | 0 / 22 / 1 | – | – |
| `pades-baseline-lta` | 0 / 20 / 3 | 0 / 22 / 1 | 0 / 22 / 1 | – |
| `pades-certification-docmdp-2` | 0 / 20 / 3 | 0 / 22 / 1 | – | – |
| `pades-two-approval-signatures` | 0 / 20 / 3 | 17 / 0 / 6 | – | – |
| `pades-certification-then-approval` | 0 / 20 / 3 | 0 / 22 / 1 | – | – |
| `icp-brasil-ad-rt` | 0 / 20 / 3 | 0 / 22 / 1 | 0 / 22 / 1 | 0 / 19 / 4 |
| `pades-revoked-signer` | 0 / 20 / 3 | 0 / 22 / 1 | – | – |

What the analyzers report on this revision:

- **`eu-dss`**: every added signature is detected as `PDF-NOT-ETSI`, not as a PAdES baseline signature, whatever the level. On `hybrid-xref.pdf`, `good17.pdf` and `good18.pdf` DSS finds no added signature at all, as the `hybrid-xref` regression case predicts. The 3 inconclusive outputs are an encrypted document DSS cannot open, a file with bytes before its header, which DSS does not recognize, and one whose input qpdf cannot list.
- **`qpdf-pades-structure`**: a timestamp scenario (T, LT, LTA) writes a `/DocTimeStamp` entry into the catalog and a document timestamp with `/Type /Sig` that no field of the form holds, even at T and LT, which call for no document timestamp. A certification signature is written as a catalog `/DocMDP` entry instead of `/Perms /DocMDP`; on the 3 inputs already certified, `/Perms /DocMDP` keeps the input's.
- **`document-timestamp-imprint`**: no document timestamp carries the digest of the bytes its range covers.
- **`demoiselle-signer`**: the `br-iti` preset commits to no signature policy, so no signature is AD-RT.
- **`pades-revoked-signer`**: the revision signs with the revoked certificate instead of refusing it, so every expected rejection succeeded and is a failure; DSS reports the signature `INDETERMINATE/REVOKED_NO_POE`.

These defects are addressed by open pull requests to `pdf-signer` and `pdf-core`; once they are merged and pinned, `bin/replay` between the two revisions under these scenarios shows what they fix.

The analyzers of the basic scenarios also run here. In these runs `openssl-cms` judged every output with a document timestamp `invalid` because it verified the RFC 3161 token over the bytes of its range, while the token signs its encapsulated TSTInfo, so even a correct document timestamp failed. It now verifies such a token as encapsulated CMS and leaves the link to the covered bytes to `document-timestamp-imprint`; the positive control below shows both `valid` on a correct document timestamp.

## Positive control

`tests/Validation/PadesPositiveControlTest.php` signs the synthetic minimal document with pyHanko, which is already in the image, and certificate `signer-a` of a test PKI: at PAdES baseline B, at LTA with validation information and a document timestamp, and as a certification signature. It checks that `eu-dss`, `qpdf-pades-structure`, `document-timestamp-imprint` and `openssl-cms` accept these outputs, and that a baseline B output judged under an LTA scenario is `invalid`. So a validator upgrade that stops accepting a correct output fails the tests, even though no implementation in the pilot produces one yet.
