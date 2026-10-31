# AGENTS.md

Notes for anyone (human or AI) working on this plugin. See README.md for features and admin usage.
Never put API keys, event IDs or attendee data here. Web access to `.md` files, dotfiles and `.git/`
is blocked site-wide by a rule in `/opt/uwpiaa/php-security.conf` (outside this repo), but this file is
still on GitHub.

## This folder is the live site

The plugin folder is mounted directly into the production WordPress container. **Any file edit here
is live immediately.** Don't edit in place. Instead:

1. `git worktree add -b feature/<name> <path outside wp_files> main`
2. Work and test in the worktree
3. Merge to `main` in this folder (`git merge --ff-only`), push, then `git worktree remove`

Rollback is `git checkout <previous commit>` in this folder.

## Testing

- There is no PHP on the host. Lint with the container's PHP by piping the file in:
  `docker exec -i uwpiaa-wordpress php -l < file.php`
- For logic tests, run a throwaway container from the same image with `--network none`, the
  worktree mounted read-only, and WordPress functions stubbed (`get_option`, transients, `add_shortcode`,
  `wp_remote_get`, escaping helpers, ...). Don't load the real `wp-load.php` for tests: it runs
  against the production database.
- No test suite is checked in yet.

## Idloom API

- Docs (Stoplight/OpenAPI): https://www.clarinstrainingevents.com/docs/ (the `idloom.events/docs`
  link redirects to an unrelated domain). The spec is embedded in `/docs/api`.
- Base URL `https://idloom.events/api/v4`, `Authorization: Bearer <key>`.
- `GET /attendees?event_uid=…&page=…&page_size=200&ignore_fields_mapping=1`: paginated, total
  in `meta.total`. `ignore_fields_mapping=1` keeps native field names (`free_fieldNN`, `firstname`, ...)
  regardless of any mapping configured in Idloom.
- `GET /events/fields?event_uid=…`: the registration form's fields with `name`, `type`, `label`;
  some fields have subfields under `fields`. Only fields active on the form are returned, so system
  fields like `registration_status` and `payment_status` aren't in it.
- `GET /events`: lists events the API key can see.
- Status values used by the rules: `registration_status === 'Form Completed'`, `payment_status === 'Paid'`.
- Custom questions are `free_field1…free_fieldNN`, **numbered per event form**, so the same question has
  different numbers in different events. Consent/opt-in values arrive as bool, `1`, `"1"`, `"true"`
  or `"yes"`.

## How it fits together

- Events live in one option, `idloom_events`, keyed by shortcode name (`includes/class-events.php`
  documents the shape). Each event has its own rules and columns. The API key (`idloom_api_key`) is shared.
- Each event's shortcode name is registered as its own shortcode, e.g. `[tucson27]`. All of them use
  `IdloomDisplay::display_attendees_shortcode`, which finds the event from the `$tag` argument.
- `[display_attendees]` is the event migrated from the old single-event settings (`idloom_event_id`,
  which is still kept for rollback). Pages may depend on that name.
- Caches are per event: filtered attendees in a transient keyed by md5(shortcode|event_uid) (5 min),
  with a `_backup` copy (1 hour) used when the API fails. Form field labels are cached for an hour in
  `idloom_fields_<md5(event_uid)>`. Saving or deleting an event clears its attendee cache.
- Visitors can only sort and search on columns configured as sortable or searchable. Keep it that way:
  attendee records include emails, phones and addresses.
- No opt-in field means everyone who passes the other rules is listed publicly. The admin page warns
  about this.

## Conventions

- Match the existing style: classes `Idloom*`, autoloaded from the map in `idloom-attendee-display.php`;
  templates in `templates/` are `require`d from class methods, so `$this` is available in them.
- Asset versions use `filemtime()` for cache busting.
- Update CHANGELOG.md and bump the plugin header version for user-visible changes.
- The `.sav` files are old copies kept by the maintainer. Leave them alone.
