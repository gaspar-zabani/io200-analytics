#!/usr/bin/env python3
"""Build the customer ZIP using only explicitly allowed application files."""

from pathlib import Path
import re
import shutil
import sys
import tempfile
import zipfile


RELEASE_FILES = (
    "analytics.js",
    "collect.php",
    "dashboard.php",
    "photo-search.php",
    "photo-inspector.php",
    "photo-context.php",
    "visits.php",
    "assets/photo-search.js",
    "assets/photo-inspector.js",
    "assets/photo-inspector-modal.js",
    "assets/visit-photo-popover.js",
    "assets/visit-photo-gallery.js",
    "install.php",
    "uninstall.php",
    "localization.php",
    "version.php",
    "update-check.php",
    "lang/en.php",
    "assets/dashboard-preview.png",
    "README.md",
    "LICENSE",
)
PACKAGE_ROOT = "io200-analytics"


def read_version(path):
    # Parse the deliberately simple PHP return file without requiring PHP locally.
    match = re.fullmatch(
        r"\s*<\?php\s+return\s+(['\"])([^'\"]+)\1\s*;\s*",
        path.read_text(encoding="utf-8"),
    )
    if match is None:
        raise ValueError("version.php must contain only <?php and a quoted version return statement")
    version = match.group(2)
    semantic = re.fullmatch(
        r"(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)"
        r"(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?"
        r"(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?",
        version,
    )
    if semantic is None or len(version) > 128:
        raise ValueError("version.php must provide a valid SemVer version")
    for identifier in (semantic.group(4) or "").split("."):
        if identifier.isdigit() and len(identifier) > 1 and identifier.startswith("0"):
            raise ValueError("version.php has a numeric prerelease identifier with leading zeroes")
    return version


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

    version = read_version(repository / "version.php")
    output_directory = repository / "dist"
    if output_directory.is_symlink():
        raise ValueError("Output directory must not be a symlink: {}".format(output_directory))
    output_directory.mkdir(exist_ok=True)
    destination = output_directory / "io200-analytics-{}.zip".format(version)
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

        if read_version(package / "version.php") != version:
            raise ValueError("version.php changed during staging; run the build again")

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
