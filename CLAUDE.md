# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Revisions Digest is a WordPress plugin that generates digests of content changes via revisions. It displays recent content changes in a dashboard widget, showing diffs between revisions with author attribution.

## Development Commands

### Install Dependencies
```bash
composer install
```

### Run Tests
Requires WordPress test suite setup first:
```bash
bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
```

Then run tests:
```bash
./vendor/bin/phpunit
```

Run a single test file:
```bash
./vendor/bin/phpunit tests/test-get-posts.php
```

### Linting (PHPCS)
```bash
./vendor/bin/phpcs
```

Fix auto-fixable issues:
```bash
./vendor/bin/phpcbf
```

## Architecture

The plugin is contained in a single file (`revisions-digest.php`) using the `RevisionsDigest` namespace.

### Key Functions

- `get_digest_changes()` - Main entry point that orchestrates fetching and diffing revisions
- `get_updated_posts($timeframe)` - Queries posts modified since the given timestamp (currently pages only)
- `get_post_revisions($post_id, $timeframe)` - Fetches revisions for a post within timeframe, plus one before
- `get_bound_revisions($revisions)` - Returns earliest and latest revisions from array
- `get_diff($latest, $earliest)` - Creates Text_Diff object comparing revision content
- `render_diff($text_diff, $renderer)` - Renders diff using WP_Text_Diff_Renderer_Table
- `widget()` - Dashboard widget display callback

### Data Flow

1. Dashboard widget calls `get_digest_changes()`
2. Finds posts modified in last week via `get_updated_posts()`
3. For each post, gets relevant revisions via `get_post_revisions()`
4. Computes diff between earliest and latest revisions
5. Renders unified diff in table format

## Coding Standards

- Uses WordPress-VIP coding standard with WordPress-Docs
- PHP 7.0+ with `declare(strict_types=1)`
- Uses type hints for parameters and return types
- Indentation: tabs (4 spaces width)
- Text domain: `revisions-digest`

## Testing

Tests extend `RevisionsDigest\Tests\TestCase` which provides `post_factory()` helper for creating posts with specific `post_modified` dates (since `wp_insert_post()` doesn't support this parameter).
