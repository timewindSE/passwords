<?php

namespace OCA\Passwords\Migration\DatabaseRepair;

use OCA\Passwords\Db\FolderMapper;
use OCA\Passwords\Db\PasswordMapper;
use OCA\Passwords\Db\PasswordRevision;
use OCA\Passwords\Db\RevisionInterface;
use OCA\Passwords\Helper\SecurityCheck\AbstractSecurityCheckHelper;
use OCA\Passwords\Services\ConfigurationService;
use OCA\Passwords\Services\EncryptionService;
use OCA\Passwords\Services\EnvironmentService;
use OCA\Passwords\Services\Object\FolderService;
use OCA\Passwords\Services\Object\PasswordRevisionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\Migration\IOutput;

/**
 * Class PasswordRevisionRepairHelper
 *
 * @package OCA\Passwords\Migration\DatabaseRepair
 */
class PasswordRevisionRepair extends AbstractRevisionRepair {

    /**
     * @var ConfigurationService
     */
    protected $config;

    /**
     * @var FolderMapper
     */
    protected $folderMapper;

    /**
     * @var EncryptionService
     */
    protected $encryptionService;

    /**
     * @var string
     */
    protected $objectName = 'password';

    /**
     * @var bool
     */
    protected $convertFields = false;

    /**
     * PasswordRevisionRepair constructor.
     *
     * @param FolderMapper            $folderMapper
     * @param PasswordMapper          $modelMapper
     * @param ConfigurationService    $config
     * @param EncryptionService       $encryption
     * @param EnvironmentService      $environment
     * @param PasswordRevisionService $revisionService
     */
    public function __construct(
        FolderMapper $folderMapper,
        PasswordMapper $modelMapper,
        ConfigurationService $config,
        EncryptionService $encryption,
        EnvironmentService $environment,
        PasswordRevisionService $revisionService
    ) {
        parent::__construct($modelMapper, $revisionService);
        $this->folderMapper      = $folderMapper;
        $this->encryptionService = $encryption;
        $this->convertFields     = $config->getAppValue('migration/customFields') === '2019.4.2' || $environment->isCliMode();
        $this->config            = $config;
    }

    /**
     * @param IOutput $output
     *
     * @throws \Exception
     */
    public function run(IOutput $output): void {
        parent::run($output);
        $this->config->setAppValue('migration/customFields', '2019.4.2');
    }

    /**
     * @param PasswordRevision|RevisionInterface $revision
     *
     * @return bool
     * @throws \Exception
     */
    public function repairRevision(RevisionInterface $revision): bool {
        $fixed = false;

        if($revision->_isDecrypted() === false && $revision->getCustomFields() === '[]') {
            $revision->setCustomFields(null);
            $fixed = true;
        }

        if($revision->getCustomFields() === null && $revision->getCseType() === 'none') {
            $this->encryptionService->decrypt($revision);
            $revision->setCustomFields('[]');
            $fixed = true;
        }

        if($this->convertCustomFields($revision)) $fixed = true;

        if($revision->getStatus() === 1 && $revision->getStatusCode() === AbstractSecurityCheckHelper::STATUS_GOOD) {
            $revision->setStatus(0);
            $fixed = true;
        }

        if($revision->getFolder() !== FolderService::BASE_FOLDER_UUID) {
            try {
                $this->folderMapper->findByUuid($revision->getFolder());
            } catch(DoesNotExistException | MultipleObjectsReturnedException $e) {
                $revision->setFolder(FolderService::BASE_FOLDER_UUID);
                $fixed = true;
            }
        }

        if($fixed) $this->revisionService->save($revision);

        return $fixed || parent::repairRevision($revision);
    }

    /**
     * @param PasswordRevision $revision
     *
     * @return bool
     * @throws \Exception
     */
    public function convertCustomFields(PasswordRevision $revision): bool {
        if(!$this->convertFields || $revision->getCseType() !== 'none') return false;

        $this->encryptionService->decrypt($revision);
        $customFields = $revision->getCustomFields();

        if(substr($customFields, 0, 1) === '[') return false;
        if($customFields === '{}') {
            $revision->setCustomFields('[]');

            return true;
        }

        $oldFields = json_decode($customFields, true);
        $newFields = [];
        foreach($oldFields as $label => $data) {
            if(substr($label, 0, 1) === '_') $data['type'] = 'data';

            $newFields[] = ['label' => $label, 'type' => $data['type'], 'value' => $data['value']];
        }

        $revision->setCustomFields(json_encode($newFields));

        return true;
    }
}