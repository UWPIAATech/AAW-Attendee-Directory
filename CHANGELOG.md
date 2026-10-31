# Changelog

## Version 1.3

## Features
- Multiple events, each with its own shortcode (e.g. `[tucson27]`); one shared API key
- Per-event filter rules: completed registration, payment (none / paid / paid or exception field), opt-in field
- Per-event table columns with label, field, sortable and searchable settings
- New admin: events list with per-event cache status, add/edit event screen
- "Load fields" reads the event's registration form from Idloom (`/events/fields`); rules and
  columns are chosen from dropdowns showing each question's label instead of `free_fieldNN`
- A new column's label defaults to its question's label
- "Test event" shows how many registrations will be listed and why others are left out

## Changes
- Existing single-event settings migrate automatically to `[display_attendees]`
- Cache Status page merged into the events list
- Events with no qualifying attendees are cached instead of calling the API on every page view
- An empty result no longer overwrites the backup cache
- Debug logging now works when WP_DEBUG is on
- CSS/JS versions use file modification time for cache busting

## Version 1.2

## Features
- Added pagination support for events with >200 attendees using idloom-events API
- Added caching system with 5-minute primary cache and 1-hour backup cache
- Added optional debug logging (enabled with WP_DEBUG)
- Added sorting for Primary Cast column
- Added minimum 3 character requirement for search with placeholder text

## Bugfixes
- Fixed issue with attendee list showing only first 200 records
- Improved error handling for API responses
- Added proper filtering for "Who's Registered?" list visibility (free_field56)

## Technical Improvements
- Implemented WordPress transients for efficient caching
- Added pagination to API requests with page_size=200
- Added cache status admin page under Idloom Settings
- Added backup cache for API rate limit handling
- Improved debug logging with WP_DEBUG integration

## Developer Notes
- Cache can be cleared manually from admin interface
- Debug logs are stored in wp-content/uploads/idloom-debug.log when WP_DEBUG is true
- API responses are now paginated and merged for complete dataset
- Added documentation for custom fields (free_field56, free_field12, free_field40)

## Requirements
- WordPress 5.0 or higher
- PHP 7.2 or higher
- Active idloom-events API key

## Version 1.1
- Initial public release
- Basic attendee list display
- Search and sort functionality
- Basic pagination
