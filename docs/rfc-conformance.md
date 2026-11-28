# RFC conformance

How this repository answers the RFC [Reproducible PDF Signing Qualification and Benchmarking for SignerPHP](https://github.com/SignerPHP/.github/pull/3), version 0.1 of 2026-10-10: each research question of section 4 and each requirement of sections 5 to 8, with the command, schema, analyzer or published report that answers it, and the parts left out or deferred, with who decided it and the issue that tracks it. The RFC itself is not edited here.

Each requirement has one status:

- **Met**: the repository does what the RFC asks, and the evidence shows it.
- **Partially met**: part of it is done; the note says what is missing.
- **Deferred**: not done yet, and kept for later work.
- **Left out**: not done, by a decision or because nobody took it up; the note says which.

The state below is that of `main` after [#74](https://github.com/SignerPHP/pdf-signing-qualification/pull/74), with the results of release [`results-20261127-1`](https://github.com/SignerPHP/pdf-signing-qualification/releases/tag/results-20261127-1).

## Research questions (section 4)

| Question | Status | Evidence |
| -------- | ------ | -------- |
| **RQ1**, reproducibility and provenance: inputs reacquired and verified by upstream location, revision, path, retrieval date and SHA-256, without a mirror | Met | `acquire` and [`v2/acquisition.schema.json`](../schemas/v2/acquisition.schema.json); the [pilot sample](pilot-sample.md) and the [main panel](main-panel.md), each run against its pinned revisions; the semantics of acquisition in the [schemas README](../schemas/README.md#semantics) |
| **RQ2**, functional qualification: which structure, signing and document-state categories go with supported behavior, expected rejection, failures or invalid outputs | Met | `run-attempts` and `report`, whose coverage matrix and verdicts are given by stratum; the [pilot results](pilot-results.md), the [differential baseline](differential-baseline.md), the [visible-appearance scenario](visible-appearance.md) and the [PAdES results](pades-scenarios.md#results-on-the-pilot-sample). The categories are limited by the strata of section 6.2 below |
| **RQ3**, independent verification: how consistently distinct verifiers judge structure, cryptography and preservation, and where they disagree | Met | The analyzers of the [schemas README](../schemas/README.md#semantics), the baselines of each input before signing, and the `validator_disagreement` class of `compare-implementations`; the causes of the disagreements are in the [differential baseline](differential-baseline.md#validator-disagreements) |
| **RQ4**, comparative performance: paired distributions of duration, CPU time and memory over eligible and independently valid cases | Met | `bin/performance` and `analyze-performance` under the [performance protocol](performance-protocol.md); the [performance results](performance-results.md), for `basic-invisible-sha256` on the main panel and one host |
| **RQ5**, diagnostic usefulness: a replay against another revision tells fixed, persistent and introduced regressions apart | Met | `compare-runs` and `bin/replay`; the [regression cases](regressions.md) and the [replays](replays.md) of `v0.2.0` against `v0.3.0` |

## Scope and responsibility boundaries (section 5)

| Requirement | Status | Evidence and notes |
| ----------- | ------ | ------------------ |
| In scope: acquisition and provenance, diverse inputs, explicit scenarios, independent verification, isolated execution, regression analysis, process-level benchmarking and optional PHP diagnostics | Partially met | Each part has its row in section 6. The optional PHP phase diagnostics were not built; see section 6.7 |
| `SignerPHP/pdf-signer` as an implementation under test | Met | The `pdf-signer` adapter, installed from an exact commit ([AGENTS.md](../AGENTS.md#environment)) |
| The deprecated `SignerPHP/signer-php` as a pinned legacy reference, when feasible | Left out | No adapter, feasibility check or run exists. It was left out by the maintainer of this repository when epic [#9](https://github.com/SignerPHP/pdf-signing-qualification/issues/9) was decomposed into #21 to #32, and recorded only in [#58](https://github.com/SignerPHP/pdf-signing-qualification/issues/58). No issue tracks it. The regression case [`hybrid-xref`](regressions.md) reproduces a defect reported against it, [signer-php#24](https://github.com/SignerPHP/signer-php/issues/24), on `pdf-signer` |
| JSignPdf through `JSignPdf/jsignpdf-php` as an external process reference | Met | The `jsignpdf` adapter and `docker/jsignpdf-sign.php` ([AGENTS.md](../AGENTS.md#environment), [#24](https://github.com/SignerPHP/pdf-signing-qualification/issues/24)) |
| Capability and environment compatibility checked before selecting scenarios | Met | Each adapter declares the scenarios it supports, and the rest is `unsupported`; `implementation_compatibility` in `run.json` records whether the command applies to the installed revision. See *Implementations* and *Compatibility of a revision* in the [schemas README](../schemas/README.md#semantics) |
| Further implementations through adapters | Met | An adapter only builds the command of an external process ([README](../README.md#any-signing-solution-can-be-qualified)) |
| Library boundary: no production dependency added to `pdf-core` or `pdf-signer` | Met | Both are installed into the image from pinned revisions, and [`composer.json`](../composer.json) requires neither; the rule is a boundary of [AGENTS.md](../AGENTS.md#boundaries) |
| External-reference boundary: JSignPdf measured as a whole observable process, with no Java instrumentation or heap profiling | Met | Measurements have `instrumented: false` and the `process_tree` boundary, which holds the JVM ([performance protocol](performance-protocol.md#measured-boundary)). The JVM options the adapter passes to start under the address-space bound are recorded in `implementation_runtime`, not used to profile |
| Excluded: LibreSign or Nextcloud throughput | Met | Not measured; stated in the limits of the [performance protocol](performance-protocol.md#limits) and [results](performance-results.md#limits) |
| Excluded: runtime dependency on repositories of another organization | Met | Nothing depends on a LibreSign repository. JSignPdf, `jsignpdf-php` and the validators are installed when the image is built, from archives checked against their SHA-256 or exact versions and commits; a run reaches no repository |
| Excluded: malware classification and mandatory machine learning; paid remote AI services | Met | No analyzer classifies malware or uses a model or a remote service |
| Excluded: indefinite retention of generated PDFs | Met | Signed outputs stay in the local `var/`, which Git ignores, and are never published ([results publication](results-publication.md#what-is-never-published)) |
| Excluded: silent algorithm substitution | Met | Scenarios name the digest, signature algorithm and sub filter; the adapters pass them, `pdf-signer` with `--no-default-timestamp`, and a revision whose help does not list every option passed is `incompatible` ([schemas README](../schemas/README.md#semantics)) |
| Excluded: claims of comprehensive ISO or PAdES conformity | Met | Every report states its limits, such as those of the [differential baseline](differential-baseline.md#limits) |

## Study design and experimental method (section 6)

### 6.1 Study type

| Requirement | Status | Evidence and notes |
| ----------- | ------ | ------------------ |
| A qualification study and a separate performance study, neither replacing the other nor claiming standards compliance | Met | Qualification: `bin/pilot`, `bin/differential` and their reports. Performance: the [protocol](performance-protocol.md), whose eligibility rule rests on the qualification verdicts, and the [results](performance-results.md) |

### 6.2 Population, sources and sampling

| Requirement | Status | Evidence and notes |
| ----------- | ------ | ------------------ |
| Candidate corpora examined for suitability and licensing, not approved by default | Met | veraPDF is used, and qpdf and pyHanko were added; pdf.js and Poppler were rejected for lack of an established license per document, as were PDFBox, pdf-lib, DSS and the PDF/A test suite ([main panel](main-panel.md#rejected-sources), [pilot sample](pilot-sample.md#licenses)) |
| Strata of PDF version, cross-reference type, object streams, revisions, prior signatures, forms, encryption, malformed inputs, size, appearance and signing profiles | Partially met | The fourteen strata of [`v3/sources.schema.json`](../schemas/v3/sources.schema.json), described in the [main panel](main-panel.md#strata). Missing: PDF versions are a stratum only below 1.3 (`legacy`); appearance is not a stratum, so no input was chosen for a placement ([main panel](main-panel.md#coverage-gaps), [visible appearance](visible-appearance.md#inputs)); signing profiles are scenarios, not strata. A `certified` stratum was left for a follow-up in [#71](https://github.com/SignerPHP/pdf-signing-qualification/pull/71) because it needs a new version of the sources schema, and no issue tracks it yet. The other gaps are still open, without an issue |
| A small pilot first, then a main panel refined after it | Met | The failure-enriched [pilot sample](pilot-sample.md) ([#5](https://github.com/SignerPHP/pdf-signing-qualification/issues/5)), then the [main panel](main-panel.md) ([#27](https://github.com/SignerPHP/pdf-signing-qualification/issues/27)). The main panel is `stratified` over a pool of three open test suites, not representative of documents in practice, as its page states; the maintainer chose that in #27 |
| Cases reserved for regression tests, and a failure-enriched panel never read as a compatibility rate | Met | [Regression cases](regressions.md), replayed apart from the run totals; reports of a `failure_enriched` or `stratified` sample say what their counts hold for (`report`, [schemas README](../schemas/README.md#semantics)) |
| No local versioning of the corpus; per-run provenance; ignored, reconstructible caches | Met | [Fixture policy](../CONTRIBUTING.md#fixture-policy); `var/cache/inputs/` and `var/cache/candidates/` are ignored; every run keeps `acquisitions.json` and a copy of its source configuration as `inputs.json` |
| Small synthetic fixtures with documented ownership | Met | [`tests/Fixtures/Synthetic/`](../tests/Fixtures/Synthetic/README.md) |

### 6.3 Input provenance and licensing

| Requirement | Status | Evidence and notes |
| ----------- | ------ | ------------------ |
| Source identifier, upstream URL, immutable revision, path and permalink, retrieval time in UTC, SHA-256 and byte size | Met | [`v3/sources.schema.json`](../schemas/v3/sources.schema.json): `id`, `url`, `revision` and the `file_url` template that the revision and path complete into a permalink; [`v2/acquisition.schema.json`](../schemas/v2/acquisition.schema.json): `retrieved_at`, `sha256`, `byte_size` |
| Several origins of the same content | Met | `further_origins`, each verified on acquisition ([#55](https://github.com/SignerPHP/pdf-signing-qualification/issues/55), [#72](https://github.com/SignerPHP/pdf-signing-qualification/pull/72)) |
| Source-project license apart from the document's, as SPDX or `NOASSERTION`, with the evidence URL and the restrictions on redistribution | Met | The license of each source and of each document, with `evidence_url` and `redistribution_restrictions` (#55, #72); inputs are admitted only under an established free license ([pilot sample](pilot-sample.md#licenses), [main panel](main-panel.md#sources-and-licenses)) |
| PDF-internal dates never taken for publication or acquisition dates | Met | `retrieved_at` is the time of the acquisition, and no code of this repository reads the dates of a document's metadata |
| A disappeared input reported, never silently replaced | Met | A missing file or a hash mismatch is recorded as `unavailable`, its attempt is `not_run` with capability `not_evaluated`, and nothing else takes its place in the cache ([schemas README](../schemas/README.md#semantics)); a boundary of [AGENTS.md](../AGENTS.md#boundaries) |

### 6.4 Variables and controls

| Requirement | Status | Evidence and notes |
| ----------- | ------ | ------------------ |
| Independent variables: document, scenario, implementation and pinned revision, signing configuration with explicit algorithms, runtime parameters | Met | [`v3/scenario.schema.json`](../schemas/v3/scenario.schema.json) and earlier versions, [`v2/run.schema.json`](../schemas/v2/run.schema.json) with the implementation revision, `implementation_runtime` and `resource_limits`; revisions are full commits or released versions only |
| Dependent observations: capability, result, verdicts, errors, artifact hashes, duration, CPU and peak memory, with PHP-phase diagnostics apart | Met | [`v1/attempt.schema.json`](../schemas/v1/attempt.schema.json), [`v1/observation.schema.json`](../schemas/v1/observation.schema.json), [`v2/measurement.schema.json`](../schemas/v2/measurement.schema.json). No PHP-phase diagnostic exists; see section 6.7 |
| Controls: operating system, container, CPU, PHP and Java versions, lockfiles, isolation policy, temperature, cache and startup conditions, warm-up, certificate fixtures, local substitutes for external services | Met | `run.json` records the image id, operating system, PHP version, CPU model, limits and confinement, and `implementation_runtime` the Java version and options; `pdf-signer` and the validators are installed from lockfiles. Each run generates its credential or its [test PKI](pades-scenarios.md#test-pki), which serves the TSA, OCSP, CRL and AIA endpoints on loopback. Warm-up, governor, boost, pinning and load average are part of the [performance protocol](performance-protocol.md#host-and-controls); temperature is not read, and the alternation of the implementations in each block keeps its drift from falling on one of them. The revision of this repository is not in the records; see section 7 |
| No automatic digest downgrade or silent scenario change; non-equivalent configurations are distinct scenarios | Met | One scenario per configuration in [`config/scenarios/`](../config/scenarios/); an adapter that cannot sign a scenario as declared records it as `unsupported` |
| Unsupported capabilities not counted as defects | Met | `capability` and `execution.outcome` are recorded apart, and an `unsupported` attempt is `not_run` ([schemas README](../schemas/README.md#semantics)) |

### 6.5 Execution isolation

| Requirement | Status | Evidence and notes |
| ----------- | ------ | ------------------ |
| Bounded time, memory, CPU, process count, files and temporary storage per attempt | Met | Wall time, enforced by the measuring process and the runner; address space, file size and open files with `prlimit`; CPU time and processes over the process tree ([#54](https://github.com/SignerPHP/pdf-signing-qualification/issues/54), [#68](https://github.com/SignerPHP/pdf-signing-qualification/pull/68)), and the storage of the `tmpfs` work directory; all in `resource_limits` ([AGENTS.md](../AGENTS.md#environment)). Validator commands get the time limit of their adapter and the confinement, without `prlimit` bounds |
| No unrestricted network egress; no access to secrets | Met | The `sandbox` service has only loopback, and the runner refuses to start otherwise; each attempt runs with an empty environment ([AGENTS.md](../AGENTS.md#environment)) |
| No path traversal or unsafe archive extraction | Met | Identifiers reject `.`, `..` and empty segments ([schemas README](../schemas/README.md#semantics)); Landlock limits writes to the attempt's work directory; no input is an archive, and nothing a run reads is extracted |
| Child processes terminated on timeout | Met | The measuring process is the subreaper of the attempt and kills its whole tree at the limit and when the command ends |
| Disposable environments and least privilege | Met | A fresh work directory per attempt, no capabilities and `no-new-privileges`, and Landlock confinement of every attempt ([#53](https://github.com/SignerPHP/pdf-signing-qualification/issues/53), [#60](https://github.com/SignerPHP/pdf-signing-qualification/pull/60)) and of every validator command ([#59](https://github.com/SignerPHP/pdf-signing-qualification/issues/59), [#67](https://github.com/SignerPHP/pdf-signing-qualification/pull/67)). Landlock does not cover file metadata, and `/proc` stays readable, as the *Confinement* semantics of the [schemas README](../schemas/README.md#semantics) state |
| Test credentials never emitted in outputs | Met | Credentials and the test PKI are generated per run outside the results and removed afterwards; the password is redacted from kept errors, and records identify certificates by SHA-256 ([results publication](results-publication.md#exposure), [PAdES audit](pades-scenarios.md#audit-of-the-bundles)) |
| Network-dependent TSA and revocation behavior only by explicit opt-in, with controlled fixtures | Met | Only version 3 scenarios declare a timestamp or revocation data, and they use the run's [test PKI](pades-scenarios.md#test-pki) on loopback |

### 6.6 Independent evaluation and validity of outputs

| Requirement | Status | Evidence and notes |
| ----------- | ------ | ------------------ |
| Process execution apart from capability and eligibility | Met | `execution.outcome` and `capability` of each attempt |
| Structural readability and revision integrity | Met | `qpdf` (`structure`), `cmp` (`input_preservation`) and, under version 3 scenarios, `qpdf-pades-structure` |
| ByteRange consistency and CMS validity | Partially met | `qpdf-byte-range`, `openssl-cms`, `pdfsig` and `pyhanko`. `qpdf-byte-range` judges every added signature but the last one `invalid` when an output adds several, which [#75](https://github.com/SignerPHP/pdf-signing-qualification/issues/75) tracks; it was opened as a follow-up of [#64](https://github.com/SignerPHP/pdf-signing-qualification/issues/64) and is deferred. The verdicts stay as given in the [PAdES results](pades-scenarios.md#findings) |
| Prior-signature preservation and DocMDP constraints | Met | `qpdf-prior-signatures` and `qpdf-certification-permissions` ([#56](https://github.com/SignerPHP/pdf-signing-qualification/issues/56), [#71](https://github.com/SignerPHP/pdf-signing-qualification/pull/71)). Field locks (`/FieldMDP`) and the other changes of the added revision are outside its policy, as #71 decided |
| Certificate trust, revocation and RFC 3161 timestamps where requested | Met | Under version 3 scenarios, `eu-dss` trusts only the run's test root and fetches its revocation data, and `document-timestamp-imprint` and `openssl-cms` check document timestamps. The basic scenarios request none, and `pyhanko` runs there with an empty trust store and no revocation lookup |
| Profile checks only with an applicable verifier and normative basis | Met | `eu-dss` for PAdES baseline levels and `demoiselle-signer` for ICP-Brasil AD-RT; the [positive control](pades-scenarios.md#positive-control) keeps them accepting correct outputs |
| Visual or semantic preservation only in designed scenarios | Met | `pdftoppm-appearance`, only under [`basic-visible-sha256`](visible-appearance.md) ([#45](https://github.com/SignerPHP/pdf-signing-qualification/issues/45)) |
| Each observation names its analyzer, version, policy, artifacts, verdict and evidence | Met | [`v1/observation.schema.json`](../schemas/v1/observation.schema.json) |
| Distinct `valid`, `invalid`, `inconclusive`, `not_evaluated` and `not_applicable` | Met | *Verdict* in the [schemas README](../schemas/README.md#semantics) |
| No majority vote between verifiers | Met | Each analyzer writes its own observation; disagreements are reported as `validator_disagreement`; a boundary of [AGENTS.md](../AGENTS.md#boundaries) |
| A scan for PDF tokens is only a heuristic | Met | No verdict comes from such a scan; the comparing analyzers work on what qpdf parses and on exact byte or pixel comparisons |

### 6.7 Benchmark and statistical protocol

| Requirement | Status | Evidence and notes |
| ----------- | ------ | ------------------ |
| Paired comparison only over a common eligible population with equivalent configuration | Met | The eligibility rule of the [protocol](performance-protocol.md#question-and-population); `analyze-performance` refuses runs that differ in anything but the implementation |
| Coverage, unsupported and failure rates of the full population published next to timings | Met | [Coverage of the whole panel](performance-results.md#coverage-of-the-whole-panel) |
| Process-level wall time, CPU time and peak memory over the relevant process tree | Met | `docker/measure-process-tree.py` and *Measurement* in the [schemas README](../schemas/README.md#semantics) ([#30](https://github.com/SignerPHP/pdf-signing-qualification/issues/30)) |
| RSS apart from PHP-managed allocation | Met | Only the peak resident set of one process of the tree is measured, and the schema and reports say so; no PHP-managed metric is recorded |
| Unavailable metrics never zero | Met | *Metric* in the [schemas README](../schemas/README.md#semantics); a boundary of [AGENTS.md](../AGENTS.md#boundaries) |
| Repetitions, warm-up, order and counterbalancing, seeds and controls fixed from pilot evidence | Met | The [calibration](performance-protocol.md#calibration) and the [repetitions, warm-up, order and seeds](performance-protocol.md#repetitions-warm-up-order-and-seeds) |
| Raw per-attempt observations preserved | Met | `measurements.json` of each run, in the bundles of [`results-20261110-1`](https://github.com/SignerPHP/pdf-signing-qualification/releases/tag/results-20261110-1) |
| Paired distributions with medians, quantiles and uncertainty, the document as the unit | Met | [Statistics](performance-protocol.md#statistics) and [paired distributions](performance-results.md#paired-distributions) |
| No universal winner from unrepresentative samples | Met | [Performance results](performance-results.md#limits) |
| Optional PHP phase diagnostics with `hrtime()` and PHP memory APIs, apart from uninstrumented runs | Left out | Not built, and no issue tracks it. The RFC makes it optional, to be weighed after the process-level measurements; nobody has taken it up since, so it is still open |

The measured sessions date from 2026-11-09. They were judged before [#50](https://github.com/SignerPHP/pdf-signing-qualification/issues/50), which the results account for, and ran before the confinement and limits of #67 and #68 and the analyzer of #71. Running them again needs the controlled host; no issue tracks it.

### 6.8 Differential and regression analysis

| Requirement | Status | Evidence and notes |
| ----------- | ------ | ------------------ |
| Each implementation's attempts and validator observations retained per input and scenario | Met | Every run directory, and the bundles of the [differential baseline](differential-baseline.md#revisions-and-environment) |
| Differences classified as capability mismatch, expected rejection, reproducible defect, validator disagreement or unresolved finding | Met | `compare-implementations` and [`v1/differential.schema.json`](../schemas/v1/differential.schema.json) ([#29](https://github.com/SignerPHP/pdf-signing-qualification/issues/29)); the [differential baseline](differential-baseline.md), republished in [#73](https://github.com/SignerPHP/pdf-signing-qualification/pull/73). The defects it attributes to `pdf-core` and JSignPdf are [not reported upstream yet](differential-baseline.md#reported-defects); opening them waits on the maintainer of this repository |
| A replay identifies content, scenario, revisions and changes of outcome | Met | `compare-runs` and `bin/replay` ([#25](https://github.com/SignerPHP/pdf-signing-qualification/issues/25), [#26](https://github.com/SignerPHP/pdf-signing-qualification/issues/26)); [replays](replays.md) |
| Qualification panels kept apart from failure-enriched replay selections | Met | The main panel, the pilot sample and the regression cases are separate configurations, and regression cases are never counted in a run's totals |

### 6.9 Ethics, security and research integrity

| Requirement | Status | Evidence and notes |
| ----------- | ------ | ------------------ |
| No raw corpora, private keys, passwords, personal data or sensitive PDFs in public results | Met | [Fixture policy](../CONTRIBUTING.md#fixture-policy) and [what is never published](results-publication.md#what-is-never-published); the audits of the [bundles](results-publication.md#exposure) and of the [PAdES bundles](pades-scenarios.md#audit-of-the-bundles) |
| Redacted diagnostics and hashes instead of sensitive documents | Met | Records cite inputs and outputs by SHA-256; a sensitive PDF is reported by its hash and redacted diagnostics ([SECURITY.md](../SECURITY.md#sensitive-pdfs)) |
| Safe disclosure of exploitable findings | Met | [SECURITY.md](../SECURITY.md), with private vulnerability reporting ([#57](https://github.com/SignerPHP/pdf-signing-qualification/issues/57), [#62](https://github.com/SignerPHP/pdf-signing-qualification/pull/62)) |
| JavaScript, actions, embedded files, unusual decompression and metadata as bounded observations | Left out | No analyzer collects them and no issue tracks it; still open |
| No external AI service or local classifier for verdicts | Met | Every verdict comes from a pinned validator |

## Analysis outputs and data retention (section 7)

| Requirement | Status | Evidence and notes |
| ----------- | ------ | ------------------ |
| Research protocol | Met | The RFC stays in [SignerPHP/.github#3](https://github.com/SignerPHP/.github/pull/3); this document maps it, and the documents of `docs/` record the decisions taken while implementing it |
| Tooling and schemas | Met | `bin/`, `src/`, [`schemas/`](../schemas/README.md) and the scenarios, panels and protocol of `config/` |
| Run records: input hashes and revisions, scenario and implementation identities, environment, attempts, evidence and metrics | Partially met | The files of a run directory ([AGENTS.md](../AGENTS.md#running-the-pilot)). The revision of this repository and the state of its working tree are not recorded, so the reports state them in their text; [#76](https://github.com/SignerPHP/pdf-signing-qualification/issues/76) tracks it, opened as a follow-up of #64 and deferred |
| Derived reports: coverage, divergences, regression summaries, statistical comparisons, uncertainty and limits | Met | `summary.md`, `report.md`, `comparison.md`, `differential.md` and `performance.md`, and the reports of `docs/` |
| Ownership: a dedicated qualification repository (option A of section 10) | Met | This repository. The reviewer named in the RFC [agreed with a dedicated repository](https://github.com/SignerPHP/.github/pull/3#issuecomment-6110667006), and its working name was [given in the same thread](https://github.com/SignerPHP/.github/pull/3#issuecomment-6453778267) |
| A separate results repository only with a demonstrated need, contract and approval | Met | None was created: bundles are release assets of this repository, under the contract of [results publication](results-publication.md), approved by its maintainers in [#44](https://github.com/SignerPHP/pdf-signing-qualification/pull/44) |
| No growing measurement history in `pdf-signer` or `pdf-core`; no operational dependency on LibreSign repositories | Met | Results live here and in releases of this repository ([why no separate repository](results-publication.md#why-no-separate-repository)) |

## Study stages and review gates (section 8)

| Stage | Status | Evidence |
| ----- | ------ | -------- |
| 0, maintainer consultation | Met | The review of the RFC by [its named reviewer](https://github.com/SignerPHP/.github/pull/3#issuecomment-6110667006), with [suggestions](https://github.com/SignerPHP/.github/pull/3#issuecomment-6110678932) that the repository took up: regression cases linked to issues, a coverage matrix, validators from other stacks and replays across versions |
| 1, research contract pilot: provenance, schemas, sample | Met | [#2](https://github.com/SignerPHP/pdf-signing-qualification/issues/2) to [#5](https://github.com/SignerPHP/pdf-signing-qualification/issues/5), under epic [#1](https://github.com/SignerPHP/pdf-signing-qualification/issues/1) |
| 2, qualification pilot: isolated runner and independent verification | Met | [#6](https://github.com/SignerPHP/pdf-signing-qualification/issues/6) to [#8](https://github.com/SignerPHP/pdf-signing-qualification/issues/8), [#10](https://github.com/SignerPHP/pdf-signing-qualification/issues/10) and [#17](https://github.com/SignerPHP/pdf-signing-qualification/issues/17); the [pilot results](pilot-results.md) |
| 3, differential baseline and regression replay | Met | [#21](https://github.com/SignerPHP/pdf-signing-qualification/issues/21) to [#29](https://github.com/SignerPHP/pdf-signing-qualification/issues/29), under epic #9; the [differential baseline](differential-baseline.md) and the [replays](replays.md) |
| 4, performance study | Met | [#30](https://github.com/SignerPHP/pdf-signing-qualification/issues/30) and [#31](https://github.com/SignerPHP/pdf-signing-qualification/issues/31); the [protocol](performance-protocol.md) and [results](performance-results.md). The separate PHP diagnostics of this stage were left out; see section 6.7 |
| 5, historical publication | Met | [#32](https://github.com/SignerPHP/pdf-signing-qualification/issues/32) and [#46](https://github.com/SignerPHP/pdf-signing-qualification/issues/46); [results publication](results-publication.md) |
| Work decomposed into epics and verifiable issues after stage 0, and later stages reviewed against the earlier ones before they start | Met | Epics #1 and #9, whose planning gate reviewed the implemented contracts before its issues were created. Later work outside the stages, such as the visible appearance (#45), the PAdES scenarios ([#61](https://github.com/SignerPHP/pdf-signing-qualification/pull/61)) and the gaps found against this RFC (#53 to #59), went through issues and pull requests of its own |

## Open items

| Item | Section | Status | Tracked by |
| ---- | ------- | ------ | ---------- |
| `signer-php` legacy reference | 5 | Left out by the maintainer when #9 was decomposed | None |
| Strata for PDF versions, appearance, signing profiles and certified inputs | 6.2 | `certified` left for a follow-up in #71; the others still open | None |
| `qpdf-byte-range` with several added signatures | 6.6 | Deferred | [#75](https://github.com/SignerPHP/pdf-signing-qualification/issues/75) |
| PHP phase diagnostics | 6.7 | Still open | None |
| Performance sessions run again with the current analyzers, confinement and limits | 6.7 | Still open | None |
| Defects of `pdf-core` and JSignPdf reported upstream | 6.8 | Waiting on the maintainer | None |
| Observations of JavaScript, actions and embedded files | 6.9 | Still open | None |
| Revision of this repository in each run | 7 | Deferred | [#76](https://github.com/SignerPHP/pdf-signing-qualification/issues/76) |
