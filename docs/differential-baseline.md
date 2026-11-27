# Differential baseline

A comparison of `pdf-signer` and JSignPdf over the same inputs, scenario, analyzers and limits (RFC section 6.8, stage 3). It says where the two implementations behave differently and how far the evidence attributes each difference; it does not say which implementation is better, and it reports no performance result. How to run it is in [`AGENTS.md`](../AGENTS.md#comparing-two-implementations); what each class means is in the [schemas README](../schemas/README.md#semantics).

```sh
bin/differential --panel=main
bin/differential --panel=pilot
```

Each command builds the image, acquires the inputs once, runs each implementation twice, alternating between them, and classifies every item of every input. A difference counts as a reproducible defect only when it is the same in both runs of each implementation.

The runs below were made at commit `909bdec5e3ae6baf591d5512f1e67b1668f777b6` of this repository, after [#61](https://github.com/SignerPHP/pdf-signing-qualification/pull/61), with every analyzer of the `basic-invisible-sha256` scenario and the limits that [#67](https://github.com/SignerPHP/pdf-signing-qualification/pull/67) to [#72](https://github.com/SignerPHP/pdf-signing-qualification/pull/72) set. They replace the runs of release [`results-20261111-2`](https://github.com/SignerPHP/pdf-signing-qualification/releases/tag/results-20261111-2), `differential-20261111T213209Z` and `differential-20261111T215015Z`, made after [#50](https://github.com/SignerPHP/pdf-signing-qualification/issues/50) with the same pinned `pdf-signer` and JSignPdf; those replaced the runs of release [`results-20261110-1`](https://github.com/SignerPHP/pdf-signing-qualification/releases/tag/results-20261110-1). Compared with the published runs, every execution outcome is the same, and so is every verdict and baseline of the seven analyzers both have, except these:

- Before #61, `openssl-cms` verified the CMS of every signature dictionary against the bytes of its `/ByteRange`, so it failed every `/ETSI.RFC3161` document timestamp, whose CMS signs its encapsulated TSTInfo. Two inputs of the main panel hold one, pyHanko's `pades-lta-dss-indirect-arrs-test.pdf` and `pades-lta-dss-indirect-arrs-test-2.pdf`. Their `openssl-cms` baseline and the verdict on every output of both implementations went from `invalid` to `valid`, so their two items moved from `shared_failure` to `agreement`. Both inputs stay shared failures for `pdfsig`, which does not decide every signature of the input or of the outputs (`inconclusive`). The pilot sample holds no document timestamp.
- The analyzer `qpdf-certification-permissions` of [#71](https://github.com/SignerPHP/pdf-signing-qualification/pull/71) is new, so its items are new: 81 on the main panel and 20 on the pilot. Of them, 14 on the main panel and 7 on the pilot are shared failures, described under the shared failures. The others are agreements: `not_applicable` on both outputs of an input without a certification signature, 65 on the main panel and 12 on the pilot, and `valid` on both outputs of the 2 inputs of the main panel and the 1 of the pilot whose certification permits an approval signature. It adds no other class.

## Revisions and environment

| Item | Value |
| ---- | ----- |
| This repository | Commit `909bdec5e3ae6baf591d5512f1e67b1668f777b6`, the head of `main` when the runs were made |
| Scenario | `basic-invisible-sha256` |
| `pdf-signer` | Commit `f27e4064be107b8a662f370a9acdcecd786da9f7` (`v0.3.0`), with `pdf-core` v0.1.1 from its `composer.lock` |
| `jsignpdf` | 3.2.1 with the OpenPDF engine, through `jsignpdf-php` at commit `6f150373a41cd2f149725e428e21d3f9ca5b4267`, on Java 21.0.13+9-LTS with `-Xmx512m`, `-XX:+UseSerialGC`, `-XX:CompressedClassSpaceSize=64m`, `-XX:ReservedCodeCacheSize=48m`, `-XX:-UsePerfData` and `MALLOC_ARENA_MAX=1` |
| Analyzers | `pyhanko` 0.38.0, `pdfsig` 22.12.0, `openssl-cms` 3.0.22, `qpdf` 11.3.0, `cmp` 3.8, `qpdf-prior-signatures` 11.3.0, `qpdf-byte-range` 11.3.0 and `qpdf-certification-permissions` 11.3.0 |
| Image | `sha256:558e74f8bd68d8691e3475874e5adef0de851790c83aff302c4926513490c9ca`, the same for the eight runs |
| Limits | Per attempt: 120 s of wall time, 240 s of CPU time for the whole process tree, 1 GiB of address space per process, 256 MiB per file, 512 MiB of storage, 256 open files and 128 processes, under Landlock ABI 11 |
| Main panel | `config/main-panel/inputs.json` with SHA-256 `2963452148130a8ecaee266635becb7023fe9befb1cf2198ac118dc002c540b3`, expected rejections with SHA-256 `cbf700fcf211a6f8b47e3e7b3324fc1518172e8e31d77b4ac5975905e0ef69fb` |
| Pilot sample | `config/inputs.json` with SHA-256 `805dd8296a3c8c7ab406911eba1672e1d8f14ca8dd37ce8fc8418449d9bcecef`, expected rejections with SHA-256 `c5745bfb527b6a495a428cc80345a1a2fb69079613bba0062145e3a28bea6e81` |
| Records | Main panel: `differential-20261127T221951Z.tar.xz`, SHA-256 `999d613abdc6280bb3a50c324a89e510fd7fe18375ad8a26b4db10e8673cc754`; pilot sample: `differential-20261127T224101Z.tar.xz`, SHA-256 `22ad0087baa285266693e5dd2a469e9519451e5af777bf1e5d7e9d47204899c0`; attached to release [`results-20261127-1`](https://github.com/SignerPHP/pdf-signing-qualification/releases/tag/results-20261127-1) |

## Denominators

Every attempt of every run. The two runs of each implementation gave the same execution outcome on every input of both samples.

| Sample | Implementation | Attempts per run | Unavailable | `unsupported` | Rejected as expected | Not signed | Signed |
| ------ | -------------- | ---------------: | ----------: | ------------: | -------------------: | ---------: | -----: |
| Main panel | `pdf-signer` | 106 | 0 | 0 | 1 | 4 | 101 |
| Main panel | `jsignpdf` | 106 | 0 | 0 | 1 | 23 | 82 |
| Pilot sample | `pdf-signer` | 27 | 0 | 0 | 1 | 3 | 23 |
| Pilot sample | `jsignpdf` | 27 | 0 | 0 | 1 | 4 | 22 |

The main panel is [stratified](main-panel.md): a share of it holds for one stratum of its pool of open test suites, not for PDFs in general. The [pilot sample](pilot-sample.md) is failure-enriched, so none of its counts is a compatibility rate. Every input of both samples was available in the four runs with the same content, so all of them are paired.

## Classification

| Class | Main panel items | Main panel inputs | Pilot items | Pilot inputs |
| ----- | ---------------: | ----------------: | ----------: | -----------: |
| `agreement` | 761 | 45 | 169 | 7 |
| `shared_failure` | 54 | 28 | 31 | 12 |
| `capability_mismatch` | 0 | 0 | 0 | 0 |
| `expected_rejection` | 0 | 0 | 0 | 0 |
| `reproducible_implementation_defect` | 2 | 2 | 4 | 3 |
| `validator_disagreement` | 18 | 18 | 4 | 4 |
| `unresolved_finding` | 25 | 25 | 6 | 6 |

No difference was seen in one run only, so none is unresolved for lack of reproduction. There is no capability mismatch, because both adapters support the scenario and the expected rejections apply to every implementation, and no difference on an expected rejection, because both implementations rejected the one declared input of each sample.

## Reproducible implementation defects

Each defect cites, in `comparison/differential.md` of its comparison, the four attempts on the input and the four observations of the analyzer. The cause below was read from the signed outputs.

| Implementation | Input | Sample | Item | Cause |
| -------------- | ----- | ------ | ---- | ----- |
| `pdf-signer` | `qpdf/qtest/qpdf/V4.pdf` | Main panel | `qpdf` `inconclusive`, `jsignpdf` `valid` | Encrypted input (RC4, R 4). The incremental update rewrites the XMP metadata stream, object 3, by compressing its still encrypted bytes again and writing them unencrypted, so a reader that decrypts them gets data that does not inflate (`stream inflate: incorrect header check`). The strings of the new revision, such as the field name, are not encrypted either, and pyHanko reads the field name as undecodable bytes |
| `pdf-signer` | `qpdf/qtest/qpdf/V4-aes.pdf` | Pilot | `qpdf` `inconclusive`, `jsignpdf` `valid` | The same defect on an AES input, already reported in the [pilot results](pilot-results.md#structure-made-worse-by-signing) |
| `pdf-signer` | `qpdf/qtest/qpdf/leading-junk.pdf` | Pilot | `qpdf` `inconclusive`, `jsignpdf` `valid` | The input has 14 bytes before `%PDF-`, and its offsets count from the header, as readers expect. The new revision writes `startxref 276753`, counted from the start of the file, so qpdf does not find the cross-reference section there and reconstructs it |
| `jsignpdf` | `qpdf/qtest/qpdf/minimal-signed-restricted.pdf` | Both | `qpdf` `inconclusive`, `pdf-signer` `valid` | Encrypted input (AES-128, R 4) whose permissions forbid modification. The appearance stream of the new signature, object 77, declares `/Length 32`, the length of an encrypted stream, but holds the 8 bytes of its data unencrypted, so qpdf finds no `endstream` at that length and recovers it |
| `jsignpdf` | `qpdf/qtest/qpdf/leading-junk.pdf` | Pilot | `cmp` `invalid`, `pdf-signer` `valid` | JSignPdf drops the bytes before the header, so the output does not start with every byte of the input, as described in the [schemas README](../schemas/README.md#semantics) |

The three `pdf-signer` defects come from `pdf-core`, which writes the incremental update: it has no support for encrypting the update of an encrypted input, and it counts the offsets of the update from the start of the file instead of from the header.

## Validator disagreements

All 22 are pyHanko against `pdfsig` or `openssl-cms`, which judge the same output `valid`. The comparison attributes none of them to an implementation and settles none by vote; the causes below were read afterwards, and they change no classification.

- On all of them pyHanko fails the `pdf-signer` output and passes the JSignPdf one: 18 on the main panel and 4 on the pilot. pyHanko's baseline is `not_applicable` on 20 of these inputs, which hold no signature before signing, and `inconclusive` on the other 2. Their causes were read from the outputs and reproduced with the pyHanko of the image, reading each file in strict and non-strict mode:
  - **Signature field outside the form** (6 of the 22, `invalid`). On `form-out.pdf`, `appearances-quack.pdf`, `form-fields-and-annotations-shared.pdf`, `resolved-appearance-conflicts.pdf`, `resolved-field-conflicts.pdf` and `resource-from-dr.pdf`, the catalog holds `/AcroForm` as a direct dictionary. `pdf-core` then writes `/SigFlags 3` and `/Fields` with the new field into the catalog itself, because `SignatureObjectAssembler::resolveOrCreateAcroForm` returns the catalog when `/AcroForm` is not a reference, and the form's `/Fields` keeps only the fields of the input. pyHanko finds signatures through the form, so it finds none. `openssl-cms` still verifies the signature, because its listing takes every signature dictionary in the file from qpdf, without going through the form, and `pdfsig` reports it as well. The same defect is in four more inputs, ten in all: on `7.2-t25-fail-a.pdf`, `7.2-t25-pass-a.pdf` and `7.2-t25-pass-b.pdf`, where strict mode stops pyHanko first, and on `minimal-signed-restricted.pdf`, where pyHanko reports only the signature the input already had, so it is `inconclusive` and names the new signature dictionary, object 77, among those it did not judge. Because pyHanko is also `inconclusive` on the JSignPdf output of that input, whose appearance stream has the wrong length, the item is a shared failure.
  - **Object number of the cross-reference stream reused** (9 of the 22, `inconclusive`). On veraPDF `6-7-4-t01-pass-c.pdf`, `6-2-11-3-3-t02-fail-a.pdf`, `6-2-10-6-t02-pass-c.pdf`, `7.2-t07-fail-a.pdf`, `7.2-t25-fail-a.pdf`, `7.2-t25-pass-a.pdf`, `7.2-t25-pass-b.pdf` and `7.21.3.1-t01-fail-a.pdf` of the main panel, and `6-1-4-t03-fail-a.pdf` of the pilot, the input ends with a cross-reference stream that does not list itself, such as object 22 of `6-1-4-t03-fail-a.pdf`, with `/Size 23` and `/Index [0 22]`. `pdf-core` numbers new objects after the highest entry of the cross-reference instead of from `/Size`, since `PdfDocumentPreparer` takes the highest object number from the entries, so the new signature widget takes the number of the input's cross-reference stream. pyHanko refuses that in strict mode (`XRef stream objects must not be clobbered in strict mode`, in the records); in non-strict mode it reads the file. The same refusal hits the `pdf-signer` output of veraPDF `6-1-12-t01-pass-a.pdf` of the pilot, which is now a shared failure: pyHanko does not see the signature that this input already has, which `pdfsig` and `openssl-cms` find, so it is `inconclusive` on the JSignPdf output too.
  - **Encrypted inputs** (5 of the 22, `inconclusive`). Five AES inputs: `diff-data-enc.pdf`, `enc2.pdf` (AES-256, R 6) and veraPDF PDF/A-2b `6-1-3-t02-fail-a.pdf` (AES-128, R 4) of the main panel, and `V4-aes.pdf` (AES-128, R 4) and veraPDF PDF/A-4 `6-1-3-t02-fail-a.pdf` (AES-256, R 6) of the pilot. The update is written unencrypted, as on `V4.pdf` and `V4-aes.pdf` above: here the field name of the new revision is in plain text. The records hold only pyHanko's `Generic processing error`; reading the files with its library gives the underlying error, a decryption of that plain text (`The length of the provided data is not a multiple of the block length`).
  - **Inherited from the input** (2 inputs, `inconclusive`). pyHanko is already `inconclusive` on the inputs: `invalid-id-xref.pdf` of the main panel is encrypted without the `/ID` that its key derivation needs, and `leading-junk.pdf` of the pilot has bytes before its header. JSignPdf passes them because its output writes an `/ID` and drops the leading bytes.

  The first two causes lie in `pdf-core` v0.1.1, and `SignatureObjectAssembler.php` and `PdfDocumentPreparer.php` are unchanged at commit `09422a7cadcf6deacd95bc7473c955595e1f9f16`, the head of its default branch on 2026-11-10.

## Unresolved findings

- **JSignPdf does not sign damaged inputs that `pdf-signer` signs** (20 on the main panel, 3 on the pilot): every one of these inputs is already `invalid` or `inconclusive` before signing for at least one analyzer, so the refusal may be justified, four of them are AES-256 inputs (`minimal-aes256.pdf`, `minimal-two-fields-aes256.pdf`, `c-r6-in.pdf` and `unfilterable-with-crypt.pdf`, R 6 for qpdf), another is `minimal-aes256-malformed-perms.pdf`, and `nontrivial-crypt-filter.pdf` needs a password.
- **`pdf-signer` does not sign inputs that JSignPdf signs** (1 on the main panel, 2 on the pilot): veraPDF `7.16-t01-fail-a.pdf` (`Failed to inflate FlateDecode stream`) and `bad35.pdf` (`Object 1 is not an object stream`), whose inputs qpdf reads with warnings.
- **Both fail differently**: `bad-content.pdf` times out with `pdf-signer` and fails with JSignPdf, and on veraPDF `6-1-11-t01-pass-a.pdf` pyHanko is `inconclusive` on one output and `invalid` on the other, while the input's own prior signature is already `invalid`. pyHanko's `inconclusive` on the `pdf-signer` output is the strict-mode refusal of a reused cross-reference stream number, described under the validator disagreements.
- **Inherited structure warnings**: on `copy-foreign-objects-in.pdf` and `invalid-id-xref.pdf`, qpdf is `inconclusive` on the `pdf-signer` output and `valid` on the JSignPdf one, but it is already `inconclusive` on both inputs.

## Shared failures

Both implementations fail these items with the same value in every run (54 on the main panel, 31 on the pilot). Agreement is not evidence that these outputs are correct.

| Cause | Items | Main panel | Pilot |
| ----- | ----- | ---------: | ----: |
| Signed by neither implementation | execution `failed` | 2 | 1 |
| Prior signatures of the input that the `cms_signature` analyzers already fail or cannot decide before signing | `openssl-cms` `invalid`; `pdfsig` `invalid` or `inconclusive`; `pyhanko` `invalid` | 15 | 7 |
| Hybrid-reference files that pyHanko refuses once signed, while it finds no signature in the input | `pyhanko` `inconclusive` | 6 | 3 |
| Inputs on which pyHanko is already `inconclusive` | `pyhanko` `inconclusive` | 1 | 3 |
| pyHanko does not report a signature that qpdf lists, for the causes given under the validator disagreements | `pyhanko` `inconclusive` | 1 | 2 |
| Inputs that qpdf reads only with warnings | `qpdf` `inconclusive` | 1 | 2 |
| Inputs that qpdf had to recover, where the analyzers that compare the output with its input cannot tell that no signature was lost | `qpdf-prior-signatures`, `qpdf-byte-range` and `qpdf-certification-permissions` `inconclusive` | 27 | 12 |
| A signature added to an input whose certification permits no change | `qpdf-certification-permissions` `invalid` | 1 | 1 |

- The prior signatures are those of `ed448-shake256-nolen.pdf`, `extensions-indirect.pdf`, `weird-byterange.pdf`, the two document timestamp inputs `pades-lta-dss-indirect-arrs-test.pdf` and `pades-lta-dss-indirect-arrs-test-2.pdf`, and veraPDF `6-1-11-t01-pass-a.pdf`, `6-1-12-t02-fail-b.pdf` and `6-1-12-t02-pass-a.pdf` on the main panel, and veraPDF `6-1-11-t01-pass-a.pdf`, `6-1-12-t01-pass-a.pdf` and `6-1-12-t02-pass-a.pdf` on the pilot. On the two document timestamp inputs only `pdfsig` fails, because it does not decide every signature, before signing as after; `openssl-cms` and pyHanko pass them.
- `pyhanko` is already `inconclusive` on `pound-in-name.pdf` of the main panel, and on `xref-errors.pdf`, `xref-with-short-size.pdf` and veraPDF `6-1-4-t02-pass-a.pdf` of the pilot. It does not report the new signature of `minimal-signed-restricted.pdf`, in both samples, and the prior signature of veraPDF `6-1-12-t01-pass-a.pdf`, in the pilot.
- Of the 27 items of the main panel on recovered inputs, 13 are `qpdf-prior-signatures`, 1 is `qpdf-byte-range`, on `recover-xref-stream.pdf`, and 13 are `qpdf-certification-permissions`: on 11 inputs it finds no `/Perms` `/DocMDP`, which does not show that the input has no certification because qpdf recovered it, on veraPDF `6-1-11-t01-pass-a.pdf` it cannot tell whether qpdf listed every signature of the input, and on `recover-xref-stream.pdf` qpdf cannot read the catalog. Of the 12 of the pilot, 6 are `qpdf-prior-signatures` and 6 `qpdf-certification-permissions`, for the first two of these reasons.
- `minimal-signed-restricted.pdf`, in both samples, carries a certification signature whose DocMDP transform has `/P 1`, which permits no change to the document. Both implementations sign it anyway, adding an approval signature (object 77 in the `pdf-signer` outputs, 76 in the JSignPdf ones), so `qpdf-certification-permissions` is `invalid` on every output. The other analyzers do not judge certification permissions: `openssl-cms` and `pdfsig` pass the outputs.

## Reported defects

The three defects of the table above whose cause lies in an implementation, two in `pdf-core` and one in JSignPdf, the two `pdf-core` causes of the validator disagreements, and the signature that both implementations add to `minimal-signed-restricted.pdf` against its certification are not reported upstream yet. JSignPdf dropping the bytes before the header is a documented behavior of the adapter, so it will not be reported.

## Limits

- Agreement between the implementations does not establish conformance: both can be wrong in the same way, and the analyzers judge only the properties of the eight analyzers above, with no trust store, revocation check or profile validation.
- A defect is reproduced by two runs in the same image and host. It says nothing about other environments, and its cause was read from one output.
- A failure the input already has before signing is not attributed to the implementation, even when the other one passes it, so a refusal of a damaged input stays unresolved however reasonable signing it would be.
- Neither implementation nor any analyzer is given a password, so inputs that need one are judged without it, and JSignPdf runs with a 512 MiB heap.
- Output hashes are not compared, and the regression cases are not counted.
- The main panel draws from three open test suites, and the pilot sample was chosen for its defects, so neither count describes the documents signed in practice.
