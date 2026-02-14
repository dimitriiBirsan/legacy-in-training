<?php

require_once __DIR__ . '/mocks.php';
require_once __DIR__ . '/../index.php';
require_once __DIR__ . '/../index_refactored.php';

use PHPUnit\Framework\TestCase;

class ArchivesProcessorsTest extends TestCase
{
    public function testRefactoredOutputMatchesLegacy(): void
    {
        $legacy = new LegacyTest();
        $legacy->getArchivesProcessors();

        $refactored = new Test();
        $refactored->getArchivesProcessors();

        $this->assertEquals(
            $legacy->getResponses(),
            $refactored->getResponses()
        );
    }
}
