<?php

/**
 * Mock classes for testing
 */

// Mock CPermission class with constants
class CPermission
{
    const OBJECT_PRIVACYLAB_OPERATORS = 1;
}

// Mock logWriter class with constants
class logWriter
{
    const MESSAGE_ERROR = 'error';
}

// Mock UserProfile class
class UserProfile
{
    public function checkPermission($object, $permission): bool
    {
        return true; // Always grant permission for testing
    }
}

// Mock Addetti class
class Addetti
{
    const P_INCARICATO_NOGRUPPI = 1;
    const P_INCARICATO = 2;

    public $nIdCompany = 1;

    public function getUtentiGruppi(): array
    {
        return [
            [
                'GRI_ID' => 1,
                'GRI_NomeGruppo' => 'Test Group',
                'GRI_Stage' => 1,
                'ADDETTI' => [
                    101 => 'John Doe',
                    102 => 'Jane Smith',
                ],
            ],
        ];
    }

    public function getPermessiGruppi(): array
    {
        return [
            [
                'GRI_ID' => 1,
                'BAF_IDBancaDati' => 1,
                'BAF_IDBaseFisica' => 1,
                'ABA_IDBasedati' => 1,
                'ABA_Lettura' => 1,
                'ABA_Scrittura' => 1,
                'ABA_Cancellazione' => 0,
                'ABA_Comunicazione' => 1,
                'ABA_Diffusione' => 0,
            ],
        ];
    }

    public function getAddetti($type, $param2 = false, $company = null, $param4 = true): array
    {
        if ($type === self::P_INCARICATO) {
            return [
                [
                    'ADD_ID' => 101,
                    'ADD_Nome' => 'John',
                    'ADD_Cognome' => 'Doe',
                    'ANA_Email' => 'john@example.com',
                ],
                [
                    'ADD_ID' => 102,
                    'ADD_Nome' => 'Jane',
                    'ADD_Cognome' => 'Smith',
                    'ANA_Email' => 'jane@example.com',
                ],
                [
                    'ADD_ID' => 103,
                    'ADD_Nome' => 'Bob',
                    'ADD_Cognome' => 'Wilson',
                    'ANA_Email' => 'bob@example.com',
                ],
            ];
        }

        if ($type === self::P_INCARICATO_NOGRUPPI) {
            return [
                [
                    'ADD_ID' => 103,
                    'ADD_Nome' => 'Bob',
                    'ADD_Cognome' => 'Wilson',
                    'ANA_Email' => 'bob@example.com',
                    'BAF_IDBancaDati' => 1,
                    'BAF_IDBaseFisica' => 1,
                    'ABA_IDBasedati' => 1,
                    'ABA_Lettura' => 1,
                    'ABA_Scrittura' => 0,
                    'ABA_Cancellazione' => 0,
                    'ABA_Comunicazione' => 0,
                    'ABA_Diffusione' => 0,
                ],
            ];
        }

        return [];
    }

    public function getNameString($user, $param = true): string
    {
        return $user['ADD_Cognome'] . ' ' . $user['ADD_Nome'];
    }
}

// Mock DBaseDati class
class DBaseDati
{
    public function getList(): array
    {
        return [
            1 => ['TIB_Nome' => 'Database 1'],
            2 => ['TIB_Nome' => 'Database 2'],
        ];
    }
}

// Mock RisBaseFisica class
class RisBaseFisica
{
    public function getList(): array
    {
        return [
            1 => ['BAS_Nome' => 'Archive 1'],
            2 => ['BAS_Nome' => 'Archive 2'],
        ];
    }
}

// Mock DocumentsTimestamp class
class DocumentsTimestamp
{
    public function getTimestampByTarget($target): ?string
    {
        $timestamps = $this->getTimestampsByTargets([$target]);
        return $timestamps[$target] ?? null;
    }

    public function getDocsToValidate($target): int
    {
        $docs = $this->getDocsToValidateByTargets([$target]);
        return $docs[$target] ?? 0;
    }

    public function getTimestampsByTargets(array $targetIds): array
    {
        // Return deterministic values for consistent testing
        $allTimestamps = [
            101 => '2026-01-15 10:00:00',
            102 => '2026-01-16 11:00:00',
            103 => '2026-01-17 12:00:00',
        ];

        $results = [];
        foreach ($targetIds as $id) {
            if (isset($allTimestamps[$id])) {
                $results[$id] = $allTimestamps[$id];
            }
        }
        return $results;
    }

    public function getDocsToValidateByTargets(array $targetIds): array
    {
        // Return deterministic values for consistent testing
        $allDocs = [
            101 => 4,
            102 => 5,
            103 => 4,
        ];

        $results = [];
        foreach ($targetIds as $id) {
            if (isset($allDocs[$id])) {
                $results[$id] = $allDocs[$id];
            }
        }
        return $results;
    }
}

// Mock ProjectUser class
class ProjectUser
{
    public static function getDocAllowedLanguages(): array
    {
        return ['en', 'it'];
    }
}

// Base trait for Test classes with common methods
trait TestBase
{
    protected $responses = [];
    protected $errors = [];

    protected function setError($error): void
    {
        $this->errors[] = $error;
    }

    protected function addResponse($data): void
    {
        $this->responses[] = $data;
    }

    protected function logData($data, $level): void
    {
        // Log nothing in tests
    }

    public function getResponses(): array
    {
        return $this->responses;
    }

    protected function getLangHtmlField($field, $lang): string
    {
        return $field . '_' . $lang;
    }

    protected function getLangDbField($field, $lang): string
    {
        return $field . '_' . $lang;
    }

    protected static function _sortArchivesProcessors($a, $b): int
    {
        return strcmp($a['order'] ?? '', $b['order'] ?? '');
    }
}
