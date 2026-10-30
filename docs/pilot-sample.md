# Pilot sample

The first inputs used to try the method end to end, declared in [`config/inputs.json`](../config/inputs.json): 28 PDFs, 17 from [pdf.js](https://github.com/mozilla/pdf.js) at `aa89148aed549a93c5aba3ca85d81711c7e5803a` and 11 from the [veraPDF corpus](https://github.com/veraPDF/veraPDF-corpus) at `bb75f4f0073d9350dfd058c0162a367e6fadf25e`.

**This sample is not representative of overall compatibility.** It was chosen on purpose from test suites that collect parser edge cases, damaged files and conformance failures, so a rate computed over it says nothing about how often an implementation succeeds on documents found in practice. It exists to check that inputs can be acquired, that the strata can be told apart, that the validators can judge the outputs and what a run costs.

## Strata

Strata overlap: one input usually belongs to several. They were recorded from the bytes at the pinned revisions, with qpdf 11.3.0 from the project container where it could open the file and from the PDF syntax markers otherwise.

| Stratum | Meaning | Inputs |
| ------- | ------- | -----: |
| `classic` | Cross-reference table only | 7 |
| `stream` | Cross-reference streams only | 13 |
| `hybrid` | Cross-reference table whose trailer points to a cross-reference stream (`/XRefStm`) | 3 |
| `none` | No usable cross-reference section; a reader has to rebuild it | 5 |
| `objstm` | Object streams | 12 |
| `encrypted` | Standard security handler | 6 |
| `incremental` | More than one revision (`startxref` sections) | 12 |
| `signed` | At least one signature dictionary with a `/ByteRange` | 6 |
| `malformed` | qpdf reports the file as damaged and repairs it, or cannot recognize its header | 9 |

PDF versions range from 1.3 to 2.0, and one file has a truncated header (`%PDF-1.` without a minor version). Sizes range from 184 bytes to 1.3 MB.

Every stratum the pilot requires is covered. The encrypted inputs do not all open the same way:

- `issue17069.pdf`, the PDF/A-4 `6-1-3-t02-fail-a` and the PDF/UA-1 `7.16-t01-fail-a` open with the empty user password in qpdf 11.3.0.
- `issue19484_1.pdf` and `issue19484_2.pdf` are opened by pdf.js without a password (its test manifest gives none) but refused by qpdf 11.3.0 with the empty password.
- `print_protection.pdf` needs the user password `1234`, which the pdf.js integration test types into its password dialog; with it, `qpdf --check` exits with 3. A signer that is not given the password cannot open it, so its failure on this input is expected and is not a defect.

Not deliberately covered, and left for the main panel: form fields other than signature fields, visible signature appearances, PDF versions below 1.3, large documents (several megabytes or thousands of pages), and signatures with timestamps or revocation information.

## Licenses

Each input records its own license next to the license of its source.

- **pdf.js.** The repository is Apache-2.0, but that does not establish the license of the PDFs in `test/pdfs`, which were contributed from bug reports and other projects. Every pdf.js input is `NOASSERTION`, with the commit that added it as evidence.
- **veraPDF corpus.** The corpus README states that the work is licensed under CC BY 4.0. The veraPDF test suite files below are recorded as `CC-BY-4.0` with that README as evidence. The Isartor files in the same repository come from another publisher and none were selected.

No input is redistributed by this repository; the license status records what may be done with the cached copies.

## Inputs

The qpdf column is the exit code of `qpdf --check` on the input itself, without a password, when the sample was selected: 0 no problem, 2 errors, 3 warnings only. It is the baseline against which the `qpdf` structure observation of a signed output is read: that adapter turns 2 into `invalid` and 3 into `inconclusive`, so an output of one of the 6 inputs at 2 or the 14 inputs at 3 can get that verdict from a problem the input already had, not from the signer. Only the 8 inputs at 0 make a non-`valid` structure verdict a sign of a signer defect on its own.

| Input | Version | Strata | qpdf | Why it was chosen |
| ----- | ------- | ------ | ---: | ----------------- |
| `pdfjs:test/pdfs/issue17069.pdf` | 1.6 | `stream`, `objstm`, `encrypted`, `signed` | 0 | Listed in the issue. An encrypted document that is already signed (`adbe.pkcs7.detached`), and the second largest input (690 KiB) |
| `pdfjs:test/pdfs/print_protection.pdf` | 1.7 | `stream`, `objstm`, `encrypted`, `incremental` | 2 | Listed in the issue. AES-256 (R 6) with the user password `1234` and permissions that forbid printing |
| `pdfjs:test/pdfs/issue19484_1.pdf` | 1.6 | `stream`, `objstm`, `encrypted`, `incremental` | 2 | Listed in the issue. R 4 encryption with a key shorter than 16 bytes, which pdf.js pads with zeros as Acrobat does; refused by qpdf |
| `pdfjs:test/pdfs/issue19484_2.pdf` | 1.6 | `stream`, `objstm`, `encrypted`, `incremental` | 2 | Listed in the issue. Second file of the same report |
| `pdfjs:test/pdfs/REDHAT-1531897-0.pdf` | 1.3 | `stream`, `objstm`, `malformed` | 2 | Listed in the issue. Cross-reference stream whose `/W` array has bogus entries |
| `pdfjs:test/pdfs/issue9105_other.pdf` | `1.` | `none`, `malformed` | 3 | Listed in the issue. A truncated header (`%PDF-1.`), no cross-reference section and a wrong `/Pages` `/Count` |
| `pdfjs:test/pdfs/issue9105_reduced.pdf` | 1.7 | `none`, `malformed` | 3 | Listed in the issue. Objects without `endobj`, so the cross-reference section has to be rebuilt by searching for `obj` |
| `pdfjs:test/pdfs/issue10438_reduced.pdf` | 1.7 | `none`, `malformed` | 3 | Listed in the issue. Objects without `endobj` where `obj` is immediately followed by the dictionary |
| `pdfjs:test/pdfs/poppler-395-0-fuzzed.pdf` | 1.7 | `classic`, `signed`, `malformed` | 3 | Listed in the issue. Fuzzed file with a damaged `/Pages` tree that also carries an `adbe.pkcs7.sha1` signature dictionary |
| `pdfjs:test/pdfs/poppler-937-0-fuzzed.pdf` | 1.4 | `classic`, `malformed` | 3 | Listed in the issue. Fuzzed file where object lookups fail while the `/Pages` tree is read |
| `pdfjs:test/pdfs/Brotli-Prototype-FileA.pdf` | 2.0 | `stream`, `objstm`, `malformed` | 2 | Listed in the issue. Its streams use the `BrotliDecode` filter, which qpdf cannot decode; the largest input (1.3 MB) |
| `pdfjs:test/pdfs/bug1020226.pdf` | 1.4 | `none`, `malformed` | 2 | Listed in the issue. No `startxref` and no `%%EOF` |
| `pdfjs:test/pdfs/issue15590.pdf` | 1.7 | `none`, `malformed` | 3 | Listed in the issue. Incomplete trailer dictionary and no `startxref` |
| `pdfjs:test/pdfs/signed_verified.pdf` | 1.7 | `classic`, `signed` | 0 | Added for the `signed` stratum. A well-formed signed document with a classic table, used by pdf.js to detect changes made after signing |
| `pdfjs:test/pdfs/issue10301.pdf` | 1.7 | `hybrid`, `objstm`, `incremental` | 0 | Added for the `hybrid` stratum, none of the listed files has one |
| `pdfjs:test/pdfs/issue11656.pdf` | 1.7 | `hybrid`, `objstm`, `incremental` | 0 | Added for the `hybrid` stratum |
| `pdfjs:test/pdfs/issue20324.pdf` | 1.4 | `hybrid`, `objstm`, `incremental` | 3 | Added for the `hybrid` stratum. A hybrid file declared as PDF 1.4, older than cross-reference streams |
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
