# Pilot sample

The first inputs used to try the method end to end, declared in [`config/inputs.json`](../config/inputs.json): 27 PDFs, 16 from the [qpdf](https://github.com/qpdf/qpdf) test suite at `4eba95899886e851cc41d76886483b347612f2a8` and 11 from the [veraPDF corpus](https://github.com/veraPDF/veraPDF-corpus) at `bb75f4f0073d9350dfd058c0162a367e6fadf25e`.

**This sample is not representative of overall compatibility.** It was chosen on purpose from test suites that collect parser edge cases, damaged files and conformance failures, so a rate computed over it says nothing about how often an implementation succeeds on documents found in practice. It exists to check that inputs can be acquired, that the strata can be told apart, that the validators can judge the outputs and what a run costs.

## Licenses

Only inputs whose own license is established as free are selected, like this project; a test fails otherwise. A repository's license does not establish the license of every file in it, so each input records the evidence for its own license next to the license of its source.

- **qpdf.** The repository is Apache-2.0. Debian's [`debian/copyright`](https://sources.debian.org/src/qpdf/12.4.2-1/debian/copyright/) for qpdf 12.4.2-1 records every file as Apache-2.0 except `appimage/qpdf.appdata.xml` (Expat), the embedded `qtest/` framework (Artistic-2.0) and some crypto sources, none of which is a PDF. The test PDFs under `qpdf/qtest/qpdf/` are therefore recorded as `Apache-2.0` with that file as evidence. The selected files were added by qpdf maintainers, except `digitally-signed.pdf`, which a contributor added in a commit to the repository. `short-O-U.pdf` was not selected, because the test suite says it derives from a file sent privately.
- **veraPDF corpus.** The corpus README states that the work is licensed under CC BY 4.0. The veraPDF test suite files below are recorded as `CC-BY-4.0` with that README as evidence. The Isartor files in the same repository come from another publisher and none were selected.

The pdf.js files suggested in the issue were not selected: the PDFs in its `test/pdfs` come from bug reports and other projects, and none of them has an established license.

## Strata

Strata overlap: one input usually belongs to several. They were recorded from the bytes at the pinned revisions, with qpdf 11.3.0 from the project container where it could open the file and from the PDF syntax markers otherwise.

| Stratum | Meaning | Inputs |
| ------- | ------- | -----: |
| `classic` | Cross-reference table only | 10 |
| `stream` | Cross-reference streams only | 11 |
| `hybrid` | Cross-reference table whose trailer points to a cross-reference stream (`/XRefStm`) | 3 |
| `none` | No usable cross-reference section; a reader has to rebuild it | 3 |
| `objstm` | Object streams | 9 |
| `encrypted` | Standard security handler | 6 |
| `incremental` | More than one revision (`startxref` sections) | 13 |
| `signed` | At least one signature dictionary with a `/ByteRange` | 5 |
| `malformed` | qpdf reports the file as damaged, an invalid cross-reference entry or stream, or cannot recognize its header | 7 |

PDF versions range from 1.3 to 2.0; two files have no recognizable header and one has junk before it. Sizes range from 5 bytes to 133 KiB.

Every stratum the pilot requires is covered. The encrypted inputs do not all open the same way:

- `minimal-signed-restricted.pdf`, `V4-aes.pdf`, `leading-junk.pdf`, the PDF/A-4 `6-1-3-t02-fail-a` and the PDF/UA-1 `7.16-t01-fail-a` open with the empty user password in qpdf 11.3.0.
- `nontrivial-crypt-filter.pdf` needs the user password `asdfqwer`, which the qpdf test suite passes to it; with it, `qpdf --check` exits with 3. A signer that is not given the password cannot open it, so its failure on this input is expected and is not a defect.

Not deliberately covered, and left for the main panel: form fields other than signature fields, visible signature appearances, PDF versions below 1.3, large documents (several megabytes or thousands of pages), and signatures with timestamps or revocation information.

## Inputs

The qpdf column is the exit code of `qpdf --check` on the input itself, without a password, when the sample was selected: 0 no problem, 2 errors, 3 warnings only. It is the baseline against which the `qpdf` structure observation of a signed output is read: that adapter turns 2 into `invalid` and 3 into `inconclusive`, so an output of one of the 2 inputs at 2 or the 14 inputs at 3 can get that verdict from a problem the input already had, not from the signer. Only the 11 inputs at 0 make a non-`valid` structure verdict a sign of a signer defect on its own.

| Input | Version | Strata | qpdf | Why it was chosen |
| ----- | ------- | ------ | ---: | ----------------- |
| `qpdf:qpdf/qtest/qpdf/hybrid-xref.pdf` | 1.5 | `hybrid`, `objstm`, `incremental` | 0 | A hybrid file written for the qpdf test suite |
| `qpdf:qpdf/qtest/qpdf/good17.pdf` | 1.5 | `hybrid`, `objstm`, `incremental` | 0 | Hybrid file with two object streams |
| `qpdf:qpdf/qtest/qpdf/good18.pdf` | 1.5 | `hybrid`, `objstm`, `incremental` | 0 | Hybrid file with one object stream |
| `qpdf:qpdf/qtest/qpdf/digitally-signed.pdf` | 1.6 | `stream`, `objstm`, `signed`, `incremental` | 3 | Linearized and signed with a self-signed test certificate (`CN=John Doe`, `example.org`); qpdf warns about its linearization hints |
| `qpdf:qpdf/qtest/qpdf/minimal-signed-restricted.pdf` | 1.7 | `classic`, `encrypted`, `signed` | 0 | An encrypted document that is already signed (by Adobe Acrobat Sign), with restricted permissions |
| `qpdf:qpdf/qtest/qpdf/nontrivial-crypt-filter.pdf` | 1.7 | `stream`, `objstm`, `encrypted`, `incremental` | 2 | AES-256 (R 6) with the user password `asdfqwer`, where streams and strings are left unencrypted and only embedded files use AES |
| `qpdf:qpdf/qtest/qpdf/V4-aes.pdf` | 1.6 | `classic`, `encrypted` | 0 | R 4 encryption with AES and the empty user password |
| `qpdf:qpdf/qtest/qpdf/leading-junk.pdf` | 1.4 | `classic`, `encrypted`, `incremental` | 0 | Linearized and encrypted (R 3), with 14 bytes of junk before the `%PDF-1.4` header |
| `qpdf:qpdf/qtest/qpdf/incremental-1.pdf` | 1.3 | `classic`, `incremental` | 0 | Three revisions, each with a classic table |
| `qpdf:qpdf/qtest/qpdf/bad-xref-entry.pdf` | 1.3 | `classic`, `malformed` | 3 | A cross-reference entry with a wrong offset, so qpdf reconstructs the table |
| `qpdf:qpdf/qtest/qpdf/xref-errors.pdf` | 1.3 | `classic`, `malformed` | 3 | Cross-reference entries with invalid syntax that qpdf accepts with warnings |
| `qpdf:qpdf/qtest/qpdf/bad3.pdf` | 1.3 | `none`, `malformed` | 3 | `startxref` points where there is no cross-reference table, and objects lack `endobj` |
| `qpdf:qpdf/qtest/qpdf/bad-direct-root.pdf` | `UPDF-1` | `none`, `malformed` | 3 | A damaged header, no `startxref` and an unknown token in the trailer |
| `qpdf:qpdf/qtest/qpdf/bad1.pdf` | none | `none`, `malformed` | 2 | Not a PDF: five bytes of text. Every signer is expected to reject it |
| `qpdf:qpdf/qtest/qpdf/bad35.pdf` | 1.5 | `stream`, `malformed` | 3 | A cross-reference stream that points into an object that is not an object stream |
| `qpdf:qpdf/qtest/qpdf/xref-with-short-size.pdf` | 1.5 | `stream`, `objstm`, `malformed` | 3 | A cross-reference stream whose data is longer than its `/W` and `/Size` allow |
| `verapdf-corpus:PDF_A-1b/6.1 File structure/6.1.4 Cross reference table/veraPDF test suite 6-1-4-t03-pass-a.pdf` | 1.4 | `classic` | 0 | Control: one well-formed revision with a classic table |
| `verapdf-corpus:PDF_A-1b/6.1 File structure/6.1.4 Cross reference table/veraPDF test suite 6-1-4-t01-fail-a.pdf` | 1.4 | `classic`, `incremental` | 0 | Cross-reference table syntax that PDF/A-1b rejects but qpdf accepts |
| `verapdf-corpus:PDF_A-1b/6.1 File structure/6.1.4 Cross reference table/veraPDF test suite 6-1-4-t03-fail-a.pdf` | 1.4 | `stream` | 3 | A cross-reference stream without object streams in a file declared as PDF 1.4 |
| `verapdf-corpus:PDF_A-1b/6.1 File structure/6.1.3 File trailer/veraPDF test suite 6-1-3-t01-pass-a.pdf` | 1.4 | `classic`, `incremental` | 0 | Three revisions, each with a classic table |
| `verapdf-corpus:PDF_A-4/6.1 File structure/6.1.4 Cross reference table/veraPDF test suite 6-1-4-t02-pass-a.pdf` | 2.0 | `classic` | 3 | Control for PDF 2.0 with a classic table |
| `verapdf-corpus:PDF_A-4/6.1 File structure/6.1.3 File trailer/veraPDF test suite 6-1-3-t02-fail-a.pdf` | 2.0 | `stream`, `objstm`, `encrypted`, `incremental` | 3 | Listed in the issue. PDF 2.0 with AES-256 (R 6), which PDF/A-4 forbids |
| `verapdf-corpus:PDF_UA-1/7.16 Security/7.16-t01-fail-a.pdf` | 1.6 | `stream`, `objstm`, `encrypted`, `incremental` | 3 | Listed in the issue. R 4 encryption whose permissions deny content extraction for accessibility |
| `verapdf-corpus:PDF_UA-1/7.16 Security/7.16-t01-pass-a.pdf` | 1.6 | `stream`, `incremental` | 0 | Unencrypted counterpart of the previous input, with four revisions |
| `verapdf-corpus:PDF_A-2b/6.1 File structure/6.1.12 Permissions/veraPDF test suite 6-1-12-t01-pass-a.pdf` | 1.6 | `stream`, `signed` | 3 | Signed with usage rights (`/Perms` `/UR3`) |
| `verapdf-corpus:PDF_A-2b/6.1 File structure/6.1.12 Permissions/veraPDF test suite 6-1-12-t02-pass-a.pdf` | 1.6 | `stream`, `objstm`, `signed`, `incremental` | 3 | Certified (`/DocMDP`) with two revisions, so a new signature has to respect the certification permissions |
| `verapdf-corpus:PDF_A-4/6.1 File structure/6.1.11 Permissions/veraPDF test suite 6-1-11-t01-pass-a.pdf` | 2.0 | `stream`, `signed` | 3 | Certified (`/DocMDP`) PDF 2.0 document |
