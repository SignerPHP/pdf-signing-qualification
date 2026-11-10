# Replays between revisions

A replay runs the complete pilot, every [regression case](regressions.md) included, at two exact revisions of `pdf-signer` over the same acquired inputs, and compares the two runs with `compare-runs`. It compares complete runs, not only earlier failures, so it reports failures that a revision fixed, failures that persist and failures that it introduced. How to run one is in [`AGENTS.md`](../AGENTS.md#replaying-another-revision); what the comparison means is in the [schemas README](../schemas/README.md#semantics).

## `v0.2.0` against `v0.3.0`

```sh
bin/replay v0.2.0 v0.3.0
```

The replay resolved `v0.2.0` to commit `4229c83ea51170303f6f8e005767b358ed9e756a` and `v0.3.0` to commit `f27e4064be107b8a662f370a9acdcecd786da9f7`, and built each image from its commit.

### Revisions and environment

| Item | Reference | Candidate |
| ---- | --------- | --------- |
| `pdf-signer` | `v0.2.0`, commit `4229c83ea51170303f6f8e005767b358ed9e756a` | `v0.3.0`, commit `f27e4064be107b8a662f370a9acdcecd786da9f7` |
| `pdf-core`, from each revision's `composer.lock` | v0.1.1 | v0.1.1 |
| Image | `sha256:19c328ea3896092974910ff6efc83ddb0e95046be41bb8d4e30c9a83971fccad` | `sha256:5f06c07ed60b98fe4e96cec10382bd4526e923434701ab91d2e2df6c8785be1e` |
| Compatibility with the adapter | `compatible` | `compatible` |
| Records | `replay-20261107T153713Z.tar.xz`, SHA-256 `a77dbae5d933e32f2630bb035077fe92b8992eabef9019eabc2704164309b2b9`, attached to release [`results-20261110-1`](https://github.com/SignerPHP/pdf-signing-qualification/releases/tag/results-20261110-1), with both runs and the comparison. These runs predate the copy of the source configuration and `report.md` in a run directory, so the bundle holds neither; the configuration with that SHA-256 is `config/inputs.json` at commit `0303f8c5c1c3901fd9cd0009c8650b90e9e729f3`, which `bin/report` cannot read because it predates the `selection` property. | The same bundle |

Both runs used the same analyzers (`pyhanko` 0.38.0, `pdfsig` 22.12.0, `openssl-cms` 3.0.22, `qpdf` 11.3.0, `cmp` 3.8, `qpdf-prior-signatures` 11.3.0 and `qpdf-byte-range` 11.3.0), the source configuration with SHA-256 `c6ca54495ff2d87abe592c552792ed468b5419db392b6e59237d3ccb753952cc`, the expected rejections with SHA-256 `fd1c9906de8e57f60706d3aa035a53f4c148fbd5b6ca3b63593f30670dd33b21`, and the `hybrid-xref` case with SHA-256 `74ddb1f357d435611e3c4d1ca9b13e0e482ab9cb24ef529506aecdbbe96d6a3b`. The comparison states only the implementation and the container image as differences between the runs.

### Output

`compare-runs` exited with 0. Both runs signed 23 of the 27 inputs, failed 3 and rejected 1 as expected.

| Input status | Inputs |
| ------------ | -----: |
| `paired` | 27 |
| `only_in_reference`, `only_in_candidate`, `unavailable`, `input_changed` | 0 |

| Change | Items of inputs | Items of regression cases |
| ------ | --------------: | ------------------------: |
| `introduced` | 0 | 0 |
| `fixed` | 0 | 0 |
| `persistent` | 42 | 2 |
| `changed` | 0 | 0 |
| `unchanged` | 201 | 8 |

The 42 persistent items are the failures listed in the [pilot results](pilot-results.md) and found again at `v0.2.0`: the executions of `bad-direct-root.pdf`, `bad35.pdf` and veraPDF `7.16-t01-fail-a.pdf`, and the verdicts that are `invalid` or `inconclusive` on the same outputs.

The regression section of `comparison.md`:

```markdown
| Case | Status | Reference | Candidate | Change |
| ---- | ------ | --------- | --------- | ------ |
| `hybrid-xref` | `paired` | `present`: The final trailer of the output has /XRefStm 445, inside the 721 bytes of the input | `present`: The final trailer of the output has /XRefStm 445, inside the 721 bytes of the input | `persistent` |

Other items of the regression cases that are not unchanged:

| Case | Item | Reference | Candidate | Change |
| ---- | ---- | --------- | --------- | ------ |
| `hybrid-xref` | verdict of `pyhanko` | `inconclusive` | `inconclusive` | `persistent` |
```

The condition of `hybrid-xref` is present at both revisions, and the other verdicts on its output are the same at both: `pdfsig`, `openssl-cms`, `qpdf`, `cmp` and `qpdf-byte-range` `valid`, and `qpdf-prior-signatures` `not_applicable`.

### Reading

The two revisions behave the same on every input and on the regression case. Both lock `pdf-core` v0.1.1, where the parser and the writer of the signed revision live. The commits between them add external signing and the VIDaaS provider, and on the way change files that local signing also goes through, such as `PdfSigningService`, `NativePdfSigningEngine`, `DetachedCmsAssembler` and `SignedBufferBuilder`. On this sample and scenario, those changes leave every execution outcome, every verdict and the regression condition as they were. Output hashes are not compared, so the replay says nothing about byte-level differences between the outputs that the analyzers judge the same.

## `v0.1.0`

```sh
bin/replay v0.1.0 v0.3.0
```

`v0.1.0` (commit `80f7963fba633bdaa2c329f0a1ccba6812c70b81`) cannot be installed with its locked dependencies: its `composer.lock` takes `signerphp/pdf-core` from a path repository, `../pdf-core`, which does not exist outside the developer's checkout. The image build fails with `Source path "../pdf-core" is not found for package signerphp/pdf-core`, and the replay stops with exit 2 and `pdf-signer 80f7963fba633bdaa2c329f0a1ccba6812c70b81 could not be installed with its locked dependencies`, before anything is acquired or signed. No run is recorded for it, because no attempt could have run the revision that the lock describes.
