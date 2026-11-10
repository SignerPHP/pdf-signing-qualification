# Performance protocol

The protocol of the paired performance study of `pdf-signer` and JSignPdf (RFC section 6.8, stage 4). It was fixed in [`config/performance/protocol.json`](../config/performance/protocol.json) after a calibration session and before the measured sessions, and every session copies it, so a session can be checked against the protocol it followed. The results are in [performance-results.md](performance-results.md); how to run a session is in [`AGENTS.md`](../AGENTS.md#running-the-performance-study).

## Question and population

The study measures, on cases both implementations handle, how much wall time, CPU time and memory each one takes to sign the same document under the same scenario. It does not rank the implementations.

- **Population.** The main panel, [`config/main-panel/inputs.json`](../config/main-panel/inputs.json), with its expected rejections, under the scenario `basic-invisible-sha256`. The pilot sample is not used, because it is enriched with failures.
- **Eligibility, fixed before the measured sessions.** An input enters the paired analysis only when it is available and, in every measured run of both implementations in every session analyzed together, the attempt was supported and not an expected rejection, its execution succeeded, every analyzer judged the output `valid` or found its property `not_applicable`, and every metric of its measurement is available. An `inconclusive` verdict excludes the input. An excluded input is counted once, under the first condition it fails, and the coverage of the whole population, eligible or not, is published next to the timings.

## Measured boundary

Each attempt is measured on its process tree, as described in the [schemas README](../schemas/README.md#semantics): wall time from the start of the implementation's command to its end, CPU user plus system time summed over the tree, and the largest peak resident set of one process of the tree.

The startup and setup of each implementation are inside the boundary and are not subtracted. For `pdf-signer` that is the PHP process and its autoloading. For JSignPdf it is the PHP script of `jsignpdf-php` that drives it and the JVM it starts, with the options recorded in each run. Subtracting a startup estimate would need a model of what is fixed and what scales with the document, and could give negative values under noise. Instead, every run is followed by a **fixed-cost reference**: a minimal synthetic document, written by [`MinimalPdf.php`](../tests/Fixtures/Synthetic/MinimalPdf.php) with a declared SHA-256, signed and validated a stated number of times, so the cost of signing almost nothing is reported next to the real documents.

Validation, the baselines and the regression replay run between attempts and are outside every measurement.

## Host and controls

| Control | Value | How it is applied |
| ------- | ----- | ----------------- |
| Host | AMD Ryzen 7 3700X, 8 cores and 16 threads, 31 GiB of memory, Fedora 44 with Linux 7.2.15; the container runs Debian | A workstation kept otherwise idle while a session runs, outside shared CI |
| CPUs | `4-7`, four physical cores whose SMT siblings `12-15` the sandbox does not use | `cpuset` of the `sandbox` service, from `compose.performance.yaml` |
| Scaling governor | `performance` on every CPU of the cpuset (driver `amd-pstate-epp`) | Set as root before the session; checked before every run |
| Frequency boost | Disabled | Set as root before the session; checked before every run |
| Image | One image for every run of a session, recorded by id | Built once by `bin/performance` |
| Memory and limits | The sandbox limits of every run: 3 GiB for the container, the per-attempt limits of `ResourceLimits` | Recorded in each `run.json` |

`bin/check-host-controls` reads the cpuset of the sandbox's cgroup, the governor of each CPU in it, the boost and the load average from inside the pinned sandbox, writes them to `controls/<label>.json`, and the session stops at the first run whose controls are not met. Other processes of the host are not isolated from the pinned CPUs; the load average before each run is recorded so that a busy host shows in the results.

## Repetitions, warm-up, order and seeds

A repetition is a complete run of one implementation over the whole panel. A session is a sequence of blocks, each with one run of each implementation, in an order drawn with PHP's `Mt19937` from the protocol's seed plus the session number. Alternating the implementations keeps a slow change of the host, such as its temperature, from falling on one implementation only, and the drawn order keeps one implementation from always running first in a block.

| Parameter | Value | Reason |
| --------- | ----: | ------ |
| Seed | 20261109 | The date the protocol was written; session 1 uses 20261110, session 2 uses 20261111 |
| Calibration runs per implementation | 5 | Enough to see a warm-up effect, a drift and the spread within a document before fixing the rest |
| Warm-up runs per implementation | 1 | The calibration showed no warm-up effect; one block is kept against a cold cache after a restart of the host or of Docker, at a cost of about 10 minutes |
| Measured runs per implementation | 5 | With 5 runs the intervals of the calibration were already within about 3% of their median, 5.4% at most, and a session stays near one hour |
| Reference repetitions after each run | 10 | Enough for a stable median of a fixed cost of under two seconds per signature |
| Quantiles | 0.10, 0.25, 0.50, 0.75, 0.90 | The spread over documents without the extremes of a panel of about a hundred |
| Bootstrap | 2000 resamples, 95% | Percentile interval of the median, resampling documents |
| Repeatability tolerance | 10% | About twice the largest change between the two halves of the calibration, 5.5%, because separate sessions add the variation of the host between them |

## Calibration

The calibration session `performance-calibration-20261109T142225Z` ran the main panel five times with each implementation under the controls above, before the number of warm-up and measured runs and the tolerance were fixed; until then the protocol held provisional values (one warm-up run, five measured runs and a 5% tolerance), and only the tolerance changed. Its analysis is a calibration, not a result: it guided the choices above and is not published as a timing.

- **Eligibility.** 44 of the 106 inputs were eligible: 1 expected rejection, 24 inputs whose execution failed or timed out with one implementation, and 37 more with an output that some analyzer did not judge `valid` or `not_applicable`. No input was valid in some runs and not in others, for either implementation.
- **Warm-up.** The first run of each implementation was not slower than the later ones: the median wall time over the eligible documents was 0.041 s for the first `pdf-signer` run against 0.041 to 0.045 s for the others, and 1.815 s for the first JSignPdf run against 1.807 to 1.902 s.
- **Drift.** The slower runs, from the third to the seventh, coincide with a load average of 1.6 to 2.7 before them, against 0.9 to 1.9 elsewhere; the host was in use during that part of the calibration. The measured sessions keep the host otherwise idle and record the load average before every run.
- **Spread within a document.** The median coefficient of variation of a document over the five runs was 5.1% for the wall time of `pdf-signer` and 2.7% for JSignPdf, 4.5% and 5.3% for CPU time, and 0.5% and 9.8% for peak memory; the largest was 15.3%, for the peak memory of JSignPdf.
- **Precision.** With five runs, the 95% bootstrap intervals of every median and every median paired ratio were within 3.1% of the median, apart from the peak memory of JSignPdf, from 5.4% below to 0.6% above its median, and the paired ratio of peak memory, from 3.5% below to 0.7% above.
- **Between two halves.** Splitting the session into its first three blocks and its last two, the medians changed by up to 5.5% (the wall time of `pdf-signer`) and the median paired ratios by up to 2.5% (peak memory). The tolerance is set at about twice that.
- **Fixed cost.** The minimal document took a median of 0.042 s of wall time and 28.1 MiB with `pdf-signer`, and 1.822 s and 128.7 MiB with JSignPdf, close to the median eligible document of each implementation, so on this panel most of the measured cost is the fixed cost of starting each runtime.

## Statistics

Each eligible document's value is its median over the measured runs of a session, so the document, not the attempt, is the unit, and repeated attempts on one document never count as independent. Per implementation and metric, the analysis reports the median of those document values with its bootstrap interval and the quantiles. The paired ratio of a document is `pdf-signer` over JSignPdf, and the paired difference `pdf-signer` minus JSignPdf; both are summarized the same way, with the number of documents on which `pdf-signer` is lower. The bootstrap resamples documents with replacement, with a generator seeded with the session's seed, so the intervals are reproduced exactly from the records. The coefficient of variation of each document over the measured runs is reported as the noise left within a session.

A second measured session on the same host repeats the result when every per-implementation median and every median paired ratio changes by at most the tolerance from the first session.

## Limits

- One host, one CPU model, four pinned cores without the boost: absolute times on other hardware, with the boost, or on loaded hosts will differ, and the ratios may differ too, because the JVM uses several threads and PHP one.
- The boundary includes the startup of each runtime. On small documents that fixed cost dominates, so a ratio says as much about the runtimes as about the signing code.
- Peak memory is the largest peak of one process, not the sum of the processes alive at the same time; for JSignPdf it leaves out the PHP process that starts the JVM.
- Inputs either implementation does not sign validly are outside the timings. A faster implementation on fewer documents is not faster in general, which is why the coverage is published next to the timings.
- The host's other processes share the machine; the controls and load averages are recorded, not enforced beyond the pinning.
- LibreSign or Nextcloud throughput and internal Java metrics are out of scope.
