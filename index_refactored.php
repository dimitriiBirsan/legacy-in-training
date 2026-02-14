<?php

class Test
{
    use TestBase;

    public function getArchivesProcessors(): bool
    {
        try {
            $hUserProfile = new UserProfile();
            $bCanView = $hUserProfile->checkPermission(CPermission::OBJECT_PRIVACYLAB_OPERATORS, 0x000040);
            if (!$bCanView) {
                $this->setError("lblErrorPermission");

                return false;
            }

            $hProcessor = new Addetti();
            $hProcessing = new DBaseDati();
            $hArchive = new RisBaseFisica();
            $hTimestamp = new DocumentsTimestamp();
            $aProcessing = $hProcessing->getList();
            $aArchive = $hArchive->getList();
            $aReturnData = [];
            $aGruppi = $hProcessor->getUtentiGruppi();
            $aPermessiGruppi = $hProcessor->getPermessiGruppi();

            // Prepare email lookup for users
            $aMailIDIncaricati = $this->buildUserEmailLookup($hProcessor);

            // Build user-to-group mapping
            $aIncaricatoGruppo = $this->buildUserGroupMapping($aGruppi);

            // Batch load timestamp data for all users (solves N+1 query problem)
            $aIncaricati = $hProcessor->getAddetti(Addetti::P_INCARICATO_NOGRUPPI, false, $hProcessor->nIdCompany, true);
            $aAllUserIds = array_unique(array_merge(
                array_keys($aMailIDIncaricati),
                array_column($aIncaricati, 'ADD_ID')
            ));
            $aTimestamps = $hTimestamp->getTimestampsByTargets($aAllUserIds);
            $aDocAmounts = $hTimestamp->getDocsToValidateByTargets($aAllUserIds);

            // Process all groups and their members
            $aReturnData = $this->processGroups(
                $aGruppi,
                $aPermessiGruppi,
                $aProcessing,
                $aArchive,
                $aMailIDIncaricati,
                $aIncaricatoGruppo,
                $aTimestamps,
                $aDocAmounts,
                $aReturnData
            );

            // Process individual users not covered by groups
            $aReturnData = $this->processIndividualUsers(
                $aIncaricati,
                $aProcessing,
                $aArchive,
                $hProcessor,
                $aIncaricatoGruppo,
                $aTimestamps,
                $aDocAmounts,
                $aReturnData
            );

            uasort($aReturnData, "self::_sortArchivesProcessors");
            $this->addResponse([
                "rows" => array_values($aReturnData),
            ]);

            return true;
        } catch (Exception $e) {
            $this->setError($e);
            $this->logData($e, logWriter::MESSAGE_ERROR);

            return false;
        }
    }

    /**
     * Build a lookup array of user ID => email
     */
    private function buildUserEmailLookup(Addetti $hProcessor): array
    {
        $aAllIncaricati = $hProcessor->getAddetti(Addetti::P_INCARICATO, false, $hProcessor->nIdCompany, true);
        $aMailIDIncaricati = [];

        foreach ($aAllIncaricati as $aIncaricato) {
            if (!in_array($aIncaricato["ADD_ID"], array_keys($aMailIDIncaricati))) {
                $aMailIDIncaricati[$aIncaricato["ADD_ID"]] = $aIncaricato["ANA_Email"] ?: "";
            }
        }

        return $aMailIDIncaricati;
    }

    /**
     * Build a mapping of user ID => array of group IDs they belong to
     */
    private function buildUserGroupMapping(array $aGruppi): array
    {
        $aIncaricatoGruppo = [];

        foreach ($aGruppi as $aGrp) {
            foreach ($aGrp["ADDETTI"] as $nAddetto => $sAddetto) {
                $aIncaricatoGruppo[$nAddetto][] = "g_" . $aGrp["GRI_ID"];
            }
        }

        return $aIncaricatoGruppo;
    }

    /**
     * Process all groups, their permissions, and their members
     */
    private function processGroups(
        array $aGruppi,
        array $aPermessiGruppi,
        array $aProcessing,
        array $aArchive,
        array $aMailIDIncaricati,
        array $aIncaricatoGruppo,
        array $aTimestamps,
        array $aDocAmounts,
        array $aReturnData
    ): array {
        foreach ($aGruppi as $aGrp) {
            $nGroupId = "g_" . $aGrp["GRI_ID"];

            // Add group entry
            $aReturnData = $this->addGroupEntry($aGrp, $nGroupId, $aReturnData);

            // Process permissions for this group
            $aReturnData = $this->processGroupPermissions(
                $aGrp,
                $nGroupId,
                $aPermessiGruppi,
                $aProcessing,
                $aArchive,
                $aMailIDIncaricati,
                $aIncaricatoGruppo,
                $aTimestamps,
                $aDocAmounts,
                $aReturnData
            );
        }

        return $aReturnData;
    }

    /**
     * Add a group entry to the return data
     */
    private function addGroupEntry(array $aGrp, string $nGroupId, array $aReturnData): array
    {
        if (!array_key_exists($nGroupId, $aReturnData)) {
            $aRow = [
                "name" => $aGrp["GRI_NomeGruppo"],
                "order" => $aGrp["GRI_NomeGruppo"],
                "id" => $nGroupId,
                "group" => 1,
                "stage" => $aGrp["GRI_Stage"],
                "userCount" => count((array) $aGrp["ADDETTI"]),
                "userList" => array_values((array) $aGrp["ADDETTI"]),
                "users" => (array) $aGrp["ADDETTI"],
            ];

            foreach (ProjectUser::getDocAllowedLanguages() as $sLang) {
                $aRow[$this->getLangHtmlField("name", $sLang)] = $aGrp[$this->getLangDbField("GRI_NomeGruppo", $sLang)] ?? null;
            }

            $aReturnData[$nGroupId] = $aRow;
        }

        return $aReturnData;
    }

    /**
     * Process permissions for a group and its members
     */
    private function processGroupPermissions(
        array $aGrp,
        string $nGroupId,
        array $aPermessiGruppi,
        array $aProcessing,
        array $aArchive,
        array $aMailIDIncaricati,
        array $aIncaricatoGruppo,
        array $aTimestamps,
        array $aDocAmounts,
        array $aReturnData
    ): array {
        foreach ($aPermessiGruppi as $aPerm) {
            if ($aPerm["GRI_ID"] != $aGrp["GRI_ID"]) {
                continue;
            }

            // Add permission data to the group
            $aReturnData[$nGroupId]["TRATTAMENTI"][$aPerm["BAF_IDBancaDati"]] = $aProcessing[$aPerm["BAF_IDBancaDati"]]["TIB_Nome"];
            $aReturnData[$nGroupId]["TRATTAMENTI_ID"][$aPerm["BAF_IDBancaDati"]] = $aPerm["BAF_IDBancaDati"];
            $aReturnData[$nGroupId]["ARCHIVI"][$aPerm["BAF_IDBaseFisica"]] = $aArchive[$aPerm["BAF_IDBaseFisica"]]["BAS_Nome"];
            $aReturnData[$nGroupId]["ARCHIVI_ID"][$aPerm["BAF_IDBaseFisica"]] = $aPerm["BAF_IDBaseFisica"];
            $aReturnData[$nGroupId]["PERMESSI"][$aPerm["ABA_IDBasedati"]] = [
                "read" => $aPerm["ABA_Lettura"],
                "writ" => $aPerm["ABA_Scrittura"],
                "dele" => $aPerm["ABA_Cancellazione"],
                "comu" => $aPerm["ABA_Comunicazione"],
                "diff" => $aPerm["ABA_Diffusione"],
            ];

            // Process each member of the group
            $aReturnData = $this->processGroupMembers(
                $aGrp,
                $aPerm,
                $aProcessing,
                $aArchive,
                $aMailIDIncaricati,
                $aIncaricatoGruppo,
                $aTimestamps,
                $aDocAmounts,
                $aReturnData
            );
        }

        return $aReturnData;
    }

    /**
     * Process members of a group and apply inherited permissions
     */
    private function processGroupMembers(
        array $aGrp,
        array $aPerm,
        array $aProcessing,
        array $aArchive,
        array $aMailIDIncaricati,
        array $aIncaricatoGruppo,
        array $aTimestamps,
        array $aDocAmounts,
        array $aReturnData
    ): array {
        foreach ($aGrp["ADDETTI"] as $nAddetto => $sAddetto) {
            // Initialize user entry if not exists
            if (!array_key_exists($nAddetto, $aReturnData)) {
                $aReturnData = $this->initializeUserEntry(
                    $nAddetto,
                    $sAddetto,
                    $aMailIDIncaricati,
                    $aIncaricatoGruppo,
                    $aTimestamps,
                    $aDocAmounts,
                    $aReturnData
                );
            }

            // Apply inherited permissions
            $aReturnData = $this->applyInheritedPermissions($nAddetto, $aPerm, $aReturnData);

            // Add treatment and archive associations
            $aReturnData[$nAddetto]["TRATTAMENTI"][$aPerm["BAF_IDBancaDati"]] = $aProcessing[$aPerm["BAF_IDBancaDati"]]["TIB_Nome"];
            $aReturnData[$nAddetto]["TRATTAMENTI_ID"][$aPerm["BAF_IDBancaDati"]] = $aPerm["BAF_IDBancaDati"];
            $aReturnData[$nAddetto]["ARCHIVI"][$aPerm["BAF_IDBaseFisica"]] = $aArchive[$aPerm["BAF_IDBaseFisica"]]["BAS_Nome"];
            $aReturnData[$nAddetto]["ARCHIVI_ID"][$aPerm["BAF_IDBaseFisica"]] = $aPerm["BAF_IDBaseFisica"];
        }

        return $aReturnData;
    }

    /**
     * Initialize a user entry with basic information
     */
    private function initializeUserEntry(
        int $nAddetto,
        string $sAddetto,
        array $aMailIDIncaricati,
        array $aIncaricatoGruppo,
        array $aTimestamps,
        array $aDocAmounts,
        array $aReturnData
    ): array {
        $bCanViewRequestBeSent = false;
        $sAddettoMail = "";

        if (array_key_exists($nAddetto, $aMailIDIncaricati)) {
            $bCanViewRequestBeSent = ($aMailIDIncaricati[$nAddetto] && !empty(trim($aMailIDIncaricati[$nAddetto])));
            $sAddettoMail = trim($aMailIDIncaricati[$nAddetto]);
        }

        $aReturnData[$nAddetto] = [
            "name" => $sAddetto,
            "id" => $nAddetto,
            "group" => 0,
            "sendmail" => $bCanViewRequestBeSent ? 1 : 0,
            "email" => $sAddettoMail,
            "groups" => $aIncaricatoGruppo[$nAddetto] ?? null,
            "lastsent" => $aTimestamps[$nAddetto] ?? null,
            "docamount" => $aDocAmounts[$nAddetto] ?? 0,
        ];

        return $aReturnData;
    }

    /**
     * Apply inherited permissions from groups using OR logic (cumulative)
     */
    private function applyInheritedPermissions(int $nAddetto, array $aPerm, array $aReturnData): array
    {
        $aReturnData[$nAddetto]["PERMESSI_EREDITATI"][$aPerm["ABA_IDBasedati"]] = [
            "read" => (int) ((int) ($aReturnData[$nAddetto]["PERMESSI_EREDITATI"][$aPerm["ABA_IDBasedati"]]["read"] ?? 0) ||
                (int) $aPerm["ABA_Lettura"]),
            "writ" => (int) ((int) ($aReturnData[$nAddetto]["PERMESSI_EREDITATI"][$aPerm["ABA_IDBasedati"]]["writ"] ?? 0) ||
                (int) $aPerm["ABA_Scrittura"]),
            "dele" => (int) ((int) ($aReturnData[$nAddetto]["PERMESSI_EREDITATI"][$aPerm["ABA_IDBasedati"]]["dele"] ?? 0) ||
                (int) $aPerm["ABA_Cancellazione"]),
            "comu" => (int) ((int) ($aReturnData[$nAddetto]["PERMESSI_EREDITATI"][$aPerm["ABA_IDBasedati"]]["comu"] ?? 0) ||
                (int) $aPerm["ABA_Comunicazione"]),
            "diff" => (int) ((int) ($aReturnData[$nAddetto]["PERMESSI_EREDITATI"][$aPerm["ABA_IDBasedati"]]["diff"] ?? 0) ||
                (int) $aPerm["ABA_Diffusione"]),
        ];

        return $aReturnData;
    }

    /**
     * Process individual users (those not in groups or with direct permissions)
     */
    private function processIndividualUsers(
        array $aIncaricati,
        array $aProcessing,
        array $aArchive,
        Addetti $hProcessor,
        array $aIncaricatoGruppo,
        array $aTimestamps,
        array $aDocAmounts,
        array $aReturnData
    ): array {
        foreach ($aIncaricati as $aInca) {
            if (!array_key_exists($aInca["ADD_ID"], $aReturnData)) {
                $aReturnData[$aInca["ADD_ID"]] = [
                    "name" => $hProcessor->getNameString($aInca, true),
                    "order" => $aInca["ADD_Cognome"] . $aInca["ADD_Nome"],
                    "id" => $aInca["ADD_ID"],
                    "group" => 0,
                    "sendmail" => !empty(trim($aInca["ANA_Email"])) ? 1 : 0,
                    "email" => trim($aInca["ANA_Email"]) ?: "",
                    "groups" => $aIncaricatoGruppo[$aInca["ADD_ID"]] ?? null,
                    "lastsent" => $aTimestamps[$aInca["ADD_ID"]] ?? null,
                    "docamount" => $aDocAmounts[$aInca["ADD_ID"]] ?? 0,
                ];
            }

            $aReturnData[$aInca["ADD_ID"]]["TRATTAMENTI"][$aInca["BAF_IDBancaDati"]] =
                $aProcessing[$aInca["BAF_IDBancaDati"]]["TIB_Nome"];
            $aReturnData[$aInca["ADD_ID"]]["TRATTAMENTI_ID"][$aInca["BAF_IDBancaDati"]] = $aInca["BAF_IDBancaDati"];

            if ($aArchive[$aInca["BAF_IDBaseFisica"]]) {
                $aReturnData[$aInca["ADD_ID"]]["ARCHIVI"][$aInca["BAF_IDBaseFisica"]] =
                    $aArchive[$aInca["BAF_IDBaseFisica"]]["BAS_Nome"];
                $aReturnData[$aInca["ADD_ID"]]["ARCHIVI_ID"][$aInca["BAF_IDBaseFisica"]] = $aInca["BAF_IDBaseFisica"];
            }

            $aReturnData[$aInca["ADD_ID"]]["PERMESSI"][$aInca["ABA_IDBasedati"]] = [
                "read" => $aInca["ABA_Lettura"],
                "writ" => $aInca["ABA_Scrittura"],
                "dele" => $aInca["ABA_Cancellazione"],
                "comu" => $aInca["ABA_Comunicazione"],
                "diff" => $aInca["ABA_Diffusione"],
            ];
        }

        return $aReturnData;
    }
}