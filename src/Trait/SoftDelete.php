<?php

namespace Snoke\SoftDelete\Trait;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\PersistentCollection;
use ReflectionClass;
use ReflectionProperty;
use Snoke\SoftDelete\Annotation\SoftDeleteCascade;

trait SoftDelete
{

    /**
     * @var object[]
     */
    private array $processedObjects = [];

    /**
     * @var DateTimeImmutable|null
     */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    /**
     * @param EntityManagerInterface $em
     * @param object $entity
     * @return void
     */
    private function recursiveSoftDelete(EntityManagerInterface $em, object $entity): void
    {
        $objectId = spl_object_id($entity);
        if (in_array($objectId, $this->processedObjects, true)) {
            return;
        }

        if (!in_array(SoftDelete::class, class_uses($entity), true)) {
            return;
        }

        $this->processedObjects[] = $objectId;
        $entity->delete();
        $em->persist($entity);

        $reflectionClass = new ReflectionClass($entity);
        $properties = $reflectionClass->getProperties();
        foreach ($properties as $property) {
            $property->setAccessible(true);
            $propertyValue = $property->getValue($entity);
            if ($propertyValue === null) {
                continue;
            }
            if ($this->hasAttribute($property, SoftDeleteCascade::class)) {
                $this->processPropertyValue($em,$property, $propertyValue, true);
            } else {
                $this->processHardDeleteAttributes($entity, $em, $property, $propertyValue);
            }
        }
        $em->flush();
    }

    /**
     * @param ReflectionProperty $property
     * @param string $attributeClass
     * @return bool
     */
    private function hasAttribute(ReflectionProperty $property, string $attributeClass): bool
    {
        return count($property->getAttributes($attributeClass)) > 0;
    }


    /**
     * @param object $entity
     * @param EntityManagerInterface $em
     * @param ReflectionProperty $property
     * @param $propertyValue
     * @param bool $orphanRemoval
     * @return void
     * @throws \ReflectionException
     */
    private function processHardDeleteAttributes(object $entity, EntityManagerInterface $em, ReflectionProperty $property, $propertyValue, bool $orphanRemoval = false): void
    {
        $attributes = $property->getAttributes();
        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();
            if (isset($instance->orphanRemoval) && (true === $instance->orphanRemoval)) {
                if (PersistentCollection::class === get_class($propertyValue)) {
                    foreach ($propertyValue as $element) {
                        $mappedBy = $propertyValue->getMapping()['mappedBy'];
                        $reflect = new ReflectionClass($element);
                        $prop = $reflect->getProperty($mappedBy);
                        $prop->setAccessible(true);
                        $prop->setValue($element, null);
                        $em->persist($element);
                    }
                }
            }
            if ($this->isHardDelete($instance)) {
                $this->processPropertyValue($em, $property, $propertyValue, false);
                break;
            }
        }
    }


    /**
     * @param object $attributeInstance
     * @return bool
     */
    private function isHardDelete(object $attributeInstance): bool
    {
        if (isset($attributeInstance->cascade) && in_array('remove', $attributeInstance->cascade, true)) {
            return true;
        }

        return false;
    }


    /**
     * @param EntityManagerInterface $em
     * @param $property
     * @param $propertyValue
     * @param bool $isSoftDelete
     * @return void
     * @throws \ReflectionException
     */
    private function processPropertyValue(EntityManagerInterface $em, $property,$propertyValue, bool $isSoftDelete): void
    {
        if ($propertyValue instanceof PersistentCollection || is_array($propertyValue)) {
            foreach ($propertyValue as $element) {
                if ($isSoftDelete) {
                    $this->recursiveSoftDelete($em, $element);
                    $attributes = $property->getAttributes();
                    foreach ($attributes as $attribute) {
                        $instance = $attribute->newInstance();
                        if (isset($instance->orphanRemoval) && (true === $instance->orphanRemoval)) {
                            if (PersistentCollection::class === get_class($propertyValue)) {
                                foreach ($propertyValue as $element) {
                                    $propertyValue->removeElement($element);
                                    $mappedBy = $propertyValue->getMapping()['mappedBy'];
                                    $reflect = new ReflectionClass($element);
                                    $prop = $reflect->getProperty($mappedBy);
                                    $prop->setAccessible(true);
                                    $prop->setValue($element, null);
                                    $em->persist($element);
                                }
                            }
                        }
                    }
                } else {
                    $em->remove($element);
                    $em->flush();
                }
            }
        } elseif (is_object($propertyValue)) {
            if ($isSoftDelete) {
                $this->recursiveSoftDelete($em, $propertyValue);
            } else {
                $em->remove($propertyValue);
                $em->flush();
            }
            $isSoftDelete ? $this->recursiveSoftDelete($em, $propertyValue) : $em->remove($propertyValue);
        }
    }


    /**
     * @param DateTimeImmutable|null $deletedAt
     * @return void
     */
    private function setDeletedAt(?DateTimeImmutable $deletedAt = null): void
    {
        $this->deletedAt = $deletedAt ?: new DateTimeImmutable();
    }


    /**
     * @return DateTimeImmutable|null
     */
    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }


    /**
     * @return void
     */
    public function delete(): void
    {
        $this->setDeletedAt();
    }


    /**
     * @param PreFlushEventArgs $args
     * @return void
     */
    #[ORM\PreFlush]
    public function deletedAtPreFlush(PreFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        if (null !== $this->deletedAt) {
            $this->recursiveSoftDelete($em, $this);
        }
    }
}
