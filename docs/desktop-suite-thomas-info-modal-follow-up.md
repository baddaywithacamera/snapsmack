# Desktop suite identity and information modal

Status: queued follow-up

Every SNAPSMACK desktop application must include both of these shared suite features:

1. Thomas the Bear, using the common desktop asset and behavior rather than a separate app-specific copy.
2. An information modal opened by `Ctrl+Shift+Z`, with the app name, build version, purpose, and relevant support or diagnostics information.

The shortcut must work from every main application screen. The modal and Thomas treatment should come from one shared desktop module so behavior cannot drift between applications. Packaging checks must verify that the shared assets are present in every built executable.
