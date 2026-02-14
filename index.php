<?php

class LegacyTest
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
            // Non mi serve l'esplosione per trattamento
            $aArchive = $hArchive->getList();
            $aReturnData = [];
            $aGruppi = $hProcessor->getUtentiGruppi();
            $aPermessiGruppi = $hProcessor->getPermessiGruppi();
            $aIncaricatoGruppo = $aEreditati = [];

            // Sposto array incaricati per caricare prima tutti, filtro per vedere se è presente una mail per ogni incaricato
// NON modificare perchè altrimenti scoppia l'interfaccia, meglio girarci intorno estraendo la mail dall'anagrafica
            $aIncaricati = $hProcessor->getAddetti(Addetti::P_INCARICATO_NOGRUPPI, false, $hProcessor->nIdCompany, true);
            $aAllIncaricati = $hProcessor->getAddetti(Addetti::P_INCARICATO, false, $hProcessor->nIdCompany, true);
            $aMailIDIncaricati = [];
            foreach ($aAllIncaricati as $aIncaricato) {
                if (!in_array($aIncaricato["ADD_ID"], array_keys($aMailIDIncaricati))) {
                    $aMailIDIncaricati[$aIncaricato["ADD_ID"]] = $aIncaricato["ANA_Email"] ?: "";
                }
            }

            foreach ($aGruppi as $aGrp) {
                foreach ($aGrp["ADDETTI"] as $nAddetto => $sAddetto) {
                    $aIncaricatoGruppo[$nAddetto][] = "g_" . $aGrp["GRI_ID"];
                }
            }
            foreach ($aGruppi as $aGrp) {
                $nId = "g_" . $aGrp["GRI_ID"];
                if (!array_key_exists($nId, $aReturnData)) {
                    $aRow = [
                        "name" => $aGrp["GRI_NomeGruppo"],
                        "order" => $aGrp["GRI_NomeGruppo"],
                        "id" => $nId,
                        "group" => 1,
                        "stage" => $aGrp["GRI_Stage"],
                        "userCount" => count((array) $aGrp["ADDETTI"]),
                        "userList" => array_values((array) $aGrp["ADDETTI"]),
                        "users" => (array) $aGrp["ADDETTI"],
                    ];
                    foreach (ProjectUser::getDocAllowedLanguages() as $sLang) {
                        $aRow[$this->getLangHtmlField("name", $sLang)] = $aGrp[$this->getLangDbField("GRI_NomeGruppo", $sLang)] ?? null;
                    }
                    $aReturnData[$nId] = $aRow;
                }
                foreach ($aPermessiGruppi as $aPerm) {
                    if ($aPerm["GRI_ID"] == $aGrp["GRI_ID"]) {
                        $aReturnData[$nId]["TRATTAMENTI"][$aPerm["BAF_IDBancaDati"]] = $aProcessing[$aPerm["BAF_IDBancaDati"]]["TIB_Nome"];
                        $aReturnData[$nId]["TRATTAMENTI_ID"][$aPerm["BAF_IDBancaDati"]] = $aPerm["BAF_IDBancaDati"];
                        $aReturnData[$nId]["ARCHIVI"][$aPerm["BAF_IDBaseFisica"]] = $aArchive[$aPerm["BAF_IDBaseFisica"]]["BAS_Nome"];
                        $aReturnData[$nId]["ARCHIVI_ID"][$aPerm["BAF_IDBaseFisica"]] = $aPerm["BAF_IDBaseFisica"];

                        $aReturnData[$nId]["PERMESSI"][$aPerm["ABA_IDBasedati"]] = [
                            "read" => $aPerm["ABA_Lettura"],
                            "writ" => $aPerm["ABA_Scrittura"],
                            "dele" => $aPerm["ABA_Cancellazione"],
                            "comu" => $aPerm["ABA_Comunicazione"],
                            "diff" => $aPerm["ABA_Diffusione"],
                        ];
                        foreach ($aGrp["ADDETTI"] as $nAddetto => $sAddetto) {
                            if (!array_key_exists($nAddetto, $aReturnData)) {
                                $bCanViewRequestBeSent = false;
                                $sAddettoMail = "";

                                // Check data from filtered array $aMailIDIncaricati to get the mail
                                if (in_array($nAddetto, array_keys($aMailIDIncaricati))) {

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
                                    "lastsent" => $hTimestamp->getTimestampByTarget($nAddetto),
                                    "docamount" => $hTimestamp->getDocsToValidate($nAddetto),
                                ];
                            }
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
                            $aReturnData[$nAddetto]["TRATTAMENTI"][$aPerm["BAF_IDBancaDati"]] = $aProcessing[$aPerm["BAF_IDBancaDati"]]["TIB_Nome"];
                            $aReturnData[$nAddetto]["TRATTAMENTI_ID"][$aPerm["BAF_IDBancaDati"]] = $aPerm["BAF_IDBancaDati"];
                            $aReturnData[$nAddetto]["ARCHIVI"][$aPerm["BAF_IDBaseFisica"]] = $aArchive[$aPerm["BAF_IDBaseFisica"]]["BAS_Nome"];
                            $aReturnData[$nAddetto]["ARCHIVI_ID"][$aPerm["BAF_IDBaseFisica"]] = $aPerm["BAF_IDBaseFisica"];
                        }
                    }
                }
            }
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
                        "lastsent" => $hTimestamp->getTimestampByTarget($aInca["ADD_ID"]),
                        "docamount" => $hTimestamp->getDocsToValidate($aInca["ADD_ID"]),
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
}