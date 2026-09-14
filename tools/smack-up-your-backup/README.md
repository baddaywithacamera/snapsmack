

## Exit package (0.7.43+)
`exit_package.py` runs TAKE YOUR SHIT WITH YOU's engine into `<backup>/exit/` when the
profile's `exit_package` flag (the *Include exit package* checkbox) is on. **The PyInstaller
spec must bundle `../take-your-shit-with-you/*.py` (minus main.py/bump_version.py) and its
`schema/` into a `tyswy/` folder inside the exe** — `smackupyourbackup.spec` does this; if
you regenerate the spec, keep that block or the frozen build says "engine not beside this
build, exit package skipped".
