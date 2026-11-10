# Performance results

The paired performance of `pdf-signer` and JSignPdf on the main panel, measured under the [performance protocol](performance-protocol.md) in two sessions on the same host. They are paired distributions over the documents both implementations signed validly, published with the coverage of the whole panel; they do not rank the implementations. How to run a session is in [`AGENTS.md`](../AGENTS.md#running-the-performance-study).

```sh
bin/performance 1
bin/performance 2
docker compose run --rm sandbox composer analyze-performance -- var/performance/analysis var/performance/performance-1-<timestamp> var/performance/performance-2-<timestamp>
```

## Revisions and environment

| Item | Value |
| ---- | ----- |
| Scenario | `basic-invisible-sha256` |
| `pdf-signer` | Commit `f27e4064be107b8a662f370a9acdcecd786da9f7` (`v0.3.0`) |
| `jsignpdf` | 3.2.1 with the OpenPDF engine, through `jsignpdf-php` at commit `6f150373a41cd2f149725e428e21d3f9ca5b4267`, on Java 21.0.13+9-LTS with `-Xmx512m`, `-XX:+UseSerialGC`, `-XX:CompressedClassSpaceSize=64m`, `-XX:ReservedCodeCacheSize=48m`, `-XX:-UsePerfData` and `MALLOC_ARENA_MAX=1` |
| Analyzers | `pyhanko` 0.38.0, `pdfsig` 22.12.0, `openssl-cms` 3.0.22, `qpdf` 11.3.0, `cmp` 3.8, `qpdf-prior-signatures` 11.3.0 and `qpdf-byte-range` 11.3.0 |
| Main panel | `config/main-panel/inputs.json` with SHA-256 `83bb6856302496b5dc9f22f1ab5c8bf9e2fe98372c5b0ecd5c42862a20a97cc5`, expected rejections with SHA-256 `a2eb2e33a06ff8612dc12af661a9e8b018cedda303bd4ed66fdcad65d5ccadd0` |
| Protocol | `config/performance/protocol.json` with SHA-256 `e7c979d156b05bb2d0c18ba743b7164b05a2631c0c2eb44cbd458702916806aa` |
| Host | AMD Ryzen 7 3700X, Linux 7.2.15 (Fedora 44), container on Debian GNU/Linux 12 |
| Controls | Sandbox pinned to CPUs `4-7`, governor `performance`, boost disabled; met before every one of the 24 runs, with a 1-minute load average of 0.86 to 1.96 |
| Image | `sha256:6f950873a936411c7a9fadafa3229e953b55f5917d94c566b7fb7ef9f5919e00`, the same for every run |
| Sessions | `performance-1-20261109T151948Z` (seed 20261110) and `performance-2-20261109T161853Z` (seed 20261111), each with one warm-up and five measured runs per implementation |

## Coverage of the whole panel

Over the ten measured runs of each implementation. Neither implementation changed its outcome on any input between runs: every input was valid in all of them or in none.

| Implementation | Attempts | Succeeded | Failed | Timed out | Valid outputs | Inputs valid in every run | Inputs never valid |
| -------------- | -------: | --------: | -----: | --------: | ------------: | ------------------------: | -----------------: |
| `pdf-signer` | 1060 | 1010 (95.3%) | 40 (3.8%) | 10 (0.9%) | 450 (42.5%) | 45 of 106 (42.5%) | 61 of 106 (57.5%) |
| `jsignpdf` | 1060 | 820 (77.4%) | 240 (22.6%) | 0 | 560 (52.8%) | 56 of 106 (52.8%) | 50 of 106 (47.2%) |

The causes of each failure and of each output not judged valid are classified in the [differential baseline](differential-baseline.md); this study does not re-classify them.

## Eligible documents

44 of the 106 inputs met the eligibility rule of the protocol in all twenty measured runs. Each excluded input is counted under the first condition it fails:

| Condition | Inputs |
| --------- | -----: |
| Not supported, or an expected rejection | 1 |
| Execution failed or timed out with one implementation | 24 |
| Output not judged `valid` or `not_applicable` by every analyzer | 37 |
| Input unavailable, or measurement unavailable | 0 |

The timings below hold for these 44 documents only.

## Paired distributions

Each document's value is its median over the five measured runs of a session; medians and quantiles are over the 44 documents, with the 95% bootstrap percentile interval of the median, resampling documents. Ratios are `pdf-signer` over JSignPdf. Session 1 is shown; session 2 is in the repeatability table.

| Metric | Statistic | `pdf-signer` | `jsignpdf` | Paired ratio |
| ------ | --------- | -----------: | ---------: | -----------: |
| Wall time | Median | 0.041 s (0.040 to 0.041) | 1.806 s (1.802 to 1.809) | 0.023 (0.023 to 0.023) |
| | Quantile 0.10 | 0.040 s | 1.786 s | 0.022 |
| | Quantile 0.90 | 0.076 s | 1.882 s | 0.040 |
| CPU time, user and system | Median | 0.040 s (0.040 to 0.041) | 2.143 s (2.119 to 2.166) | 0.019 (0.019 to 0.020) |
| | Quantile 0.10 | 0.039 s | 2.086 s | 0.018 |
| | Quantile 0.90 | 0.075 s | 2.250 s | 0.033 |
| Peak memory | Median | 28.9 MiB (28.8 to 29.0) | 138.1 MiB (130.5 to 139.2) | 0.221 (0.211 to 0.225) |
| | Quantile 0.10 | 28.2 MiB | 123.6 MiB | 0.203 |
| | Quantile 0.90 | 57.2 MiB | 158.6 MiB | 0.358 |

`pdf-signer` was lower on every one of the 44 documents in all three metrics, in both sessions. The quartiles, the paired differences and the spread within each document are in `performance.md` of the analysis. In session 1, the largest coefficient of variation of a document over the five runs was 2.2% for wall time, 6.5% for CPU time and 16.2% for peak memory, all three for JSignPdf; in session 2, 2.1%, 6.4% and 15.3%.

## Fixed cost

The minimal synthetic document, signed ten times after every measured run:

| Session | Implementation | Valid and measured | Wall time | CPU time | Peak memory |
| ------- | -------------- | -----------------: | --------: | -------: | ----------: |
| 1 | `pdf-signer` | 50 of 50 | 0.040 s | 0.039 s | 28.1 MiB |
| 1 | `jsignpdf` | 50 of 50 | 1.783 s | 2.096 s | 128.6 MiB |
| 2 | `pdf-signer` | 50 of 50 | 0.039 s | 0.039 s | 28.2 MiB |
| 2 | `jsignpdf` | 50 of 50 | 1.780 s | 2.091 s | 128.6 MiB |

On the median eligible document, the fixed cost is about 98% of the wall time of each implementation: these documents are small, and what the ratios mostly compare is starting a PHP process against starting a PHP process that starts a JVM. The paired difference in wall time stays between -1.806 s and -1.745 s from the first to the ninth decile; the upper decile of `pdf-signer` (0.076 s, 57.2 MiB) is where the work of a larger document shows.

## Repeatability

Session 2 repeats session 1 within the protocol's 10% tolerance on every statistic; the largest change was 1.1%.

| Metric | Statistic | Session 1 | Session 2 | Change |
| ------ | --------- | --------: | --------: | -----: |
| Wall time | Median of `pdf-signer` | 0.041 s | 0.041 s | +0.0% |
| Wall time | Median of `jsignpdf` | 1.806 s | 1.803 s | -0.2% |
| Wall time | Median paired ratio | 0.023 | 0.023 | +0.2% |
| CPU time | Median of `pdf-signer` | 0.040 s | 0.040 s | -0.3% |
| CPU time | Median of `jsignpdf` | 2.143 s | 2.142 s | 0.0% |
| CPU time | Median paired ratio | 0.019 | 0.019 | -0.4% |
| Peak memory | Median of `pdf-signer` | 28.9 MiB | 28.9 MiB | -0.1% |
| Peak memory | Median of `jsignpdf` | 138.1 MiB | 138.2 MiB | +0.1% |
| Peak memory | Median paired ratio | 0.221 | 0.219 | -1.1% |

## Limits

- No implementation is declared faster or lighter in general. These distributions hold for 44 documents of one panel, one scenario, the revisions above and one host with four pinned cores and no boost.
- The 62 documents outside the paired analysis are not timed; how each implementation behaves on them is in the coverage above and in the differential baseline. `pdf-signer` produced a valid output on fewer inputs than JSignPdf.
- The measured boundary includes the startup of each runtime, which dominates on this panel. A long-running JVM signing many documents, as a server would, is not measured here and would spread the startup over many signatures.
- Peak memory is the largest peak of one process; for JSignPdf it leaves out the PHP process that starts the JVM, and it is the metric that varies most between runs of one document.
- CPU time above wall time for JSignPdf comes from the JVM's threads on the four pinned cores.
- LibreSign or Nextcloud throughput and internal Java metrics are out of scope.
