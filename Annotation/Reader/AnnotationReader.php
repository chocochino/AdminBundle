<?php

namespace Symfonian\Indonesia\AdminBundle\Annotation\Reader;

/*
 * Author: Muhammad Surya Ihsanuddin<surya.kejawen@gmail.com>
 * Url: https://github.com/ihsanudin
 */

use InvalidArgumentException;
use ReflectionObject;
use Doctrine\Common\Annotations\Reader;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfonian\Indonesia\AdminBundle\Controller\CrudController;
use Symfonian\Indonesia\AdminBundle\Annotation\Schema\Crud;
use Symfonian\Indonesia\AdminBundle\Annotation\Schema\Entity;
use Symfonian\Indonesia\AdminBundle\Annotation\Schema\Grid;
use Symfonian\Indonesia\AdminBundle\Annotation\Schema\Page;
use Symfonian\Indonesia\AdminBundle\Annotation\Schema\Util\UtilAnnotationInterface;
use Symfonian\Indonesia\AdminBundle\Annotation\Schema\Util\AutoComplete;
use Symfonian\Indonesia\AdminBundle\Annotation\Schema\Util\DatePicker;
use Symfonian\Indonesia\AdminBundle\Annotation\Schema\Util\Editor;
use Symfonian\Indonesia\AdminBundle\Annotation\Schema\Util\FileChooser;
use Symfonian\Indonesia\AdminBundle\Annotation\Schema\Util\IncludeJavascript;

final class AnnotationReader
{
    private $reader;

    public function __construct(Reader $reader)
    {
        $this->reader = $reader;
    }

    public function onKernelController(FilterControllerEvent $event)
    {
        $controller = $event->getController();
        if (!is_array($controller)) {
            return;
        }

        $controller = $controller[0];
        if (!$controller instanceof CrudController) {
            return;
        }

        $reflectionObject = new ReflectionObject($controller);
        foreach ($this->reader->getClassAnnotations($reflectionObject) as $annotation) {
            if ($annotation instanceof Crud) {
                $this->compileTemplate($annotation, $controller);
            }

            if ($annotation instanceof Entity) {
                $controller->setEntity($annotation->getClass());
            }

            if ($annotation instanceof Grid) {
                $this->compileGrid($annotation, $controller);
            }

            if ($annotation instanceof Page) {
                $this->compilePage($annotation, $controller);
            }

            if ($annotation instanceof UtilAnnotationInterface) {
                if ($annotation instanceof AutoComplete) {
                    $controller->setAutoComplete($annotation->getRoute(), $annotation->getTargetSelector());
                }

                if ($annotation instanceof DatePicker) {
                    $controller->useDatePicker();
                }

                if ($annotation instanceof Editor) {
                    $controller->useEditor();
                }

                if ($annotation instanceof FileChooser) {
                    $controller->useCustomFileChooser();
                }

                if ($annotation instanceof IncludeJavascript) {
                    $controller->includeJs($annotation->getFile(), $annotation->getIncludeRoute());
                }
            }
        }
    }

    private function compileTemplate(Crud $annotation, CrudController $controller)
    {
        if ($annotation->getAdd()) {
            $controller->setNewTemplate($annotation->getAdd());
        }

        if ($annotation->getEdit()) {
            $controller->setEditTemplate($annotation->getEdit());
        }

        if ($annotation->getShow()) {
            $controller->setShowTemplate($annotation->getShow());
        }

        if ($annotation->getList()) {
            $controller->setListTemplate($annotation->getList());
        }

        if ($annotation->getForm()) {
            $controller->setForm($annotation->getForm());
        }

        if (! $annotation->getShowFields()) {
            throw new InvalidArgumentException('show fields must be set.');
        }

        if ($annotation->getAjaxTemplate()) {
            $controller->setAjaxTemplate($annotation->getAjaxTemplate());
        }
    }

    private function compileGrid(Grid $annotation, CrudController $controller)
    {
        $controller->setGridFields($annotation->getFields());
        $controller->setFilter($annotation->getFilter());
        $controller->upperCaseFilter($annotation->getNormalizeFilter());
    }

    private function compilePage(Page $annotation, CrudController $controller)
    {
        $controller->setTitle($annotation->getTitle());
        $controller->setDescription($annotation->getDescription());
    }
}