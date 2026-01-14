<?php

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Filesystem\Path;

echo "Testing Path::normalize() bug on branch 6.4\n";
echo "System: " . PHP_OS . "\n";
echo "Directory Separator: " . DIRECTORY_SEPARATOR . "\n\n";

$testCases = [
    'foo\\bar',
    'foo\\\\bar',
    'test\\file.txt',
    '/unix/path/with\\backslash',
];

echo "Test Results:\n";
echo str_repeat('-', 60) . "\n";

foreach ($testCases as $test) {
    $result = Path::normalize($test);
    $escaped = addcslashes($test, '\\');
    $resultEscaped = addcslashes($result, '\\');
    
    echo "Input:  '$escaped'\n";
    echo "Output: '$resultEscaped'\n";
    
    if (DIRECTORY_SEPARATOR === '/') {
        // On Unix, backslashes should be preserved
        $shouldBe = $test;
        $status = ($result === $shouldBe) ? '✓ CORRECT' : '✗ BUG (should preserve backslash)';
    } else {
        // On Windows, backslashes should be converted
        $shouldBe = str_replace('\\', '/', $test);
        $status = ($result === $shouldBe) ? '✓ CORRECT' : '✗ UNEXPECTED';
    }
    
    echo "Status: $status\n";
    echo str_repeat('-', 60) . "\n";
}
