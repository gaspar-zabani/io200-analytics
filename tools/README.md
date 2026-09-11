# Customer release packaging

Requires Python 3 with its standard library only. No dependencies to install.

From the repository root:

```text
python tools/build_release.py
```

Use `py -3 tools/build_release.py` on Windows or `python3 tools/build_release.py`
on macOS if that is how Python 3 is installed. The script also works when invoked
from another directory; paths are resolved relative to the script.

The output is `dist/io200-analytics-<version>.zip`, containing one `io200-analytics/` folder.
Only files in `RELEASE_FILES` are included. Update that list when adding customer
application files. Missing files or symlinks fail the build with a nonzero exit
status. Development files and this tooling are excluded.

Each build uses fresh temporary staging, verifies the ZIP entry list and archive
integrity, then replaces the final ZIP. An existing ZIP is never appended to and
remains untouched if building or verification fails. Temporary staging is removed
when the script exits normally, including handled failures; leftover staging from
an interrupted process is never reused or packaged.

## Release workflow

1. Set the release version in `version.php` and commit the approved release changes.
2. Run the build command.
3. Inspect/extract `dist/io200-analytics-<version>.zip` and check its customer contents.
4. Upload the ZIP to the corresponding release.

`version.php` is the authoritative version source. Keep it as a PHP file containing
only a quoted SemVer return statement (`<?php` followed by `return '…';`). The
builder parses it without executing PHP and rejects missing or invalid versions.
For example, version `1.1.0-beta.4` produces
`dist/io200-analytics-1.1.0-beta.4.zip`; the folder inside remains `io200-analytics/`.
Both `version.php` and `update-check.php` are required package files. Use the same
version in the release title/tag. There is no publishing automation.

## Testing the update notification with a fresh check

The update checker stores only its manifest/check timestamp in one file:

```text
<PHP temporary directory>/ioa-update-<SHA-256>.json
```

The temporary directory is `sys_get_temp_dir()` in the dashboard's PHP runtime.
The hash is SHA-256 of the absolute directory containing `update-check.php`,
followed by `|`, followed by `IOA_UPDATE_MANIFEST_URL`. This isolates the cache
by installation and manifest endpoint. Valid manifests are cached for six hours;
failed checks are cached for 15 minutes. These durations do not need changing.

To force a fresh check during styling tests:

1. On the hosting server, identify the exact cache path. If SSH/terminal PHP uses
   the same temporary directory and filesystem paths as web PHP, run this from
   the deployed `io200-analytics` directory (POSIX shell):

   ```sh
   php -r 'require "update-check.php"; $directory = dirname((new ReflectionFunction("ioaAvailableUpdate"))->getFileName()); echo sys_get_temp_dir() . DIRECTORY_SEPARATOR . "ioa-update-" . hash("sha256", $directory . "|" . IOA_UPDATE_MANIFEST_URL) . ".json", PHP_EOL;'
   ```

   This prints the path only; it does not fetch a manifest or modify state.
   If web PHP uses a private temporary directory or different configuration,
   use the hosting panel's PHP configuration/support to confirm its
   `sys_get_temp_dir()` and the deployed absolute directory. A local workstation's
   path/hash does not identify the server's cache.
2. Wait for any dashboard requests to finish, then delete **only that exact
   file** using the hosting file manager or SSH. Do not delete the temporary
   directory or use a wildcard matching other installations' caches. If the
   directory is inaccessible, ask hosting support to delete that exact file.
3. Reload the dashboard while signed in as an IO200 Admin. The next update check
   recreates the file and attempts to fetch the manifest. The notice appears
   only if the response passes existing validation and its version is newer
   than `version.php`. Repeat the deletion before another fresh-check test.

Deleting this file does not change analytics data, sessions, installer state, or
other caches. A browser hard refresh alone does not clear this server-side file.
No temporary bypass code or debug UI is needed, and nothing from this procedure
needs removing before committing.
