#!/usr/bin/env python3
"""Build the customer ZIP using only explicitly allowed application files."""

from pathlib import Path
import shutil
import sys
import tempfile
import zipfile


RELEASE_FILES = (
    "analytics.js",
    "collect.php",
    "dashboard.php",
    "install.php",
    "uninstall.php",
    "localization.php",
    "lang/en.php",
    "assets/dashboard-preview.png",
    "README.md",
    "LICENSE",
)
PACKAGE_ROOT = "io200-analytics"


def build_release():
    repository = Path(__file__).resolve().parent.parent
    for relative in RELEASE_FILES:
        source = repository / relative
        # Check parent directories too, so an allowed path cannot escape via a link.
        for component in (source, *source.parents):
            if component == repository:
                break
            if component.is_symlink():
                raise ValueError("Symlinks are not allowed: {}".format(component))
        if not source.is_file():
            raise ValueError("Required release file is missing: {}".format(relative))

    output_directory = repository / "dist"
    if output_directory.is_symlink():
        raise ValueError("Output directory must not be a symlink: {}".format(output_directory))
    output_directory.mkdir(exist_ok=True)
    destination = output_directory / "io200-analytics.zip"
    if destination.is_symlink():
        raise ValueError("Output ZIP must not be a symlink: {}".format(destination))

    # Stage on the output filesystem so the verified ZIP can replace the old one.
    with tempfile.TemporaryDirectory(prefix="ioa-release-", dir=output_directory) as temporary:
        staging = Path(temporary)
        package = staging / PACKAGE_ROOT
        for relative in RELEASE_FILES:
            target = package / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(repository / relative, target)

        archive_path = staging / "release.zip"
        expected_entries = [PACKAGE_ROOT + "/" + relative for relative in RELEASE_FILES]
        with zipfile.ZipFile(archive_path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
            for relative, entry in zip(RELEASE_FILES, expected_entries):
                archive.write(package / relative, arcname=entry)

        with zipfile.ZipFile(archive_path, "r") as archive:
            if archive.namelist() != expected_entries:
                raise ValueError("ZIP contents do not match the release allowlist")
            bad_entry = archive.testzip()
            if bad_entry is not None:
                raise ValueError("ZIP integrity check failed: {}".format(bad_entry))

        archive_path.replace(destination)

    return destination


def main():
    try:
        destination = build_release()
    except Exception as error:
        print("Release build failed: {}".format(error), file=sys.stderr)
        return 1
    print("Release ZIP: {}".format(destination))
    return 0


if __name__ == "__main__":
    sys.exit(main())
