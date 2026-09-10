# Customer release packaging

Requires Python 3 with its standard library only. No dependencies to install.

From the repository root:

```text
python tools/build_release.py
```

Use `py -3 tools/build_release.py` on Windows or `python3 tools/build_release.py`
on macOS if that is how Python 3 is installed. The script also works when invoked
from another directory; paths are resolved relative to the script.

The output is `dist/io200-analytics.zip`, containing one `io200-analytics/` folder.
Only files in `RELEASE_FILES` are included. Update that list when adding customer
application files. Missing files or symlinks fail the build with a nonzero exit
status. Development files and this tooling are excluded.

Each build uses fresh temporary staging, verifies the ZIP entry list and archive
integrity, then replaces the final ZIP. An existing ZIP is never appended to and
remains untouched if building or verification fails. Temporary staging is removed
when the script exits normally, including handled failures; leftover staging from
an interrupted process is never reused or packaged.

## Release workflow

1. Commit the approved release changes.
2. Run the build command.
3. Inspect/extract `dist/io200-analytics.zip` and check its customer contents.
4. Upload the ZIP to the corresponding release.

The release title/tag supplies the version. The package filename stays fixed;
there is no version file or publishing automation.
