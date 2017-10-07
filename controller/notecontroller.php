<?php
/**
 * NextCloud / ownCloud - fractalnote
 *
 * Licensed under the Apache License, Version 2.0
 *
 * @author Alexander Demchenko <a.demchenko@aldem.ru>, <https://github.com/alboro>
 * @copyright Alexander Demchenko 2017
 */
namespace OCA\FractalNote\Controller;

use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCA\FractalNote\Service\NotesStructure;
use OCA\FractalNote\Service\ConflictException;
use OCA\FractalNote\Service\NotFoundException;
use OCA\FractalNote\Service\WebException;
use OCA\FractalNote\Controller\AbstractController;

class NoteController extends AbstractController
{
    /**
     * @NoAdminRequired
     *
     * @param integer $mtime
     * @param integer $parentId
     * @param string  $title
     * @param integer $sequence
     *
     * @return DataResponse
     */
    public function create($mtime, $parentId, $title, $sequence)
    {
        return $this->handleWebErrors(function () use ($mtime, $parentId, $title, $sequence) {
            if (!$this->connector->isConnected()) {
                throw new NotFoundException();
            }
            if ($this->connector->getModifyTime() !== $mtime) {
                throw new ConflictException($title);
            }
            $relation = $this->notesStructure->create($parentId, $title, $sequence);
            return [$this->connector->getModifyTime(), $relation->getNodeId()];
        });
    }

    /**
     * @NoAdminRequired
     *
     * @param integer $mtime
     * @param array   $nodeData
     *
     * @return DataResponse
     */
    public function update($mtime, $nodeData)
    {
        return $this->handleWebErrors(function () use ($mtime, $nodeData) {
            $id = (int)$nodeData['id'];
            if (!$id || !$this->connector->isConnected()) {
                throw new NotFoundException();
            }
            if ($this->connector->getModifyTime() !== $mtime) {
                $storedTitle = $this->notesStructure->findNode($id)->getName();
                throw new ConflictException($storedTitle);
            }
            if (array_key_exists('newParentId', $nodeData)) {
                $this->notesStructure->move($id, $nodeData['newParentId'], $nodeData['sequence']);
            } else {
                $this->notesStructure->update($id, $nodeData['title'], $nodeData['content']);
            }
            return [$this->connector->getModifyTime()];
        });
    }

    /**
     * @param integer $mtime
     * @param integer $id
     *
     * @return DataResponse
     */
    public function destroy($mtime, $id)
    {
        return $this->handleWebErrors(function () use ($mtime, $id) {
            $id = (int)$id;
            if (!$id || !$this->connector->isConnected()) {
                throw new NotFoundException();
            }
            if ($this->connector->getModifyTime() !== $mtime) {
                $title = $this->notesStructure->findNode($id)->getName();
                throw new ConflictException($title);
            }
            $this->notesStructure->delete($id);
            return [$this->connector->getModifyTime()];
        });
    }
}
