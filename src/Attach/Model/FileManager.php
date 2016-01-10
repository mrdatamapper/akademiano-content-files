<?php
/**
 * User: Vasiliy Shvakin (orbisnull) zen4dev@gmail.com
 */

namespace Attach\Model;


use DeltaCore\Config;
use DeltaCore\Parts\Configurable;
use DeltaDb\EntityInterface;
use DeltaDb\Repository;
use DeltaUtils\FileSystem;
use Hashids\Hashids;
use HttpWarp\File\FileInterface;
use HttpWarp\File\UploadFile;
use Sequence\Model\Parts\Sequence;
use Sequence\Model\SequenceManagerInterface;
use UUID\Model\Parts\UuidTrait;
use UUID\Model\UuidComplexShort;
use UUID\Model\UuidFactory;

class FileManager extends Repository
{
    use Configurable;
    use Sequence;

    protected $rootUri;
    /** @var  Hashids */
    protected $hashids;

    /** @var  UuidFactory */
    protected $uuidFactory;

    protected $metaInfo = [
        "fields" => [
            "id",
            "section",
            "object",
            "type",
            "name",
            "description",
            "path",
            "uuid"
        ]
    ];

    public function getRelationsConfig()
    {
        return $this->getConfig()->get(["Attach", "relationMatrix"], []);
    }

    public function getSectionsConfig()
    {
        return $this->getConfig()->get(["Attach", "sectionMatrix"], []);
    }

    public function getSequenceName()
    {
        $config = $this->getConfig();
        $sequence = $config->get(["Attach", "sequence"], "default");
        return $sequence;
    }

    /**
     * @return mixed
     */
    public function getRootUri()
    {
        if (is_null($this->rootUri)) {
            $this->rootUri = "http://";
            if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || $_SERVER['SERVER_PORT'] == 443
            ) {
                $this->rootUri = "https://";
            }
            $this->rootUri .= $_SERVER["SERVER_NAME"];
        }
        return $this->rootUri;
    }

    /**
     * @param mixed $rootUri
     */
    public function setRootUri($rootUri)
    {
        $this->rootUri = $rootUri;
    }

    public function getSectionHash($entityClass)
    {
        if (is_object($entityClass)) {
            $entityClass = get_class($entityClass);
        }
        $hash = hexdec(hash("crc32", $entityClass));
        if ($hash <= 1000) {
            $hash = $hash + 1000;
        } elseif ($hash > 100000) {
            $hash = ceil($hash / 9999);
        }
        return $hash;
    }

    public function getSection($entityClass)
    {
        $typesConfig = $this->getSectionsConfig();
        if (is_object($entityClass)) {
            $entityClass = get_class($entityClass);
        }
        $section = null;
        if ($typesConfig instanceof Config) {
            $section = $typesConfig->get($entityClass);
        }
        if (null === $section) {
            $section = $this->getSectionHash($entityClass);
        }
        return $section;
    }

    /**
     * @return UuidFactory
     */
    public function getUuidFactory()
    {
        return $this->uuidFactory;
    }

    /**
     * @param UuidFactory $uuidFactory
     */
    public function setUuidFactory($uuidFactory)
    {
        $this->uuidFactory = $uuidFactory;
    }

    /**
     * @return mixed
     */
    public function getLastChanged()
    {
        return $this->lastChanged;
    }

    /**
     * @param mixed $lastChanged
     */
    public function setLastChanged($lastChanged)
    {
        $this->lastChanged = $lastChanged;
    }

    public function createUuid()
    {
        $sm = $this->getSequenceManager();
        $adapter = $sm->getAdapter("PgSequenceUuidComplexShort");
        $uuid = $adapter->getNext();
        $uf = $this->getUuidFactory();
        $uuid =  $uf->create($uuid);
        return $uuid;
    }

    /**
     * @deprecated
     */
    public function getNextSequence()
    {
        $sequence = $this->getSequenceName();
        $sm = $this->getSequenceManager();
        $next = $sm->getNext($sequence);
        return $next;
    }

    public function getSavePath($ext = null, $currentPath = null)
    {
        $configPaths = [];
        if ($ext) {
            $configPaths[] = ["Attach", "filesPath", $ext];
        }
        if ($currentPath) {
            $fileMime = FileSystem::getFileType($currentPath);
            $configPaths[] = ["Attach", "filesPath", $fileMime];
            $fileType = FileSystem::getFileTypeConst($currentPath);
            $configPaths[] = ["Attach", "filesPath", $fileType];
        }
        $configPaths[] = ["Attach", "filesPath", "default"];
        $configPaths[] = ["Attach", "filesPath"];

        $path = $this->getConfig()->getOneIs($configPaths);
        if (is_array($path)) {
            throw new \RuntimeException("Many option for file path available");
        }
        return $path;
    }

    /**
     * @return Hashids
     */
    public function getHahids()
    {
        if (null === $this->hashids) {
            $salt = $this->getConfig(["Attach", "hashids", "salt"], __FILE__);
            $this->hashids = new Hashids($salt, 4, "qwertyuiopasdfghjklzxcvbnm123456789");
        }
        return $this->hashids;
    }

    public function hash($value)
    {
        return $this->getHahids()->encode($value);
    }

    public function getNewFilePath($ext = null, $currentPath = null, UuidComplexShort $uuid)
    {
        $firstDirsLevelCount = $this->getConfig(["Attach", "firstDirsLevelCount"], 16);
        $secondDirsLevelCount = $this->getConfig(["Attach", "secondDirsLevelCount"], 16);

        $dir1 = ($uuid->getId() + $uuid->getDate()->format("B")) % $firstDirsLevelCount;
        $dir1 = $this->hash($dir1);
        $dir2 = $uuid->getId() % $secondDirsLevelCount;
        $dir2 = $this->hash($dir2);
        $subdirs = $dir1 . "/" . $dir2;
        $savedPath = $this->getSavePath($ext, $currentPath);
        if ($ext) {
            $ext = "." . $ext;
        }
        $name = "{$savedPath}/{$subdirs}/{$uuid->toHex()}{$ext}";
        return $name;
    }

    public function saveFileIO(FileInterface $file, UuidComplexShort $uuid = null)
    {
        $fileExt = $file->getExt();
        $tmpPath = $file->getPath();
        if (null === $uuid) {
            $uuid = $this->createUuid();
        }
        $newFile = $this->getNewFilePath($fileExt, $tmpPath, $uuid);
        $fullNewPath = ROOT_DIR . "/" . $newFile;
        $dir = dirname($fullNewPath);
        if (!file_exists($dir)) {
            mkdir($dir, 0750, true);
        }
        if (!$file->mv($fullNewPath)) {
            return false;
        };
        return $newFile;
    }

    public function create(array $data = null)
    {
        /** @var File $entity */
        $entity = parent::create($data);
        $entity->setRootUri($this->getRootUri());
        $entity->setUuidFactory($this->getUuidFactory());
        return $entity;
    }

    public function saveFileForObject(EntityInterface $object, FileInterface $file, $name = null, $description = null)
    {
        $section = $this->getSection($object);
        $objId = $object->getId();
        $uuid = $this->createUuid();
        $path = $this->saveFileIO($file, $uuid);
        if (!$path) {
            throw new \RuntimeException("file not saved");
        }
        $fileInfo = [
            "section" => $section,
            "object" => $objId,
            "path" => $path,
            "type" => $file->getType(),
            "uuidFactory" => $this->getUuidFactory(),
            "uuid" => $uuid,
        ];
        if (!is_null($name)) {
            $fileInfo["name"] = $name;
        }
        if (!is_null($description)) {
            $fileInfo["description"] = $description;
        }
        $file = $this->create($fileInfo);
        $this->save($file);
    }

    public function getFilesForObject(EntityInterface $object)
    {
        $section = $this->getSection($object);
        $id = $object->getId();
        $criteria = [
            "section" => $section,
            "object" => $id,
        ];
        $items = $this->find($criteria);
        return $items;
    }
}
