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

## Results on the pilot sample

One run of `bin/pilot --scenario=<id>` for each of the nine scenarios above, over the [pilot sample](pilot-sample.md) (`config/inputs.json`), made one after the other on 27 November 2026 on a Linux host, without any Compose override. As the sample page says, the sample is failure-enriched: its inputs were chosen for their edge cases, so no count below is a compatibility rate.

### Revisions and environment

| Item | Value |
| ---- | ----- |
| This repository | Commit `2009492ea440916e6e58dc5e9a45b48e45c141e9`, whose code is that of `main` at `909bdec5e3ae6baf591d5512f1e67b1668f777b6`; it changes only documents |
| Implementation | `pdf-signer` at commit `f27e4064be107b8a662f370a9acdcecd786da9f7` (`v0.3.0`), the pinned revision |
| Analyzers | `pyhanko` 0.38.0, `pdfsig` 22.12.0, `openssl-cms` 3.0.22, `qpdf` 11.3.0, `cmp` 3.8, `qpdf-prior-signatures`, `qpdf-byte-range`, `qpdf-certification-permissions` and `qpdf-pades-structure` 11.3.0, and `eu-dss` 6.2 under every scenario; `document-timestamp-imprint` 11.3.0 under `pades-baseline-lta` and `icp-brasil-ad-rt`; `demoiselle-signer` 4.4.0 under `icp-brasil-ad-rt` |
| Source configuration | `config/inputs.json` with SHA-256 `805dd8296a3c8c7ab406911eba1672e1d8f14ca8dd37ce8fc8418449d9bcecef` |
| Expected rejections | `config/expected-rejections.json` with SHA-256 `c5745bfb527b6a495a428cc80345a1a2fb69079613bba0062145e3a28bea6e81`; it declares none for these scenarios, and `pades-revoked-signer` itself expects every attempt to be rejected |
| Environment | Debian GNU/Linux 12 (bookworm), PHP 8.4.27, x86_64, host kernel 7.3.5, Landlock ABI 11 |
| Image | `sha256:558e74f8bd68d8691e3475874e5adef0de851790c83aff302c4926513490c9ca`, the same for the nine runs and for the [differential baseline](differential-baseline.md) |
| Records | One bundle per run, attached to release [`results-20261127-1`](https://github.com/SignerPHP/pdf-signing-qualification/releases/tag/results-20261127-1) and listed below |

| Scenario | Bundle | SHA-256 |
| -------- | ------ | ------- |
| `pades-baseline-b` | `run-20261127T225941Z.tar.xz` | `3b7730db36a2de056a3efa8cbb6783bbe64817b45cfd3da620222b27b418561c` |
| `pades-baseline-t` | `run-20261127T230101Z.tar.xz` | `efab2ee0ba4986fe298155602e7075fc9e3317af3f614f05ab2c6e5a9bb44896` |
| `pades-baseline-lt` | `run-20261127T230226Z.tar.xz` | `2904153953d24799e0c316852c86bc2bc426e5ee7d79ddbcc400fe5dfa3ce6c9` |
| `pades-baseline-lta` | `run-20261127T230355Z.tar.xz` | `678c62aef060b635048a869bc9e4afc8bf2f2a21379c35ec00192877a8081765` |
| `pades-certification-docmdp-2` | `run-20261127T230528Z.tar.xz` | `2d226669eb05a59c84195af961bb8ea93171536a461d11b2b2af1f60ab7b4e3f` |
| `pades-two-approval-signatures` | `run-20261127T230647Z.tar.xz` | `7e78b7dc80524d5a650726a6f6e06d3013dbb987065600f915786e2f73f0e706` |
| `pades-certification-then-approval` | `run-20261127T230811Z.tar.xz` | `6e6a2e2b1c60464623d762744023b402e53ab323e371886d1f17d04ff9b03953` |
| `icp-brasil-ad-rt` | `run-20261127T230937Z.tar.xz` | `9af8cc14c87421e74535d1459101a7efed6ecdbbf8fa63f4a1c78a9bc65b2178` |
| `pades-revoked-signer` | `run-20261127T231130Z.tar.xz` | `6b09e99c74769e554038dc62e08262f2f1152472b00888ca47f70ba442fc59d1` |

### Executions

Every scenario has the same 27 attempts, one per input of the sample, with the same outcomes: `pdf-signer` signed 23 inputs and failed on 4, with the errors of the [pilot results](pilot-results.md): `bad-direct-root.pdf` (`startxref not found`), `bad1.pdf` (`PDF version not found`), `bad35.pdf` (`Object 1 is not an object stream`) and veraPDF `7.16-t01-fail-a` (`Failed to inflate FlateDecode stream`). No attempt timed out or was not run. `bad1.pdf` is an expected rejection only under the basic scenarios, so here it counts as `failed`.

Under `pades-revoked-signer` every attempt has capability `expected_rejection`. The 23 signed inputs are expected rejections that succeeded, so 23 of the 27 attempts are failures. The 4 attempts that failed are counted as rejected as expected, but they failed for the reasons above, which have nothing to do with the revoked certificate.

### Verdicts on the outputs

Each count is of the 23 outputs; the 4 attempts without an output are `not_evaluated` for every analyzer and are left out.

These analyzers gave the same verdicts under the nine scenarios:

| Analyzer | `valid` | `invalid` | `inconclusive` | `not_applicable` |
| -------- | ------: | --------: | -------------: | ---------------: |
| `pdfsig` | 20 | 3 | 0 | 0 |
| `qpdf` | 16 | 1 | 6 | 0 |
| `cmp` | 23 | 0 | 0 | 0 |
| `qpdf-prior-signatures` | 3 | 0 | 9 | 11 |
| `qpdf-certification-permissions` | 1 | 1 | 9 | 12 |

The others, as `valid` / `invalid` / `inconclusive` of 23 outputs, with no `not_applicable`, and – where the analyzer does not run:

| Scenario | `pyhanko` | `openssl-cms` | `qpdf-byte-range` | `eu-dss` | `qpdf-pades-structure` | `document-timestamp-imprint` | `demoiselle-signer` |
| -------- | --------- | ------------- | ----------------- | -------- | ---------------------- | ---------------------------- | ------------------- |
| `pades-baseline-b` | 6 / 1 / 16 | 19 / 3 / 1 | 22 / 0 / 1 | 0 / 20 / 3 | 13 / 0 / 10 | – | – |
| `pades-baseline-t` | 0 / 1 / 22 | 0 / 22 / 1 | 0 / 14 / 9 | 0 / 20 / 3 | 0 / 14 / 9 | – | – |
| `pades-baseline-lt` | 0 / 1 / 22 | 0 / 22 / 1 | 0 / 14 / 9 | 0 / 20 / 3 | 0 / 14 / 9 | – | – |
| `pades-baseline-lta` | 0 / 1 / 22 | 0 / 22 / 1 | 0 / 14 / 9 | 0 / 20 / 3 | 0 / 14 / 9 | 0 / 14 / 9 | – |
| `pades-certification-docmdp-2` | 6 / 1 / 16 | 19 / 3 / 1 | 22 / 0 / 1 | 0 / 20 / 3 | 0 / 14 / 9 | – | – |
| `pades-two-approval-signatures` | 5 / 4 / 14 | 19 / 3 / 1 | 0 / 14 / 9 | 0 / 20 / 3 | 13 / 0 / 10 | – | – |
| `pades-certification-then-approval` | 5 / 4 / 14 | 19 / 3 / 1 | 0 / 14 / 9 | 0 / 20 / 3 | 0 / 14 / 9 | – | – |
| `icp-brasil-ad-rt` | 0 / 1 / 22 | 0 / 22 / 1 | 0 / 14 / 9 | 0 / 20 / 3 | 0 / 14 / 9 | 0 / 14 / 9 | 0 / 19 / 4 |
| `pades-revoked-signer` | 0 / 1 / 22 | 0 / 22 / 1 | 0 / 14 / 9 | 0 / 20 / 3 | 0 / 14 / 9 | – | – |

No output is `valid` for `eu-dss` under any scenario.

The baseline, each analyzer's verdict on the 27 inputs before signing, is the same in the nine runs, as `valid` / `invalid` / `inconclusive` / `not_applicable`: `pyhanko` 2 / 2 / 10 / 13, `pdfsig` 2 / 1 / 5 / 19, `openssl-cms` 2 / 3 / 10 / 12 and `qpdf` 11 / 2 / 14 / 0.

### Findings

- **`eu-dss`**: every added signature DSS finds is `TOTAL_PASSED` but detected as `PDF-NOT-ETSI`, not as a PAdES baseline signature, whatever the level, so 20 of 23 outputs are `invalid` under every scenario. On `minimal-signed-restricted.pdf` DSS finds no added signature, because `pdf-signer` writes the new field outside `/AcroForm /Fields`; under the two scenarios with two signers it finds none on `hybrid-xref.pdf`, `good17.pdf` and `good18.pdf` either, where pyHanko finds no signature at all. Its 3 `inconclusive` verdicts come from an encrypted document DSS cannot open (`nontrivial-crypt-filter.pdf`), a file with bytes before its header, which DSS does not recognize (`leading-junk.pdf`), and an input qpdf cannot list (veraPDF `6-1-11-t01-pass-a`).
- **`qpdf-pades-structure`**: a timestamp scenario (T, LT, LTA, AD-RT and the revoked signer, which is LT) writes a `/DocTimeStamp` entry into the catalog, or into `/Perms` on `minimal-signed-restricted.pdf`, and document timestamps with `/Type /Sig` that no field of the form holds: one at T and LT, which call for none, and two at LTA and AD-RT. A certification signature is written as a catalog `/DocMDP` entry instead of `/Perms /DocMDP`; on the two inputs already certified, `minimal-signed-restricted.pdf` and veraPDF `6-1-12-t02-pass-a`, `/Perms /DocMDP` keeps the input's. Under baseline B and two approval signatures the structure is `valid` on the 13 outputs whose input qpdf lists completely. The `inconclusive` verdicts are the 8 inputs qpdf cannot list completely and `nontrivial-crypt-filter.pdf`, and under those two scenarios also `leading-junk.pdf`, whose output qpdf had to recover.
- **`document-timestamp-imprint`** and **`openssl-cms`**: no document timestamp carries the digest of the bytes its range covers, so `document-timestamp-imprint` judges 14 of 23 outputs `invalid` and the rest `inconclusive`. `openssl-cms`, which checks the same link, judges 22 of 23 outputs `invalid` under the five scenarios whose outputs hold a document timestamp, against 3 under the others.
- **`pyhanko`**: under the five scenarios whose outputs hold a document timestamp it judges no output `valid`, because those timestamps are dictionaries of type `/Sig` outside the form, which pyHanko does not report, so they are not judged.
- **`demoiselle-signer`**: under `icp-brasil-ad-rt` every signature Demoiselle checks commits to no signature policy, so none is AD-RT and 19 of 23 outputs are `invalid`. It is `inconclusive` on `minimal-signed-restricted.pdf`, `nontrivial-crypt-filter.pdf` and `V4-aes.pdf`, where it ends without a report, and on veraPDF `6-1-11-t01-pass-a`, which qpdf cannot list.
- **`pades-revoked-signer`**: the revision signs with the revoked certificate instead of refusing it, and DSS reports each of the 19 signatures it judges `INDETERMINATE/REVOKED_NO_POE` besides `PDF-NOT-ETSI`.
- **`qpdf-byte-range`**: its policy requires the range of every added signature to reach the last byte of the output, which only one added signature can do. When the output adds a second signature or a document timestamp after the first, the earlier one leaves out the later revision, so `qpdf-byte-range` judges the same 14 of 23 outputs `invalid` under every scenario where the output holds more than one added dictionary. Under LTA, AD-RT and the two scenarios with two signers this follows from the policy, not from a defect of the output; under T, LT and the revoked signer the later dictionary is the document timestamp those scenarios do not call for. Its 9 `inconclusive` verdicts are on inputs qpdf had to recover or cannot read.

These runs judge the pinned revision. Pull requests merged into `pdf-signer` and `pdf-core` after it address the document timestamps, the `/Perms /DocMDP` registration, the signature policy and the refusal of a revoked certificate; once a later revision is pinned, `bin/replay` between the two under these scenarios shows what they fix.

The analyzers of the basic scenarios also run here. In the runs made for [#61](https://github.com/SignerPHP/pdf-signing-qualification/pull/61), `openssl-cms` judged every output with a document timestamp `invalid` because it verified the RFC 3161 token over the bytes of its range, while the token signs its encapsulated TSTInfo, so even a correct document timestamp failed. It now verifies such a token as encapsulated CMS and checks that the TSTInfo carries the digest of the bytes the range covers as its message imprint, so it still judges that link in the scenarios without `document-timestamp-imprint`.

### Audit of the bundles

Each bundle is a `tar.xz` archive of the run directory without `outputs/`, built as the bundles of the differential baseline are. They list no `.pdf` file and no `outputs/` path, and hold only JSON and Markdown records. The test PKI is generated in the scratch directory under `/sandbox` and removed at the end of the run, so no key, keystore, password or certificate file of it is in the run directory, and the records identify its root and signer certificates by SHA-256 only. Searched over the extracted records: no PEM block, no `%PDF-` content, no host name, user name, home directory or host path (only `/dev/null`, `/usr/lib` and `/opt` inside the image) and no private address. The word `password` appears only in messages of the validators, such as `User password = ` with an empty value for an encrypted input and pyHanko's prompt for one. The e-mail addresses belong to the test certificates of the upstream inputs (`example.org`, `verapdf.org`, `adobe.com`), and the long hexadecimal strings are the `/Contents` of the signatures those inputs already hold.

## Positive control

`tests/Validation/PadesPositiveControlTest.php` signs the synthetic minimal document with pyHanko, which is already in the image, and certificate `signer-a` of a test PKI: at PAdES baseline B, at LTA with validation information and a document timestamp, and as a certification signature. It checks that `eu-dss`, `qpdf-pades-structure`, `document-timestamp-imprint` and `openssl-cms` accept these outputs, that a baseline B output judged under an LTA scenario is `invalid`, and that `openssl-cms` and `document-timestamp-imprint` judge a document timestamp `invalid` once a byte its range covers is changed. So a validator upgrade that stops accepting a correct output fails the tests, even though no implementation in the pilot produces one yet.
