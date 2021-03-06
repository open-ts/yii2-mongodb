<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\mongodb\file;

use MongoDB\BSON\ObjectID;
use yii\mongodb\Exception;
use Yii;
use yii\web\UploadedFile;

/**
 * Collection represents the Mongo GridFS collection information.
 *
 * A file collection object is usually created by calling [[Database::getFileCollection()]] or [[Connection::getFileCollection()]].
 *
 * File collection inherits all interface from regular [[\yii\mongo\Collection]], adding methods to store files.
 *
 * @property string $prefix prefix of this file collection.
 * @property \yii\mongodb\Collection $chunkCollection Mongo collection instance. This property is read-only.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 2.0
 */
class Collection extends \yii\mongodb\Collection
{
    /**
     * @var \yii\mongodb\Database MongoDB database instance.
     */
    public $database;

    /**
     * @var string prefix of this file collection.
     */
    private $_prefix;
    /**
     * @var \yii\mongodb\Collection file chunks Mongo collection.
     */
    private $_chunkCollection;
    /**
     * @var boolean whether file related fields indexes are ensured for this collection.
     */
    private $indexesEnsured = false;


    /**
     * @return string prefix of this file collection.
     */
    public function getPrefix()
    {
        return $this->_prefix;
    }

    /**
     * @param string $prefix prefix of this file collection.
     */
    public function setPrefix($prefix)
    {
        $this->_prefix = $prefix;
        $this->name = sprintf('%s.files', $prefix);
    }

    /**
     * Creates upload command.
     * @param array $options upload options.
     * @return Upload file upload instance.
     * @since 2.1
     */
    public function createUpload($options = [])
    {
        $config = $options;
        $config['collection'] = $this;
        return new Upload($config);
    }

    /**
     * Creates download command.
     * @param array|ObjectID $document file document ot be downloaded.
     * @return Download file download instance.
     * @since 2.1
     */
    public function createDownload($document)
    {
        $config = [
            'collection' => $this,
            'document' => $document,
        ];
        return new Download($config);
    }

    /**
     * Returns the Mongo collection for the file chunks.
     * @param boolean $refresh whether to reload the collection instance even if it is found in the cache.
     * @return \yii\mongodb\Collection mongo collection instance.
     */
    public function getChunkCollection($refresh = false)
    {
        if ($refresh || !is_object($this->_chunkCollection)) {
            $this->_chunkCollection = Yii::createObject([
                'class' => 'yii\mongodb\Collection',
                'database' => $this->database,
                'name' => sprintf('%s.chunks', $this->getPrefix())
            ]);
        }

        return $this->_chunkCollection;
    }

    /**
     * @inheritdoc
     */
    public function drop()
    {
        return parent::drop() && $this->database->dropCollection($this->getChunkCollection()->name);
    }

    /**
     * @inheritdoc
     * @return Cursor cursor for the search results
     */
    public function find($condition = [], $fields = [], $options = [])
    {
        return new Cursor($this, parent::find($condition, $fields, $options));
    }

    /**
     * @inheritdoc
     */
    public function remove($condition = [], $options = [])
    {
        // TODO : better approach for deleting
        $cursor = parent::find($condition, ['_id'], $options);
        $deleteCount = 0;
        foreach ($cursor as $row) {
            $deleteCount += parent::remove(['_id' => $row['_id']]);
            $this->getChunkCollection()->remove(['files_id' => $row['_id']]);
        }
        return $deleteCount;
    }

    /**
     * Creates new file in GridFS collection from given local filesystem file.
     * Additional attributes can be added file document using $metadata.
     * @param string $filename name of the file to store.
     * @param array $metadata other metadata fields to include in the file document.
     * @param array $options list of options in format: optionName => optionValue
     * @return mixed the "_id" of the saved file document. This will be a generated [[\MongoId]]
     * unless an "_id" was explicitly specified in the metadata.
     * @throws Exception on failure.
     */
    public function insertFile($filename, $metadata = [], $options = [])
    {
        $options['document'] = $metadata;
        $document = $this->createUpload($options)->addFile($filename)->complete();
        return $document['_id'];
    }

    /**
     * Creates new file in GridFS collection with specified content.
     * Additional attributes can be added file document using $metadata.
     * @param string $bytes string of bytes to store.
     * @param array $metadata other metadata fields to include in the file document.
     * @param array $options list of options in format: optionName => optionValue
     * @return mixed the "_id" of the saved file document. This will be a generated [[\MongoId]]
     * unless an "_id" was explicitly specified in the metadata.
     * @throws Exception on failure.
     */
    public function insertFileContent($bytes, $metadata = [], $options = [])
    {
        $options['document'] = $metadata;
        $document = $this->createUpload($options)->addContent($bytes)->complete();
        return $document['_id'];
    }

    /**
     * Creates new file in GridFS collection from uploaded file.
     * Additional attributes can be added file document using $metadata.
     * @param string $name name of the uploaded file to store. This should correspond to
     * the file field's name attribute in the HTML form.
     * @param array $metadata other metadata fields to include in the file document.
     * @param array $options list of options in format: optionName => optionValue
     * @return mixed the "_id" of the saved file document. This will be a generated [[\MongoId]]
     * unless an "_id" was explicitly specified in the metadata.
     * @throws Exception on failure.
     */
    public function insertUploads($name, $metadata = [], $options = [])
    {
        $uploadedFile = UploadedFile::getInstanceByName($name);
        if ($uploadedFile === null) {
            throw new Exception("Uploaded file '{$name}' does not exist.");
        }

        $options['filename'] = $uploadedFile->name;
        $options['document'] = $metadata;
        $document = $this->createUpload($options)->addFile($uploadedFile->tempName)->complete();
        return $document['_id'];
    }

    /**
     * Retrieves the file with given _id.
     * @param mixed $id _id of the file to find.
     * @return \MongoGridFSFile|null found file, or null if file does not exist
     * @throws Exception on failure.
     */
    public function get($id)
    {
        $document = $this->findOne(['_id' => $id]);
        if (empty($document)) {
            return null;
        }
        return $this->createDownload($document);
    }

    /**
     * Deletes the file with given _id.
     * @param mixed $id _id of the file to find.
     * @return boolean whether the operation was successful.
     * @throws Exception on failure.
     */
    public function delete($id)
    {
        $this->remove(['_id' => $id], ['limit' => 1]);
        return true;
    }

    /**
     * Makes sure that indexes, which are crucial for the file processing,
     * exist at this collection and [[chunkCollection]].
     * The check result is cached per collection instance.
     * @param boolean $force whether to ignore internal collection instance cache.
     * @return $this self reference.
     */
    public function ensureIndexes($force = false)
    {
        if (!$force && $this->indexesEnsured) {
            return $this;
        }

        $this->ensureFileIndexes();
        $this->ensureChunkIndexes();

        $this->indexesEnsured = true;
        return $this;
    }

    /**
     * Ensures indexes at file collection.
     */
    private function ensureFileIndexes()
    {
        $indexKey = ['filename' => 1, 'uploadDate' => 1];
        foreach ($this->listIndexes() as $index) {
            if ($index['key'] === $indexKey) {
                return;
            }
        }

        $this->createIndex($indexKey);
    }

    /**
     * Ensures indexes at chunk collection.
     */
    private function ensureChunkIndexes()
    {
        $chunkCollection = $this->getChunkCollection();
        $indexKey = ['files_id' => 1, 'n' => 1];
        foreach ($chunkCollection->listIndexes() as $index) {
            if (!empty($index['unique']) && $index['key'] === $indexKey) {
                return;
            }
        }
        $chunkCollection->createIndex($indexKey, ['unique' => true]);
    }
}
