# Results publication

The decision on whether and how results are published (RFC section 7, stage 5), taken from the evidence of the replay, the differential baseline and the performance study. It is a proposal until the maintainers of this repository approve it in review; no repository and no release is created before that.

## Decision

No separate results repository is created. Derived reports stay in `docs/` of this repository, as they are now. The run records behind a published report are attached, compressed and without any PDF, to a release of this repository, and the report names each bundle and its SHA-256. Everything else stays in the local `var/` directory, which Git ignores, and is reconstructed by running the commands again.

## Evidence

Measured on the run directories of the [replays](replays.md), the [differential baseline](differential-baseline.md) and the [performance results](performance-results.md), with the image and revisions those documents record, and on a later run of the pilot sample, since the [pilot results](pilot-results.md) name no run directory.

### Size

Records are every file of a directory except `outputs/`; outputs are the signed PDFs. Compressed sizes are of a tar archive of the records, without `outputs/`.

| Directory | Runs | Records | gzip -9 | xz -9 | Outputs |
| --------- | ---: | ------: | ------: | ----: | ------: |
| `run-20261109T135248Z`, pilot sample | 1 | 0.8 MB | 43 KB | 25 KB | 1.2 MB, 23 PDFs |
| `replay-20261107T153713Z`, pilot sample | 2 | 1.6 MB | 85 KB | 31 KB | 13.6 MB, 48 PDFs |
| `differential-20261107T210046Z`, pilot sample | 4 | 3.5 MB | 181 KB | 59 KB | 16.0 MB, 94 PDFs |
| `differential-20261107T204257Z`, main panel | 4 | 74.3 MB | 3.2 MB | 246 KB | 358.7 MB, 370 PDFs |
| `performance-calibration-20261109T142225Z` | 10 | 184.4 MB | 7.9 MB | 474 KB | 911.4 MB, 1025 PDFs |
| `performance-1-20261109T151948Z` | 12 | 221.2 MB | 9.4 MB | 630 KB | 1.09 GB, 1230 PDFs |
| `performance-2-20261109T161853Z` | 12 | 221.2 MB | 9.4 MB | 635 KB | 1.09 GB, 1230 PDFs |

- A run of the main panel holds about 18.5 MB of records, and 16 MB of them are one string: the `raw_output` of `pdfsig` on `qpdf/qtest/qpdf/bad-content.pdf`, 251,900 lines of syntax errors, repeated in the baselines of every run. The records are not truncated to save space, because the evidence must stay as the validator wrote it. xz reduces such a run alone to about 150 KB, and to about 52 KB per run in the archive of a session, where the same output repeats in each run.
- The outputs are more than a hundred times larger than the records compressed with gzip, and each one holds its whole input, because a signature is an incremental update.
- The Git history of this repository holds 5.5 MB. Committing the records of the two measured performance sessions, which Git stores compressed about as gzip does, would add some 19 MB, four times the whole history, and every later session would add as much again.

### Reads

- No command reads a record of an earlier invocation. `compare-runs`, `compare-implementations`, `analyze-performance` and `report` read the run directories that the same `bin/` command has just written. The only analysis across invocations is `analyze-performance` on sessions 1 and 2, written the same day on the same host.
- Of the 39 directories in `var/runs/`, most were runs made during development; the published documents rest on a few of them.
- The published documents carry the numbers, the revisions, the hashes of the configurations and the commands, but name no run directory a reader can obtain. A reviewer who wants to check a classification of the differential baseline, or a median of the performance results, has to run the study again, and for the performance study that needs a host under the protocol's controls.

The evidence shows no need to keep a history of runs: nothing reads one. It shows a need to keep the records behind each published claim, so that the claim can be checked without repeating the study.

### Exposure

Searched over every record of the directories above, outside `outputs/`:

- No host name, user name, home directory or address of the host. A run records the operating system of the image, the host kernel release, the CPU model, the container image id and the resource limits.
- No private key and no password. Each run generates a self-signed certificate, its key in a PKCS#12 file and a random password, removes the file when the run ends, and replaces the password with `[redacted]` in the output it keeps from a failed attempt. Observations identify the signer's certificate by its SHA-256 only. The word `password` appears only in messages of the implementations and validators, such as `Bad user password` and `User password = ` with an empty value for an encrypted input.
- Paths only inside the image, such as `var/cache/inputs/files/...` in the messages of qpdf.
- Fragments of the inputs, inside the messages of the validators, such as `Syntax Error (132234): Illegal character ')'`. The inputs are the upstream files that [`config/inputs.json`](../config/inputs.json) and [`config/main-panel/inputs.json`](../config/main-panel/inputs.json) select only under an established free license.
- The outputs are the inputs with a signature by a certificate generated for the run. Publishing them would mirror upstream PDFs, which [CONTRIBUTING.md](../CONTRIBUTING.md) forbids.

## Publication contract

### What is published

- The derived reports in `docs/`, as now.
- For each run, replay, differential or performance directory that a report in `docs/` rests on, one bundle: a `tar.xz` archive of that directory without any `outputs/` directory, named after the directory, such as `differential-20261107T204257Z.tar.xz`. It contains the JSON records, `summary.md`, `report.md`, the copy of the source configuration and, for a performance session, its `protocol.json`, `study.json`, `controls/` and the records of `references/`.

### What is never published

- PDFs of any kind: inputs, outputs, the references' outputs and regression inputs. Upstream PDFs stay upstream and in the local cache.
- Private keys, keystores and passwords.
- PDFs reported by users, or records of a run over one, since such an input is never declared in a source configuration.
- Logs written outside the run directories, such as the output of `bin/performance` captured on the host.

### Form

- A bundle is attached to a release of this repository whose tag is `results-<YYYYMMDD>`, the date of the release. A release has no source change of its own; it only carries bundles.
- The report that rests on a bundle names it with its release, its file name and its SHA-256, in the table of revisions and environment.
- A bundle is attached before the report that cites it is merged, so a merged report never cites a bundle that does not exist.
- Before attaching, the maintainer checks that the archive lists no `.pdf` file and no `outputs/` path.

### Size and growth

The bundles behind the documents published so far amount to about 2.1 MB: 13 to 25 KB for a pilot run, 31 KB for the replay, 305 KB for the two differentials, and 1.75 MB for the calibration, the two measured sessions and their analysis. Each new published result adds, at the sizes measured above, about 30 KB per replay of the pilot, 250 KB per differential of the main panel and 630 KB per session of the performance study. A release of `pdf-signer` followed by one replay and one differential adds under 300 KB; a new performance study of two sessions and a calibration adds under 2 MB. GitHub limits a release asset to 2 GiB, three orders of magnitude above the largest bundle.

The size of a bundle grows with the panel and with the output of the validators. When a bundle exceeds 50 MB, the maintainers review this decision before attaching it.

### Retention

- A bundle is kept as long as a report on the default branch, at any revision, cites it. Release assets are not replaced; a corrected bundle goes into a new release and the report is updated to cite it.
- A bundle is deleted only when it is found to contain something this contract excludes. The report that cited it then says so.
- Run directories that no report cites are not retained beyond the local `var/` of whoever ran them. They are reconstructible from the same revisions, while the upstream inputs remain available at their pinned revisions.

### Maintenance

The maintainers of this repository maintain the contract: they attach the bundles, review in each pull request that a report cites its bundles with their hashes, and review this decision when a bundle exceeds the limit above or when a new kind of record would be published. Attaching the bundles is a manual step; a command to build and check them may follow once this decision is approved.

## Why no separate repository

- **No history is read.** A results repository would keep the series of runs over time; no command and no document reads one, and replays compare two revisions that are run again, not stored runs.
- **The size does not need it.** The records behind every published result fit in about 2 MB compressed, and release assets keep them out of the Git history, so this repository does not grow with them.
- **It would add maintenance.** A second repository needs its own access, CI and review for content that changes only when a report changes, and its reports would drift from the code and configuration that produced them, which live here with their hashes.
- **The RFC's boundaries hold without it.** No measurement history grows in `pdf-signer` or `pdf-core`, no PDF is mirrored and no record depends on another organization's repository.

A separate repository is reconsidered when records of earlier runs are read again, such as a series of results across many revisions, or when the bundles grow past the review limit.
