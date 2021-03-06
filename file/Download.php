<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\mongodb\file;

use MongoDB\BSON\ObjectID;
use Yii;
use yii\base\InvalidConfigException;
use yii\base\Object;
use yii\helpers\FileHelper;

/**
 * Download represents the GridFS download operation.
 *
 * @property array|ObjectID $document document to be downloaded.
 * @property \MongoDB\Driver\Cursor $chunkCursor cursor for the file chunks. This property is read-only.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 2.1
 */
class Download extends Object
{
    /**
     * @var Collection file collection to be used.
     */
    public $collection;

    /**
     * @var array|ObjectID document to be downloaded.
     */
    private $_document;
    /**
     * @var \MongoDB\Driver\Cursor cursor for the file chunks.
     */
    private $_chunkCursor;


    /**
     * @return array document to be downloaded.
     * @throws InvalidConfigException on invalid document configuration.
     */
    public function getDocument()
    {
        if (!is_array($this->_document)) {
            if (is_scalar($this->_document) || $this->_document instanceof ObjectID) {
                $document = $this->collection->findOne(['_id' => $this->_document]);
                if (empty($document)) {
                    throw new InvalidConfigException('Document id=' . $this->_document . ' does not exist at collection "' . $this->collection->getFullName() . '"');
                }
                $this->_document = $document;
            } else {
                $this->_document = (array)$this->_document;
            }
        }
        return $this->_document;
    }

    /**
     * @param array|ObjectID $document
     */
    public function setDocument($document)
    {
        $this->_document = $document;
    }

    /**
     * Returns the size of the associated file.
     * @return integer file size.
     */
    public function getSize()
    {
        $document = $this->getDocument();
        if (isset($document['length'])) {
            return $document['length'];
        }
        return 0;
    }

    /**
     * Returns associated file's filename.
     * @return string|null file name.
     */
    public function getFilename()
    {
        $document = $this->getDocument();
        if (isset($document['filename'])) {
            return $document['filename'];
        }
        return null;
    }

    /**
     * @return \MongoDB\Driver\Cursor chuck list cursor.
     * @throws InvalidConfigException
     */
    public function getChunkCursor()
    {
        if ($this->_chunkCursor === null) {
            $file = $this->getDocument();
            $this->_chunkCursor = $this->collection->getChunkCollection()->find(
                ['files_id' => $file['_id']],
                [],
                ['sort' => ['n' => 1]]
            );
        }
        return $this->_chunkCursor;
    }

    /**
     * Saves file into the given stream.
     * @param resource $stream stream, which file should be saved to.
     * @return integer number of written bytes.
     */
    public function toStream($stream)
    {
        $bytesWritten = 0;
        foreach ($this->getChunkCursor() as $chunk) {
            $bytesWritten += fwrite($stream, $chunk['data']->getData());
        }
        return $bytesWritten;
    }

    /**
     * Saves download to the physical file.
     * @param string $filename name of the physical file.
     * @return integer number of written bytes.
     */
    public function toFile($filename)
    {
        $filename = Yii::getAlias($filename);
        FileHelper::createDirectory(dirname($filename));
        return $this->toStream(fopen($filename, 'w+'));
    }

    /**
     * Returns a string of the bytes in the associated file.
     * @return string file content.
     */
    public function toString()
    {
        $result = '';
        foreach ($this->getChunkCursor() as $chunk) {
            $result .= $chunk['data']->getData();
        }
        return $result;
    }

    // Compatibility with `MongoGridFSFile` :

    /**
     * Alias of [[toString()]] method.
     * @return string file content.
     */
    public function getBytes()
    {
        return $this->toString();
    }

    /**
     * Alias of [[toFile()]] method.
     * @param string $filename name of the physical file.
     * @return integer number of written bytes.
     */
    public function write($filename)
    {
        return $this->toFile($filename);
    }

    /**
     * @return resource file stream resource.
     */
    public function getResource()
    {
        // TODO : create stream wrapper
    }
}