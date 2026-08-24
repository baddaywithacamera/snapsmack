# ACTION LAB

**Status:** TO DO / concept  
**Type:** AI-enabled companion tool  
**Core promise:** Describe the photographic look or correction you want. ACTION LAB builds the reusable action/preset and places it in the correct local Actions folder for you.

## The pitch

Stop buying expensive action and preset packs just to get one useful treatment. Tell ACTION LAB what you want in ordinary language—such as “muted winter documentary colour, protect skin tones, lift deep shadows, add restrained grain”—and it creates a reusable local recipe you own.

## First useful version

- Accept a plain-language description of the desired result.
- Ask which supported editor or SNAP SLAPPER workflow should receive it.
- Convert the request into a constrained, inspectable adjustment recipe.
- Preview the recipe against one or more user-selected photographs.
- Let the user refine the result conversationally.
- Show every adjustment before installation.
- Save the finished action/preset into the configured local Actions folder.
- Preserve the generated recipe as an editable ACTION LAB project.
- Never upload photographs unless the user explicitly chooses a cloud model and approves the upload.

## Output targets

- SNAP SLAPPER native adjustment recipes first.
- Standard preset formats where they are documented and safely writable.
- Editor-specific actions only where the target application provides a supported format or automation interface.
- A sidecar recipe plus clear manual-install instructions when direct generation is not reliable.

## Guardrails

- Generated actions are non-destructive by default.
- No arbitrary scripts or executable code generated into an Actions folder.
- Originals are never overwritten during preview.
- Installation requires a visible summary and confirmation.
- Every installed action can be removed or exported.
- AI suggestions are treated as editable starting points, not magic or objective corrections.

## Open decisions

- Exact first editor target after SNAP SLAPPER.
- Local model, cloud model, or user-selectable hybrid.
- Whether recipes should support masks and subject-aware adjustments in the first release.
- Naming and interchange format for portable ACTION LAB recipes.
