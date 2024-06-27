<?php
namespace Snoke\SoftDelete;

use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Snoke\SoftDelete\DependencyInjection\SnokeSoftDeleteExtension;

use Symfony\Component\DependencyInjection\ContainerBuilder;
class SnokeSoftDeleteBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SnokeSoftDeleteExtension();
    }
    public function build(ContainerBuilder $container)
    {
        parent::build($container);
    }

}