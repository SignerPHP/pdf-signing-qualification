# Synthetic fixtures

Small, original files created for this repository's tests. Upstream and user-reported PDFs never go here; see [CONTRIBUTING.md](../../../CONTRIBUTING.md).

Every file in this directory must be listed below, by its path relative to this directory, before it is committed.

| File | Owner | How it was produced | Purpose |
| ---- | ----- | ------------------- | ------- |
| `HybridXrefPdf.php` | SignerPHP | Written for this repository; writes `hybrid-xref.pdf` at test time, a PDF 1.5 whose catalog, page tree and page are in an object stream listed only by the cross-reference stream that the trailer names with `/XRefStm` | Input of the [`hybrid-xref`](../../../docs/regressions.md#hybrid-xref) regression case |
