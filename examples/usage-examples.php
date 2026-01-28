<?php
/**
 * Example usage of the Revisions Digest helper class
 *
 * This file demonstrates how to use the new Digest class API.
 *
 * @package revisions-digest
 */

// phpcs:disable Squiz.PHP.CommentedOutCode.Found, Squiz.Commenting.InlineComment.InvalidEndChar
// require_once 'revisions-digest.php';
// phpcs:enable

use RevisionsDigest\Digest;

echo "=== Revisions Digest Helper Class Examples ===\n\n";

// Example 1: Basic usage (backward compatible).
echo "1. Basic usage (backward compatible):\n";
echo "   \$changes = \\RevisionsDigest\\get_digest_changes();\n\n";

// Example 2: Get changes for different periods.
echo "2. Get changes for different periods:\n";
echo "   // Get changes from last day\n";
echo "   \$day_changes = \\RevisionsDigest\\get_digest_changes_for_period(Digest::PERIOD_DAY);\n\n";
echo "   // Get changes from last month\n";
echo "   \$month_changes = \\RevisionsDigest\\get_digest_changes_for_period(Digest::PERIOD_MONTH);\n\n";

// Example 3: Group changes by different criteria.
echo "3. Group changes by different criteria:\n";
echo "   // Group by user\n";
echo "   \$user_grouped = \\RevisionsDigest\\get_digest_changes_for_period(\n";
echo "       Digest::PERIOD_WEEK,\n";
echo "       Digest::GROUP_BY_USER\n";
echo "   );\n\n";

echo "   // Group by date\n";
echo "   \$date_grouped = \\RevisionsDigest\\get_digest_changes_for_period(\n";
echo "       Digest::PERIOD_WEEK,\n";
echo "       Digest::GROUP_BY_DATE\n";
echo "   );\n\n";

// Example 4: Get changes with intelligent descriptions.
echo "4. Get changes with intelligent descriptions:\n";
echo "   \$described_changes = \\RevisionsDigest\\get_digest_with_descriptions(\n";
echo "       Digest::PERIOD_WEEK,\n";
echo "       Digest::GROUP_BY_USER\n";
echo "   );\n\n";
echo "   // Each change will have a 'description' field like:\n";
echo "   // \"Adam made several changes yesterday\"\n";
echo "   // \"Sarah made major changes 3 days ago\"\n";
echo "   // \"Thomas and Ann made 5 changes in the last week\"\n\n";

// Example 5: Using the class directly.
echo "5. Using the Digest class directly:\n";
echo "   \$digest = new Digest(Digest::PERIOD_DAY, Digest::GROUP_BY_USER);\n";
echo "   \$changes = \$digest->get_changes();\n";
echo "   \$grouped_changes = \$digest->get_grouped_changes();\n\n";

// Example 6: Custom timeframe.
echo "6. Custom timeframe:\n";
echo "   \$custom_time = strtotime('-3 days');\n";
echo "   \$digest = new Digest(Digest::PERIOD_WEEK, Digest::GROUP_BY_POST, \$custom_time);\n";
echo "   \$changes = \$digest->get_changes();\n\n";

echo "=== Available Constants ===\n\n";
echo "Time Periods:\n";
echo "- Digest::PERIOD_DAY   ('day')\n";
echo "- Digest::PERIOD_WEEK  ('week')\n";
echo "- Digest::PERIOD_MONTH ('month')\n\n";

echo "Grouping Options:\n";
echo "- Digest::GROUP_BY_DATE     ('date')\n";
echo "- Digest::GROUP_BY_USER     ('user')\n";
echo "- Digest::GROUP_BY_POST     ('post')\n";
echo "- Digest::GROUP_BY_TAXONOMY ('taxonomy')\n\n";

echo "=== Backward Compatibility ===\n\n";
echo "All existing functions continue to work:\n";
echo "- get_digest_changes() - Returns same format as before\n";
echo "- get_updated_posts() - Still available\n";
echo "- get_post_revisions() - Still available\n";
echo "- All other utility functions remain unchanged\n\n";
