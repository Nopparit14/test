#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
BUILD_DIR="$ROOT/build"
ARCHIVE="$BUILD_DIR/HYperRegedit-original-identity.xcarchive"
IPA="$BUILD_DIR/HYper-Regedit-Key-Enabled-unsigned.ipa"

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"
command -v xcodebuild >/dev/null || { echo 'xcodebuild is required on macOS' >&2; exit 127; }

xcodebuild \
  -project "$ROOT/ThreeOneOSFive.xcodeproj" \
  -scheme OGIOS \
  -configuration Release \
  -sdk iphoneos \
  -archivePath "$ARCHIVE" \
  CODE_SIGNING_ALLOWED=NO \
  CODE_SIGNING_REQUIRED=NO \
  CODE_SIGN_IDENTITY='' \
  archive

APP="$ARCHIVE/Products/Applications/OGIOS.app"
test -d "$APP"
TEMP_PATCHES="$BUILD_DIR/temp_patches"
mkdir -p "$TEMP_PATCHES"
for package in "$APP"/*.3105 "$APP"/Patches/*.3105 "$APP"/.patches/*.3105 "$ROOT"/ThreeOneOSFive/Patches/*.3105; do
  [ -e "$package" ] || continue
  cp -f "$package" "$TEMP_PATCHES/"
done
(
  cd "$TEMP_PATCHES"
  zip -q -0 "$APP/Assets.dat" *.3105
)
rm -rf "$TEMP_PATCHES"
rm -rf "$APP"/*.3105 "$APP/Patches" "$APP/.patches"

/usr/libexec/PlistBuddy -c "Set :UIFileSharingEnabled false" "$APP/Info.plist" || true
/usr/libexec/PlistBuddy -c "Set :LSSupportsOpeningDocumentsInPlace false" "$APP/Info.plist" || true
/usr/libexec/PlistBuddy -c "Set :CFBundleExecutable OGIOS" "$APP/Info.plist" || true
/usr/libexec/PlistBuddy -c "Set :CFBundlePackageType APPL" "$APP/Info.plist" || true
mkdir -p "$BUILD_DIR/Payload"
cp -R "$APP" "$BUILD_DIR/Payload/"
(
  cd "$BUILD_DIR"
  /usr/bin/zip -qry "$IPA" Payload
  rm -rf Payload
)
echo "$IPA"
