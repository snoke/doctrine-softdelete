<?php

namespace Snoke\SoftDelete\EventListener;

use ReflectionException;
use ReflectionClass;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Snoke\SoftDelete\Annotation\SoftDeleteCascade;
use Snoke\SoftDelete\Trait\SoftDelete;

#[AsDoctrineListener(event: Events::onFlush, priority: 500, connection: 'default')]
class FlushListener
{
    private EntityManagerInterface $objectManager;
    private array $processedObjects = [];


    private function softDelete($entity): void
    {
        $entity->delete();
        $this->processedObjects[spl_object_id($entity)] = $entity;
        $this->objectManager->persist($entity);
        $this->objectManager->flush();
    }

    private function hardDelete($entity): void
    {
        $this->objectManager->remove($entity);

        $this->processedObjects[spl_object_id($entity)] = $entity;
        $this->objectManager->persist($entity);
        $this->objectManager->flush();
    }

    private function getChildren(object $parent):array {
        $children = [
            'hardDelete' => [],
            'softDelete' => [],
        ];

        $reflectionClass = new ReflectionClass($parent);
        $properties = $reflectionClass->getProperties();
        foreach ($properties as $property) {
            /** @noinspection PhpExpressionResultUnusedInspection */
            $property->setAccessible(true);
            $propertyValue = $property->getValue($parent);
            if ($propertyValue === null) {
                continue;
            }
            if (count($property->getAttributes(SoftDeleteCascade::class))) {
                if ($propertyValue instanceof PersistentCollection || is_array($propertyValue)) {
                    foreach ($propertyValue as $element) {
                        $children['softDelete'][] = ['entity' => $element, 'mapping' => $propertyValue->getMapping()];

                    }
                }
                else {
                    $children['softDelete'][] = ['entity' =>  $propertyValue, 'mapping' => null];
                }
            } else {
                foreach ($property->getAttributes() as $attribute) {
                    $attributeInstance = $attribute->newInstance();
                    if (isset($attributeInstance->cascade) && in_array('remove', $attributeInstance->cascade, true)) {
                        if (PersistentCollection::class === get_class($propertyValue)) {
                            foreach ($propertyValue as $element) {
                                $children['hardDelete'][] = ['entity' => $element, 'mapping' => $propertyValue->getMapping()];
                            }
                        }
                    }
                }
            }
        }



        return $children;
    }

    /**
     * @throws ReflectionException
     */
    private function deleteChildren(object $parent): void
    {
        if (in_array(spl_object_id($parent), array_keys($this->processedObjects), true)) { return; }

        $children = $this->getChildren($parent);
        foreach($children['softDelete'] as $child) {
            $this->softDeleteChild($parent, $child['entity'],$child['mapping']);
        }
        foreach($children['hardDelete'] as $child) {
            $this->hardDeleteChild($parent,$child['entity'],$child['mapping']);
        }
    }

    /**
     * @throws ReflectionException
     */
    private function dissolveRelation(object $parent, object $child, AssociationMapping $mapping):void {

        $reflection = new ReflectionClass($child);
        $property = $reflection->getProperty($mapping['mappedBy']);
        /** @noinspection PhpExpressionResultUnusedInspection */
        $property->setAccessible(true);
        $propertyValue = $property->getValue($child);
        if (!($propertyValue instanceof PersistentCollection)) {
            $property->setValue($child,null);
            $this->objectManager->persist($child);
        }


        $reflection = new ReflectionClass($parent);
        $property = $reflection->getProperty($mapping['fieldName']);
        /** @noinspection PhpExpressionResultUnusedInspection */
        $property->setAccessible(true);
        $propertyValue = $property->getValue($parent);
        if ($propertyValue instanceof PersistentCollection) {
            $propertyValue->removeElement($child);
            $property->setValue($parent, $propertyValue);
            $this->objectManager->persist($parent);
        }

    }

    /**
     * @throws ReflectionException
     */
    private function hardDeleteChild(object $parent, object $child, AssociationMapping $mapping = null): void
    {
        if (in_array(spl_object_id($child), array_keys($this->processedObjects), true)) { return; }

        $this->deleteChildren($child);

        $this->dissolveRelation($parent,$child, $mapping);

        $this->objectManager->remove($child);
        $this->processedObjects[spl_object_id($child)] = $child;
    }

    /**
     * @throws ReflectionException
     */
    private function softDeleteChild(object $parent, object $child, AssociationMapping $mapping = null): void
    {
        if (in_array(spl_object_id($child), array_keys($this->processedObjects), true)) { return; }

        $this->deleteChildren($child);
        $this->softDelete($child);
    }

    /**
     * @throws ReflectionException
     */
    private function delete(object $entity, bool $softDelete, ?object $parent = null): void
    {
        if (in_array(spl_object_id($entity), array_keys($this->processedObjects), true)) {
            return;
        }

        $this->processedObjects[spl_object_id($entity)] = $entity;
        $this->deleteChildren($entity);

        $softDelete ? $this->softDelete($entity) : $this->hardDelete($entity);

        $this->objectManager->persist($entity);
    }

    /**
     * @throws ReflectionException
     */
    private function handleUpdate($entity): void
    {
        if (!in_array(SoftDelete::class, class_uses($entity), true)) {
            return;
        }

        if (null === $entity->getDeletedAt()) {
            return;
        }

        $this->delete($entity, true);
    }

    private function handleDeletion(object $entity) {
        $this->delete($entity, true);
        $classMetadata = $this->objectManager->getClassMetadata(get_class($entity));

        // Remove the entity from the scheduled deletions
        $uow = $this->objectManager->getUnitOfWork();
        $reflectionProperty = new \ReflectionProperty(UnitOfWork::class, 'entityDeletions');
        $reflectionProperty->setAccessible(true);
        $scheduledDeletions = $reflectionProperty->getValue($uow);
        $objectId = spl_object_id($entity);
        unset($scheduledDeletions[$objectId]);
        $reflectionProperty->setValue($uow, $scheduledDeletions);
    }

    private function handleProccessedObjects() {
        foreach($this->processedObjects as $objectId => $object) {
           //$this->objectManager->detach($object);
        }
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        die("ASD");
        /** @var EntityManagerInterface $objectManager */
        $this->objectManager = $args->getObjectManager();

        $uow = $this->objectManager->getUnitOfWork();

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if (in_array(SoftDelete::class,class_uses($entity))) {
                $this->handleDeletion($entity);
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (in_array(SoftDelete::class,class_uses($entity))) {
                $this->handleUpdate($entity);
            }
        }

        $this->handleProccessedObjects();
    }
    public function __construct(bool $detach = false)
    {
        var_dump($detach);
        die("XXXX");
        die($someSetting);
    }
}