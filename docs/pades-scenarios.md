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

One run of `bin/pilot --scenario=<id>` for each of the nine scenarios above, over the [pilot sample](pilot-sample.md) (`config/inputs.json`), made one after the other on 28 November 2026 on a Linux host, without any Compose override, after [#75](https://github.com/SignerPHP/pdf-signing-qualification/issues/75) changed the policy of `qpdf-byte-range` and [#76](https://github.com/SignerPHP/pdf-signing-qualification/issues/76) made each run record the revision of this repository. They replace the runs of release [`results-20261127-1`](https://github.com/SignerPHP/pdf-signing-qualification/releases/tag/results-20261127-1), made on 27 November 2026 at commit `2009492ea440916e6e58dc5e9a45b48e45c141e9` with image `sha256:558e74f8bd68d8691e3475874e5adef0de851790c83aff302c4926513490c9ca` and the policy `qpdf-byte-range-whole-file`, whose bundles stay in that release. Every execution outcome, every baseline and every verdict of the other analyzers is the same in both sets of runs, and so is the summary of every observation once run identifiers and generated field names are set aside; only `qpdf-byte-range` changed, as described under its finding. As the sample page says, the sample is failure-enriched: its inputs were chosen for their edge cases, so no count below is a compatibility rate.

### Revisions and environment

| Item | Value |
| ---- | ----- |
| This repository | Commit `b64a385d30ac38b1fe9adef65f2fb983a3afe786`, the head of `main` when the runs were made, with a clean working tree, as the `harness` of each `run.json` records |
| Implementation | `pdf-signer` at commit `f27e4064be107b8a662f370a9acdcecd786da9f7` (`v0.3.0`), the pinned revision |
| Analyzers | `pyhanko` 0.38.0, `pdfsig` 22.12.0, `openssl-cms` 3.0.22, `qpdf` 11.3.0, `cmp` 3.8, `qpdf-prior-signatures`, `qpdf-byte-range`, `qpdf-certification-permissions` and `qpdf-pades-structure` 11.3.0, and `eu-dss` 6.2 under every scenario; `document-timestamp-imprint` 11.3.0 under `pades-baseline-lta` and `icp-brasil-ad-rt`; `demoiselle-signer` 4.4.0 under `icp-brasil-ad-rt` |
| Source configuration | `config/inputs.json` with SHA-256 `805dd8296a3c8c7ab406911eba1672e1d8f14ca8dd37ce8fc8418449d9bcecef` |
| Expected rejections | `config/expected-rejections.json` with SHA-256 `c5745bfb527b6a495a428cc80345a1a2fb69079613bba0062145e3a28bea6e81`; it declares none for these scenarios, and `pades-revoked-signer` itself expects every attempt to be rejected |
| Environment | Debian GNU/Linux 12 (bookworm), PHP 8.4.27, x86_64, host kernel 7.3.6, Landlock ABI 11 |
| Image | `sha256:b653a08efba79b80191975170a263aebaf7ddd91783b5e1c138a3ec95c5abf4c`, built from that commit, the same for the nine runs and for the [differential baseline](differential-baseline.md) |
| Records | One bundle per run, attached to release [`results-20261128-1`](https://github.com/SignerPHP/pdf-signing-qualification/releases/tag/results-20261128-1) and listed below; the bundles of the runs they replace stay in [`results-20261127-1`](https://github.com/SignerPHP/pdf-signing-qualification/releases/tag/results-20261127-1) |

| Scenario | Bundle | SHA-256 |
| -------- | ------ | ------- |
| `pades-baseline-b` | `run-20261128T133415Z.tar.xz` | `ade93b9cc894f1b057b6369d311d861a63a7f75b9034d5160953e09a65ec5e7b` |
| `pades-baseline-t` | `run-20261128T133534Z.tar.xz` | `8c2be0aeb29b4f043cbe119443d53aa7516d1e803521d3723564ae8d659b0f04` |
| `pades-baseline-lt` | `run-20261128T133658Z.tar.xz` | `0217d2da0c5a6a9cf57d44cb72635bd8e54bd7ee3a8662858addd0b41d579a26` |
| `pades-baseline-lta` | `run-20261128T133826Z.tar.xz` | `7aee8080245537f77c74e0a05ea9aef0a942f466888e3300b5dfa32d8a755442` |
| `pades-certification-docmdp-2` | `run-20261128T134001Z.tar.xz` | `77e097d50d5ecccc9aaf5064c5772b0bcf7617d4fecc05944e1dfb4330a6522d` |
| `pades-two-approval-signatures` | `run-20261128T134119Z.tar.xz` | `68d4e3877c7b193cb6cede887914df3f55fd141ef5294350d302c43ee7f48696` |
| `pades-certification-then-approval` | `run-20261128T134243Z.tar.xz` | `1c32b233ee12c59cd077370908ddcde9e84041fb10fce4038af0f0f8130a6d21` |
| `icp-brasil-ad-rt` | `run-20261128T134409Z.tar.xz` | `6152abd2a146ef532cb18022e1e9658b7b9c0cc56e791ceb55c931895f87ee6a` |
| `pades-revoked-signer` | `run-20261128T134602Z.tar.xz` | `bfe7d627edf5f4035aa5fd559b75eb48a3f6948b7d3f4189c78728cb68923cd9` |

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
| `pades-baseline-t` | 0 / 1 / 22 | 0 / 22 / 1 | 13 / 0 / 10 | 0 / 20 / 3 | 0 / 14 / 9 | – | – |
| `pades-baseline-lt` | 0 / 1 / 22 | 0 / 22 / 1 | 13 / 0 / 10 | 0 / 20 / 3 | 0 / 14 / 9 | – | – |
| `pades-baseline-lta` | 0 / 1 / 22 | 0 / 22 / 1 | 13 / 0 / 10 | 0 / 20 / 3 | 0 / 14 / 9 | 0 / 14 / 9 | – |
| `pades-certification-docmdp-2` | 6 / 1 / 16 | 19 / 3 / 1 | 22 / 0 / 1 | 0 / 20 / 3 | 0 / 14 / 9 | – | – |
| `pades-two-approval-signatures` | 5 / 4 / 14 | 19 / 3 / 1 | 13 / 0 / 10 | 0 / 20 / 3 | 13 / 0 / 10 | – | – |
| `pades-certification-then-approval` | 5 / 4 / 14 | 19 / 3 / 1 | 13 / 0 / 10 | 0 / 20 / 3 | 0 / 14 / 9 | – | – |
| `icp-brasil-ad-rt` | 0 / 1 / 22 | 0 / 22 / 1 | 13 / 0 / 10 | 0 / 20 / 3 | 0 / 14 / 9 | 0 / 14 / 9 | 0 / 19 / 4 |
| `pades-revoked-signer` | 0 / 1 / 22 | 0 / 22 / 1 | 13 / 0 / 10 | 0 / 20 / 3 | 0 / 14 / 9 | – | – |

No output is `valid` for `eu-dss` under any scenario.

The baseline, each analyzer's verdict on the 27 inputs before signing, is the same in the nine runs, as `valid` / `invalid` / `inconclusive` / `not_applicable`: `pyhanko` 2 / 2 / 10 / 13, `pdfsig` 2 / 1 / 5 / 19, `openssl-cms` 2 / 3 / 10 / 12 and `qpdf` 11 / 2 / 14 / 0.

### Findings

- **`eu-dss`**: 20 of 23 outputs are `invalid` under every scenario, and every added signature DSS judges is detected as `PDF-NOT-ETSI`, not as a PAdES baseline signature, whatever the level. Outside `pades-revoked-signer`, each added signature of 18 outputs is `TOTAL_PASSED` with that format (15 outputs under the two scenarios with two signers). On veraPDF `6-1-3-t02-fail-a` the added signature is `INDETERMINATE/SIG_CONSTRAINTS_FAILURE`, and under the two scenarios with two signers the second one is `TOTAL_FAILED/FORMAT_FAILURE`. Under `pades-revoked-signer` no added signature is `TOTAL_PASSED` (see below). On `minimal-signed-restricted.pdf` DSS finds no added signature under any scenario, and pyHanko does not see it through the form either; in that output, which the bundles do not hold, the new field is listed in a `/Fields` entry that `pdf-signer` adds to the catalog itself, not in `/AcroForm /Fields`. Under the two scenarios with two signers DSS and pyHanko find no added signature on `hybrid-xref.pdf`, `good17.pdf` and `good18.pdf` either: in those outputs the last trailer still carries the input's `/XRefStm`, the condition of the `hybrid-xref` regression case, and the catalog qpdf reads through it has no `/AcroForm`. Its 3 `inconclusive` verdicts come from an encrypted document DSS cannot open (`nontrivial-crypt-filter.pdf`), a file with bytes before its header, which DSS does not recognize (`leading-junk.pdf`), and an input qpdf cannot list (veraPDF `6-1-11-t01-pass-a`), where DSS reports a `TOTAL_FAILED/FORMAT_FAILURE` signature in `PKCS7-B` that cannot be told apart from the input's.
- **`qpdf-pades-structure`**: a timestamp scenario (T, LT, LTA, AD-RT and the revoked signer, which is LT) writes a `/DocTimeStamp` entry into the catalog, or into `/Perms` on `minimal-signed-restricted.pdf`, and document timestamps with `/Type /Sig` that no field of the form holds: one at T and LT, which call for none, and two at LTA and AD-RT. A certification signature is not registered as `/Perms /DocMDP`: it is written as a catalog `/DocMDP` entry, except under `pades-certification-then-approval` on `hybrid-xref.pdf`, `good17.pdf` and `good18.pdf`, where the catalog qpdf reads holds neither, for the reason given under `eu-dss`; on the two inputs already certified, `minimal-signed-restricted.pdf` and veraPDF `6-1-12-t02-pass-a`, `/Perms /DocMDP` keeps the input's. The `inconclusive` verdicts are the 8 outputs whose input qpdf cannot list completely and `nontrivial-crypt-filter.pdf`, whose output qpdf cannot read, and under baseline B and two approval signatures also `leading-junk.pdf`, whose output qpdf had to recover and where no problem is found. Under those two scenarios the structure is `valid` on the other 13 outputs.
- **`document-timestamp-imprint`** and **`openssl-cms`**: no document timestamp carries the digest of the bytes its range covers, so `document-timestamp-imprint` judges 14 of 23 outputs `invalid` and the rest `inconclusive`. `openssl-cms`, which checks the same link, judges 22 of 23 outputs `invalid` under the five scenarios whose outputs hold a document timestamp, against 3 under the others.
- **`pyhanko`**: under the five scenarios whose outputs hold a document timestamp it judges no output `valid`, because those timestamps are dictionaries of type `/Sig` outside the form, which pyHanko does not report, so they are not judged.
- **`demoiselle-signer`**: under `icp-brasil-ad-rt` every signature Demoiselle checks commits to no signature policy, so none is AD-RT and 19 of 23 outputs are `invalid`. It is `inconclusive` on `minimal-signed-restricted.pdf`, `nontrivial-crypt-filter.pdf` and `V4-aes.pdf`, where it ends without a report, and on veraPDF `6-1-11-t01-pass-a`, which qpdf cannot list.
- **`pades-revoked-signer`**: the revision signs with the revoked certificate instead of refusing it, and DSS reports the added signature `INDETERMINATE/REVOKED_NO_POE`, with format `PDF-NOT-ETSI`, on 20 outputs: the 19 `invalid` outputs other than `minimal-signed-restricted.pdf`, where it finds none, and the `inconclusive` output of veraPDF `6-1-11-t01-pass-a`.
- **`qpdf-byte-range`**: under its policy `qpdf-byte-range-revisions`, each of several added signatures must cover the revision that first holds its dictionary, and a revision without a signature after it may only update the DSS. Under the seven scenarios whose outputs add more than one dictionary (a second signature, or one or two document timestamps), 13 of 23 outputs are `valid`: each range ends at the end of its own revision, and the revisions without a signature that `pdf-signer` writes, after the document timestamp under LT and the revoked signer and between the two timestamps under LTA and AD-RT, change only the `/DSS` entry of the catalog and the objects of the DSS. Under T, LT and the revoked signer the later dictionary is the document timestamp those scenarios do not call for; this analyzer judges the coverage of the bytes whatever the scenario, and `qpdf-pades-structure` reports the extra timestamp. The 10 `inconclusive` verdicts are the 8 outputs whose input qpdf had to recover, judged by the rule for one signature, under which every range must reach the end of the output, because a signature the recovery lost from the input's listing would count as added; `nontrivial-crypt-filter.pdf`, whose input qpdf cannot read; and `leading-junk.pdf`, whose output qpdf has to recover, so its revisions cannot be read. Under baseline B and `pades-certification-docmdp-2`, where the output adds one signature, the verdicts are the same as in `results-20261127-1`: 22 `valid` and `nontrivial-crypt-filter.pdf` `inconclusive`. In the runs of `results-20261127-1`, under the policy `qpdf-byte-range-whole-file`, which required every added range to reach the last byte of the output, the seven scenarios gave 0 / 14 / 9: the 13 outputs now `valid` and `leading-junk.pdf` were `invalid`.

These runs judge the pinned revision. Pull requests merged into `pdf-signer` and `pdf-core` after it address the document timestamps, the `/Perms /DocMDP` registration, the signature policy and the refusal of a revoked certificate; once a later revision is pinned, `bin/replay` between the two under these scenarios shows what they fix.

The analyzers of the basic scenarios also run here. In the runs made for [#61](https://github.com/SignerPHP/pdf-signing-qualification/pull/61), `openssl-cms` judged every output with a document timestamp `invalid` because it verified the RFC 3161 token over the bytes of its range, while the token signs its encapsulated TSTInfo, so even a correct document timestamp failed. It now verifies such a token as encapsulated CMS and checks that the TSTInfo carries the digest of the bytes the range covers as its message imprint, so it still judges that link in the scenarios without `document-timestamp-imprint`.

### Audit of the bundles

Each bundle is a `tar.xz` archive of the run directory without `outputs/`, built as the bundles of the differential baseline are and audited in the same way. They list no `.pdf` file and no `outputs/` path, and hold only JSON and Markdown records. The test PKI is generated in the scratch directory under `/sandbox` and removed at the end of the run, so no key, keystore, password or certificate file of it is in the run directory, and the records identify its root and signer certificates by SHA-256 only. Searched over the extracted records: no PEM block, no `%PDF-` content, no host name, user name, home directory or host path, and no private address. The `harness` of each `run.json` holds only the commit and the state of the working tree, `clean`, and no path. The only absolute paths are `/dev/null`, `/usr/lib` and `/opt` inside the image, and the system directories of the confinement record, and the relative paths are those of the inputs and outputs in the mount, `var/cache/inputs/files/...` and `var/runs/<run>/outputs/<sha256>.pdf`, inside the messages of qpdf. The word `password` appears only in messages of the validators: qpdf's `invalid password` and `User password = ` with an empty value, `Supplied password is user password` or `owner password`, for an encrypted input or output, and pyHanko's prompt for one. The e-mail addresses belong to the test certificates of the upstream inputs (`example.org`, `verapdf.org`, `adobe.com`), and the long hexadecimal strings are the `/Contents` of the signatures those inputs already hold.

## Positive control

`tests/Validation/PadesPositiveControlTest.php` signs the synthetic minimal document with pyHanko, which is already in the image, and certificate `signer-a` of a test PKI: at PAdES baseline B, at LTA with validation information and a document timestamp, and as a certification signature. It checks that `eu-dss`, `qpdf-pades-structure`, `document-timestamp-imprint` and `openssl-cms` accept these outputs, that `qpdf-byte-range` judges the LTA one `inconclusive` only because its last revision, after the document timestamp, changes the document information dictionary besides the DSS, which ETSI EN 319 142-1 does not exempt, that a baseline B output judged under an LTA scenario is `invalid`, and that `openssl-cms` and `document-timestamp-imprint` judge a document timestamp `invalid` once a byte its range covers is changed. So a validator upgrade that stops accepting a correct output fails the tests, even though no implementation in the pilot produces one yet.
