# Differential baseline

A comparison of `pdf-signer` and JSignPdf over the same inputs, scenario, analyzers and limits (RFC section 6.8, stage 3). It says where the two implementations behave differently and how far the evidence attributes each difference; it does not say which implementation is better, and it reports no performance result. How to run it is in [`AGENTS.md`](../AGENTS.md#comparing-two-implementations); what each class means is in the [schemas README](../schemas/README.md#semantics).

```sh
bin/differential --panel=main
bin/differential --panel=pilot
```

Each command builds the image, acquires the inputs once, runs each implementation twice, alternating between them, and classifies every item of every input. A difference counts as a reproducible defect only when it is the same in both runs of each implementation.

## Revisions and environment

| Item | Value |
| ---- | ----- |
| Scenario | `basic-invisible-sha256` |
| `pdf-signer` | Commit `f27e4064be107b8a662f370a9acdcecd786da9f7` (`v0.3.0`), with `pdf-core` v0.1.1 from its `composer.lock` |
| `jsignpdf` | 3.2.1 with the OpenPDF engine, through `jsignpdf-php` at commit `6f150373a41cd2f149725e428e21d3f9ca5b4267`, on Java 21.0.13+9-LTS with `-Xmx512m`, `-XX:+UseSerialGC`, `-XX:CompressedClassSpaceSize=64m`, `-XX:ReservedCodeCacheSize=48m`, `-XX:-UsePerfData` and `MALLOC_ARENA_MAX=1` |
| Analyzers | `pyhanko` 0.38.0, `pdfsig` 22.12.0, `openssl-cms` 3.0.22, `qpdf` 11.3.0, `cmp` 3.8, `qpdf-prior-signatures` 11.3.0 and `qpdf-byte-range` 11.3.0 |
| Image | `sha256:5f06c07ed60b98fe4e96cec10382bd4526e923434701ab91d2e2df6c8785be1e`, the same for the eight runs |
| Main panel | `config/main-panel/inputs.json` with SHA-256 `83bb6856302496b5dc9f22f1ab5c8bf9e2fe98372c5b0ecd5c42862a20a97cc5`, expected rejections with SHA-256 `a2eb2e33a06ff8612dc12af661a9e8b018cedda303bd4ed66fdcad65d5ccadd0` |
| Pilot sample | `config/inputs.json` with SHA-256 `0b4cd0e74b6fb5c1652e04f28b02bb600c14866a89dde8dacefa5f0b50ff7575`, expected rejections with SHA-256 `fd1c9906de8e57f60706d3aa035a53f4c148fbd5b6ca3b63593f30670dd33b21` |
| Records | Main panel: `differential-20261107T204257Z.tar.xz`, SHA-256 `7318064492dadc01454c0d478db913454ed50b6437e26c32f50c2f06937a4a58`; pilot sample: `differential-20261107T210046Z.tar.xz`, SHA-256 `fa6ba63e3d4a2a1200d0ea9dedd965b0db0df43256d52eea222055d83cb145c3`; attached to release [`results-20261110-1`](https://github.com/SignerPHP/pdf-signing-qualification/releases/tag/results-20261110-1) |

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
| `agreement` | 692 | 45 | 156 | 7 |
| `shared_failure` | 41 | 27 | 22 | 11 |
| `capability_mismatch` | 0 | 0 | 0 | 0 |
| `expected_rejection` | 0 | 0 | 0 | 0 |
| `reproducible_implementation_defect` | 2 | 2 | 4 | 3 |
| `validator_disagreement` | 19 | 19 | 6 | 6 |
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

All 25 are pyHanko against `pdfsig` or `openssl-cms`, which judge the same output `valid`; none is attributed to an implementation, and none is settled by vote.

- On 23 of them pyHanko fails the `pdf-signer` output and passes the JSignPdf one. On six form inputs of the main panel (`form-out.pdf`, `appearances-quack.pdf`, `form-fields-and-annotations-shared.pdf`, `resolved-appearance-conflicts.pdf`, `resolved-field-conflicts.pdf` and `resource-from-dr.pdf`) pyHanko judges the `pdf-signer` output `invalid`. On the other 17 it ends with a generic processing error or refuses to read the file in strict mode, which is `inconclusive`; two of them, `diff-data-enc.pdf` and `enc2.pdf`, are AES-256 inputs. Their cause has not been examined.
- On `minimal-signed-restricted.pdf` pyHanko is `inconclusive` on the JSignPdf output, whose appearance stream has the wrong length.

## Unresolved findings

- **JSignPdf does not sign damaged inputs that `pdf-signer` signs** (20 on the main panel, 3 on the pilot): every one of these inputs is already `invalid` or `inconclusive` before signing for at least one analyzer, so the refusal may be justified, four of them are AES-256 inputs (`minimal-aes256.pdf`, `minimal-two-fields-aes256.pdf`, `c-r6-in.pdf` and `unfilterable-with-crypt.pdf`, R 6 for qpdf), another is `minimal-aes256-malformed-perms.pdf`, and `nontrivial-crypt-filter.pdf` needs a password.
- **`pdf-signer` does not sign inputs that JSignPdf signs** (1 on the main panel, 2 on the pilot): veraPDF `7.16-t01-fail-a.pdf` (`Failed to inflate FlateDecode stream`) and `bad35.pdf` (`Object 1 is not an object stream`), whose inputs qpdf reads with warnings.
- **Both fail differently**: `bad-content.pdf` times out with `pdf-signer` and fails with JSignPdf, and on veraPDF `6-1-11-t01-pass-a.pdf` pyHanko is `inconclusive` on one output and `invalid` on the other, while the input's own prior signature is already `invalid`.
- **Inherited structure warnings**: on `copy-foreign-objects-in.pdf` and `invalid-id-xref.pdf`, qpdf is `inconclusive` on the `pdf-signer` output and `valid` on the JSignPdf one, but it is already `inconclusive` on both inputs.

## Shared failures

Both implementations fail these items with the same value in every run (41 on the main panel, 22 on the pilot). Most are the prior signatures that were already broken in the inputs (`openssl-cms`, `pdfsig` and `pyhanko` `invalid`), the hybrid-reference files pyHanko refuses (`inconclusive`), and inputs that qpdf had to recover, where `qpdf-prior-signatures` cannot tell that no signature was lost (`inconclusive`). Two inputs of the main panel and one of the pilot are signed by neither implementation. Agreement is not evidence that these outputs are correct.

## Reported defects

The three defects of the table above whose cause lies in an implementation, two in `pdf-core` and one in JSignPdf, are not reported upstream yet. JSignPdf dropping the bytes before the header is a documented behavior of the adapter, so it will not be reported.

## Limits

- Agreement between the implementations does not establish conformance: both can be wrong in the same way, and the analyzers judge only the seven properties above, with no trust store, revocation check or profile validation.
- A defect is reproduced by two runs in the same image and host. It says nothing about other environments, and its cause was read from one output.
- A failure the input already has before signing is not attributed to the implementation, even when the other one passes it, so a refusal of a damaged input stays unresolved however reasonable signing it would be.
- Neither implementation nor any analyzer is given a password, so inputs that need one are judged without it, and JSignPdf runs with a 512 MiB heap.
- Output hashes are not compared, and the regression cases are not counted.
- The main panel draws from three open test suites, and the pilot sample was chosen for its defects, so neither count describes the documents signed in practice.
