# Revisions Digest

**This is a work in progress.**

A WordPress plugin which generates digests of changes to content via their revisions. This allows users to keep track of changes to content which they may be interested in - for example changes to pages on a documentation website.

Digests can be consumed via:

* [x] A dashboard widget
* [ ] Email
* [ ] An RSS feed
* [ ] A REST API endpoint

The default duration for a digest is weekly. In a future version this will be configurable.

## New Helper Class API

The plugin now includes a `Digest` helper class that encapsulates all revisions digest functionality with enhanced features:

### Basic Usage

```php
use RevisionsDigest\Digest;

// Create a digest for the last week (default)
$digest = new Digest();
$changes = $digest->get_changes();

// Get changes with intelligent descriptions
$described_changes = $digest->get_grouped_changes();
```

### Time Periods

```php
// Get changes from different periods
$day_digest = new Digest(Digest::PERIOD_DAY);
$week_digest = new Digest(Digest::PERIOD_WEEK);
$month_digest = new Digest(Digest::PERIOD_MONTH);
```

### Grouping Options

```php
// Group changes by different criteria
$by_user = new Digest(Digest::PERIOD_WEEK, Digest::GROUP_BY_USER);
$by_date = new Digest(Digest::PERIOD_WEEK, Digest::GROUP_BY_DATE);
$by_post = new Digest(Digest::PERIOD_WEEK, Digest::GROUP_BY_POST);
$by_taxonomy = new Digest(Digest::PERIOD_WEEK, Digest::GROUP_BY_TAXONOMY);
```

### Convenience Functions

```php
// Backward compatible function (still works)
$changes = \RevisionsDigest\get_digest_changes();

// New functions for enhanced functionality
$period_changes = \RevisionsDigest\get_digest_changes_for_period(Digest::PERIOD_DAY);
$described_changes = \RevisionsDigest\get_digest_with_descriptions(Digest::PERIOD_WEEK, Digest::GROUP_BY_USER);
```

### Intelligent Descriptions

The class provides intelligent descriptions for changes:

- "Adam made small changes yesterday"
- "Sarah made major changes 3 days ago" 
- "Thomas and Ann both made 5 changes in the last week"
- "3 authors made 12 changes in the last month"

## Features

- **Multiple time periods**: day, week, month, or custom timeframes
- **Flexible grouping**: by date, user, post, or taxonomy
- **Intelligent descriptions**: contextual descriptions of who changed what and when
- **Backward compatibility**: all existing functions continue to work unchanged
- **Clean API**: object-oriented interface with sensible defaults

## Minimum Requirements

**PHP:** 7.1  
**WordPress:** 4.8  

## Development

### Requirements

Development tooling (PHPStan, PHPCS, PHPUnit) requires **PHP 7.4+**. The runtime requirement remains PHP 7.1 for production use.

### Installation

Install PHP dependencies:

```bash
composer install
```

Install JavaScript/CSS development dependencies:

```bash
npm install
```

### Linting

This project uses WordPress coding standards for both PHP and JavaScript/CSS.

**PHP Linting:**

```bash
# Check for issues
composer lint

# Auto-fix issues
composer lint:fix
```

**JavaScript/CSS Linting:**

```bash
# Check for issues
npm run lint

# Auto-fix issues
npm run lint:fix
```

### Static Analysis

This project uses [PHPStan](https://phpstan.org/) for static analysis at level 5.

```bash
# Run PHPStan
composer phpstan
```

### Pre-commit Hooks

This project uses [husky](https://typicode.github.io/husky/) and [lint-staged](https://github.com/lint-staged/lint-staged) to automatically run linters on staged files before each commit. After running `npm install`, the pre-commit hook will be installed automatically.

### Running Tests

First, set up the WordPress test suite:

```bash
bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
```

Then run tests:

```bash
./vendor/bin/phpunit
```
