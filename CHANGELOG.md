# v0.2.6
## 10/23/2026

1. [](#bugfix)
    * Installs cleanly with Admin 2's Direct Install. `permissions.yaml` moved to `config/`: at the top level, Grav took its name for the plugin's and installed it as `user/plugins/permissions`.

# v0.2.5
## 10/23/2026

1. [](#bugfix)
    * Restoring follows page access rules, as creating a page does: a user denied a section can't restore a page into it, whether chosen as the new parent or as where the page was. A translation needs the right to edit its page.
    * A page path of `.` is refused as not in the trash, instead of a server error.
1. [](#improved)
    * Needs API 1.0.57 or later, the version it is tested on.

# v0.2.4
## 10/23/2026

1. [](#bugfix)
    * The trash folder gets an `.htaccess` that refuses web requests, so a trashed image or PDF can't be opened by URL (Apache and LiteSpeed; nginx needs its own rule).

# v0.2.3
## 10/23/2026

1. [](#improved)
    * The Trash page reads one small file per entry: the tree of pages inside an entry is saved with it when it is deleted, instead of being rebuilt from every page file on each visit. Empty trash and restore checks no longer build it at all.
1. [](#bugfix)
    * A delete cut off by the host's time or memory limit no longer leaves a half-copied folder in the trash forever; such folders are cleared after an hour.

# v0.2.2
## 10/17/2026

1. [](#bugfix)
    * Old entries are cleared after the deleted page is saved, not before, and one that cannot be removed is logged and skipped. Before, a single stuck entry made every later delete go ahead with no copy kept.
    * Deleting one language of a module now saves that file (it looked for `modular/<name>.<lang>.md`); if the file can't be found, the failure shows on the Trash page instead of passing silently.
    * Deleting a language the page doesn't have no longer leaves an entry behind.
    * Restoring into a folder named `.` or `..` is refused with a message, not a server error.
    * Delete and Empty trash ask with Admin 2's own dialog, with a "Delete permanently" button.
    * Admin 2's own colours, so the page reads right in light and dark mode; delete buttons stay grey until pointed at, dates drop the seconds, and the table scrolls on narrow screens.
    * Sends the sign-in token as `X-API-Token`, which hosts that strip `Authorization` let through.
    * Only a location conflict offers "choose a location"; any other refusal shows as an error.

# v0.2.1
## 10/16/2026

1. [](#bugfix)
    * The trash permissions can be given to users: they were never registered, so only super admins could use the trash.
    * Deleting a page with one page under it no longer says "the 1 pages under it".
1. [](#improved)
    * All text comes from `languages/en.yaml`, so the plugin can be translated.

# v0.2.0
## 09/30/2026

1. [](#new)
    * The Trash page is an expandable tree. Restore or delete any page inside a trashed page; ticking a page ticks everything under it, and unticked pages stay in the trash.
    * `POST /trash/{id}/discard` deletes one page of an entry. `restore` takes `node` and `exclude`.
1. [](#improved)
    * The sidebar count includes child pages.

# v0.1.0
## 09/29/2026

1. [](#new)
    * Initial release: pages deleted through the API are copied to a trash, with an Admin 2 page to search, restore, delete permanently and empty it.
