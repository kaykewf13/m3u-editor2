# Plugin System Worklog

This file is the durable handoff for the `feature/plugin-system-foundation` branch.

Use it when chat history disappears, when switching devices, or before starting a new plugin-system session.

## How To Update This Log

After each meaningful plugin-system change, append a short entry with:

- date
- branch
- goal
- files changed
- verification run
- next likely step

Keep it short. The point is fast recovery, not perfect prose.

## 2026-03-20

- Branch: `feature/plugin-system-foundation`
- Context: recovered the plugin-system branch after losing the previous conversation
- Recovery summary:
  - latest plugin-security chain is `638cdda8` -> `4d60d62f` -> `0e3ce708` -> `0b54e256`
  - `01964a0b` is newer, but it only fixes dev-container env persistence in `start-container`
  - main plugin system hub is `app/Plugins/PluginManager.php`
  - reviewed install, trust, integrity, lifecycle, and registry doctor are already implemented
- Security gap found during recovery:
  - validation was loading untrusted `Plugin.php` before trust
  - archive staging was extracting untrusted archives before rejecting unsafe paths
- Hardening work started:
- Hardening work completed:
  - moved entrypoint validation toward static inspection in `app/Plugins/PluginValidator.php`
  - rejected top-level executable statements before the plugin class during reviewed-install validation
  - replaced blind archive extraction with guarded entry enumeration and file-by-file extraction in `app/Plugins/PluginManager.php`
  - added regression coverage in `tests/Feature/PluginSystemTest.php`
- Verification:
  - `docker compose -f docker-compose.dev.yml --profile test run --rm -v "$PWD/app:/var/www/html/app" -v "$PWD/config:/var/www/html/config" -v "$PWD/routes:/var/www/html/routes" -v "$PWD/plugins:/var/www/html/plugins" -v "$PWD/database:/var/www/html/database" -v "$PWD/tests:/var/www/html/tests" m3u-editor-dev-test tests/Feature/PluginSystemTest.php`
  - `docker compose -f docker-compose.dev.yml --profile test run --rm -v "$PWD/app:/var/www/html/app" -v "$PWD/config:/var/www/html/config" -v "$PWD/routes:/var/www/html/routes" -v "$PWD/plugins:/var/www/html/plugins" -v "$PWD/database:/var/www/html/database" -v "$PWD/tests:/var/www/html/tests" -v "$PWD/stubs:/var/www/html/stubs" m3u-editor-dev-test tests/Feature/MakePluginCommandTest.php`
  - result: both suites passed after mounting the live branch into the existing test image
  - note: direct `--build` runs are currently blocked by the macOS AppleDouble sidecar file `._PLUGIN_DEV.md`
- Next likely step:
  - commit the hardening pass
  - keep local AppleDouble cleanup outside the repo because that is environment-specific
  - if more security work is needed, the next seam is archive limits and tighter reviewed-install operator UX
