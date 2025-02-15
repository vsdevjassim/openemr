<?php

namespace OpenEMR\Services\FHIR;

use OpenEMR\FHIR\Export\ExportCannotEncodeException;
use OpenEMR\FHIR\Export\ExportException;
use OpenEMR\FHIR\Export\ExportJob;
use OpenEMR\FHIR\Export\ExportStreamWriter;
use OpenEMR\FHIR\Export\ExportWillShutdownException;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\FHIR\FhirServiceBase;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIREncounter;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCode;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCodeableConcept;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCoding;
use OpenEMR\FHIR\R4\FHIRElement\FHIRPeriod;
use OpenEMR\FHIR\R4\FHIRElement\FHIRReference;
use OpenEMR\FHIR\R4\FHIRResource\FHIREncounter\FHIREncounterParticipant;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Validators\ProcessingResult;

class FhirEncounterService extends FhirServiceBase implements IFhirExportableResourceService
{
    /**
     * @var EncounterService
     */
    private $encounterService;

    public function __construct()
    {
        parent::__construct();
        $this->encounterService = new EncounterService();
    }

    /**
     * Returns an array mapping FHIR Encounter Resource search parameters to OpenEMR Encounter search parameters
     * @return array The search parameters
     */
    protected function loadSearchParameters()
    {
        return  [
            'patient' => new FhirSearchParameterDefinition('patient', SearchFieldType::TOKEN, ['pid']),
            '_id' => new FhirSearchParameterDefinition('_id', SearchFieldType::TOKEN, ['uuid']),
            'date' => new FhirSearchParameterDefinition('date', SearchFieldType::DATETIME, ['date'])
        ];
    }

    /**
     * Parses an OpenEMR patient record, returning the equivalent FHIR Patient Resource
     * https://build.fhir.org/ig/HL7/US-Core-R4/StructureDefinition-us-core-encounter-definitions.html
     *
     * @param array $dataRecord The source OpenEMR data record
     * @param boolean $encode Indicates if the returned resource is encoded into a string. Defaults to false.
     * @return FHIREncounter
     */
    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        $encounterResource = new FHIREncounter();

        $meta = array('versionId' => '1', 'lastUpdated' => gmdate('c'));
        $encounterResource->setMeta($meta);

        $id = new FhirId();
        $id->setValue($dataRecord['uuid']);
        $encounterResource->setId($id);

        $status = new FHIRCode('finished');
        $encounterResource->setStatus($status);

        if (!empty($dataRecord['provider_id'])) {
            $parctitioner = new FHIRReference(['reference' => 'Practitioner/' . $dataRecord['provider_id']]);
            $participant = new FHIREncounterParticipant(array(
                'individual' => $parctitioner,
                'period' => ['start' => gmdate('c', strtotime($dataRecord['date']))]
            ));
            $participantType = new FHIRCodeableConcept();
            $participantType->addCoding(array(
                "system" => "http://terminology.hl7.org/CodeSystem/v3-ParticipationType",
                "code" => "PPRF"
            ));
            $participantType->setText("Primary Performer");
            $participant->addType($participantType);
            $encounterResource->addParticipant($participant);
        }

        if (!empty($dataRecord['facility_id'])) {
            $serviceOrg = new FHIRReference(['reference' => 'Organization/' . $dataRecord['facility_id']]);
            $encounterResource->setServiceProvider($serviceOrg);
        }

        if (!empty($dataRecord['reason'])) {
            $reason = new FHIRCodeableConcept();
            $reason->setText($dataRecord['reason']);
            $encounterResource->addReasonCode($reason);
        }

        if (!empty($dataRecord['puuid'])) {
            $patient = new FHIRReference(['reference' => 'Patient/' . $dataRecord['puuid']]);
            $encounterResource->setSubject($patient);
        }

        if (!empty($dataRecord['date'])) {
            $period = new FHIRPeriod();
            $period->setStart(gmdate('c', strtotime($dataRecord['date'])));
            $encounterResource->setPeriod($period);
        }

        if (!empty($dataRecord['class_code'])) {
            $class = new FHIRCoding();
            $class->setSystem("http://terminology.hl7.org/CodeSystem/v3-ActCode");
            $class->setCode($dataRecord['class_code']);
            $class->setDisplay($dataRecord['class_title']);
            $encounterResource->setClass($class);
        }

        // Encounter.type
        $type = new FHIRCodeableConcept();
        $type->addCoding(array(
            "system" => "http://snomed.info/sct",
            "code" => "185349003"
        ));
        $type->setText("Encounter for check up (procedure)");
        $encounterResource->addType($type);

        if ($encode) {
            return json_encode($encounterResource);
        } else {
            return $encounterResource;
        }
    }

    /**
     * Performs a FHIR Encounter Resource lookup by FHIR Resource ID
     * @param $fhirResourceId //The OpenEMR record's FHIR Encounter Resource ID.
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     */
    public function getOne($fhirResourceId, $puuidBind = null)
    {
        $processingResult = $this->encounterService->getEncounter($fhirResourceId, $puuidBind);
        if (!$processingResult->hasErrors()) {
            if (count($processingResult->getData()) > 0) {
                $openEmrRecord = $processingResult->getData()[0];
                $fhirRecord = $this->parseOpenEMRRecord($openEmrRecord);
                $processingResult->setData([]);
                $processingResult->addData($fhirRecord);
            }
        }
        return $processingResult;
    }

    /**
     * Searches for OpenEMR records using OpenEMR search parameters
     *
     * @param array openEMRSearchParameters OpenEMR search fields
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return ProcessingResult
     */
    protected function searchForOpenEMRRecords($searchParam, $puuidBind = null): ProcessingResult
    {
        return $this->encounterService->getEncountersBySearch($searchParam, true, $puuidBind);
    }

    public function parseFhirResource($fhirResource = array())
    {
        // TODO: If Required in Future
    }

    public function insertOpenEMRRecord($openEmrRecord)
    {
        // TODO: If Required in Future
    }

    public function updateOpenEMRRecord($fhirResourceId, $updatedOpenEMRRecord)
    {
        // TODO: If Required in Future
    }

    public function createProvenanceResource($dataRecord = array(), $encode = false)
    {
        // TODO: If Required in Future
    }

    /**
     * Grabs all the objects in my service that match the criteria specified in the ExportJob.  If a
     * $lastResourceIdExported is provided, The service executes the same data collection query it used previously and
     * startes processing at the resource that is immediately after (ordered by date) the resource that matches the id of
     * $lastResourceIdExported.  This allows processing of the service to be resumed or paused.
     * @param ExportStreamWriter $writer Object that writes out to a stream any object that extend the FhirResource object
     * @param ExportJob $job The export job we are processing the request for.  Holds all of the context information needed for the export service.
     * @return void
     * @throws ExportWillShutdownException  Thrown if the export is about to be shutdown and all processing must be halted.
     * @throws ExportException  If there is an error in processing the export
     * @throws ExportCannotEncodeException Thrown if the resource cannot be properly converted into the right format (ie JSON).
     */
    public function export(ExportStreamWriter $writer, ExportJob $job, $lastResourceIdExported = null): void
    {
        // TODO: encounter search should support multiple date parameter searches for interval period search
//        $date = [
//            'le' . $job->getStartTime()->format(\DateTime::RFC3339_EXTENDED)
//            ,'ge' . $job->getResourceIncludeTime()->format(\DateTime::RFC3339_EXTENDED)
//        ];
        $date = 'le' . $job->getStartTime()->format(\DateTime::RFC3339_EXTENDED);
        $result = $this->getAll(['date' => $date]);
        $encounters = $result->getData();
        if (!empty($encounters)) {
            foreach ($encounters as $encounter) {
                $writer->append($encounter);
            }
        }
    }

    /**
     * Returns whether the service supports the system export operation
     * @see https://hl7.org/fhir/uv/bulkdata/export/index.html#endpoint---system-level-export
     * @return bool true if this resource service should be called for a system export operation, false otherwise
     */
    public function supportsSystemExport()
    {
        return true;
    }

    /**
     * Returns whether the service supports the group export operation.
     * Note only resources in the Patient compartment SHOULD be returned unless the resource assists in interpreting
     * patient data (such as Organization or Practitioner)
     * @see https://hl7.org/fhir/uv/bulkdata/export/index.html#endpoint---group-of-patients
     * @return bool true if this resource service should be called for a group export operation, false otherwise
     */
    public function supportsGroupExport()
    {
        return true;
    }

    /**
     * Returns whether the service supports the all patient export operation
     * Note only resources in the Patient compartment SHOULD be returned unless the resource assists in interpreting
     * patient data (such as Organization or Practitioner)
     * @see https://hl7.org/fhir/uv/bulkdata/export/index.html#endpoint---all-patients
     * @return bool true if this resource service should be called for a patient export operation, false otherwise
     */
    public function supportsPatientExport()
    {
        return true;
    }
}
