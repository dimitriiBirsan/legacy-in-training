<?php

/**
 * Quick Comparison Helper
 *
 * Add this at the end of your file to quickly compare outputs
 * during development
 */

require_once __DIR__ . '/mocks.php';
require_once __DIR__ . '/../index.php';
require_once __DIR__ . '/../index_refactored.php';

class QuickComparison
{
    /**
     * Run both versions and compare outputs
     */
    public static function compare(): void
    {
        echo "Running comparison...\n\n";

        // Run legacy version
        echo "1. Executing legacy version...\n";
        $legacyInstance = new LegacyTest(); // Your original class name
        $legacyResult = self::executeAndCapture($legacyInstance);
        echo "   ✓ Legacy execution completed\n";

        // Run refactored version
        echo "2. Executing refactored version...\n";
        $refactoredInstance = new Test(); // Your refactored class
        $refactoredResult = self::executeAndCapture($refactoredInstance);
        echo "   ✓ Refactored execution completed\n\n";

        // Quick stats
        echo "3. Quick Statistics:\n";
        echo "   Legacy rows: " . count($legacyResult['rows'] ?? []) . "\n";
        echo "   Refactored rows: " . count($refactoredResult['rows'] ?? []) . "\n";
        echo "\n";

        // Compare
        echo "4. Comparing outputs...\n";
        $identical = self::deepCompare($legacyResult, $refactoredResult);

        if ($identical) {
            echo "\n";
            echo str_repeat("=", 70) . "\n";
            echo "   ✓✓✓ SUCCESS! Outputs are IDENTICAL! ✓✓✓\n";
            echo str_repeat("=", 70) . "\n";
        } else {
            echo "\n";
            echo str_repeat("=", 70) . "\n";
            echo "   ✗✗✗ FAILURE! Outputs differ! ✗✗✗\n";
            echo str_repeat("=", 70) . "\n";
            echo "\nCheck the detailed output above for differences.\n";
        }
    }

    /**
     * Execute the method and capture output
     */
    private static function executeAndCapture($instance): array
    {
        try {
            $result = $instance->getArchivesProcessors();
            $responses = $instance->getResponses();

            return $responses[0] ?? [];
        } catch (Exception $e) {
            echo "   ERROR: " . $e->getMessage() . "\n";
            return [];
        }
    }

    /**
     * Deep comparison with detailed output
     */
    private static function deepCompare($legacy, $refactored, $path = 'root', &$diffCount = 0): bool
    {
        if (gettype($legacy) !== gettype($refactored)) {
            $diffCount++;
            echo "   ✗ Type mismatch at {$path}\n";
            echo "     Legacy: " . gettype($legacy) . "\n";
            echo "     Refactored: " . gettype($refactored) . "\n";
            return false;
        }

        if (!is_array($legacy)) {
            if ($legacy !== $refactored) {
                $diffCount++;
                echo "   ✗ Value mismatch at {$path}\n";
                echo "     Legacy: " . var_export($legacy, true) . "\n";
                echo "     Refactored: " . var_export($refactored, true) . "\n";
                return false;
            }
            return true;
        }

        // Compare array keys
        $legacyKeys = array_keys($legacy);
        $refactoredKeys = array_keys($refactored);

        sort($legacyKeys);
        sort($refactoredKeys);

        if ($legacyKeys !== $refactoredKeys) {
            $diffCount++;
            $missingInRefactored = array_diff($legacyKeys, $refactoredKeys);
            $extraInRefactored = array_diff($refactoredKeys, $legacyKeys);

            echo "   ✗ Key mismatch at {$path}\n";
            if (!empty($missingInRefactored)) {
                echo "     Missing in refactored: " . implode(', ', $missingInRefactored) . "\n";
            }
            if (!empty($extraInRefactored)) {
                echo "     Extra in refactored: " . implode(', ', $extraInRefactored) . "\n";
            }
            return false;
        }

        // Recursively compare values
        $allMatch = true;
        foreach ($legacy as $key => $value) {
            $newPath = is_numeric($key) ? "{$path}[{$key}]" : "{$path}.{$key}";
            if (!self::deepCompare($value, $refactored[$key], $newPath, $diffCount)) {
                $allMatch = false;
            }
        }

        return $allMatch;
    }
}

// Execute if run directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    QuickComparison::compare();
}
