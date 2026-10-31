# Idloom Events Attendee Display

A WordPress plugin for displaying attendee information from the Idloom Events API with sorting, filtering, and pagination capabilities.

## Features

- Displays attendees in a sortable table
- Real-time search filtering (minimum 3 characters)
- Pagination (20 attendees per page)
- Sortable columns for attendee details
- Displays primary cast, other casts, and country information
- API integration with Idloom Events
- Multiple events, each with its own shortcode (e.g. `[tucson27]`)
- Per-event filter rules and table columns
- Admin configuration panel with field discovery and a test tool
- Caching system per event (5min primary, 1hr backup)
- Support for large events (200+ attendees)

## Installation

1. Download the plugin files
2. Upload to your `/wp-content/plugins/` directory
3. Activate the plugin through WordPress admin menu
4. Navigate to "Idloom Settings" and configure

## Configuration

1. Navigate to "Idloom Events" in the WordPress admin menu
2. Enter your API Key (shared by all events) and save
3. Click "Add Event" and fill in:
   - **Name** – for admin reference only
   - **Shortcode** – e.g. `tucson27`; lowercase letters, numbers and underscores
   - **Idloom Event ID** – then click "Load fields" to read the event's registration form; rules and
     columns are then picked from dropdowns showing each question's label
   - **Who appears on the list** – completed registrations only, payment requirement
     (none / paid / paid or exception field such as a coupon), and the opt-in field
   - **Columns** – label, field, sortable, searchable, in display order
4. Save, then use "Test event" to see how many registrations will be listed and why others are left out

Each event's form numbers its custom questions differently (`free_field1`, `free_field2`, …),
so rules and columns are set per event. Labels come from the Idloom `GET /events/fields` endpoint
and are cached for an hour.

## Usage

Put the event's shortcode, e.g. `[tucson27]`, on a page. One list per page.

### Upgrading from 1.2

The single event configured in 1.2 is moved automatically to an event with the shortcode
`[display_attendees]` and the same rules and columns as before, so existing pages keep working.

## File Structure

- idloom-attendee-display.php
- includes/
  - class-api-handler.php
  - class-admin.php
  - class-display.php
  - class-events.php
- templates/
  - admin-page.php
  - event-edit.php
  - attendee-list.php
- assets/
  - css/style.css
  - js/script.js
  - js/admin.js

## API Requirements

- Requires valid Idloom Events API credentials
- Rules use the standard `registration_status` ('Form Completed') and `payment_status` ('Paid') fields;
  the exception, opt-in and column fields are chosen per event

## Dependencies

- WordPress 5.0+
- PHP 7.2+
- jQuery (included with WordPress)
- Dashicons (included with WordPress)

## Development

### Events
Stores per-event settings in the `idloom_events` option and validates admin input.

### API Handler
Handles API communication, per-event caching, and rule filtering. Includes pagination support for large datasets.

### Admin Panel
Manages the API key, events, and per-event cache through the WordPress admin interface.

### Display Handler
Registers one shortcode per event and controls frontend display, sorting and searching.

## Debug Mode
Enable WP_DEBUG in wp-config.php for detailed logging to the PHP error log (wp-content/debug.log when WP_DEBUG_LOG is on).

## Styling

Custom CSS classes for styling:
- .attendee-list
- .attendee-search
- .attendee-table
- .sortable
- .pagination

## License

GPL v3 or later