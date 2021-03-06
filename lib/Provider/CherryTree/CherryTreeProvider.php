<?php
/**
 * NextCloud - fractalnote
 *
 * Licensed under the Apache License, Version 2.0
 *
 * @author Alexander Demchenko <a.demchenko@aldem.ru>, <https://github.com/alboro>
 * @copyright Alexander Demchenko 2017
 */
namespace OCA\FractalNote\Provider\CherryTree;

use Exception;
use OC\Files\Filesystem;
use OC\Files\View;
use OCA\FractalNote\Provider\CherryTree\Mapper\BookmarkMapper;
use OCA\FractalNote\Provider\CherryTree\Mapper\CodeboxMapper;
use OCA\FractalNote\Provider\CherryTree\Mapper\GridMapper;
use OCA\FractalNote\Provider\CherryTree\Mapper\ImageMapper;
use OCA\FractalNote\Provider\CherryTree\Mapper\NodeMapper;
use OCA\FractalNote\Provider\CherryTree\Mapper\RelationMapper;
use OCA\FractalNote\Provider\CherryTree\Db\SqliteConnectionFactory;
use OCA\FractalNote\Provider\CherryTree\Entity\Node;
use OCA\FractalNote\Provider\CherryTree\Entity\Relation;
use OCA\FractalNote\Service\AbstractProvider;
use OCA\FractalNote\Service\Exception\NoChangesException;
use OCA\FractalNote\Service\Exception\NotEditableException;
use OCA\FractalNote\Service\Exception\NotFoundException;
use OCA\FractalNote\Service\Exception\WebException;
use OCP\IDBConnection;

class CherryTreeProvider extends AbstractProvider
{

    private $db;
    /** @var View */
    private $viewer;

    /**
     * CherryTreeProvider constructor.
     *
     * @param View $view
     * @param      $filePath
     *
     * @throws NotFoundException
     */
    public function __construct(View $view, $filePath)
    {
        $this->viewer = $view;
        $this->setDbByFilePath($filePath);
    }

    /**
     * @return bool
     */
    public function isConnected()
    {
        return true;
    }

    /**
     * @return IDBConnection
     */
    public function getDb()
    {
        return $this->db;
    }

    /**
     * @param IDBConnection $db
     *
     * @return AbstractProvider
     */
    public function setDb(IDBConnection $db)
    {
        $this->db = $db;

        return $this;
    }


    /**
     * @param string $file
     *
     * @return void
     *
     * @throws NotFoundException
     */
    private function setDbByFilePath($file)
    {
        $this->setFilesystemPathToStructure($file);
        if (!$file || !$this->viewer->is_file($file)) {
            throw new NotFoundException();
        }
        $postFix = ($file[strlen($file) -1] === '/') ? '/' : '';
        $relativeFilePath = $this->viewer->getAbsolutePath($file);
        list($storage, $internalPath) = Filesystem::resolvePath(
            $relativeFilePath . $postFix
        );
        $filePath = $storage->getLocalFile($internalPath);
        $this->setDb(
            SqliteConnectionFactory::getConnectionByPath($filePath)
        );
    }

    public function requireSync()
    {
        $this->viewer->touch($this->getFilesystemPathToStructure());
    }

    public function lockResource()
    {
        $this->viewer->lockFile($this->getFilesystemPathToStructure(), \OCP\Lock\ILockingProvider::LOCK_SHARED, true);
    }

    public function unlockResource()
    {
        $this->viewer->unlockFile($this->getFilesystemPathToStructure(), \OCP\Lock\ILockingProvider::LOCK_SHARED, true);
    }

    public function getModifyTime()
    {
        return $this->viewer->filemtime($this->getFilesystemPathToStructure());
    }

    /**
     * @param integer|string $nodeId
     * @param integer        $storedExpiration
     *
     * @return mixed
     */
    public function isExpired($nodeId, $storedExpiration)
    {
        return $this->getModifyTime() !== $storedExpiration;
    }

    /**
     * @return Relation[]
     */
    public function buildTree(): array
    {
        return $this->createRelationMapper()->buildTree();
    }

    /**
     * Not in use for now
     */
    public function findNode($id): Node
    {
        try {
            $node = $this->createNodeMapper()->find($id);

            // in order to be able to plug in different storage backends like files
            // for instance it is a good idea to turn storage related exceptions
            // into service related exceptions so controllers and service users
            // have to deal with only one type of exception
        } catch (Exception $e) {
            $this->handleException($e, false);
        }

        return $node;
    }

    public function updateChildRelationLevels(Node $node): void
    {
        $relationMapper = $this->createRelationMapper();
        $parentLevel = $node->getLevel();
        $childRelations = $relationMapper->findChildRelationsWithNodes($node->getId());
        foreach ($childRelations as $relation) {
            $relation->getNode()->setLevel($parentLevel + 1);
            $this->createNodeMapper()->update($relation->getNode());
            $this->updateChildRelationLevels($relation->getNode());
        }
    }

    protected function _createNode(
        string $parentNodeId,
        string $title,
        int $position,
        string $content,
        bool $isRich
    ): string {
        $db = $this->getDb();
        $db->beginTransaction();
        $nodeMapper = $this->createNodeMapper();
        $relationMapper = $this->createRelationMapper();

        $note = Node::factory();
        $note->setName($title);
        $note->setTxt($content);
        $note->setSyntax('plain-text');
        $note->setIsRichtxt($isRich);
        $note->setLevel($relationMapper->calculateLevelByParentId($parentNodeId));
        $note->setId($nodeMapper->calculateNextIncrementValue());
        $nodeMapper->insert($note);

        $child = new Relation();
        $child->setNode($note);
        $child->setFatherId($parentNodeId);
        $child->setSequence($position);
        $relationMapper->insert($child);

        $db->commit();
        return (string) $child->getNodeId();
    }

    /**
     * @param integer $nodeId
     * @param integer $newParentId
     * @param integer $sequence
     */
    protected function move($nodeId, $newParentId, $sequence): Relation
    {
        if ((int)$newParentId < 0) {
            throw new WebException('Passed parent node is out of range');
        }
        $relationMapper = $this->createRelationMapper();
        $relation = $relationMapper->find($nodeId); /* @var $relation Relation */
        $newParentId === 0 || $this->createNodeMapper()->find($newParentId);

        $relation->setFatherId($newParentId);
        null !== $sequence && $relation->setSequence($sequence);
        if (!$relation->getUpdatedFields()) {
            throw new NoChangesException();
        }
        $relationMapper->update($relation);

        return $relation;
    }

    protected function _updateNode($nodeId, $title, $content, $newParentId, $position): void
    {
        $nodeMapper = $this->createNodeMapper();
        $db = $this->getDb();
        $db->beginTransaction();

        $note = $nodeMapper->find($nodeId); /* @var Node $note */

        if ($newParentId === null) {
            null !== $title && $note->setName($title);
            if (null !== $content) {
                if (!$note->isEditable()) {
                    throw new NotEditableException($note->isRich(), $note->isReadOnly());
                }
                $note->setTxt($content);
            }
            if (!$note->getUpdatedFields()) {
                throw new NoChangesException();
            }
        } elseif (isset($newParentId)) {
            $relationMapper = $this->createRelationMapper();
            $this->move($nodeId, $newParentId, $position);
            $note->setLevel($relationMapper->calculateLevelByParentId((int)$newParentId));
            $this->updateChildRelationLevels($note);
        }
        // make changes
        $nodeMapper->update($note);

        $db->commit();
    }

    /**
     * @param integer $noteId
     */
    protected function _delete($noteId): void
    {
        $db = $this->getDb();
        $db->beginTransaction();

        $relationMapper = $this->createRelationMapper();
        $nodeMapper = $this->createNodeMapper();
        $relation = $relationMapper->find($noteId); /** @var $relation Relation */
        $note = $nodeMapper->find($noteId); /** @var $note Node */
        if ($note->getLevel() === 0 && $relationMapper->countChildRelations(0) === 1) {
            throw new \LogicException('The only one top node cannot be deleted.');
        }
        $childRelations = $relationMapper->findChildRelations($noteId);
        foreach ($childRelations as $childRelation) {
            $childRelation instanceof Relation && $this->_delete($childRelation->getNodeId());
        }
        $relationMapper->delete($relation);

        $bookmarkMapper = $this->createBookmarkMapper();
        $bookmark = $bookmarkMapper->findBookmark($note->getId());
        $bookmark && $bookmarkMapper->delete($bookmark);

        if ($note->isRich()) {

            $imageMapper = $this->createImageMapper();
            $images = $imageMapper->findAllByNodeId($note->getId());
            foreach ($images as $image) {
                $imageMapper->delete($image);
            }

            $codeMapper = $this->createCodeMapper();
            $codeboxes = $codeMapper->findAllByNodeId($note->getId());
            foreach ($codeboxes as $codebox) {
                $codeMapper->delete($codebox);
            }

            $gridMapper = $this->createGridMapper();
            $grids = $gridMapper->findAllByNodeId($note->getId());
            foreach ($grids as $grid) {
                $gridMapper->delete($grid);
            }
        }
        $nodeMapper->delete($note);
        $db->commit();
    }

    protected function createNodeMapper(): NodeMapper
    {
        return new NodeMapper($this->getDb());
    }

    protected function createRelationMapper(): RelationMapper
    {
        return new RelationMapper($this->getDb());
    }

    protected function createImageMapper(): ImageMapper
    {
        return new ImageMapper($this->getDb());
    }

    protected function createBookmarkMapper(): BookmarkMapper
    {
        return new BookmarkMapper($this->getDb());
    }

    protected function createGridMapper(): GridMapper
    {
        return new GridMapper($this->getDb());
    }

    protected function createCodeMapper(): CodeboxMapper
    {
        return new CodeboxMapper($this->getDb());
    }

    protected function handleException($e, $resourceLocked = true)
    {
        $resourceLocked && $this->getDb()->rollBack();
        parent::handleException($e, $resourceLocked);
    }
}
