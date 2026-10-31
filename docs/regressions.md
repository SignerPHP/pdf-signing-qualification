# Regression cases

A regression case turns a reported defect into an input that can be replayed against any revision of an implementation. Each case is declared in [`config/regressions/`](../config/regressions/), following [`regression.schema.json`](../schemas/v1/regression.schema.json): the issue it comes from, the scenario it runs under, the exact input and the condition that shows the defect. Its test runs it through the attempt runner and the validator panel and fixes the outcome observed at the pinned revision, so that a new revision which changes it fails the test until the baseline is reviewed.

Regression cases are a failure-enriched selection. They are not part of the pilot sample and say nothing about overall compatibility.

## `hybrid-xref`

- **Origin:** [SignerPHP/signer-php#24](https://github.com/SignerPHP/signer-php/issues/24). When the signed file is a hybrid-reference file, the trailer written for the new revision copies the previous trailer and keeps its `/XRefStm`, which then points to the cross-reference stream of the original revision.
- **Scenario:** `basic-invisible-sha256`.
- **Input:** a synthetic PDF 1.5 of 721 bytes produced by [`HybridXrefPdf`](../tests/Fixtures/Synthetic/HybridXrefPdf.php). Its catalog, page tree and page are in an object stream that only the cross-reference stream at offset 445 lists; the cross-reference table covers objects 0 and 4 to 6, and the trailer names the stream with `/XRefStm 445`. qpdf, Poppler and pyHanko read it.
- **Condition:** the final trailer of the signed output, as `qpdf --show-object=trailer` reports it, still has `/XRefStm 445`, an offset inside the original revision.
- **Test:** [`HybridXrefRegressionTest`](../tests/Regression/HybridXrefRegressionTest.php).

### Baseline at `pdf-signer` `f27e4064be107b8a662f370a9acdcecd786da9f7`

`pdf-core` v0.1.1 already follows `/XRefStm` when it parses the input, but its `XrefContentResolver` does not remove it from the trailer it writes. The attempt succeeds, the condition is present, and the validators observe:

| Analyzer | Verdict | Evidence |
| -------- | ------- | -------- |
| `pyhanko` | `inconclusive` | pyHanko refuses to validate signatures in hybrid-reference files in its strict mode ("Settings do not permit validation of signatures in hybrid-reference files"); it does not judge the signature |
| `pdfsig` | `valid` | The signature is found and its digest matches |
| `openssl-cms` | `valid` | The CMS verifies over the signed ranges |
| `qpdf` | `valid` | No syntax or stream errors |

None of the validators shows the symptom described in the issue, a signature annotation that disappears. qpdf and Poppler give precedence to the entries of the new cross-reference table over those of the stream named by the stale `/XRefStm`, so they resolve the updated catalog and page. The condition is therefore checked on the trailer itself.

With the change proposed in the issue applied to `buildV14()`, the trailer no longer has `/XRefStm`, the test fails on that condition, and the four verdicts stay the same. pyHanko still refuses the output, because its original revision remains hybrid.

### Limits

- Only the `buildV14()` path is exercised: the last section of a hybrid-reference file is a cross-reference table, so pdf-core writes a table. The issue also changes `buildV15()`, which this case does not reach.
- The `pyhanko` verdict on this input is `inconclusive` because pyHanko's strict syntax policy refuses the file; it says nothing about the integrity of the signature. The same applies to every hybrid-reference input, including the three in the pilot sample; see [#17](https://github.com/SignerPHP/pdf-signing-qualification/issues/17).
- `qpdf:qpdf/qtest/qpdf/hybrid-xref.pdf` from the pilot sample shows the same condition and the same verdicts. The synthetic input is used so that the test runs without acquiring upstream files.
