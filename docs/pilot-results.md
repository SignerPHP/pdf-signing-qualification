# Pilot results

First complete run of the pilot over the [pilot sample](pilot-sample.md), produced with `bin/pilot`. As the sample page says, these numbers are not a compatibility rate: the inputs were chosen for their edge cases.

## Revisions and environment

| Item | Value |
| ---- | ----- |
| Scenario | `basic-invisible-sha256` |
| Implementation | `pdf-signer` at commit `f27e4064be107b8a662f370a9acdcecd786da9f7` |
| Analyzers | `pyhanko` 0.38.0, `pdfsig` 22.12.0, `openssl-cms` 3.0.22, `qpdf` 11.3.0 |
| Source configuration | `config/inputs.json` with SHA-256 `c6ca54495ff2d87abe592c552792ed468b5419db392b6e59237d3ccb753952cc` |
| Environment | Debian GNU/Linux 12 (bookworm), PHP 8.4.27, x86_64, project image built from this revision's `Dockerfile` |

Two runs on these revisions gave the same execution outcome for each of the 27 attempts and the same verdict for each of the 108 observations. Their summaries differ only in the names of the signature fields, which `pdf-signer` generates at random.

## Totals

| Outcome | Attempts |
| ------- | -------: |
| `succeeded` | 23 |
| `failed` | 4 |
| `timed_out` | 0 |
| `not_run` | 0 |
| Total | 27 |

| Analyzer | `valid` | `invalid` | `inconclusive` | `not_evaluated` | `not_applicable` |
| -------- | ------: | --------: | -------------: | --------------: | ---------------: |
| `pyhanko` | 8 | 4 | 11 | 4 | 0 |
| `pdfsig` | 20 | 3 | 0 | 4 | 0 |
| `openssl-cms` | 19 | 3 | 1 | 4 | 0 |
| `qpdf` | 16 | 1 | 6 | 4 | 0 |

The 4 `not_evaluated` per analyzer are the 4 failed attempts, which produced no output.

## Failures found

### Signing failures

| Input | Error | Reading |
| ----- | ----- | ------- |
| `qpdf/qtest/qpdf/bad1.pdf` | PDF version not found | Not a PDF; the rejection is expected |
| `qpdf/qtest/qpdf/bad-direct-root.pdf` | startxref not found | Damaged header and no `startxref`; qpdf reads it with warnings |
| `qpdf/qtest/qpdf/bad35.pdf` | Object 1 is not an object stream | qpdf reads it with warnings |
| veraPDF `PDF_UA-1/7.16 Security/7.16-t01-fail-a.pdf` | Failed to inflate FlateDecode stream | R 4 encryption that qpdf opens with the empty user password; a candidate defect |

The attempt records still carry capability `supported` for `bad1.pdf`; declaring expected rejections per input is not implemented yet.

### Structure made worse by signing

Among the inputs whose own `qpdf --check` exits with 0, two signed outputs are not `valid` for `qpdf`, so the problem comes from the signer:

- `qpdf/qtest/qpdf/V4-aes.pdf` (R 4, AES): qpdf cannot decode the stream data of object 3 after signing (`incorrect header check`).
- `qpdf/qtest/qpdf/leading-junk.pdf` (14 bytes before the header): qpdf no longer finds the cross-reference section at the recorded offset and reconstructs it.

The other `inconclusive` structure verdicts (`bad-xref-entry.pdf`, `xref-errors.pdf`, `bad3.pdf`, `xref-with-short-size.pdf`) are on inputs that qpdf already reported with warnings.

### Signature verdicts

- **Hybrid cross-reference files.** On `hybrid-xref.pdf`, `good17.pdf` and `good18.pdf`, pyHanko judges the new signature `invalid` because its settings do not permit validating signatures in hybrid-reference files, while `pdfsig` and `openssl-cms` judge it `valid`. This is the behavior tracked by the [`hybrid-xref`](https://github.com/SignerPHP/pdf-signing-qualification/issues/8) regression case.
- **Inputs that were already signed.** On veraPDF `6-1-12-t01-pass-a`, `6-1-12-t02-pass-a` and `6-1-11-t01-pass-a`, `pdfsig` and `openssl-cms` judge the output `invalid` because of the signature that was already in the input, which `pdfsig` could not verify or found with a digest mismatch in the input itself; pyHanko judges that same signature `invalid` on `6-1-12-t02-pass-a`. The signature added by `pdf-signer` is valid for `pdfsig` and `openssl-cms`. The `cms_signature` adapters judge every signature, so without a baseline of the input's own signatures these verdicts do not separate a pre-existing problem from a signer defect.
- **Encrypted outputs.** No validator is given a password. `nontrivial-crypt-filter.pdf`, which needs the user password `asdfqwer`, was signed without it, and its output cannot be opened by qpdf (`invalid password`), so `qpdf` reports `invalid` and the other adapters `inconclusive`. pyHanko is also `inconclusive` on `V4-aes.pdf` and the PDF/A-4 `6-1-3-t02-fail-a` with a generic processing error.
- **pyHanko strict parsing.** pyHanko is `inconclusive` on 8 more outputs because it refuses to read them in strict mode: illegal header (`leading-junk.pdf`), broken cross-reference sections (`xref-errors.pdf`, `bad3.pdf`, `xref-with-short-size.pdf`, `6-1-4-t02-pass-a`), and cross-reference stream objects that are clobbered (`6-1-4-t03-fail-a`, `6-1-12-t01-pass-a`, `6-1-11-t01-pass-a`). Whether the last case comes from the input or from the signed revision has not been examined.

## Open questions for the next stages

- A baseline of every validator on the input itself, not only `qpdf --check`, so that inherited problems are not read as signer defects.
- A way to declare expected rejections, such as `bad1.pdf` and inputs that need a password, as capability `expected_rejection`.
- Whether validators should be given the password of an encrypted input.
