# Visible-appearance scenario

The scenario [`basic-visible-sha256`](../config/scenarios/basic-visible-sha256.json) is `basic-invisible-sha256` with a visible signature: the same digest, signature algorithm and sub filter, without timestamp or revocation information, and a widget that shows the signature on the page. It qualifies the appearance of the added signature, not only its cryptographic validity.

```sh
bin/pilot --panel=main --scenario=basic-visible-sha256 jsignpdf
bin/differential --panel=main --scenario=basic-visible-sha256
```

## Placement

The scenario places the field on page 1, 36 points from the left and top edges of the page's `/MediaBox`, 240 points wide and 84 points high, in the page's default user space before any `/Rotate`. It follows version 2 of the [scenario schema](../schemas/v2/scenario.schema.json), which requires a placement for a visible appearance.

That placement is the default appearance of `pdf-signer`, the only visible appearance its command line gives without changing `pdf-signer`: its rectangle `[36, 36, 276, 120]` is measured from the top of the page, and on a 792-point page the output's `/Rect` is `[36 756 276 672]`. JSignPdf takes a rectangle from the bottom of the page, and subtracts a negative coordinate from the page height, so the adapter passes `-pg 1 -llx 36 -lly -120 -urx 276 -ury -36`, and the field lands at the same place whatever the page height.

Appearance contents are not part of the scenario: `pdf-signer` draws its stamp image and JSignPdf its signer text. Customizing them is out of scope.

## Confirmation

Each adapter accepts the visible scenario only with this placement; any other page, edge or size is recorded as `unsupported`. [`VisibleScenarioTest`](../tests/Signing/VisibleScenarioTest.php) confirms it from signed outputs: each implementation signs a two-page synthetic document under the scenario, and every analyzer of the scenario, the visual one included, judges its output `valid`, except `qpdf-prior-signatures`, which is `not_applicable` on an unsigned input.

While confirming the placement on synthetic pages, both implementations put the field at the same place on pages of 792 and 200 points, and both ignore a `/CropBox`. Two cases do not match the scenario, and the visual analyzer reports them:

- **`/MediaBox` away from the origin.** Both implementations measure from the origin of the user space instead of the corner of the `/MediaBox`, so the field is off by the box's offset.
- **`/Rotate`.** `pdf-signer` places the field in the unrotated page, as the scenario declares. JSignPdf turns its negative coordinates into a rectangle outside the page.

## Visual analyzer

`pdftoppm-appearance` writes the `visual` property, only in runs of a visible scenario, so runs of `basic-invisible-sha256` keep the analyzers of the published results. It uses the pinned `qpdf` and `pdftoppm` of the image, and records the `pdftoppm` version. Its policy is in the [schemas README](../schemas/README.md); in short, the added signature must have one widget, in the `/Annots` of page 1, at the declared rectangle, visible and with a normal appearance stream, and rendered at 72 dpi its page changes inside the field only, at least one pixel there, while every other page renders as in the input. What it cannot read is `inconclusive`.

The rendering is poppler's, so it is what a poppler-based reader shows. When the form's `/NeedAppearances` is true, poppler builds the appearance of the fields itself instead of drawing their `/AP`, and a signature field it builds has nothing to draw; such an output is `invalid` because nothing appears on the page, and the summary then names the appearance, not the reason. An implementation that clears the flag, as JSignPdf's OpenPDF engine does, is drawn from its own `/AP`.

A rendering comparison, and not only the structure, is needed because a widget at the right place with an appearance stream can still draw nothing, and an implementation can change the page outside the field, for example by redrawing another form field. One renderer is used; a second one would be recorded as an analyzer of its own.

## Inputs

The scenario runs over the existing samples: the [pilot sample](pilot-sample.md) and the [main panel](main-panel.md), whose inputs are declared with their strata under the free-license policy, and the synthetic fixtures of the tests. Their expected rejections are declared for this scenario too, with the same reasons. No input was chosen for the placement, so rotated pages and offset media boxes are reached only where a panel happens to hold them.
