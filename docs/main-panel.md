# Main panel

The main qualification panel, declared in [`config/main-panel/inputs.json`](../config/main-panel/inputs.json): 106 inputs drawn from a pool of 3404 candidates, 49 from the [qpdf](https://github.com/qpdf/qpdf) test suite, 34 from the [veraPDF corpus](https://github.com/veraPDF/veraPDF-corpus) and 23 from the [pyHanko](https://github.com/MatthiasValvekens/pyHanko) test data. It is separate from the failure-enriched [pilot sample](pilot-sample.md), which stays as it is in `config/inputs.json`, and from the [regression cases](regressions.md), which are replayed on their own inputs and never counted with it.

`bin/pilot --panel=main` runs it.

## Population

**The panel is stratified, and the population it describes is its pool.** The pool is every PDF that the [selection plan](../config/main-panel/selection.json) admits from three open test suites at pinned revisions. These files were written to test PDF readers, validators and signers, so they over-represent edge cases, damaged files and conformance failures compared with documents found in practice, and most of them are a page or two long.

- **What a result describes.** A share computed over the inputs of one stratum estimates that share for the candidates of the same stratum in the pool. The quota gives rare strata far more weight in the panel than in the pool, so a share over all inputs is not the share of the pool: run reports say so, and give every count by stratum.
- **What it does not describe.** Documents found in practice, documents produced by a given application, scanned documents or any population outside these three test suites. No overall compatibility rate for PDFs in general follows from it.

## How it was drawn

`composer select-panel`, in the `app` service, turns the plan into the panel and is reproducible: run again at the same revisions and with the same qpdf, it writes the same `inputs.json` and [`pool.json`](../config/main-panel/pool.json).

1. It lists every regular file of each source at its pinned commit through the Git trees API, so a symbolic link is never a candidate, and keeps the paths that match an include pattern and no exclusion. A file whose path the sources schema cannot express as an input path is counted as unaddressable and is not a candidate.
2. It downloads each candidate into `var/cache/candidates/` and refuses one whose bytes do not match the Git object id of the listing.
3. It records the strata of each candidate with qpdf 11.3.0 from the project container, as described below. A file on which qpdf crashes, does not finish within 120 seconds or ends with an exit code other than 0, 2 or 3 is recorded as an analyzer failure with its reason, and a file that gets no stratum is counted as unclassified; neither is a candidate.
4. It orders the candidates by the SHA-256 of `main-panel-1:<source>:<path>` and takes each one while any of its strata holds fewer than 12 inputs. A stratum with 12 candidates or fewer is therefore taken whole.

`pool.json` records the SHA-256 of the plan it was built from, the qpdf version, the candidates and the drawn inputs per source and stratum, the files each exclusion left out, and the unaddressable, unclassified and analyzer-failure files. `bin/select-panel` writes `inputs.json` and `pool.json` in full before it replaces either, so a failed write leaves the previous pair in place.

## Strata

The strata are those of the sources schema. The classifier gives the 27 pilot inputs exactly the strata versioned for them in `config/inputs.json`, all fourteen included, and a test checks that an input declared in both configurations has the same strata in each. The cross-reference strata come from the bytes: the last `startxref` has to point to a table or an object, `/XRefStm` makes a file hybrid, and a file with both tables and cross-reference streams but no `/XRefStm` has none of the four, so it is unclassified unless another stratum applies; no candidate is in that case. `objstm` and `encrypted` also come from the bytes, and so does `incremental`: a file has one revision per `startxref`, except that the first-page section of a linearized file is not a revision of its own, so a linearized file needs three `startxref` to be incremental; `signed`, `forms`, `large`, `timestamped` and `revocation` come from `qpdf --json` when qpdf opens the file and from the bytes otherwise; `malformed` comes from the warnings of `qpdf --check`. `encrypted` is the standard security handler only, so a file encrypted for public-key recipients is not in it.

| Stratum | Meaning | Pool | Panel |
| ------- | ------- | ---: | ----: |
| `classic` | Cross-reference table only | 2806 | 55 |
| `stream` | Cross-reference streams only | 571 | 33 |
| `hybrid` | Cross-reference table whose trailer points to a cross-reference stream | 6 | 6 |
| `none` | No usable cross-reference section | 21 | 12 |
| `objstm` | Object streams | 155 | 23 |
| `encrypted` | Standard security handler | 45 | 14 |
| `incremental` | More than one revision, not counting the first-page section of a linearized file | 251 | 26 |
| `signed` | At least one signature dictionary with a `/ByteRange` | 43 | 17 |
| `malformed` | qpdf reports damage, an invalid cross-reference entry or stream, or no header | 57 | 19 |
| `forms` | A form field, as qpdf lists them from the page annotations, that is not a signature field | 148 | 15 |
| `legacy` | Header version below 1.3 | 5 | 5 |
| `large` | At least 2 MiB or 1000 pages | 13 | 12 |
| `timestamped` | A document timestamp or a signature timestamp token | 5 | 5 |
| `revocation` | A document security store or non-empty Adobe revocation information | 3 | 3 |

## Sources and licenses

Every candidate carries the license of its source's documents with the evidence for it, and a test refuses any license outside the free licenses the project accepts.

| Source | Revision | Document license | Evidence | Pool | Panel |
| ------ | -------- | ---------------- | -------- | ---: | ----: |
| qpdf | `4eba95899886e851cc41d76886483b347612f2a8` | Apache-2.0 | Debian's [`debian/copyright`](https://sources.debian.org/src/qpdf/12.4.2-1/debian/copyright/), as for the pilot sample | 675 | 49 |
| veraPDF corpus | `bb75f4f0073d9350dfd058c0162a367e6fadf25e` | CC-BY-4.0 | The corpus [README](https://github.com/veraPDF/veraPDF-corpus/blob/bb75f4f0073d9350dfd058c0162a367e6fadf25e/README.md) | 2599 | 34 |
| pyHanko | `0a9f7977343f4cc9d5926314b5a2d180028e58a0` | MIT | The repository [LICENSE](https://github.com/MatthiasValvekens/pyHanko/blob/0a9f7977343f4cc9d5926314b5a2d180028e58a0/LICENSE), for files the maintainer wrote | 130 | 23 |

pyHanko has no record of the license of each file like qpdf's Debian copyright. Its Git history shows that its maintainer added 131 of its 134 test PDFs, which the repository's MIT license covers; the plan keeps only those, and leaves out the third-party file and the three files added by contributors. It is the only source found with timestamps and revocation information.

### Files left out of the pool

| Source | Pattern | Files | Reason |
| ------ | ------- | ----: | ------ |
| qpdf | `qpdf/qtest/qpdf/short-O-U.pdf` | 1 | The test suite says it copies the encryption parameters of a file that was sent privately |
| qpdf | `*/issue-*.pdf` | 25 | Reproduces a bug report, so it may derive from a file a reporter attached rather than one written by the qpdf maintainers |
| qpdf | `*/fuzz-*.pdf` | 1 | Produced by a fuzzer from inputs whose origin is not recorded |
| veraPDF corpus | `Isartor test files/*` | 205 | The Isartor test suite is published by the PDF Association, not by veraPDF, so the corpus README does not establish its license |
| veraPDF corpus | `TWG test files/*` | 85 | Written by the PDF Association Technical Working Group, not by veraPDF, so the corpus README does not establish their license |
| pyHanko | `*/safedocs/*` | 1 | Comes from the DARPA SafeDocs project, not from the pyHanko maintainer |
| pyHanko | `*/layout-tests/default-font.pdf` | 1 | Added by a contributor rather than the maintainer |
| pyHanko | `*/layout-tests/text-stamp-color.pdf` | 1 | Added by a contributor rather than the maintainer |
| pyHanko | `*/malformed-xref-space-padded.pdf` | 1 | Added by a contributor rather than the maintainer |

Another 31 files are unaddressable: 14 qpdf files named after their encryption parameters, such as `enc-R2,V1,O=master.pdf`, and 17 veraPDF files under a directory whose name has parentheses. The input path of the sources schema does not allow `(`, `)`, `,` or `=`.

One file is an analyzer failure: `qpdf --check` 11.3.0 crashes with signal 11 on `qpdf/qtest/qpdf/xref-compressed-in-compressed.pdf`, the file of qpdf bug #1750, so its strata cannot be recorded.

### Rejected sources

| Source | Reason |
| ------ | ------ |
| https://github.com/mozilla/pdf.js | The PDFs in `test/pdfs` come from bug reports and other projects, and none has an established license |
| https://gitlab.freedesktop.org/poppler/test | Its COPYING (GPL-2.0) covers the test programs; the PDFs come from bug reports, and no record establishes the license of each document |
| https://github.com/apache/pdfbox | Most test PDFs are attachments of issue reports, so the Apache-2.0 license of the code does not establish theirs |
| https://github.com/Hopding/pdf-lib | The test assets were gathered from many places, and no record establishes the license of each document |
| https://github.com/esig/dss | The PAdES validation samples come from many signers and products, and no record establishes the license of each document |
| https://github.com/bfosupport/pdfa-testsuite | Its PDF/A-2 cases overlap those of the veraPDF corpus, which says it complements this suite, and its CC-BY-3.0 license is not yet on the list of licenses this project accepts |

## Coverage gaps

- **Documents with thousands of pages.** No candidate has 100 pages or more; the only one in the listed repositories, an Isartor file with 10000 pages, is excluded with the rest of Isartor. `large` is reached by size only, from 2.3 MiB to 10.4 MiB.
- **Timestamps and revocation information.** The pool has 5 timestamped and 3 revocation candidates, all in the panel, so a share in these strata rests on very few documents.
- **PDF versions below 1.3.** The pool has 5, all from qpdf, all in the panel.
- **Visible signature appearances.** Not a stratum: the [visible-appearance scenario](visible-appearance.md) runs over this panel as drawn, so it has no input chosen for its placement, such as rotated pages or a `/MediaBox` away from the origin.
- **Unaddressable files.** The 31 files above, until the input path of the schemas admits their names.
- **Files qpdf cannot analyze.** `xref-compressed-in-compressed.pdf`, until the container has a qpdf that does not crash on it.

## Expected rejections

[`config/main-panel/expected-rejections.json`](../config/main-panel/expected-rejections.json) declares one input, under each scenario: `qpdf:qpdf/qtest/qpdf/enc-XI-long-password.pdf` needs a user password that the qpdf test suite passes to it, and no scenario gives the signer one. Every other failure on the panel is a result.

## Sandbox limits

The panel fits the limits of the `sandbox` service and of each attempt, and no limit was raised for it. A run of `pdf-signer` at `f27e4064be107b8a662f370a9acdcecd786da9f7` on 2026-11-07 took four minutes for the 106 inputs, from 20:05:10Z to 20:09:11Z, under the limits its `run.json` records: 120 seconds, 1 GiB of address space, 256 MiB per file and 512 MiB of work directory per attempt. Every `large` input, up to 10.4 MiB, was signed within them. The 106 inputs weigh 71.5 MiB in all.

In that run, 101 attempts succeeded, 3 failed, 1 was rejected as expected and 1 timed out. The timeout was on `qpdf:qpdf/qtest/qpdf/bad-content.pdf`, a damaged file of 132 KiB whose trailer qpdf cannot recover either; it is not a matter of size, and it is a result to report, not a limit to raise.
