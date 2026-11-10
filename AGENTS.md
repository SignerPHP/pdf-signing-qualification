# AGENTS.md

Operational map for humans and AI agents working in this repository. The method itself is defined in the [RFC](https://github.com/SignerPHP/.github/pull/3); read it instead of inferring the method from code.

## Environment

Everything runs in the project container (`Dockerfile`, base images pinned by digest). CI uses the same image. Tools needed by later work (validators, signer dependencies) are added to that `Dockerfile`, never installed only on a host or only in CI.

```sh
docker compose build
docker compose run --rm app composer install
```

Validators are pinned there: Debian packages by exact version from a fixed `snapshot.debian.org` date, and pyHanko with its dependencies by version and hash in `docker/pyhanko-requirements.txt`. To change a validator version, update the snapshot date and the pin, rebuild the image and keep the hash lock regenerated, never loosened.

The pinned `pdf-signer` is installed at `/opt/implementations/pdf-signer` from an exact commit, with the dependencies locked by its own `composer.lock`. To change it, update the default of `PDF_SIGNER_REVISION` in the `Dockerfile` and the expected revision in the tests. The build argument takes another full commit hash, and refuses anything else and a revision without `composer.lock`. With `PDF_SIGNER_REVISION` set, the `sandbox` service builds and runs `pdf-signing-qualification:pdf-signer-<commit>` from that commit, so an image tag always names the revision inside it; without it, the service uses `local`, built from the default.

Java (Temurin) is installed at `/opt/java` and JSignPdf at `/opt/implementations/jsignpdf`, both from an official archive checked against its SHA-256, and `jsignpdf-php` at `/opt/implementations/jsignpdf-php` from an exact commit. `docker/jsignpdf-sign.php` is the thin script that drives it. To change any of them, update the `Dockerfile` and the expected versions in the tests.

Each attempt runs under `docker/measure-process-tree.py`, installed at `/opt/measurement`, which measures the attempt's whole process tree and stops it at the time limit; its boundary is described in the schemas README.

Two Compose services share the image. `app` has network access, for `composer install` and `acquire`. `sandbox` has no network interface other than loopback, a read-only root filesystem with 512 MiB `tmpfs` mounts at `/sandbox` and `/tmp`, 3 GiB of memory for the whole container, a process limit and no capabilities; attempts and the tests run there. The attempt runner refuses to start when another network interface is present or when its work directory is on a filesystem larger than the storage limit, so attempts work in `/sandbox`. Inside it each attempt runs in its own process group, with an empty environment, its own work directory, and `prlimit` bounds on address space per process, file size and open files; the whole group is killed at the timeout and as soon as the attempt's process ends. The `/app` mount stays writable.

On Linux, export `HOST_UID=$(id -u) HOST_GID=$(id -g)` when your IDs are not 1000, so files written in the mount keep your ownership.

## Running the pilot

`bin/pilot [--panel=pilot|main] [implementation]` is the one command that runs the pilot from a clean checkout, on the host: it builds the image, installs the dependencies, runs `acquire` in `app` and `run-attempts` in `sandbox`, and passes the image id to the latter. The panel is the failure-enriched pilot sample of `config/inputs.json` unless `--panel=main` selects the main panel of `config/main-panel/`, with its own expected rejections; the option comes first. The implementation is `pdf-signer` unless another identifier, such as `jsignpdf`, is given; an unknown identifier is refused after the build, before anything is acquired. It writes everything under `var/runs/<run-id>/`:

| File | Contents |
| ---- | -------- |
| `acquisitions.json` | One acquisition record per declared input |
| `inputs.json` | A byte-for-byte copy of the source configuration the run used, whose SHA-256 and size `run.json` records, written with the other records; it carries the strata of each input and how the sample was selected |
| `run.json` | The run record: scenario, environment, container image, resource limits, implementation revision and runtime, analyzer versions, and the hashes of `config/inputs.json` and `config/expected-rejections.json` |
| `baselines.json` | One baseline per available input and analyzer that judges one document: each validator's verdict on the input itself, before signing |
| `attempts.json` | One attempt per declared input, in declaration order, including unavailable inputs and failures |
| `measurements.json` | One measurement per attempt that ran: wall time, CPU time and peak memory of its process tree, with its outcome |
| `observations.json` | One observation per attempt and analyzer |
| `outputs/` | Signed outputs, named by SHA-256 |
| `regressions.json` | The regression cases of `config/regressions/` replayed by the run: the attempts and observations on their synthetic inputs, and per case whether its condition is present in the output, with the evidence |
| `summary.md` | Whether the adapter can run the installed revision when it cannot, totals per execution outcome and of the attempts rejected as expected, totals per analyzer and verdict, the baseline of each input next to the verdict on its output, the inputs without a baseline, the rejections that were expected, the condition of each regression case, and every other attempt or output that was not successful or not `valid`; also printed |
| `report.md` | The coverage and failure report of `bin/report`, generated from the files above only |

An aborted acquisition (exit 2) stops it before anything is signed; unavailable inputs (exit 1) are recorded and the run continues. `run.json` is written last, so a run directory without it holds an aborted or unfinished run, not results. `bin/pilot` writes `report.md` after the run; `bin/replay` writes one into each of its two run directories. A report that cannot be written does not stop either of them: the pilot still says where the results are and then exits with 1, and the replay warns and still compares the runs.

## Replaying another revision

`bin/replay <reference-revision> <candidate-revision>` runs the complete pilot, every regression case included, at two exact `pdf-signer` revisions over the same acquired inputs, and compares the runs. Each revision is a full commit hash or a release tag such as `v0.3.0`; branch names, short hashes and other moving labels are refused before anything is built. A release tag is resolved to its commit with `git ls-remote` when the replay starts and the image is built from that commit, so a cached image never stands for a tag that has moved since. It builds one image per revision, acquires the inputs once, runs `run-attempts` in `sandbox` with each image and runs `compare-runs` on the two runs. It writes `var/replays/replay-<timestamp>/` with `acquisitions.json`, the two run directories `run-<timestamp>-reference` and `run-<timestamp>-candidate`, and `comparison/`, and exits with the status of `compare-runs`. A revision that cannot be installed with its locked dependencies stops it with exit 2 before anything is signed. A revision that installs but whose command line does not accept every option of the adapter is still run: its `run.json` records it as `incompatible` with the reason, its summary says so, and every attempt records what happened.

## Comparing two implementations

`bin/differential [--panel=pilot|main] [first-implementation second-implementation]` runs both implementations, `pdf-signer` and `jsignpdf` unless two other identifiers are given, twice each over the same acquired inputs, scenario, analyzers and image, and runs `compare-implementations` on the four runs. The panel option works as in `bin/pilot`. It writes `var/differentials/differential-<timestamp>/` with `acquisitions.json`, the run directories `run-<timestamp>-<implementation>-<repetition>`, each with its `report.md`, and `comparison/` with `differential.json` and `differential.md`, and exits with the status of `compare-implementations`. The runs alternate between the implementations, so a change in the host during the runs does not fall on one implementation only. The published results are in [docs/differential-baseline.md](docs/differential-baseline.md).

## Commands

Commands run as `docker compose run --rm <service> composer <script>`, in `app` for `acquire` and `select-panel` and in `sandbox` for the others. `compare-runs`, `compare-implementations` and `report` read results only, so they need neither the network nor the sandbox limits, and run in either.

| Script | Purpose |
| ------ | ------- |
| `acquire -- [inputs-configuration] [input-cache]` | Acquire the inputs declared in `config/inputs.json`, or the given configuration, into `var/cache/inputs/`; exits 1 if any is unavailable, 2 if the acquisition was aborted |
| `select-panel -- [selection-plan] [output-directory] [candidate-cache]` | Draw the main panel from `config/main-panel/selection.json`: list and download every candidate of its pinned sources into `var/cache/candidates/`, record their strata with qpdf, draw the quota of each stratum with the plan's seed and write `inputs.json` and `pool.json` next to the plan; runs in `app`, exits 2 when a listing is truncated or a file does not match its listed object; see [docs/main-panel.md](docs/main-panel.md) |
| `run-attempts -- [--implementation=<id>] <run-directory> [inputs-configuration] [input-cache] [expected-rejections] [regression-cases]` | Judge every available input whose record is in `<run-directory>/acquisitions.json`, sign it with the implementation (`pdf-signer` by default), validate each output and replay every regression case of `config/regressions/` or of the given directory; the option comes first, another input configuration needs its own expected rejections, because the versioned ones name its undeclared inputs, and it refuses to start for an unknown identifier, any other option, an expected rejection for an undeclared input or scenario, a regression case without a condition check, or unless there is exactly one record per declared input |
| `compare-runs -- <reference-run> <candidate-run> <comparison-directory>` | Compare two finished run directories: pair their attempts by input and scenario and their regression cases by id, classify each capability, execution outcome, verdict and regression condition as `introduced`, `fixed`, `persistent`, `changed` or `unchanged`, state the differences between the runs, and write `comparison.json` and `comparison.md` to a new directory; exits 1 when the candidate run introduced a failure, 0 when it did not, and 2 when the runs cannot be compared, such as runs with no input in common under the same scenario |
| `compare-implementations -- <first-run> <first-run-repeated> <second-run> <second-run-repeated> <comparison-directory>` | Compare two runs of each of two implementations over the same inputs, scenario, analyzers, policies, expected rejections and limits: classify each capability, execution outcome and verdict of each input as `agreement`, `shared_failure`, `capability_mismatch`, `expected_rejection`, `reproducible_implementation_defect`, `validator_disagreement` or `unresolved_finding`, citing the attempts, observations and baselines it rests on, and write `differential.json` and `differential.md` to a new directory; exits 0 when written and 2 when the runs cannot be compared, such as runs that differ in anything but the implementation and the image |
| `report -- <run-directory>` | Write the coverage and failure report of a finished run to `<run-directory>/report.md` and print it, from the run records and the run's copy of the source configuration only: a coverage matrix by stratum under the run's scenario, each analyzer's verdicts by stratum, the validator disagreements, and the failures grouped by cause and by input source, every table with its denominator; it says when the sample is failure-enriched, and exits 2 for a run it cannot report, such as one without `inputs.json` or whose copy does not match `run.json` |
| `tests` | PHPUnit |
| `pint:check` / `pint:fix` | Code style (Pint, Laravel preset) |
| `psalm` | Static analysis |
| `check` | Everything CI runs: `composer validate --strict`, style, static analysis, tests |

## Layout

| Path | Contents |
| ---- | -------- |
| `bin/` | Command-line entry points |
| `config/inputs.json` | Versioned source configuration of the pilot sample: how the sample was selected, upstream sources, revisions and the inputs selected from them, each with its strata |
| `config/main-panel/` | The main panel: its selection plan (`selection.json`), the source configuration and pool that `select-panel` drew from it (`inputs.json`, `pool.json`) and its expected rejections; see [docs/main-panel.md](docs/main-panel.md) |
| `config/expected-rejections.json` | Versioned declarations of the inputs expected to be rejected under a scenario, each with its reason, following `expected-rejections.schema.json` |
| `config/scenarios/` | Versioned scenarios, one per file, following `scenario.schema.json` |
| `config/regressions/` | Regression cases, one per file, following `regression.schema.json`; see [docs/regressions.md](docs/regressions.md) |
| `docker/` | Files copied into the image, such as the hashed pyHanko requirements, the JSignPdf signing script and the process tree measurement |
| `docs/` | Project documents, such as the strata and reasons of the [pilot sample](docs/pilot-sample.md), the population and sampling of the [main panel](docs/main-panel.md), the [regression cases](docs/regressions.md), the [replays between revisions](docs/replays.md), the [pilot results](docs/pilot-results.md) and the [differential baseline](docs/differential-baseline.md) |
| `src/` | Library code, namespace `SignerPHP\PdfSigningQualification\` |
| `src/Signing/` | Attempt runner and implementation adapters; an adapter only builds the command of an external process and, optionally, the command that checks it applies to the installed revision, see the schemas README |
| `src/Regression/` | Loading of the regression cases, the condition check of each case and their replay within a run |
| `src/Panel/` | Selection plan of a stratified panel, the strata classifier, the quota draw and the selector that writes the panel and its pool |
| `src/Run/` | Pilot run over every declared input, its environment record and summary |
| `src/Validation/` | Validator adapters; each one writes its own observation, see the schemas README |
| `schemas/` | Versioned JSON Schemas for input sources, acquisitions, scenarios, runs, attempts, observations, baselines and measurements; see its README |
| `tests/` | PHPUnit tests, namespace `SignerPHP\PdfSigningQualification\Tests\` |
| `tests/Fixtures/Synthetic/` | Original synthetic fixtures, each listed in the directory README |
| `tests/Regression/` | One test per regression case, fixing the outcome observed at the pinned revisions, and the tests of the regression replay |
| `var/cache/inputs/` | Acquired upstream inputs; ignored, reconstructible |
| `var/cache/candidates/` | Candidates downloaded by `select-panel`, named by their Git object id; ignored, reconstructible |
| `var/runs/` | Run outputs, one directory per run; ignored |
| `var/replays/` | Replays between two revisions, one directory per replay; ignored |
| `var/differentials/` | Comparisons between two implementations, one directory per comparison; ignored |

## Workflow

1. Write the test that expresses the expected behavior and watch it fail for the expected reason.
2. Implement the smallest change that makes it pass.
3. Run `composer check` in the `sandbox` service.

## Boundaries

- Never commit upstream or user-reported PDFs, private keys or passwords. Follow the fixture policy in [CONTRIBUTING.md](CONTRIBUTING.md).
- Never replace an unavailable or hash-mismatched input silently. Record it as unavailable.
- Never weaken a verdict, a validator or a test to make a run or a check pass. A disagreement between validators is a result to report, not to resolve by vote.
- Never record an unavailable measurement as zero.
- No production dependency is added to `pdf-core` or `pdf-signer` for this repository's needs.
