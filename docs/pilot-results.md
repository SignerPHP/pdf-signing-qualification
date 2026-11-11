# Pilot results

First complete run of the pilot over the [pilot sample](pilot-sample.md), produced with `bin/pilot`. As the sample page says, these numbers are not a compatibility rate: the inputs were chosen for their edge cases.

## Revisions and environment

| Item | Value |
| ---- | ----- |
| Scenario | `basic-invisible-sha256` |
| Implementation | `pdf-signer` at commit `f27e4064be107b8a662f370a9acdcecd786da9f7` |
| Analyzers | `pyhanko` 0.38.0, `pdfsig` 22.12.0, `openssl-cms` 3.0.22, `qpdf` 11.3.0 |
| Source configuration | `config/inputs.json` with SHA-256 `c6ca54495ff2d87abe592c552792ed468b5419db392b6e59237d3ccb753952cc` |
| Expected rejections | `config/expected-rejections.json` with SHA-256 `fd1c9906de8e57f60706d3aa035a53f4c148fbd5b6ca3b63593f30670dd33b21` |
| Environment | Debian GNU/Linux 12 (bookworm), PHP 8.4.27, x86_64 |
| Image | `sha256:6fb98b47025a92b3a123f7cd300242404c92cb5be53e6e163b0b7b2962aa3324`, the same for both runs |
| Records | `run-20261106T150907Z.tar.xz`, SHA-256 `86b605ac29f1e549f404dbc24db244bcd85be72423e65f32de3c22a61d2408f0` and `run-20261106T151233Z.tar.xz`, SHA-256 `9f21feaced898918c735fa8746357f5e8c2f76c0dc7bbdb79879dd1236b8b45d`, attached to release [`results-20261110-1`](https://github.com/SignerPHP/pdf-signing-qualification/releases/tag/results-20261110-1). These runs predate the copy of the source configuration and `report.md` in a run directory, so the bundles hold neither; the configuration with that SHA-256 is `config/inputs.json` at commit `0303f8c5c1c3901fd9cd0009c8650b90e9e729f3`, which `bin/report` cannot read because it predates the `selection` property. |

Two runs on these revisions gave the same execution outcome for each of the 27 attempts and the same verdict for each of the 108 observations. Their summaries differ only in the names of the signature fields, which `pdf-signer` generates at random.

These runs were judged under the earlier policy of `pyhanko`, before [#50](https://github.com/SignerPHP/pdf-signing-qualification/issues/50) required pyHanko to report every signature that qpdf lists in the output. They are not run again. Judging their stored outputs and inputs again with the current adapter changes two verdicts and one baseline in each run, all from pyHanko. On `qpdf/qtest/qpdf/minimal-signed-restricted.pdf`, `pdf-signer` writes the new signature field outside `/AcroForm /Fields`, pyHanko judges only the signature the input already had, and its verdict goes from `valid` to `inconclusive`. On `qpdf/qtest/qpdf/bad-xref-entry.pdf`, qpdf lists the signatures of the output only by reconstructing its cross-reference table, so the listing cannot show that pyHanko judged every one, and the verdict goes from `valid` to `inconclusive`. The `pyhanko` row below would read 6 `valid` and 16 `inconclusive`. On veraPDF `6-1-12-t01-pass-a`, where pyHanko finds no signature but qpdf lists one, its baseline goes from `not_applicable` to `inconclusive`, so the `pyhanko` row of the baseline would read 10 `inconclusive` and 13 `not_applicable`.

## Totals

| Outcome | Attempts |
| ------- | -------: |
| `succeeded` | 23 |
| `failed` | 3 |
| `timed_out` | 0 |
| `not_run` | 0 |
| Rejected as expected | 1 |
| Total | 27 |

| Analyzer | `valid` | `invalid` | `inconclusive` | `not_evaluated` | `not_applicable` |
| -------- | ------: | --------: | -------------: | --------------: | ---------------: |
| `pyhanko` | 8 | 1 | 14 | 4 | 0 |
| `pdfsig` | 20 | 3 | 0 | 4 | 0 |
| `openssl-cms` | 19 | 3 | 1 | 4 | 0 |
| `qpdf` | 16 | 1 | 6 | 4 | 0 |

The 4 `not_evaluated` per analyzer are the 3 failed attempts and the expected rejection, which produced no output.

## Baseline

Each analyzer's verdict on the 27 inputs themselves, before signing. The verdicts on the outputs above did not change when the baseline was added, and `pdf-signer` and `jsignpdf` runs give the same baseline.

| Analyzer | `valid` | `invalid` | `inconclusive` | `not_applicable` |
| -------- | ------: | --------: | -------------: | ---------------: |
| `pyhanko` | 2 | 2 | 9 | 14 |
| `pdfsig` | 2 | 1 | 5 | 19 |
| `openssl-cms` | 2 | 3 | 10 | 12 |
| `qpdf` | 11 | 2 | 14 | 0 |

The `qpdf` totals match the exit codes written by hand in the [pilot sample](pilot-sample.md). `not_applicable` is an analyzer that read the input and found no signature in it. `openssl-cms` is `inconclusive` on 8 more inputs than it would be on readable files, because it does not take "no signature" from a listing of a file that qpdf had to recover, and `pdfsig` is `inconclusive` on `nontrivial-crypt-filter.pdf`, which it cannot decode without the password.

## Failures found

### Expected rejection

`qpdf/qtest/qpdf/bad1.pdf` is not a PDF, and `config/expected-rejections.json` declares it, so its attempt has capability `expected_rejection`. It failed with `PDF version not found`, as expected.

### Signing failures

| Input | Error | Reading |
| ----- | ----- | ------- |
| `qpdf/qtest/qpdf/bad-direct-root.pdf` | startxref not found | Damaged header and no `startxref`; qpdf reads it with warnings |
| `qpdf/qtest/qpdf/bad35.pdf` | Object 1 is not an object stream | qpdf reads it with warnings |
| veraPDF `PDF_UA-1/7.16 Security/7.16-t01-fail-a.pdf` | Failed to inflate FlateDecode stream | R 4 encryption that qpdf opens with the empty user password; a candidate defect |

### Structure made worse by signing

Among the inputs whose own `qpdf --check` exits with 0, two signed outputs are not `valid` for `qpdf`, so the problem comes from the signer:

- `qpdf/qtest/qpdf/V4-aes.pdf` (R 4, AES): qpdf cannot decode the stream data of object 3 after signing (`incorrect header check`).
- `qpdf/qtest/qpdf/leading-junk.pdf` (14 bytes before the header): qpdf no longer finds the cross-reference section at the recorded offset and reconstructs it.

The other `inconclusive` structure verdicts (`bad-xref-entry.pdf`, `xref-errors.pdf`, `bad3.pdf`, `xref-with-short-size.pdf`) are on inputs that qpdf already reported with warnings.

### Signature verdicts

- **Hybrid cross-reference files.** On `hybrid-xref.pdf`, `good17.pdf` and `good18.pdf`, pyHanko is `inconclusive` because its strict syntax settings do not permit validating signatures in hybrid-reference files ([#17](https://github.com/SignerPHP/pdf-signing-qualification/issues/17)), so it does not judge the new signature; `pdfsig` and `openssl-cms` judge it `valid`. The stale `/XRefStm` kept by the signer on these inputs is tracked by the [`hybrid-xref`](https://github.com/SignerPHP/pdf-signing-qualification/issues/8) regression case.
- **Inputs that were already signed.** On veraPDF `6-1-12-t01-pass-a`, `6-1-12-t02-pass-a` and `6-1-11-t01-pass-a`, `pdfsig` and `openssl-cms` judge the output `invalid`, and the baseline shows that the problem is in the input: `openssl-cms` is `invalid` on all three inputs. On `6-1-12-t02-pass-a` every `cms_signature` analyzer is `invalid` on the input. On the other two, the `/ByteRange` of the prior signature reaches past the end of the input, so `openssl-cms` reports that it does not fit the file and `pdfsig` leaves it undecided (`inconclusive`). Signing makes the file longer, so `pdfsig` can then compute that range and reports a digest mismatch: its verdict moves from `inconclusive` to `invalid` with no change to the prior signature. The signature added by `pdf-signer` is valid for `pdfsig` and `openssl-cms`.
- **Analyzers that disagree on an input.** pyHanko is `not_applicable` on `6-1-12-t01-pass-a`, where `pdfsig` and `openssl-cms` find a signature. The disagreement stays in the records.
- **Encrypted outputs.** No validator is given a password. `nontrivial-crypt-filter.pdf`, which needs the user password `asdfqwer`, was signed without it, and its output cannot be opened by qpdf (`invalid password`), so `qpdf` reports `invalid` and the other adapters `inconclusive`. This is a known limit: passwords stay out of the configuration, and the input is not declared as an expected rejection because the attempt succeeded. pyHanko is also `inconclusive` on `V4-aes.pdf` and the PDF/A-4 `6-1-3-t02-fail-a` with a generic processing error.
- **pyHanko strict parsing.** Besides the hybrid files, pyHanko is `inconclusive` on 8 more outputs because it refuses to read them in strict mode: illegal header (`leading-junk.pdf`), broken cross-reference sections (`xref-errors.pdf`, `bad3.pdf`, `xref-with-short-size.pdf`, `6-1-4-t02-pass-a`), and cross-reference stream objects that are clobbered (`6-1-4-t03-fail-a`, `6-1-12-t01-pass-a`, `6-1-11-t01-pass-a`). The baseline shows that pyHanko reads those three inputs in strict mode, and that it also reads the outputs of those inputs signed by JSignPdf, so this refusal appears only on the `pdf-signer` outputs and is a candidate defect, not an inherited problem. Its cause has not been examined.

## Open questions for the next stages

- Whether validators should be given the password of an encrypted input.
